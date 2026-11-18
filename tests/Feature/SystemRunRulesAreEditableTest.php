<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use App\Services\SystemRunRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ THE SYSTEM RUN RULES ARE DATA, AND THE OWNER CAN EDIT THEM.
 *
 * Asked for after a rule was flipped, shipped and rejected the same day:
 *
 *   "Mta system run ekat adala file ekak hadl denn, edit kragnn puluwn. Naththn
 *    meka aye aye wardinwa. Dn me hadanne kiweni parada? Ekak hadankot anith eka
 *    wardinwa. Common ekak thiyala, system run ekat adalawa, eken check krala
 *    run eka yawanm hari ne."
 *
 * So what a run does must be changeable WITHOUT a code change, a test rewrite
 * or a deploy. These tests are about that promise: the values are saved, the
 * engine reads them, and changing one really does change what a run pays.
 */
class SystemRunRulesAreEditableTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private Consultant $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');

        $this->main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function admin(): User
    {
        return User::role('super_admin')->firstOrFail();
    }

    private function student(Consultant $account, int $leg): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now()->subDays(10),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now()->subDays(10),
        ]);

        $payment->forceFill(['created_at' => now()->subDays(10)])->save();

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    private function firstAccount(): Consultant
    {
        return $this->main->hiddenAccounts()->orderBy('id')->first();
    }

    private function legs(Consultant $account): array
    {
        $flush = app(ServiceGroupFlush::class);
        $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();

        return [
            $flush->legBalance($account->fresh(), $group, 1),
            $flush->legBalance($account->fresh(), $group, 2),
        ];
    }

    // ---- The default the owner settled on ----------------------------------

    /**
     * ⚠️ "Does the main settle its rollup" was an editable rule for one day and
     * is gone (2026-09-01) — the owner had it removed after seeing it empty
     * SCN-00101 and SCN-00102 on live data. It must not come back as a setting:
     * a rule that destroys money should not be one checkbox away.
     * MainRollupIsDisplayOnlyTest pins the behaviour itself.
     */
    public function test_the_main_settling_is_not_an_editable_rule(): void
    {
        $this->assertNotContains(
            'system_run_main_settles_rollup',
            array_column(SystemRunRules::editable(), 'key'),
        );
    }

    public function test_the_repeating_stage_forfeits_by_default(): void
    {
        $this->assertTrue(SystemRunRules::repeatingForfeitsSurplus());
    }

    /** The reported shape: an unbalanced account keeps everything. */
    public function test_an_unbalanced_account_is_not_emptied_by_its_main(): void
    {
        $account = $this->firstAccount();
        $account->forceFill(['lifetime_pair_stage' => 2])->save();

        for ($i = 0; $i < 12; $i++) {
            $this->student($account, 1);       // 30,000 on one side only
        }

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame(
            [30000.0, 0.0],
            $this->legs($account),
            'not balanced, so not one rupee moves — and its main cannot move it either',
        );
    }

    // ---- The rules are really wired to the engine ---------------------------

    /** Change the stage-1 threshold and the run changes with it. */
    public function test_raising_stage_one_changes_what_a_run_settles(): void
    {
        $account = $this->firstAccount();

        // 2,500 a side — enough for a 1-to-1 at the default of 1 pair.
        $this->student($account, 1);
        $this->student($account, 2);

        Setting::set('stage_first_pairs', 2, 'integer', 'commission');
        ServiceGroupStaging::forget();

        $this->assertSame(5000.0, SystemRunRules::thresholdFor(0, self::SLOT), 'the ladder moved');

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame(
            [2500.0, 2500.0],
            $this->legs($account),
            'it no longer reaches the raised threshold, so nothing settles',
        );
    }

    /** Turning the stage-3 forfeit off stops the company taking the surplus. */
    public function test_turning_off_the_stage_three_forfeit_leaves_the_surplus_on_the_leg(): void
    {
        Setting::set(SystemRunRules::REPEAT_FORFEITS_KEY, false, 'boolean', 'commission');

        $account = $this->firstAccount();
        $account->forceFill(['lifetime_pair_stage' => 2])->save();

        for ($i = 0; $i < 5; $i++) {
            $this->student($account, 1);
            $this->student($account, 2);       // 12,500 a side, well past 7,500
        }

        $result = app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame(15000.0, (float) $result['payout'], 'it still releases 7,500 a side');
        $this->assertSame(0.0, (float) $result['forfeit'], 'but nothing is closed off to the company');
        $this->assertSame(
            [5000.0, 5000.0],
            $this->legs($account),
            'the surplus stays on the leg for next time',
        );
    }

    // ---- The screen ---------------------------------------------------------

    public function test_a_super_admin_can_open_the_rules_page(): void
    {
        $this->actingAs($this->admin())
            ->get(route('system-run.rules'))
            ->assertOk()
            ->assertSee('The rules the System Run obeys', false)
            ->assertSee('At stage 3, the surplus goes to the company', false);
    }

    public function test_an_admin_cannot(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get(route('system-run.rules'))
            ->assertForbidden();
    }

    public function test_saving_the_form_changes_what_the_engine_reads(): void
    {
        $this->assertSame(1, SystemRunRules::stepFor(0), 'guard: one pair at stage 1 to begin with');

        $this->actingAs($this->admin())
            ->post(route('system-run.rules.update'), [
                'stage_first_pairs' => 4,
                'stage_second_pairs' => 5,
                'stage_repeat_pairs' => 6,
                SystemRunRules::REPEAT_FORFEITS_KEY => 1,
                'account_unlock_students' => 12,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        ServiceGroupStaging::forget();

        $this->assertSame(4, SystemRunRules::stepFor(0), 'the engine now reads the saved value');
        $this->assertSame(4 * 2500.0, SystemRunRules::thresholdFor(0, 2500), 'and the threshold moved with it');
    }

    /** An unchecked box must save as OFF, not simply be ignored. */
    public function test_unchecking_a_rule_turns_it_off(): void
    {
        Setting::set(SystemRunRules::REPEAT_FORFEITS_KEY, true, 'boolean', 'commission');
        $this->assertTrue(SystemRunRules::repeatingForfeitsSurplus(), 'guard: on to begin with');

        $this->actingAs($this->admin())
            ->post(route('system-run.rules.update'), [
                'stage_first_pairs' => 1,
                'stage_second_pairs' => 2,
                'stage_repeat_pairs' => 3,
                SystemRunRules::REPEAT_FORFEITS_KEY => 0,
                'account_unlock_students' => 12,
            ]);

        $this->assertFalse(SystemRunRules::repeatingForfeitsSurplus());
    }

    /** ⚠️ A zero step would make every account "balanced" at nothing, forever. */
    public function test_a_stage_can_never_be_saved_as_zero(): void
    {
        $this->actingAs($this->admin())
            ->post(route('system-run.rules.update'), [
                'stage_first_pairs' => 0,
                'stage_second_pairs' => -3,
                'stage_repeat_pairs' => 3,
                SystemRunRules::REPEAT_FORFEITS_KEY => 1,
                'account_unlock_students' => 12,
            ]);

        ServiceGroupStaging::forget();

        $this->assertSame(1, SystemRunRules::stepFor(0), 'clamped to at least one pair');
        $this->assertSame(1, SystemRunRules::stepFor(1));
    }

    /** The page shows a dry run and must not settle anything by being opened. */
    public function test_opening_the_page_settles_nothing(): void
    {
        $account = $this->firstAccount();
        $this->student($account, 1);
        $this->student($account, 2);

        $before = $this->legs($account);

        $this->actingAs($this->admin())->get(route('system-run.rules'))->assertOk();

        $this->assertSame($before, $this->legs($account), 'a preview writes nothing');
    }
}
