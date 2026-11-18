<?php

namespace App\Livewire;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * The SCN account summary popup, opened from User Management.
 *
 * Asked for 2026-09-14: the Direct Registrations "Info" button, on every user
 * row — "userge athulta yan nathuw eliye idanm eka balagann", check a user
 * without opening their page. The same popup, the same view and the same
 * two-stage load as ScnAccountSummary. Only three things differ:
 *
 *   - it opens by USER, because User Management lists users, and staff (and a
 *     consultant an admin created directly) have no Student row at all;
 *   - it is for staff only — super admin and admin, the roles on /users —
 *     checked inside every action;
 *   - nothing is hidden as "above you": staff see the whole network.
 */
class UserAccountSummary extends ScnAccountSummary
{
    /** The user being summarised, or null when the popup is closed. */
    public ?int $userId = null;

    /**
     * ⚠️ AUTHORISE INSIDE EVERY ACTION. The /users routes are super_admin|admin,
     * but a Livewire action arrives on Livewire's own update endpoint without
     * that middleware — so without this, any signed-in consultant could post a
     * userId and read anybody's network. The public $userId prop is exactly what
     * such a request would edit, which is why it is never trusted on its own.
     */
    protected function staffOnly(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'admin']),
            403,
            'Only an administrator can open this summary.',
        );
    }

    /** Staff see the whole network, so no account is hidden as "above you". */
    protected function canSee(int $accountId): bool
    {
        return true;
    }

    /**
     * The parent's entry point (a student id), re-checked for staff instead of
     * for a consultant's network — it is inherited, so it must not be left open.
     */
    protected function studentInNetwork(int $studentId): ?Student
    {
        $this->staffOnly();

        return Student::with(['user.consultant.owner.user', 'consultant'])->find($studentId);
    }

    /**
     * The student row for a user — or an unsaved stand-in when there is none.
     *
     * The stand-in lets the very same summary be built: its student_code and
     * consultant_id are simply empty, and everything else is read from the user
     * (their SCN accounts, their payments, their sponsor).
     */
    protected function studentForUser(int $userId): ?Student
    {
        $user = User::with('consultant.owner.user')->find($userId);

        if (! $user) {
            return null;
        }

        $student = Student::with(['user.consultant.owner.user', 'consultant'])
            ->where('user_id', $userId)
            ->first();

        if ($student) {
            return $student;
        }

        $standIn = new Student;
        $standIn->forceFill(['user_id' => $user->id]);
        $standIn->setRelation('user', $user);
        $standIn->setRelation('consultant', null);

        return $standIn;
    }

    /** Open the popup for one user row. */
    #[On('show-user-summary')]
    public function openUser(int $userId): void
    {
        $this->staffOnly();

        $this->reset(['studentId', 'userId', 'summary', 'deep', 'deepLoaded', 'error']);
        $this->userId = $userId;

        $student = $this->studentForUser($userId);

        if (! $student) {
            $this->error = 'That user no longer exists.';

            return;
        }

        // The role, because a User Management row can be staff — the summary
        // alone would read like a student with nothing bought.
        $this->summary = $this->buildSummary($student) + [
            'role' => Str::headline($student->user->primaryRole()),
        ];
    }

    /** Stage 2, resolved from the user rather than a student id. */
    public function loadDeep(): void
    {
        $this->staffOnly();

        if ($this->userId === null) {
            parent::loadDeep();

            return;
        }

        if ($this->deepLoaded) {
            return;
        }

        $student = $this->studentForUser($this->userId);

        if (! $student) {
            return;
        }

        $this->deep = $this->buildDeep($student);
        $this->deepLoaded = true;
    }

    public function close(): void
    {
        $this->reset(['studentId', 'userId', 'summary', 'deep', 'deepLoaded', 'error']);
    }
}
