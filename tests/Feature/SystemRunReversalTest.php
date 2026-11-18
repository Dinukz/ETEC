<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\GroupMember;
use App\Models\SystemRun;
use App\Models\User;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use App\Services\SystemRunReversal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Undoing a System Run, one account at a time.
 *
 * Requested 2026-08-25: "apit meka revers krannth option ekak hadann — wardila
 * giyanm ho wadiyen dila thiyeno, eka account wise revers krann puluwan
 * widiyta." A run stamps rows released, cancels the overflow into company
 * revenue and moves the stage on; before this, getting one account wrong meant
 * living with it.
 */
class SystemRunReversalTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function account(bool $repeating = false): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $account = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-4'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        if ($repeating) {
            $account->forceFill(['lifetime_pair_stage' => ServiceGroupStaging::ONE_TIME_STAGES])->save();
        }

        app(\App\Services\HiddenAccountService::class)->ensureGroup($account);

        return $account->fresh();
    }

    private function seedRows(Consultant $account, int $tier, int $count): void
    {
        $group = app(\App\Services\HiddenAccountService::class)->ensureGroup($account);

        for ($i = 0; $i < $count; $i++) {
            $student = User::factory()->create();
            $student->assignRole('student');

            $seat = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $student->id,
                'tier' => $tier,
                'position' => ($tier * 10000) + random_int(1, 9000),
                'commission_amount' => 0,
                'joined_at' => now()->subDays(3),
            ]);

            Commission::create([
                'consultant_id' => $account->id,
                'user_id' => $student->id,
                'group_id' => $group->id,
                'group_member_id' => $seat->id,
                'type' => 'bonus',
                'tier' => $tier,
                'amount' => self::SLOT,
                'status' => 'approved',
            ])->forceFill(['created_at' => now()->subDays(3)])->save();
        }
    }

    /** Run, and record it the way the controller does. */
    private function runAndRecord(): SystemRun
    {
        $result = app(ServiceGroupFlush::class)->runAll();

        return SystemRun::create([
            'run_by' => User::role('admin')->first()->id,
            'cutoff_at' => $result['cutoff'],
            'accounts_flushed' => $result['flushed'],
            'total_released' => $result['payout'],
            'total_forfeited' => $result['forfeit'],
            'accounts' => $result['accounts'] ? json_encode($result['accounts']) : null,
            'failures' => null,
        ]);
    }

    /**
     * ⚠️ Released rows go back onto their legs; the stage stays where it is.
     *
     * The stage used to go back too. Since 2026-09-14 a stage only moves
     * forward (owner: "dana stage eka thama digtam thiyenne"), so a reversal
     * returns the money and the account carries on from the stage it reached.
     */
    public function test_it_puts_a_released_account_back_as_it_was(): void
    {
        $account = $this->account();

        $this->seedRows($account, 1, 2);
        $this->seedRows($account, 2, 2);

        $run = $this->runAndRecord();

        $this->assertSame(5000.0, (float) $run->total_released);
        $this->assertSame(1, (int) $account->fresh()->lifetime_pair_stage, 'The run advanced it.');

        $result = app(SystemRunReversal::class)->reverse($run, $account->id);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(
            10000.0,
            (float) Commission::where('consultant_id', $account->id)->whereNull('released_at')->sum('amount'),
            'Everything is back on the legs.',
        );
        $this->assertSame(1, (int) $account->fresh()->lifetime_pair_stage, 'And the stage stays where the run moved it — stages only move forward.');
    }

    /** ⚠️ Forfeited money comes back off company revenue, with the right status. */
    public function test_it_takes_forfeited_money_back_off_company_revenue(): void
    {
        $account = $this->account(repeating: true);

        $this->seedRows($account, 1, 5);   // 12,500 — 5,000 will be forfeited
        $this->seedRows($account, 2, 3);   // 7,500

        $revenueBefore = (float) CompanyRevenue::sum('amount');

        $run = $this->runAndRecord();

        $this->assertSame(5000.0, (float) $run->total_forfeited);
        $this->assertSame($revenueBefore + 5000.0, (float) CompanyRevenue::sum('amount'));

        $result = app(SystemRunReversal::class)->reverse($run, $account->id);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame($revenueBefore, (float) CompanyRevenue::sum('amount'), 'The company revenue row is gone.');
        $this->assertSame(
            0,
            Commission::where('consultant_id', $account->id)->where('status', 'cancelled')->count(),
            'Nothing is left cancelled.',
        );
        $this->assertSame(
            20000.0,
            (float) Commission::where('consultant_id', $account->id)->whereNull('released_at')->sum('amount'),
            'All 8 records are back on the legs.',
        );
    }

    /**
     * ⚠️ THE LINE IT MUST NOT CROSS. Money already marked PAID has left the
     * company — putting it back on a leg would let it be paid a second time.
     */
    public function test_it_refuses_to_claw_back_money_already_paid(): void
    {
        $account = $this->account();

        $this->seedRows($account, 1, 2);
        $this->seedRows($account, 2, 2);

        $run = $this->runAndRecord();

        // The accountant pays one of the released records before anyone notices
        // the run was wrong.
        $paidRow = Commission::where('consultant_id', $account->id)->whereNotNull('released_at')->first();
        $paidRow->update(['status' => 'paid']);

        $result = app(SystemRunReversal::class)->reverse($run, $account->id);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, $result['skipped_paid'], 'It reports what it would not touch.');
        $this->assertSame('paid', $paidRow->fresh()->status);
        $this->assertNotNull($paidRow->fresh()->released_at, 'A paid row stays off the leg.');
        $this->assertStringContainsString('already been marked paid', $result['message']);
    }

    /** Reversing twice must not credit anything a second time. */
    public function test_it_cannot_be_reversed_twice(): void
    {
        $account = $this->account();

        $this->seedRows($account, 1, 2);
        $this->seedRows($account, 2, 2);

        $run = $this->runAndRecord();

        $this->assertTrue(app(SystemRunReversal::class)->reverse($run, $account->id)['ok']);

        $second = app(SystemRunReversal::class)->reverse($run->fresh(), $account->id);

        $this->assertFalse($second['ok']);
        $this->assertStringContainsString('already been reversed', $second['message']);
    }

    /** ⚠️ One account only — the others in the same run are untouched. */
    public function test_it_reverses_only_the_account_asked_for(): void
    {
        $a = $this->account();
        $b = $this->account();

        foreach ([$a, $b] as $account) {
            $this->seedRows($account, 1, 2);
            $this->seedRows($account, 2, 2);
        }

        $run = $this->runAndRecord();
        $this->assertSame(2, (int) $run->accounts_flushed);

        app(SystemRunReversal::class)->reverse($run, $a->id);

        $this->assertSame(
            10000.0,
            (float) Commission::where('consultant_id', $a->id)->whereNull('released_at')->sum('amount'),
            'A is back.',
        );
        $this->assertSame(
            5000.0,
            (float) Commission::where('consultant_id', $b->id)->whereNull('released_at')->sum('amount'),
            'B still has its released pair off the legs.',
        );
        $this->assertSame(1, (int) $b->fresh()->lifetime_pair_stage, "And B's stage is untouched.");
    }

    /** ⚠️ Super admin only — this un-books revenue and re-credits totals. */
    public function test_only_a_super_admin_can_reverse(): void
    {
        $account = $this->account();
        $this->seedRows($account, 1, 2);
        $this->seedRows($account, 2, 2);

        $run = $this->runAndRecord();

        $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->post(route('system-run.reverse', $run), ['account_id' => $account->id])
            ->assertForbidden();

        $this->assertSame(
            5000.0,
            (float) Commission::where('consultant_id', $account->id)->whereNull('released_at')->sum('amount'),
            'Nothing moved.',
        );

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->post(route('system-run.reverse', $run), ['account_id' => $account->id])
            ->assertRedirect();

        $this->assertSame(
            10000.0,
            (float) Commission::where('consultant_id', $account->id)->whereNull('released_at')->sum('amount'),
        );
    }

    /** An account that was never in the run cannot be reversed from it. */
    public function test_it_refuses_an_account_the_run_never_settled(): void
    {
        $account = $this->account();
        $this->seedRows($account, 1, 2);
        $this->seedRows($account, 2, 2);

        $run = $this->runAndRecord();

        $stranger = $this->account();

        $result = app(SystemRunReversal::class)->reverse($run, $stranger->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not settled by this run', $result['message']);
    }
}
