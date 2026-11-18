<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupMember;
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
 * Regression: verifying a SECOND payment for a student who already has a seat
 * in that account's service group used to 500 the whole verification.
 *
 * Production, 2026-08-14, verifying PYM-00054:
 *
 *   SQLSTATE[23000]: Integrity constraint violation: 1062
 *   Duplicate entry '74-134' for key 'uniq_group_user'
 *   insert into group_members (group_id=74, user_id=134, payment_id=143, ...)
 *   at CommissionService.php:336
 *
 * `group_members` carries `unique (group_id, user_id)` — one seat per student
 * per service group. But CommissionService::placeInto()'s idempotency guard
 * only looked for an existing seat by `payment_id`, so a returning student
 * (second course, or a re-registration) was invisible to it and the insert
 * collided with their existing seat.
 *
 * Re-using the seat is also the right money answer, not merely a crash fix:
 * one seat is one 2,500, which is exactly what that unique key encodes.
 */
class RepeatStudentSeatReuseTest extends TestCase
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

    private function account(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant->hiddenAccounts()->first();
    }

    private function makeStudent(): User
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        return $user;
    }

    /** Verify a payment for an EXISTING user — the returning-student case. */
    private function verifyPaymentFor(User $user, Consultant $account, int $leg, ?Course $course = null): Payment
    {
        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => ($course ?? Course::first())->id,
            'consultant_id' => $account->id,
            'requested_tier' => $leg,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    private function secondCourse(): Course
    {
        return Course::create([
            'title' => 'Second Course',
            'slug' => 'second-course-'.Str::random(5),
            'description' => 'Test course.',
            'price' => 28000,
            'currency' => 'LKR',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    // ----- The crash ---------------------------------------------------------

    public function test_a_second_payment_for_the_same_student_does_not_blow_up(): void
    {
        $account = $this->account();
        $student = $this->makeStudent();

        $this->verifyPaymentFor($student, $account, 1);

        // This is the line that used to throw UniqueConstraintViolationException.
        $second = $this->verifyPaymentFor($student, $account, 1, $this->secondCourse());

        $this->assertSame('verified', $second->status, 'The second verification must complete, not 500.');
    }

    public function test_the_student_keeps_exactly_one_seat_in_that_service_group(): void
    {
        $account = $this->account();
        $student = $this->makeStudent();

        $this->verifyPaymentFor($student, $account, 1);
        $this->verifyPaymentFor($student, $account, 1, $this->secondCourse());

        $groupIds = Group::where('consultant_id', $account->id)->pluck('id');

        $this->assertSame(
            1,
            GroupMember::whereIn('group_id', $groupIds)->where('user_id', $student->id)->count(),
            'One seat per student per service group — that is what unique (group_id, user_id) encodes.',
        );
    }

    /**
     * One seat meant one 2,500 — for payments verified BEFORE 22 Oct 2026.
     *
     * ⚠️ NARROWED 2026-10-22. The owner asked for the opposite from then on —
     * "inn student or consultant kenk buy kraddi 2500 calculate wen na meka fix
     * krla denn" — see RepeatPurchaseRules and RepeatPurchaseEarnsTheSlotTest.
     * What this still pins is that HISTORY is untouched: a repeat purchase
     * verified before that day keeps the single 2,500 it was given.
     */
    public function test_before_22_october_2026_a_returning_student_earned_no_second_slot_commission(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-15 10:00:00', 'Asia/Colombo'));

        $account = $this->account();
        $student = $this->makeStudent();

        $this->verifyPaymentFor($student, $account, 1);
        $this->verifyPaymentFor($student, $account, 1, $this->secondCourse());

        $this->assertSame(
            (float) self::SLOT,
            (float) Commission::where('consultant_id', $account->id)
                ->where('type', 'bonus')
                ->where('user_id', $student->id)
                ->sum('amount'),
            'The seat already earned its 2,500 — the second payment must not award another.',
        );
    }

    /** The direct-add bonus is keyed on payment_id, so it is untouched by this fix. */
    public function test_the_direct_bonus_still_pays_on_the_second_payment(): void
    {
        $account = $this->account();
        $student = $this->makeStudent();

        $this->verifyPaymentFor($student, $account, 1);
        $this->verifyPaymentFor($student, $account, 1, $this->secondCourse());

        $this->assertSame(
            2 * (float) self::DIRECT,
            (float) Commission::where('consultant_id', $account->id)
                ->where('type', 'referral')
                ->where('user_id', $student->id)
                ->sum('amount'),
            'Two registrations, two direct bonuses — that behaviour is unchanged by the seat fix.',
        );
    }

    // ----- Nothing else may regress -------------------------------------------

    public function test_a_brand_new_student_still_gets_their_own_seat(): void
    {
        $account = $this->account();
        $groupIds = Group::where('consultant_id', $account->id)->pluck('id');

        $before = GroupMember::whereIn('group_id', $groupIds)->count();

        $this->verifyPaymentFor($this->makeStudent(), $account, 1);
        $this->verifyPaymentFor($this->makeStudent(), $account, 2);

        $this->assertSame(
            $before + 2,
            GroupMember::whereIn('group_id', Group::where('consultant_id', $account->id)->pluck('id'))->count(),
            'Different students must still each get a seat — the fix must not collapse them.',
        );
    }

    /**
     * The same student placed on a DIFFERENT account keeps a separate seat —
     * the unique key is per group, so the reuse lookup must be scoped to this
     * account's groups only.
     */
    public function test_the_same_student_still_seats_separately_on_another_account(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $first = $consultant->hiddenAccounts()->first();
        $second = $consultant->hiddenAccounts()->skip(1)->first();

        $this->assertNotNull($second, 'This test needs the consultant to own two placement accounts.');

        $student = $this->makeStudent();

        $this->verifyPaymentFor($student, $first, 1);
        $this->verifyPaymentFor($student, $second, 1, $this->secondCourse());

        foreach ([$first, $second] as $account) {
            $this->assertSame(
                1,
                GroupMember::whereIn('group_id', Group::where('consultant_id', $account->id)->pluck('id'))
                    ->where('user_id', $student->id)
                    ->count(),
                'Each account keeps its own seat for the student.',
            );
        }
    }
}
