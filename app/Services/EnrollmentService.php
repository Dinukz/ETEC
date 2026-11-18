<?php

namespace App\Services;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates the pending Enrollment + Payment pair that feeds the accountant's
 * manual verification queue. Shared by the consultant "add student" flow and
 * the public self-service checkout.
 */
class EnrollmentService
{
    /**
     * @param  array{method?: string, payment_type?: string, reference_number?: string, receipt_path?: string, notes?: string, awaiting_type?: bool, requested_tier?: int}  $paymentData
     *
     * `awaiting_type` marks a registration where the payment plan (free/half/full)
     * isn't chosen yet — the consultant confirms it later via confirmPayment().
     * Self-checkout never sets this, so its "assume full price" behaviour is unchanged.
     *
     * `requested_tier` (1 or 2) is the Service Group the consultant explicitly
     * picked at registration; CommissionService honours it once the payment is
     * verified. Null (self-checkout) falls back to automatic sequential fill.
     */
    public function enroll(User $user, Course $course, ?Consultant $consultant = null, array $paymentData = []): Enrollment
    {
        return DB::transaction(function () use ($user, $course, $consultant, $paymentData) {
            if (! $user->hasRole('student') && ! $user->hasRole('consultant')) {
                $user->assignRole('student');
            }

            $student = Student::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'student_code' => NumberPool::format('SRN', NumberPool::take()),
                    'status' => 'active',
                    'enrolled_at' => now(),
                ],
            );

            // ⚠️ A DELIBERATE PLACEMENT RE-POINTS AN EXISTING STUDENT (2026-08-22).
            //
            // This used to write the sponsor ONLY when the column was blank, so
            // a RETURNING buyer — which is every consultant buying another
            // course — kept their original sponsor on the Student row while the
            // payment moved to the newly chosen placement. The two rows then
            // disagreed, and Pending Students (which matches on BOTH) went
            // empty for everyone: reported 2026-08-22 as "meke dn nm pending
            // eka penwan na".
            //
            // `requested_tier` is the discriminator because CheckoutController
            // sets it ONLY for a deliberate pick. A bare ?ref= purchase must
            // NOT re-point — that would quietly move an existing student away
            // from the consultant who originally signed them up.
            $deliberatePlacement = in_array((int) ($paymentData['requested_tier'] ?? null), [1, 2], true);

            if ($consultant && (! $student->consultant_id || $deliberatePlacement)) {
                $student->update(['consultant_id' => $consultant->id]);
            }

            $enrollment = Enrollment::firstOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                ['status' => 'pending', 'progress_percentage' => 0, 'enrolled_at' => now()],
            );

            $payment = Payment::where('user_id', $user->id)->where('course_id', $course->id)->where('status', 'pending')->first();

