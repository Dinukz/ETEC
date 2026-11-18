<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupStaging;
use App\Support\FinanceWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The weekly student requirement has a temporary OFF switch.
 *
 * Owner, 2026-09-15: "me restric eka podi kalekat disable krnn puluwnd ... system
 * stable wenakn hoda storng user base ekak hadenkn meka disable krla denn,
 * awshya welwata meka enable krnn puluwn wenn one."
 *
 * OFF must be retroactive for the people it has ALREADY caught — otherwise the
 * 4 consultants restricted at the 2026-09-15 rollover would stay unpayable — but
 * it must not erase their stored status, so switching it back On resumes exactly
 * where it left off.
 */
class WeeklyQuotaCanBeSwitchedOffTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        Setting::set('finance_week_start_day', '2', 'integer', 'finance'); // Tuesday
        Setting::set('finance_week_quota', '2', 'integer', 'finance');
        Setting::set('finance_grace_weeks', '5', 'integer', 'finance');

        // ⚠️ The switch is memoised per process — clear it between tests or a
        // previous test's setting leaks into this one.
        FinanceWeek::forget();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');
        ServiceGroupStaging::forget();

        // Exactly one closed period is judged — see
        // WeeklyRestrictionDoesNotZeroIncomeTest for why this matters.
        $this->consultant()->forceFill([
            'created_at' => FinanceWeek::current()->minus(2)->start->addDay(),
        ])->save();

        Notification::fake();
    }

    private function consultant(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function account(): Consultant
    {
        return $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();
    }

    private function service(): \App\Services\ConsultantFinanceService
    {
        return app(\App\Services\ConsultantFinanceService::class);
    }

    private function switchTo(int $on): void
    {
        Setting::set(FinanceWeek::ENABLED_KEY, (string) $on, 'integer', 'finance');
        FinanceWeek::forget();
    }

    private function verifiedStudentAt(Consultant $account, \DateTimeInterface $when, int $leg = 1): Payment
    {
        $user = User::factory()->create(['created_at' => $when]);

        $student = Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => $when,
        ]);
        $student->forceFill(['created_at' => $when])->save();

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => $when,
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    /** Miss the quota in the last closed period. */
    private function missTheQuota(): void
    {
        $this->verifiedStudentAt($this->account(), FinanceWeek::current()->minus(1)->start->addDay());
    }

    // ----- the default ---------------------------------------------------------------

    /** ⚠️ ON unless explicitly switched off — a missing setting must not disable the rules. */
    public function test_it_is_on_by_default(): void
    {
        $this->assertTrue(FinanceWeek::quotaEnabled());

        $this->missTheQuota();

        $this->assertSame('restricted', $this->service()->evaluate($this->consultant()->fresh()));
    }

    // ----- switched off ---------------------------------------------------------------

    /** ⚠️ THE REQUEST. With it off, missing the quota restricts nobody. */
    public function test_with_it_off_missing_the_quota_does_not_restrict(): void
    {
        $this->switchTo(0);
        $this->missTheQuota();

        $this->assertSame('active', $this->service()->evaluate($this->consultant()->fresh()));
        $this->assertNotSame('restricted', $this->consultant()->fresh()->finance_status);
    }

    /**
     * ⚠️ RETROACTIVE, BUT NOT DESTRUCTIVE. Someone already restricted can be
     * paid again the moment it is switched off — and their stored status is
     * still there, untouched.
     */
    public function test_someone_already_restricted_becomes_payable_without_losing_their_status(): void
    {
        $this->missTheQuota();
        $this->service()->evaluate($this->consultant()->fresh());
        $this->assertSame('restricted', $this->consultant()->fresh()->finance_status, 'guard: really restricted');
        $this->assertFalse($this->consultant()->fresh()->isFinanceActive());

        $this->switchTo(0);

        $this->assertTrue($this->consultant()->fresh()->isFinanceActive(), 'payout and approval flow again');
        $this->assertFalse($this->consultant()->fresh()->isFinanceRestricted());
        $this->assertSame('restricted', $this->consultant()->fresh()->finance_status,
            'the stored status must be kept so switching it back on resumes');
    }

    /** ...and switching it back on puts the restriction straight back. */
    public function test_switching_it_back_on_restores_the_restriction(): void
    {
        $this->missTheQuota();
        $this->service()->evaluate($this->consultant()->fresh());

        $this->switchTo(0);
        $this->assertTrue($this->consultant()->fresh()->isFinanceActive());

        $this->switchTo(1);

        $this->assertFalse($this->consultant()->fresh()->isFinanceActive());
        $this->assertTrue($this->consultant()->fresh()->isFinanceRestricted());
    }

    /** Income can be approved while it is off, even for a restricted consultant. */
    public function test_income_can_be_approved_while_it_is_off(): void
    {
        $this->missTheQuota();
        $this->service()->evaluate($this->consultant()->fresh());

        $this->switchTo(0);

        // Something new to approve, recorded while the switch is off.
        $payment = $this->verifiedStudentAt($this->account(), now(), 2);

        $this->assertGreaterThan(
            0,
            Commission::where('payment_id', $payment->id)->where('status', 'approved')->count(),
            'income recorded while the requirement is off is approved normally',
        );
    }

    /** A SUSPENDED consultant is not locked out of the site while it is off. */
    public function test_a_suspended_consultant_is_not_locked_out_while_it_is_off(): void
    {
        DB::table('consultants')->where('id', $this->consultant()->id)
            ->update(['finance_status' => 'suspended']);

        // Guard: with it ON they are pushed to the suspended page.
        $this->actingAs($this->consultant()->user)
            ->get(route('consultant.dashboard'))
            ->assertRedirect(route('consultant.suspended'));

        $this->switchTo(0);

        $this->actingAs($this->consultant()->fresh()->user)
            ->get(route('consultant.dashboard'))
            ->assertOk();
    }

    /**
     * A suspended consultant's ?ref= link resolves again while it is off.
     *
     * Exercised through the scope itself (CheckoutController and
     * RegisterController both resolve ?ref= through it) so this pins the rule
     * rather than one of the three call sites.
     */
    public function test_a_suspended_consultants_referral_link_works_while_it_is_off(): void
    {
        DB::table('consultants')->where('id', $this->consultant()->id)
            ->update(['finance_status' => 'suspended', 'referral_code' => 'REFTEST123']);

        $resolve = fn () => Consultant::where('referral_code', 'REFTEST123')->notFinanceSuspended()->first();

        $this->assertNull($resolve(), 'guard: with it on, a suspended referral link does not resolve');

        $this->switchTo(0);

        $this->assertNotNull($resolve(), 'with it off the link resolves again');
    }

    /**
     * The control is really on the Settings page and really wired to the
     * controller — this is what catches a missing $financeQuotaEnabled, which
     * would otherwise only blow up in the admin's face on the live site.
     */
    public function test_the_settings_page_offers_the_switch(): void
    {
        $this->actingAs(User::where('email', 'superadmin@etec.lk')->firstOrFail())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('name="finance_quota_enabled"', false)
            ->assertSee('Off — nobody is restricted');
    }

    /** The "your account is restricted" banner is gone while it is off. */
    public function test_the_restricted_banner_is_gone_while_it_is_off(): void
    {
        $this->missTheQuota();
        $this->service()->evaluate($this->consultant()->fresh());

        $this->switchTo(0);

        $this->actingAs($this->consultant()->fresh()->user)
            ->get(route('consultant.dashboard'))
            ->assertOk()
            ->assertDontSee('Your account is restricted');
    }
}
