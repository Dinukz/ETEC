<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\CommissionRuleAudit;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A CHECKER THAT LIES IS WORSE THAN NO CHECKER.
 *
 * Built 2026-08-29 after the owner asked for a way to verify the money without
 * opening one payment at a time: "check krgnn hariyt commission (2500), direct
 * bonus (4000) and upline (2500) ewa check krgnn kramyk naddd... manual krn
 * nathuw kramyk nadda?"
 *
 * Every test here works the same way: build a network the engine itself
 * produced, confirm the audit says CLEAN, then break exactly one thing and
 * confirm it is caught. Both halves matter — an audit that flags healthy data
 * would be ignored within a week, and one that misses a planted fault is worse
 * than nothing because it grants false confidence.
 */
class CommissionRuleAuditTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    private Consultant $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        Cache::forget('commission_settings.active');

        $this->main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function register(Consultant $account, int $leg = 1, string $plan = 'full', float $amount = 39000): Payment
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => $amount, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => $plan, 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    /** @return array<int, string> the rules the audit reported */
    private function rules(): array
    {
        return array_keys(app(CommissionRuleAudit::class)->run()['summary']);
    }

    private function account(int $index = 0): Consultant
    {
        return $this->main->hiddenAccounts()->orderBy('id')->get()[$index];
    }

    // ---- It must not cry wolf ---------------------------------------------

    public function test_a_healthy_network_reports_nothing(): void
    {
        $this->register($this->account(0), 1);
        $this->register($this->account(0), 2);
        $this->register($this->account(1), 1);

        $audit = app(CommissionRuleAudit::class)->run();

        $this->assertSame([], $audit['violations'], 'Data the engine itself produced must read as clean.');
        $this->assertGreaterThan(0, $audit['checked'], 'guard: it actually looked at something');
    }

    /** A half payment legitimately has no 2,500 — that is the rule, not a fault. */
    public function test_a_half_payment_with_only_the_direct_bonus_is_clean(): void
    {
        $this->register($this->account(0), 1, 'half', 18000);

        $this->assertSame([], $this->rules());
    }

    /** A free card legitimately earns nothing at all. */
    public function test_a_zero_payment_with_no_commission_is_clean(): void
    {
        $this->register($this->account(0), 1, 'free_card', 0);

        $this->assertSame([], $this->rules());
    }

    // ---- ...and it must catch a planted fault -------------------------------

    public function test_it_catches_a_missing_direct_bonus(): void
    {
        $payment = $this->register($this->account(0), 1);

        Commission::where('payment_id', $payment->id)->where('type', 'referral')->delete();

        $this->assertContains('missing_direct', $this->rules());
    }

    public function test_it_catches_a_missing_2500(): void
    {
        $payment = $this->register($this->account(0), 1);

        Commission::where('payment_id', $payment->id)
            ->where('type', 'bonus')
            ->where('consultant_id', $this->account(0)->id)
            ->delete();

        $this->assertContains('missing_slot', $this->rules());
    }

    public function test_it_catches_a_2500_on_a_half_payment(): void
    {
        $payment = $this->register($this->account(0), 1, 'half', 18000);

        Commission::create([
            'consultant_id' => $this->account(0)->id,
            'group_id' => \App\Models\Group::where('consultant_id', $this->account(0)->id)->value('id'),
            'user_id' => $payment->user_id, 'payment_id' => $payment->id,
            'tier' => 1, 'amount' => self::SLOT, 'slots' => 1,
            'type' => 'bonus', 'status' => 'approved',
        ]);

        $this->assertContains('unexpected_slot_on_half', $this->rules());
    }

    public function test_it_catches_commission_on_a_free_card(): void
    {
        $payment = $this->register($this->account(0), 1, 'free_card', 0);

        Commission::create([
            'consultant_id' => $this->account(0)->id,
            'group_id' => \App\Models\Group::where('consultant_id', $this->account(0)->id)->value('id'),
            'user_id' => $payment->user_id, 'payment_id' => $payment->id,
            'tier' => 1, 'amount' => self::SLOT, 'slots' => 1,
            'type' => 'bonus', 'status' => 'approved',
        ]);

        $this->assertContains('money_on_zero_payment', $this->rules());
    }

    public function test_it_catches_a_wrong_amount(): void
    {
        $payment = $this->register($this->account(0), 1);

        Commission::where('payment_id', $payment->id)
            ->where('type', 'referral')
            ->update(['amount' => 999]);

        $this->assertContains('wrong_direct_amount', $this->rules());
    }

    public function test_it_catches_a_duplicated_direct_bonus(): void
    {
        $payment = $this->register($this->account(0), 1);
        $row = Commission::where('payment_id', $payment->id)->where('type', 'referral')->firstOrFail();

        Commission::create($row->only([
            'consultant_id', 'group_id', 'user_id', 'payment_id', 'tier', 'amount', 'slots', 'type', 'status',
        ]));

        $this->assertContains('duplicate_direct', $this->rules());
    }

    /**
     * ⚠️ THE ONE THAT MATTERS MOST — the climb stopping part-way has been
     * reported three times, and every time it left a GAP: the account holding
     * the student has its row, an account above has its row, and one in between
     * has none.
     */
    public function test_it_catches_the_2500_stopping_part_way_up(): void
    {
        // The consultant buys another course, which opens a set under the
        // account they picked — so there is a real chain: 00105 -> 00103 -> 00101.
        $bought = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $this->main->user_id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $this->main->hiddenAccounts()->orderBy('id')->first()->id,
            'requested_tier' => 1, 'amount' => 39000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);
        app(PaymentService::class)->verify($bought, User::where('email', 'accountant@etec.lk')->firstOrFail());

        $accounts = Consultant::where('owner_consultant_id', $this->main->id)->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(5, $accounts->count(), 'guard: the course purchase opened a set');

        $deep = $accounts[4];
        $middle = $accounts[2];

        $this->register($deep, 1);

        $this->assertSame([], $this->rules(), 'guard: the full climb reads clean');

        // Knock out only the middle link.
        Commission::where('consultant_id', $middle->id)->where('type', 'bonus')->delete();

        $this->assertContains('upline_gap', $this->rules());
    }

    // ---- Stages ------------------------------------------------------------

    /**
     * The 2026-08-29 damage: a stage advanced without the account ever pairing.
     * Nothing a normal run does will correct one, so it has to be findable.
     */
    public function test_it_catches_a_stage_the_account_never_paired_for(): void
    {
        $this->register($this->account(0), 1);
        $this->register($this->account(0), 2);

        // A run that settles nobody, so there is a record to judge against but
        // no pairing on this account.
        \App\Models\SystemRun::create([
            'run_by' => User::role('super_admin')->firstOrFail()->id,
            'cutoff_at' => now(), 'accounts_flushed' => 0,
            'total_released' => 0, 'total_forfeited' => 0,
            'accounts' => json_encode([['code' => 'SCN-99999', 'account_id' => 999999, 'rounds' => 1]]),
        ]);

        $this->account(0)->forceFill(['lifetime_pair_stage' => 2])->save();

        $issues = app(CommissionRuleAudit::class)->run()['stage_issues'];

        $this->assertNotEmpty($issues, 'an account claiming 3-to-3 having never paired must be reported');
        $this->assertSame(0, $issues[0]['earned'], 'and it should be back at 1-to-1');
    }

    /**
     * ⚠️ It must say "I cannot tell" rather than "all clear" when no run has
     * ever recorded which accounts it settled. A checker that cannot see is not
     * a checker that found nothing.
     */
    public function test_it_admits_when_stages_cannot_be_checked_at_all(): void
    {
        $this->register($this->account(0), 1);
        $this->account(0)->forceFill(['lifetime_pair_stage' => 2])->save();

        $audit = app(CommissionRuleAudit::class)->run();

        $this->assertFalse($audit['stage_checkable'], 'no run has been recorded, so nothing can be judged');
        $this->assertSame([], $audit['stage_issues']);
    }

    // ---- The screen --------------------------------------------------------

    public function test_a_super_admin_can_open_the_page_and_it_writes_nothing(): void
    {
        $payment = $this->register($this->account(0), 1);
        Commission::where('payment_id', $payment->id)->where('type', 'referral')->delete();

        $before = Commission::count();

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('commission-health.rule-audit'))
            ->assertOk()
            ->assertSee('Direct bonus was never recorded', false);

        $this->assertSame($before, Commission::count(), 'the audit is read-only');
    }

    public function test_the_stage_repair_is_super_admin_only(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->post(route('commission-health.repair-stages'))
            ->assertForbidden();
    }

    /**
     * ⚠️ STAGES ONLY MOVE FORWARD (2026-09-14). The old repair put a stage above
     * its recorded pairings back down — which sent admin-set stages back to
     * 1-to-1. It must change nothing now, and the page must not offer it.
     */
    public function test_the_stage_repair_never_moves_a_stage_back(): void
    {
        $this->register($this->account(0), 1);

        \App\Models\SystemRun::create([
            'run_by' => User::role('super_admin')->firstOrFail()->id,
            'cutoff_at' => now(), 'accounts_flushed' => 0,
            'total_released' => 0, 'total_forfeited' => 0,
            'accounts' => json_encode([['code' => 'SCN-99999', 'account_id' => 999999, 'rounds' => 1]]),
        ]);

        $this->account(0)->forceFill(['lifetime_pair_stage' => 2])->save();

        $superAdmin = User::role('super_admin')->firstOrFail();

        $this->actingAs($superAdmin)
            ->get(route('commission-health.rule-audit'))
            ->assertOk()
            ->assertDontSee('Put these stages right');

        $this->actingAs($superAdmin)
            ->post(route('commission-health.repair-stages'))
            ->assertSessionHas('error');

        $this->assertSame(2, (int) $this->account(0)->fresh()->lifetime_pair_stage, 'the stage an admin set is kept');
    }
}
