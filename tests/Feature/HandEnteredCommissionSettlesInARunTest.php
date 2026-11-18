<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\Setting;
use App\Models\SystemRun;
use App\Models\User;
use App\Services\ManualCommissionEntry;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A commission added by hand lands on the leg, and a System Run settles it on
 * the DAY IT IS GIVEN.
 *
 * Reported 2026-09-15: *"add a commisson by hand eke thama add kran commission
 * add wen na ... methanat aniwaren date ekak awshyai, mokad system run ekedi me
 * add kran point adal day ekat add kragnn one"*.
 *
 * ⚠️ TWO SEPARATE FAULTS, both fixed in ServiceGroupFlush::legScopeQuery():
 *   1. A hand entry has no student, so no SEAT. The leg query matched seats with
 *      `whereIn('group_member_id', …)`, and a NULL never matches an IN-list — so
 *      the row was written, counted in the consultant's Service Group totals,
 *      and then invisible to every leg query. The page showed the 2,500 while a
 *      run read LKR 0 on the same account in the same minute.
 *   2. A hand entry has no payment, so nothing dated it. Payment-less rows were
 *      waved through on ANY cutoff, so the owner could not say which run should
 *      settle one.
 */
class HandEnteredCommissionSettlesInARunTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        Setting::set('stage_first_pairs', '1', 'integer', 'commission');
        Setting::set('stage_second_pairs', '2', 'integer', 'commission');
        Setting::set('stage_repeat_pairs', '3', 'integer', 'commission');
        Setting::set('account_unlock_students', '12', 'integer', 'commission');
        ServiceGroupStaging::forget();

        Notification::fake();
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    /** The first placement account — nothing sits before it, so it is not locked. */
    private function account(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()
            ->consultant->hiddenAccounts()->orderBy('id')->firstOrFail();
    }

    private function groupFor(Consultant $account): Group
    {
        return Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
    }

    /** @return array{0: float, 1: float} what each leg holds, with no date bound */
    private function legs(?Consultant $account = null): array
    {
        $account = ($account ?? $this->account())->fresh();
        $flush = app(ServiceGroupFlush::class);
        $group = $this->groupFor($account);

        return [$flush->legBalance($account, $group, 1), $flush->legBalance($account, $group, 2)];
    }

    private function addByHand(int $leg, ?Carbon $on = null, string $scope = 'account'): array
    {
        return app(ManualCommissionEntry::class)
            ->add($this->account(), null, 'slot', $leg, $scope, null, $on);
    }

    private function runFor(Carbon $date): SystemRun
    {
        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), [
                'confirm' => 'RUN',
                'cutoff' => $date->toDateString(),
                'confirm_earlier' => '1',
            ])
            ->assertRedirect();

        return SystemRun::latest('id')->firstOrFail();
    }

    private function settled(SystemRun $run, Consultant $account): ?array
    {
        return collect($run->settledAccounts())->firstWhere('account_id', $account->id);
    }

    private function today(): Carbon
    {
        return Carbon::now('Asia/Colombo');
    }

    // ----- fault 1: it never reached the leg at all ---------------------------------

    /** ⚠️ THE REPORT. A hand-entered 2,500 now shows on the leg it was given to. */
    public function test_a_hand_entered_commission_lands_on_the_leg(): void
    {
        $this->assertSame([0.0, 0.0], $this->legs(), 'guard: nothing on the legs yet');

        $this->addByHand(1, $this->today());

        $this->assertSame([(float) self::SLOT, 0.0], $this->legs(), 'it belongs to Service Group 1');
    }

    /** ⚠️ And a System Run actually settles it — the whole point of the report. */
    public function test_a_system_run_settles_a_hand_entered_pair(): void
    {
        $account = $this->account();

        $this->addByHand(1, $this->today());
        $this->addByHand(2, $this->today());

        $this->assertSame([(float) self::SLOT, (float) self::SLOT], $this->legs(), 'guard: balanced by hand');

        $run = $this->runFor($this->today());
        $settled = $this->settled($run, $account);

        $this->assertNotNull($settled, 'the account took part in the run');
        $this->assertSame(5000.0, (float) $settled['released'], '2,500 a side at 1-to-1');
        $this->assertSame([0.0, 0.0], $this->legs(), 'and the legs are cleared');
    }

    // ----- fault 2: the date decides which run picks it up ---------------------------

    /** ⚠️ THE DATE FIELD. A run dated BEFORE the chosen day must not touch it. */
    public function test_a_run_before_the_chosen_date_does_not_settle_it(): void
    {
        $account = $this->account();

        $this->addByHand(1, $this->today());
        $this->addByHand(2, $this->today());

        $run = $this->runFor($this->today()->subDays(2));

        $this->assertNull($this->settled($run, $account), 'it is not dated for that run');
        $this->assertSame([(float) self::SLOT, (float) self::SLOT], $this->legs(), 'and nothing was taken');
    }

    /** ...and a run for the day it WAS given settles it, even days later. */
    public function test_the_chosen_date_is_the_day_the_run_settles_it(): void
    {
        $account = $this->account();
        $threeDaysAgo = $this->today()->subDays(3);

        $this->addByHand(1, $threeDaysAgo);
        $this->addByHand(2, $threeDaysAgo);

        $run = $this->runFor($threeDaysAgo);

        $this->assertNotNull($this->settled($run, $account), 'settled by the date it was given');
        $this->assertSame([0.0, 0.0], $this->legs());
    }

    // ----- the upline scope ----------------------------------------------------------

    /** Every account the climb credits shows the money on its OWN leg. */
    public function test_the_upline_scope_lands_on_each_accounts_own_leg(): void
    {
        $result = $this->addByHand(1, $this->today(), 'upline');

        $this->assertNotEmpty($result['created'], 'guard: the climb credited something');

        $flush = app(ServiceGroupFlush::class);

        foreach ($result['created'] as $row) {
            $account = Consultant::where('scn_code', $row['code'])->first()
                ?? Consultant::where('consultant_code', $row['code'])->firstOrFail();

            $group = Group::where('consultant_id', $account->id)->orderBy('id')->first();

            if (! $group) {
                continue;
            }

            $this->assertGreaterThan(
                0.0,
                $flush->legBalance($account->fresh(), $group, 1),
                $row['code'].' should hold its hand-entered 2,500 on its own Service Group 1',
            );
        }
    }

    // ----- what must NOT change -------------------------------------------------------

    /** A seated row is still scoped to its own group, exactly as before. */
    public function test_a_row_seated_in_another_group_is_still_excluded(): void
    {
        $account = $this->account();
        $other = User::where('email', 'consultant@etec.lk')->firstOrFail()
            ->consultant->hiddenAccounts()->orderBy('id')->skip(1)->first();

        $this->assertNotNull($other, 'guard: there is a second placement account');

        // Written against THIS account but carrying the OTHER account's group —
        // the group_id branch must not let it onto this account's leg.
        Commission::create([
            'consultant_id' => $account->id,
            'user_id' => $account->owningPerson()->user_id,
            'group_id' => $this->groupFor($other)->id,
            'type' => \App\Services\CommissionService::TYPE_SLOT,
            'tier' => 1,
            'amount' => self::SLOT,
            'status' => 'approved',
        ]);

        $this->assertSame([0.0, 0.0], $this->legs(), 'another group\'s row stays off this leg');
    }
}