            if (! $payment) {
                $awaitingType = (bool) ($paymentData['awaiting_type'] ?? false);
                $paymentCode = CodeGeneratorService::next('payments', 'payment_code', 'PYM', 5, 1);

                // Self-checkout pays the full price up front, so its plan is
                // already known. Leaving this null made the payment unverifiable
                // — the accountant's screen rejects payments with no plan, which
                // is only meant to catch consultant registrations still awaiting
                // one.
                $paymentType = $paymentData['payment_type'] ?? ($awaitingType ? null : 'full');

                Payment::create([
                    'payment_code' => $paymentCode,
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'enrollment_id' => $enrollment->id,
                    'consultant_id' => $consultant?->id,
                    'requested_tier' => in_array((int) ($paymentData['requested_tier'] ?? null), [1, 2], true) ? (int) $paymentData['requested_tier'] : null,
                    'amount' => $awaitingType ? 0 : self::amountForType($course, $paymentType),
                    'currency' => 'LKR',
                    'method' => $paymentData['method'] ?? 'bank_transfer',
                    'payment_type' => $paymentType,
                    'reference_number' => $paymentData['reference_number'] ?? null,
                    'receipt_path' => $paymentData['receipt_path'] ?? null,
                    'notes' => $paymentData['notes'] ?? null,
                    'status' => 'pending',
                    'paid_at' => $awaitingType ? null : now(),
                ]);

                // A login identifier for students who don't have one yet — shown in
                // the welcome email, later replaced by their student code once a
                // consultant confirms their payment type (see confirmPayment()).
                if (! $user->username) {
                    $user->update(['username' => $paymentCode]);
                }
            } else {
                // ⚠️ A RE-SUBMIT MUST UPDATE THE PENDING PAYMENT (2026-08-22).
                //
                // This branch did not exist: when a pending payment was already
                // on file the whole block above was skipped and everything the
                // buyer just entered was SILENTLY DISCARDED, while the
                // controller still redirected with a success message. Reported
                // as "kalin gaththe 124 dammaham gaththe 123 group eka" —
                // picking SCN-00124 kept the SCN-00123 chosen on the first
                // submit, which reads as an off-by-one but is actually the
                // second submit being thrown away.
                //
                // resources/views/pages/checkout.blade.php already tells the
                // user "Submitting again updates your payment details", so this
                // makes the service honour what the page promises.
                //
                // Only fields the caller actually supplied are written, and the
                // PLAN (payment_type/amount) is left alone unless one is given
                // — a consultant registration deliberately sits at
                // awaiting_type with amount 0 until the plan is confirmed, and
                // overwriting that with a full price here would corrupt it.
                $changes = array_filter([
                    'consultant_id' => $consultant?->id,
                    'requested_tier' => in_array((int) ($paymentData['requested_tier'] ?? null), [1, 2], true)
                        ? (int) $paymentData['requested_tier']
                        : null,
                    'method' => $paymentData['method'] ?? null,
                    'reference_number' => $paymentData['reference_number'] ?? null,
                    'receipt_path' => $paymentData['receipt_path'] ?? null,
                    'notes' => $paymentData['notes'] ?? null,
                ], fn ($v) => $v !== null);

                if (! empty($paymentData['payment_type'])) {
                    $changes['payment_type'] = $paymentData['payment_type'];
                    $changes['amount'] = self::amountForType($course, $paymentData['payment_type']);
                }

                if ($changes !== []) {
                    // ⚠️ A PAYMENT MOVED TO ANOTHER ACCOUNT LOSES ITS PAYER (2026-11-03).
                    // Whoever paid for a registration on a downline's account paid
                    // for THAT account; if a checkout re-submit moves it elsewhere,
                    // today's rule takes over (see RegistrationPayer). Inert for every
                    // payment that never had a payer, and never names the column on
                    // a database without it.
                    if (isset($changes['consultant_id'])
                        && (int) $changes['consultant_id'] !== (int) $payment->consultant_id
                        && $payment->getAttribute(RegistrationPayer::COLUMN) !== null) {
                        // forceFill: not mass-assignable on purpose. Saved by the
                        // update() below, in the same statement.
                        $payment->forceFill([RegistrationPayer::COLUMN => null]);
                    }

                    $payment->update($changes);
                }
            }

            return $enrollment;
        });
    }

    /** Amount due for a given payment plan; null/unrecognised type = full price. */
    public static function amountForType(Course $course, ?string $type): float
    {
        $price = (float) $course->effective_price;

        return match ($type) {
            // The instalment plan is a fixed FIRST payment (18,000 of a 28,000
            // course by default, leaving a 10,000 balance) — deliberately not
            // literally half. Capped at the course price so a cheaper course
            // can never be billed more than it costs.
            //
            // A course may set its own first payment (2026-10-22: the Elliott
            // Wave course is 20,000 of 39,000) — see App\Support\CourseMoney.
            'half' => \App\Support\CourseMoney::halfPayment($course),
            'free_card' => 0.0,
            default => $price,
        };
    }

    /**
     * What a student still owes on a course, after every non-rejected payment
     * already recorded for them on it (their original half payment, plus any
     * earlier balance settlement — never negative, never above the price).
     */
    public static function remainingBalance(Course $course, int $userId): float
    {
        $price = (float) $course->effective_price;

        $alreadyPaid = (float) Payment::where('user_id', $userId)
            ->where('course_id', $course->id)
            ->where('status', '!=', 'rejected')
            ->sum('amount');

        return round(max(0, min($price, $price - $alreadyPaid)), 2);
    }
}
