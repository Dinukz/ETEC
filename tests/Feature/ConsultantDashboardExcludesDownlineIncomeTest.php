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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The consultant's own Dashboard ("Total Earned" / "Total Paid") shows only
 * money that arrived through a balanced release, plus their own direct-add
 * bonus — "1 to 1, 2 to 2, 3 to 3 e tiaki direct service ekai add unanm athi...
 * 5000, 10000, 15000 me tika add unanm athi".
 *
 * ONE filter implements that: a slot row counts once a System Run stamped
 * `released_at`. The direct bonus is never flushed, so it is exempt.
 *
 * ⚠️ HISTORY, because the file name still says "ExcludesDownlineIncome": on
 * 2026-08-13 this class also rejected `isUplineCredit()` rows outright, because
 * downline income was then auto-approved at verification and so could never be
 * part of a clean 5,000/10,000/15,000. On 2026-08-14 the owner put downline
 * income back through the flush, so it now arrives exactly the way this card is
 * meant to show — and that second filter was removed. Downline income is no
 * longer excluded for being downline; it is excluded only while it is
 * unflushed, like everyone's own students.
 *
 * Both directions are pinned in UplineIncomeIsFlushedTest.
 *
 * consultants.total_earned / total_paid (the unfiltered columns, used by the
 * Service Groups page and the admin queue) are deliberately UNCHANGED — this
 * is a presentation-only filter scoped to ConsultantDashboardController.
 */
class ConsultantDashboardExcludesDownlineIncomeTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        Setting::set('stage_first_pairs', '1', 'integer', 'commission');
        Setting::set('stage_second_pairs', '2', 'integer', 'commission');
        Setting::set('stage_repeat_pairs', '3', 'integer', 'commission');
        Setting::set('account_unlock_students', '12', 'integer', 'commission');
        ServiceGroupStaging::forget();
    }

    private function sponsorUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function addStudent(Consultant $account, int $leg): User
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, $accountant);

        return $user;
    }

    /**
     * Slot income that was never released by a flush must not appear here,
     * whoever it came from. This is the rule that replaced the old
     * downline-specific exclusion.
     */
    public function test_unreleased_slot_income_is_not_counted(): void
    {
        $sponsorUser = $this->sponsorUser();
        $account = $sponsorUser->consultant->hiddenAccounts()->first();

        // A balanced pair — but no System Run has flushed it, so nothing on
        // these two rows is releasable.
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $stats = $this->actingAs($sponsorUser)->get(route('consultant.dashboard'))->viewData('stats');

        $this->assertSame(
            2 * (float) self::DIRECT,
            $stats['total_earned'],
            'Only the two direct bonuses count — the slot income has not been flushed.',
        );
    }

    public function test_dashboard_total_earned_includes_the_direct_add_bonus(): void
    {
        $sponsorUser = $this->sponsorUser();
        $account = $sponsorUser->consultant->hiddenAccounts()->first();

        $this->addStudent($account, 1);
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        // Leg 1 alone cannot balance, so only the direct bonus is approvable —
        // confirming it counts here without any flush.
        $response = $this->actingAs($sponsorUser)->get(route('consultant.dashboard'));

        $this->assertSame((float) self::DIRECT, $response->viewData('stats')['total_earned']);
    }

    public function test_pending_balance_is_computed_from_the_same_filtered_figures(): void
    {
        $sponsorUser = $this->sponsorUser();
        $account = $sponsorUser->consultant->hiddenAccounts()->first();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $stats = $this->actingAs($sponsorUser)->get(route('consultant.dashboard'))->viewData('stats');

        $this->assertSame(
            $stats['total_earned'] - $stats['total_paid'],
            $stats['pending_balance'],
            'Pending balance must be internally consistent with the same filtered figures — never mixed with the unfiltered column.',
        );
    }

    /** The unfiltered column keeps everything; only this one screen narrows it. */
    public function test_the_unfiltered_column_is_not_touched_by_this_filter(): void
    {
        $sponsorUser = $this->sponsorUser();
        $consultant = $sponsorUser->consultant;
        $account = $consultant->hiddenAccounts()->first();

        $this->addStudent($account, 1);
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $this->assertSame(
            (float) self::DIRECT,
            (float) $account->fresh()->total_earned,
            'consultants.total_earned still records exactly what was approved, unfiltered.',
        );
    }
}
