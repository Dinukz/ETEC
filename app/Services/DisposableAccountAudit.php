<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * ⚠️ WHICH ACCOUNTS COULD BE DELETED WITHOUT LOSING ANYTHING REAL.
 *
 * Asked for 2026-09-01. The numbering had reached SCN-00229 because roughly a
 * hundred test identities were holding numbers: "kalin patn gaththe SCN 100, dn
 * patn ganne SCN 229. Meka patn ganna one SCN 100."
 *
 * ⚠️⚠️ THE LIVE SITE AND THE TEST DATA SHARE ONE DATABASE. The owner confirmed
 * it. So this class does NOT decide what is "test data" — it cannot know, and
 * guessing would delete a real consultant. It reports the FINANCIAL FOOTPRINT of
 * every account and lets a person decide, with the evidence in front of them.
 *
 * An account is only ever offered as disposable when ALL of these hold:
 *   - no verified payment, ever
 *   - no commission row of any status, ever, on them OR on any account they own
 *   - no student seated on any of their Service Groups
 *   - they are not staff (super admin / admin / accountant / lecturer)
 *   - they are not the person asking
 *
 * Anything with money attached is listed as KEPT, with the reason, so the
 * absence of an account from the delete list is itself visible rather than
 * silent. Nothing here deletes: the screen posts to the ordinary bulk-delete,
 * which re-checks its own rules per user.
 */
class DisposableAccountAudit
{
    /** Roles that are never offered for deletion, whatever their footprint. */
    public const PROTECTED_ROLES = ['super_admin', 'admin', 'accountant', 'lecturer', 'group_supporter'];

    /**
     * @return array{disposable: Collection, kept: Collection, next_number: int, reclaimable: int}
     */
    public function run(?int $actorId = null): array
    {
        $users = User::query()
            ->with(['roles', 'consultant', 'student'])
            ->orderBy('id')
            ->get();

        // Every id that carries money, gathered in three queries rather than
        // per user — this runs over the whole user table.
        $withPayments = Payment::where('status', 'verified')
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->flip();

        $earningAccountIds = Commission::distinct()->pluck('consultant_id')->flip();

        $seatedAccountIds = \App\Models\Group::whereIn(
            'id', \App\Models\GroupMember::distinct()->pluck('group_id')
        )->pluck('consultant_id')->flip();

        $disposable = collect();
        $kept = collect();

        foreach ($users as $user) {
            $reason = $this->whyKept($user, $actorId, $withPayments, $earningAccountIds, $seatedAccountIds);

            $row = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
                'code' => $user->displayCode(),
                'created_at' => $user->created_at,
                'reason' => $reason,
            ];

            $reason === null ? $disposable->push($row) : $kept->push($row);
        }

        return [
            'disposable' => $disposable,
            'kept' => $kept,
            'next_number' => NumberPool::peek(),
            // What the counter WOULD fall to if every disposable account went.
            'reclaimable' => $this->numberAfterDeleting($disposable->pluck('id')->all()),
        ];
    }

    /**
     * Why this account must be kept, or null when nothing is attached to it.
     *
     * Ordered so the most reassuring reason wins: a person reading this wants
     * to know the money is what saved it, not that it happened to be an admin.
     */
    protected function whyKept(User $user, ?int $actorId, $withPayments, $earning, $seated): ?string
    {
        if ($actorId !== null && $user->id === $actorId) {
            return 'This is you.';
        }

        if ($user->hasAnyRole(self::PROTECTED_ROLES)) {
            return 'Staff account ('.\Illuminate\Support\Str::headline($user->getRoleNames()->first() ?? 'staff').').';
        }

        if (isset($withPayments[$user->id])) {
            return 'Has a verified payment.';
        }

        // A consultant owns hidden placement accounts; money on ANY of them
        // counts, or deleting the person would take earning accounts with it.
        $accountIds = $user->consultant
            ? Consultant::where('id', $user->consultant->id)
                ->orWhere('owner_consultant_id', $user->consultant->id)
                ->pluck('id')
            : collect();

        foreach ($accountIds as $id) {
            if (isset($earning[$id])) {
                return 'Has commission recorded on one of their accounts.';
            }

            if (isset($seated[$id])) {
                return 'Has a student seated on one of their Service Groups.';
            }
        }

        return null;
    }

    /**
     * Where the number pool would restart if these users were deleted.
     *
     * Computed WITHOUT deleting anything: take every code currently in use,
     * drop the ones belonging to these users and the accounts they own, and see
     * what the highest survivor is.
     */
    protected function numberAfterDeleting(array $userIds): int
    {
        if ($userIds === []) {
            return NumberPool::peek();
        }

        $goingConsultantIds = Consultant::whereIn('user_id', $userIds)->pluck('id');

        $survivingScn = Consultant::whereNotIn('user_id', $userIds)
            ->orWhereNull('user_id')
            ->whereNotIn('owner_consultant_id', $goingConsultantIds->isEmpty() ? [0] : $goingConsultantIds->all())
            ->pluck('scn_code');

        $survivingSrn = \App\Models\Student::whereNotIn('user_id', $userIds)->pluck('student_code');

        $highest = NumberPool::FLOOR - 1;

        foreach ($survivingScn->concat($survivingSrn) as $code) {
            $highest = max($highest, (int) NumberPool::numberIn($code));
        }

        return $highest + 1;
    }
}
