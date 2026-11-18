<?php

namespace App\Concerns;

use App\Models\Payment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Which students belong to these accounts?" — asked in more than one place,
 * answered here once.
 *
 * Extracted from Consultant\StudentController on 2026-09-07, when the Service
 * Groups page needed the SAME question answered for a downline's accounts (the
 * Team Students tab). Two copies of this rule would be the mistake this
 * codebase has already made three times with money rules — a second definition
 * that starts identical and drifts.
 */
trait ResolvesOwnedStudents
{
    /**
     * Every student belonging to these accounts, by EITHER ownership column.
     *
     * ⚠️ A STUDENT IS OURS TWO DIFFERENT WAYS (2026-08-22).
     *
     * `students.consultant_id` is who signed them up. `payments.consultant_id`
     * is the account their money was placed under. Those normally agree, but a
     * placement checkout deliberately sets the payment to a chosen SCN account
     * — so filtering on the student column alone silently hid every buyer who
     * placed themselves somewhere other than their sponsor, and filtering on
     * the payment column alone would hide a registered student who has not
     * paid a cent yet (no payment row exists to match).
     *
     * Only the union is correct on both counts. Both sides are scoped to
     * $accountIds, so it widens the list without leaking anyone else's
     * students.
     *
     * ⚠️ THIS IS DELIBERATELY NOT A SEAT QUERY, and that is the whole point of
     * using it for the Team Students tab. A GroupMember seat only exists once a
     * payment has been VERIFIED and the commission engine has walked the
     * sponsor chain — so a half payment (which seats nobody at all), a free
     * card, an unverified registration and a downline whose sponsor link was
     * never recorded are each invisible to a seat query, permanently. They are
     * all visible here, because a student exists the moment they are registered.
     *
     * @param  array<int>  $accountIds
     */
    protected function ownedStudents(array $accountIds): Builder
    {
        $payerUserIds = Payment::whereIn('consultant_id', $accountIds)->distinct()->pluck('user_id');

        return Student::query()->where(function ($q) use ($accountIds, $payerUserIds) {
            $q->whereIn('consultant_id', $accountIds)
                ->orWhereIn('user_id', $payerUserIds);
        });
    }
}
