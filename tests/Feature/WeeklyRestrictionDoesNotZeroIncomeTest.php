<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use App\Support\FinanceWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Missing the weekly quota must never make a consultant's money read zero.
 *
 * Reported 2026-09-15, the morning of a finance-week rollover (the week starts
 * Tuesday): "hama finace week ekedim okkogem commission direct bonus okkom 0
 * wenna ... meka monw unath 0 wen nathi wenn hadann one". On the live site that
 * boundary restricted 4 consultants and stamped held_at on 365 commission rows
 * worth LKR 912,500. Nothing was deleted — legScopeQuery() simply excludes held
 * rows, so every Service Group leg they owned printed LKR 0.
 *
 * The quota keeps its teeth: a restricted consultant still cannot have income
 * approved and still cannot be paid, because BOTH gates check the live finance
 * status rather than held_at.
 */
class WeeklyRestrictionDoesNotZeroIncomeTest extends TestCase
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
        FinanceWeek::forget();

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

        // ⚠️ EXACTLY ONE CLOSED PERIOD IS JUDGED. evaluate() walks forward from
        // the period AFTER the one the consultant was created in, so backdating
        // further than this makes them miss every intervening week and land on
        // 'suspended' — while these fixtures are about the 'restricted' step.
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

    /** A verified student on $account, registered at $when. */
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

    /** Miss the quota in the last closed period, so the owner is restricted. */
    private function restrict(): Consultant
    {
        $period = FinanceWeek::current()->minus(1);

        // 1 of the required 2.
        $this->verifiedStudentAt($this->account(), $period->start->addDay());

        $status = $this->service()->evaluate($this->consultant()->fresh());
        $this->assertSame('restricted', $status, 'guard: the fixture really restricts');

        return $this->consultant()->fresh();
    }

    private function legs(Consultant $account): array
    {
        $group = Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
        $flush = app(ServiceGroupFlush::class);

        return [$flush->legBalance($account, $group, 1), $flush->legBalance($account, $group, 2)];
    }

    private function heldCount(): int
    {
        return Commission::whereIn('consultant_id', $this->consultant()->accountIds())
            ->whereNotNull('held_at')->count();
    }

    // ----- the report --------------------------------------------------------------

    /** ⚠️ THE REPORT. Being restricted must not stamp held_at on anything. */
    public function test_missing_the_quota_does_not_hold_income(): void
    {
        $this->restrict();

        $this->assertSame(0, $this->heldCount(), 'the weekly quota must not hide money');
    }

    /**
     * ⚠️ THE REPORT, as the consultant sees it: the legs still show the money.
     *
     * Only ONE student is registered in the still-open period on purpose — two
     * would meet the quota and release the restriction through
     * checkOpenPeriodRelease() before it could be observed at all.
     */
    public function test_the_service_group_legs_still_show_the_money(): void
    {
        $account = $this->account();

        // Service Group 2 earns before the restriction...
        $this->verifiedStudentAt($account, now(), 2);
        $this->assertSame((float) self::SLOT, $this->legs($account)[1], 'guard: money on Service Group 2');

        // ...and restrict() registers the single closed-period student, whose
        // own 2,500 lands on Service Group 1.
        $this->restrict();

        $this->assertSame(
            [(float) self::SLOT, (float) self::SLOT],
            $this->legs($account->fresh()),
            'the restriction must not empty the legs',
        );
    }

    /** Income recorded WHILE restricted is not born held either. */
    public function test_new_income_while_restricted_is_not_held(): void
    {
        $this->restrict();

        $payment = $this->verifiedStudentAt($this->account(), now(), 2);

        $this->assertGreaterThan(0, Commission::where('payment_id', $payment->id)->count(), 'guard: income was recorded');
        $this->assertSame(0, Commission::where('payment_id', $payment->id)->whereNotNull('held_at')->count());
    }

    // ----- the quota still has teeth ------------------------------------------------

    /**
     * ⚠️ THE TEETH. Income earned WHILE restricted is still never approved, and
     * Approve All cannot move it either — the gate is the live finance status,
     * not the held_at stamp this change stopped writing.
     *
     * Measured against income earned while still active, which is auto-approved
     * at verification and legitimately already counted.
     */
    public function test_income_earned_while_restricted_is_still_not_approved(): void
    {
        $consultant = $this->restrict();
        $this->assertFalse($consultant->isFinanceActive(), 'guard: still restricted');

        $earnedBefore = (float) $this->consultant()->fresh()->total_earned;

        $payment = $this->verifiedStudentAt($this->account(), now(), 2);
        $rows = Commission::where('payment_id', $payment->id)->get();

        $this->assertGreaterThan(0, $rows->count(), 'guard: the income was recorded');
        $this->assertSame(0, $rows->where('status', 'approved')->count(),
            'nothing earned while restricted may be approved');

        $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $this->assertSame($earnedBefore, (float) $this->consultant()->fresh()->total_earned,
            'Approve All must still not credit a restricted consultant.');
    }

    // ----- putting back what the old rule hid ----------------------------------------

    /** ⚠️ THE RESTORE. One press clears every stamp left by the old rule. */
    public function test_put_back_held_income_clears_old_stamps(): void
    {
        $account = $this->account();
        $this->verifiedStudentAt($account, now(), 1);
        $this->verifiedStudentAt($account, now(), 2);

        // Exactly the shape live was left in: stamps from the old rule.
        Commission::whereIn('consultant_id', $this->consultant()->accountIds())
            ->update(['held_at' => now()]);

        $this->assertGreaterThan(0, $this->heldCount(), 'guard: money is hidden');
        $this->assertSame([0.0, 0.0], $this->legs($account->fresh()), 'guard: and the legs read zero');

        $this->actingAs(User::where('email', 'superadmin@etec.lk')->firstOrFail())
            ->post(route('commission-health.release-held'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, $this->heldCount());
        $this->assertSame([(float) self::SLOT, (float) self::SLOT], $this->legs($account->fresh()),
            'the money is visible on the legs again');
    }

    /** It moves money, so it is super-admin only. */
    public function test_putting_income_back_is_super_admin_only(): void
    {
        Commission::query()->update(['held_at' => now()]);
        $before = $this->heldCount();

        // ⚠️ AN ADMIN, NOT AN ACCOUNTANT. The route group already allows
        // 'super_admin|admin', so only an admin actually reaches the
        // controller — an accountant is turned away by the route middleware and
        // would prove nothing about the super-admin guard inside it.
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->post(route('commission-health.release-held'))
            ->assertForbidden();

        $this->assertSame($before, $this->heldCount(), 'nothing changed for a non-super-admin');
    }
}
