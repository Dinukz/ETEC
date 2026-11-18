<?php

namespace App\Services;

use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Notifications\PaymentRejected;
use App\Notifications\PaymentVerified;
use App\Notifications\StudentPaymentConfirmed;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private CommissionService $commissions)
    {
    }

    /**
     * Accountant verifies a payment. Activates enrollment, records company
     * revenue, and runs the commission engine — all atomically.
     */
    public function verify(Payment $payment, User $verifier, ?string $remarks = null): Payment
    {
        if ($payment->status === 'verified') {
            return $payment;
        }

        // This database's migrations ledger has been found out of sync with its
        // real schema more than once, leaving columns the commission engine
        // writes on every verify silently missing in production. Self-healing
        // them must happen HERE — genuinely before any transaction opens.
        // MySQL implicitly commits on DDL (ALTER TABLE), so running this
        // check anywhere inside the transaction below (even at the very top of
        // a nested method) silently ends that transaction underneath Laravel's
        // bookkeeping, which then throws "There is no active transaction" the
        // moment it tries to commit — a real production incident, not a
        // hypothetical one.
        if ($payment->consultant_id) {
            \App\Services\CommissionSchemaRepair::ensure();
        }

        $alreadyVerified = false;

        $payment = DB::transaction(function () use ($payment, $verifier, $remarks, &$alreadyVerified) {
            // Re-fetch under a row lock: two near-simultaneous verify requests
            // (an impatient double-click, or two accountants racing) would
            // otherwise both pass the unlocked check above and both run the
            // commission engine on the same payment — paying it out twice.
            // The second caller blocks here until the first commits, then sees
            // status='verified' and does nothing further.
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'verified') {
                $alreadyVerified = true;

                return $payment;
            }

            $payment->update([
                'status' => 'verified',
                'verified_at' => now(),
            ]);

            PaymentVerification::create([
                'payment_id' => $payment->id,
                'verified_by' => $verifier->id,
                'action' => 'verified',
                'remarks' => $remarks,
                'verified_at' => now(),
            ]);

            // Activate the linked enrollment (or the user's pending one for this course).
            $enrollment = $payment->enrollment
                ?? Enrollment::where('user_id', $payment->user_id)
                    ->where('course_id', $payment->course_id)
                    ->first();

            if ($enrollment) {
                $enrollment->update([
                    'status' => 'active',
                    'enrolled_at' => $enrollment->enrolled_at ?? now(),
                ]);
            }

            // Company keeps whatever isn't paid out as commission. One payment
            // can now generate several commissions — the registering consultant
            // plus every sponsor above them whose legs balanced — so total the
            // recorded rows rather than just the direct one.
            $this->commissions->handleVerifiedPayment($payment);
            $commissionAmount = (float) \App\Models\Commission::where('payment_id', $payment->id)->sum('amount');

            CompanyRevenue::create([
                'source_type' => $payment->consultant_id ? 'group_overflow' : 'course_payment',
                'payment_id' => $payment->id,
                'course_id' => $payment->course_id,
                'amount' => max(0, (float) $payment->amount - (float) $commissionAmount),
                'description' => 'Revenue from payment '.$payment->payment_code,
                // The payment date, not the verification date - the income has to
                // land on the same day as the commissions it is the remainder of.
                'recorded_at' => $payment->paid_at ?? now(),
            ]);

            // The temporary payment-code username is retired now the payment is
            // confirmed — the student logs in with their email or student code.
            $studentCode = \App\Models\Student::where('user_id', $payment->user_id)->value('student_code');
            if ($payment->user && $studentCode && $payment->user->username !== $studentCode) {
                $payment->user->update(['username' => $studentCode]);
            }

            return $payment->refresh();
        });

        if ($alreadyVerified) {
            return $payment;
        }

        // ⚠️ A CONSULTANT'S COURSE PURCHASE OPENS A NEW SET OF SCN ACCOUNTS
        // (owner-confirmed 2026-08-20).
        //
        // "Consultant aluth account ekak buy krahama, e amount eka admin verify
        // krapu gaman account tika consultant add wenn one — courses wala lesson
        // complete kran one na." So it is keyed to VERIFICATION, never to
        // finishing the course.
        //
        // Deliberately AFTER the transaction above has committed. Opening a set
        // allocates three SCN codes, and CodeGeneratorService takes a
        // lockForUpdate across every SCN row to do it — holding that inside the
        // payment transaction would serialise every concurrent verification in
        // the system behind one buyer's signup.
        //
        // Never allowed to fail the verification: the payment is already
        // verified and the money already recorded at this point, so a problem
        // opening accounts must surface as a reportable error, not as a
        // rolled-back payment.
        try {
            $this->openAccountSetForBuyer($payment);
        } catch (\Throwable $e) {
            report($e);
        }

        // No upline notification here. Upline 2,500 rows auto-approve during
        // payment verification, but they still sit on the leg until System Run
        // release. Keep notifications tied to the later finance workflow so we
        // do not announce the same row once as approved and again as released.

        $payment->loadMissing(['user.student', 'course']);
        $student = $payment->user;
        $studentCode = $student?->student?->student_code;

        // In-app bell for the student + the confirmation email (Mail::html path).
        if ($student) {
            try {
                $student->notify(new PaymentVerified($payment));
            } catch (\Throwable $e) {
                report($e);
            }

            TransactionalMail::paymentVerifiedToStudent(
                $student->email,
                $student->name,
                $payment->course->title ?? '—',
                $payment->payment_code,
                $this->statusLabel($payment),
                (float) $payment->amount,
                $studentCode,
            );
        }

        // The commission (and this confirmation email) belong to the REAL
        // consultant — a hidden service-group account has no user of its own,
        // so resolve up to its owner.
        $realConsultant = $payment->consultant?->is_hidden
            ? $payment->consultant->owner
            : $payment->consultant;

        // Built once: the holder's email below, and the payer's when somebody
        // else paid (2026-11-03).
        $details = [
            'Full Name' => $student->name ?? '—',
            'Username' => $studentCode ?? '—',
            'Email' => $student->email ?? '—',
            'Course Name' => $payment->course->title ?? '—',
            'Payment Status' => $this->statusLabel($payment),
            'Registration Year' => optional($payment->created_at)->format('Y') ?? '—',
        ];

        if ($payment->payment_type === 'half') {
            $balance = max(0, (float) ($payment->course->effective_price ?? 0) - (float) $payment->amount);
            $details['Amount Paid'] = 'LKR '.number_format((float) $payment->amount, 2);
            $details['Balance Due'] = 'LKR '.number_format($balance, 2);
        }

        if ($realConsultant?->user) {
            try {
                $realConsultant->user->notify(new StudentPaymentConfirmed($payment));
            } catch (\Throwable $e) {
                report($e);
            }

            TransactionalMail::paymentConfirmedToConsultant(
                $realConsultant->user->email,
                $realConsultant->user->name,
                $details,
            );
        }

        // ⚠️ AND THE CONSULTANT WHO PAID, when that is not the holder (2026-11-03):
        // they confirmed the plan and attached the slip — see RegistrationPayer.
        // Never allowed to fail a verification that is already committed.
        try {
            $payer = RegistrationPayer::payerOf($payment);

            if ($payer && (int) $payer->id !== (int) ($realConsultant?->id ?? 0)) {
                $payer->user->notify(new StudentPaymentConfirmed($payment));

                TransactionalMail::paymentConfirmedToConsultant($payer->user->email, $payer->user->name, $details);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $payment;
    }

    /** Accountant rejects a payment with a mandatory reason. */
    public function reject(Payment $payment, User $verifier, string $remarks): Payment
    {
        $payment = DB::transaction(function () use ($payment, $verifier, $remarks) {
            $payment->update([
                'status' => 'rejected',
                'verified_at' => now(),
            ]);

            PaymentVerification::create([
                'payment_id' => $payment->id,
                'verified_by' => $verifier->id,
                'action' => 'rejected',
                'remarks' => $remarks,
                'verified_at' => now(),
            ]);

            return $payment->refresh();
        });

        $payment->user?->notify(new PaymentRejected($payment, $remarks));

        return $payment;
    }

    /**
     * Open a new set of SCN accounts for a CONSULTANT who bought a course.
     *
     * Only fires for a buyer who is already a real consultant. A first-time
     * buyer is still a student here; they get their accounts when they are
     * promoted to consultant, which already creates the opening set via
     * ConsultantObserver -> HiddenAccountService::ensureFor().
     *
     * Guards, in order:
     *  - 'balance' top-ups open nothing: the course was already bought.
     *  - ⚠️ A FREE CARD OPENS THE SET LIKE ANY OTHER PURCHASE (2026-09-14).
     *    This used to skip every zero-value payment too — "a free card must not
     *    mint three SCN accounts for nothing". On the live site that left 23
     *    consultants who bought a course on a free card with no new accounts,
     *    while all 141 paid purchases had theirs; reported as "free card
     *    dammaham user management eke e accounts penwan na". The accounts are
     *    placement, not money: a free card still pays nobody a rupee
     *    (CommissionService's $isWorthless). Public so MissingAccountSets can
     *    open, through this same method, the sets the old rule skipped.
     *  - One set per PAYMENT, keyed on a marker so a re-verified or re-run
     *    payment cannot open a second set. Verification is already idempotent
     *    for money (the row lock above), and this has to be too.
     */
    public function openAccountSetForBuyer(Payment $payment): void
    {
        if ($payment->payment_type === 'balance') {
            return;
        }

        $consultant = $payment->user?->consultant;

        if (! $consultant || $consultant->is_hidden) {
            return;
        }

        // Idempotency: the opening set is created at promotion, so a consultant
        // already holding accounts is buying an ADDITIONAL set — but only once
        // per payment. `notes` is not reliable for this, so the marker is the
        // payment's own id recorded on the accounts it opened.
        $alreadyOpened = Consultant::where('owner_consultant_id', $consultant->id)
            ->where('opened_by_payment_id', $payment->id)
            ->exists();

        if ($alreadyOpened) {
            return;
        }

        $created = app(HiddenAccountService::class)->addAccountSet($consultant);

        Consultant::whereIn('id', $created->pluck('id'))->update(['opened_by_payment_id' => $payment->id]);
    }

    protected function statusLabel(Payment $payment): string
    {
        return match ($payment->payment_type) {
            'full' => 'Full Paid',
            'half' => 'Partial Paid',
            'free_card' => 'Free',
            'balance' => 'Balance Paid — Fully Settled',
            default => 'Verified',
        };
    }
}
