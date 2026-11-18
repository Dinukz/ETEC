<?php

namespace App\Livewire;

use App\Models\Consultant;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Services\AccountPlacementMap;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Everything about one person's SCN accounts, on demand, in one popup.
 *
 * Asked for 2026-09-10 from the Direct Registrations table: how many SCN
 * accounts they hold and what each is, their service groups, the courses they
 * bought, whether an admin added them directly or they came through another
 * SCN account, where the placement sits, which upline SCN they were added
 * under, and their downline with each member's placement — *"okkom summary
 * widiyt keti karala dann, therungann pahasu wenn"*.
 *
 * ⚠️ WHY THIS IS A COMPONENT AND NOT THE HOUSE MODAL PARTIAL.
 * The existing modals on this table (_pending-actions, _confirm-payment-modal)
 * render their whole contents into EVERY row on page load. That is fine for a
 * confirm dialog with three fields. A full summary is ~30 queries per person
 * and the table paginates 12 rows, so copying that idiom would cost ~360
 * queries on every page view for popups nobody opened. This holds nothing
 * until a row's button is pressed.
 *
 * ⚠️ LOADED IN TWO STAGES — "part wise", as the owner asked.
 * Stage 1 (open): accounts, service groups, courses, origin, upline — ~10
 * queries. Stage 2 (a second click): placement and the downline walk — ~25
 * more, dominated by downlineBranchesBelow() which scales with the depth AND
 * breadth of the network. Nobody waits for stage 2 unless they want it.
 */
class ScnAccountSummary extends Component
{
    /** The student row being summarised, or null when the popup is closed. */
    public ?int $studentId = null;

    /** Stage 1 payload. Scalars and arrays only — a Livewire prop is serialised into the page. */
    public array $summary = [];

    /** Stage 2 payload, empty until asked for. */
    public array $deep = [];

    public bool $deepLoaded = false;

    public ?string $error = null;

    /**
     * The signed-in consultant, or null.
     *
     * Resolved per request rather than held as a prop: a prop would be
     * round-tripped through the browser and is exactly the thing an attacker
     * would edit.
     */
    protected function viewer(): ?Consultant
    {
        return auth()->user()?->consultant;
    }

    /** Memoised for the request — reachableAccountIds() is two queries every call. */
    protected ?array $reachable = null;

    /** @return array<int> */
    protected function reachable(): array
    {
        return $this->reachable ??= array_map('intval', $this->viewer()?->reachableAccountIds() ?? []);
    }

    /**
     * May the viewer read this account's SCN code?
     *
     * A consultant sees their own network and nothing above it. Overridden by
     * UserAccountSummary, where the viewer is staff and nothing is above them.
     */
    protected function canSee(int $accountId): bool
    {
        return in_array($accountId, $this->reachable(), true);
    }

    /**
     * ⚠️ AUTHORISE INSIDE EVERY ACTION — the route middleware does NOT cover us.
     *
     * /consultant/students sits behind `role:consultant`, but a Livewire action
     * arrives on Livewire's own update endpoint carrying only the framework's
     * middleware. Without this check any logged-in user could post a studentId
     * and read another consultant's whole network. Same warning, same reason,
     * as App\Livewire\ServiceIncomeBoard.
     *
     * reachableAccountIds() is the boundary the rest of the consultant screens
     * already use — their own accounts plus their whole downline's.
     */
    protected function studentInNetwork(int $studentId): ?Student
    {
        $viewer = $this->viewer();

        abort_unless($viewer !== null, 403, 'No consultant profile found for your account.');

        $reachable = $this->reachable();

        $student = Student::with(['user.consultant.owner.user', 'consultant'])->find($studentId);

        if (! $student) {
            return null;
        }

        // Owned either way — students.consultant_id is who signed them up,
        // payments.consultant_id is the account their money went under, and
        // only the union is correct (see ResolvesOwnedStudents).
        $ownedByStudentRow = $student->consultant_id !== null
            && in_array((int) $student->consultant_id, $reachable, true);

        $ownedByPayment = Payment::where('user_id', $student->user_id)
            ->whereIn('consultant_id', $reachable)
            ->exists();

        abort_unless($ownedByStudentRow || $ownedByPayment, 403, 'That student is not in your network.');

        return $student;
    }

    /**
     * Stage 1 — open the popup and load the cheap half.
     *
     * ⚠️ Reached from a browser event, not a wire:click. The table rows are
     * plain Blade outside any component, so wire:click on them would be inert
     * markup; they call Livewire.dispatch() and this listener picks it up.
     */
    #[On('show-scn-summary')]
    public function open(int $studentId): void
    {
        $this->reset(['summary', 'deep', 'deepLoaded', 'error']);

        $student = $this->studentInNetwork($studentId);

        if (! $student) {
            $this->error = 'That registration no longer exists.';
            $this->studentId = $studentId;

            return;
        }

        $this->studentId = $studentId;
        $this->summary = $this->buildSummary($student);
    }

    public function close(): void
    {
        $this->reset(['studentId', 'summary', 'deep', 'deepLoaded', 'error']);
    }

    /** Stage 2 — the expensive half, only when asked for. */
    public function loadDeep(): void
    {
        if ($this->studentId === null || $this->deepLoaded) {
            return;
        }

        $student = $this->studentInNetwork($this->studentId);

        if (! $student) {
            return;
        }

        $this->deep = $this->buildDeep($student);
        $this->deepLoaded = true;
    }

    // ----- stage 1 ----------------------------------------------------------

    /** @return array<string, mixed> */
    protected function buildSummary(Student $student): array
    {
        // ⚠️ ?-> on every user read. A Student can outlive its User, and one
        // orphan row must not 500 the popup (see _table.blade.php's own note).
        $user = $student->user;
        $consultant = $user?->consultant;

        $accounts = [];
        $accountIds = [];

        if ($consultant) {
            // orderedAccountIds(), not accountIds(): this is a list a human
            // reads, so the main must come first and placements follow in the
            // order they opened.
            $accountIds = $consultant->orderedAccountIds();

            $rows = Consultant::whereIn('id', $accountIds)
                ->get(['id', 'scn_code', 'consultant_code', 'is_hidden'])
                ->sortBy(fn ($a) => array_search((int) $a->id, $accountIds, true));

            foreach ($rows as $account) {
                $accounts[] = [
                    'id' => (int) $account->id,
                    'code' => $account->scn_code ?: $account->consultant_code,
                    'is_main' => ! $account->is_hidden,
                ];
            }
        }

        return [
            'name' => $user?->name ?? 'This student',
            'student_code' => $student->student_code,
            'email' => $user?->email,
            'is_consultant' => $consultant !== null,
            'accounts' => $accounts,
            'account_count' => count($accounts),
            'groups' => $this->serviceGroups($accountIds),
            'courses' => $this->courses($student),
            'origin' => $this->origin($student, $consultant),
            'upline' => $this->upline($student, $consultant),
        ];
    }

    /**
     * Service groups per account, with how many sit on each leg.
     *
     * ⚠️ LABELLED BY SCN ACCOUNT, NOT BY group_code. The table used to print
     * the GRP-XXXXXX code, which is an internal key nobody outside the database
     * can place — reported 2026-09-22, *"group code eka samanaya kenkt theren
     * na … SCN number or name use krnn"*. The account is the thing the reader
     * already knows, so the row is named after it and the main is flagged.
     */
    protected function serviceGroups(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $groups = Group::whereIn('consultant_id', $accountIds)->get(['id', 'consultant_id', 'group_code']);

        $accounts = Consultant::whereIn('id', $accountIds)
            ->get(['id', 'scn_code', 'consultant_code', 'is_hidden'])
            ->keyBy(fn ($a) => (int) $a->id);

        // One query for every leg of every account — the same shape
        // Consultant\GroupController uses.
        $counts = GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->whereIn('groups.consultant_id', $accountIds)
            ->selectRaw('groups.consultant_id, group_members.tier, COUNT(*) as members')
            ->groupBy('groups.consultant_id', 'group_members.tier')
            ->get();

        // ⚠️ first(), not firstWhere() — firstWhere() takes a key/operator/value,
        // not a closure, and silently matches nothing when handed one.
        $seated = fn (int $accountId, int $tier) => (int) ($counts
            ->first(fn ($c) => (int) $c->consultant_id === $accountId && (int) $c->tier === $tier)
            ?->members ?? 0);

        return $groups->map(function ($group) use ($accounts, $seated) {
            $accountId = (int) $group->consultant_id;
            $account = $accounts[$accountId] ?? null;

            return [
                // The SCN account the group belongs to — what the reader knows.
                'code' => $account?->scn_code ?: $account?->consultant_code ?: ('#'.$accountId),
                'is_main' => $account !== null && ! $account->is_hidden,
                'account_id' => $accountId,
                'leg1' => $seated($accountId, 1),
                'leg2' => $seated($accountId, 2),
            ];
        })
            // Main first, then the placements in the order they opened — the
            // same sequence as the SCN accounts list above it.
            ->sortBy(fn ($row) => array_search($row['account_id'], $accountIds, true))
            ->values()
            ->all();
    }

    /**
     * Courses bought, from PAYMENTS rather than enrollments.
     *
     * A payment carries the plan and the status, and a balance top-up is a
     * second payment for the same course — so dedupe by course and keep the
     * latest.
     */
    protected function courses(Student $student): array
    {
        return Payment::where('user_id', $student->user_id)
            ->with('course:id,title')
            ->latest('id')
            ->get(['id', 'user_id', 'course_id', 'payment_type', 'status', 'amount'])
            ->filter(fn ($p) => $p->course !== null)
            ->unique('course_id')
            ->map(fn ($p) => [
                'title' => $p->course->title,
                'plan' => $p->payment_type,
                'status' => $p->status,
                'amount' => (float) $p->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Admin-direct, or in through an SCN account?
     *
     * ⚠️ THERE IS NO created_by COLUMN — this is a decision table, not a field.
     * A student registered by a consultant always carries students.consultant_id
     * (EnrollmentService writes it); an admin-created one never does, because
     * UserManagementController::syncProfile() creates the Student row with no
     * consultant. consultants.parent_consultant_id is the same signal once they
     * have been promoted.
     */
    protected function origin(Student $student, ?Consultant $consultant): array
    {
        // ⚠️ THE REAL SPONSOR FIRST FOR A CONSULTANT (2026-09-27).
        //
        // This read students.consultant_id first. For a plain student that IS
        // their sponsor, but for a consultant it is whichever account their own
        // student row was last attached to — commonly one of their OWN
        // placement accounts, after buying a course on it. The owner's screen:
        // Lakshan Maduranga's popup read "Sponsoring account SCN-13242" (his
        // own account) directly above "Added under SCN-00131" (his real
        // sponsor). Same order as upline() now, so the two can never disagree.
        $sponsorId = $consultant?->parent_consultant_id ?? $student->consultant_id;

        if ($sponsorId === null) {
            return [
                'kind' => 'admin',
                'label' => 'Added directly by an administrator — no sponsoring SCN account on record.',
                'code' => null,
            ];
        }

        // Same downward-only rule as upline(): the FACT that they came in
        // through an SCN account is fine to show, but the CODE of an account
        // sitting above the viewer is not theirs to read.
        if (! $this->canSee((int) $sponsorId)) {
            return [
                'kind' => 'sponsored',
                'label' => 'Came in through an SCN account above you.',
                'code' => null,
            ];
        }

        $sponsor = Consultant::find($sponsorId, ['id', 'scn_code', 'consultant_code']);

        return [
            'kind' => 'sponsored',
            'label' => 'Came in through an SCN account.',
            'code' => $sponsor?->scn_code ?: $sponsor?->consultant_code,
        ];
    }

    /**
     * The SCN account they were added under, and which leg of it.
     *
     * ⚠️ NOTHING ABOVE THE VIEWER. The network tree only ever goes downward
     * (NetworkController's rule); naming the sponsor of somebody in your
     * downline is fine, walking further up is not.
     *
     * One hop is not by itself enough of a rule. If the subject IS the viewer,
     * one hop lands on the viewer's OWN sponsor, which is above them. So the
     * parent is named only when it is an account the viewer already reaches —
     * that stops exactly at the viewer and never climbs past.
     */
    protected function upline(Student $student, ?Consultant $consultant): array
    {
        $parentId = $consultant?->parent_consultant_id ?? $student->consultant_id;

        if ($parentId === null) {
            return ['code' => null, 'leg' => null];
        }

        if (! $this->canSee((int) $parentId)) {
            return ['code' => null, 'leg' => null, 'above_you' => true];
        }

        $parent = Consultant::find($parentId, ['id', 'scn_code', 'consultant_code', 'is_hidden']);

        // ⚠️ placement_tier is only meaningful when the parent is a MAIN
        // account — reading it under a hidden parent is the 2026-08-19 bug.
        $leg = null;

        if ($parent && ! $parent->is_hidden && \App\Services\FinanceSchemaRepair::placementTierReady()) {
            $chosen = (int) ($consultant?->placement_tier ?? 0);
            $leg = in_array($chosen, [1, 2], true) ? $chosen : null;
        }

        return [
            'code' => $parent?->scn_code ?: $parent?->consultant_code,
            'leg' => $leg,
        ];
    }

    // ----- stage 2 ----------------------------------------------------------

    /** @return array<string, mixed> */
    protected function buildDeep(Student $student): array
    {
        $consultant = $student->user?->consultant;

        if (! $consultant) {
            return [
                'placements' => [], 'downline' => [], 'placed' => [], 'placed_more' => 0,
                // A plain student still has an upline — the account they were
                // registered under, and everything above it.
                'upline_chain' => $this->uplineChain($student, null),
                'line' => $this->line($student),
                'not_a_consultant' => true,
            ];
        }

        $placed = $this->placed($consultant);

        return [
            'placements' => $this->placements($consultant),
            'upline_chain' => $this->uplineChain($student, $consultant),
            'line' => $this->line($student),
            'downline' => $this->downline($consultant),
            'placed' => $placed['rows'],
            'placed_more' => $placed['more'],
            'placed_total' => $placed['total'],
            'not_a_consultant' => false,
        ];
    }

    /**
     * ⚠️ WHERE THIS PERSON STANDS ON THEIR LINE (2026-10-01).
     *
     * Students added on one account's Service Group stand in a line in the order
     * they were added (App\Services\StudentLine). The owner: *"meyage yatathe inn
     * kattiya downline upline student and consultant la penwann one"* — show who
     * is above and below on it, students and consultants alike, for everyone,
     * including people promoted before the rule existed (display only — their
     * records are not moved).
     *
     * ⚠️ NOT "WHO EARNS FROM YOU". The Upline list is that, built from the
     * engine's own climb. A line position says where someone stands among
     * everyone added on the same account.
     *
     * ⚠️ NO NAMES FROM OUTSIDE THE VIEWER'S NETWORK. Everyone on a line was added
     * on one account; a viewer who may not read that account sees names only for
     * people inside their own network, and "Student" / "Consultant" for the rest.
     * Staff see everything (UserAccountSummary::canSee()).
     *
     * @return array<string, mixed>|null
     */
    protected function line(Student $student): ?array
    {
        $userId = (int) $student->user_id;
        $where = $userId ? \App\Services\StudentLine::lineOf($userId) : null;

        if ($where === null) {
            return null;
        }

        $ids = array_column(\App\Services\StudentLine::members($where['account_id'], $where['leg']), 'user_id');
        $index = array_search($userId, $ids, true);

        if ($index === false) {
            return null;
        }

        $cap = \App\Services\StudentLine::CAP;
        $above = array_slice(array_reverse(array_slice($ids, 0, $index)), 0, $cap);
        $below = array_slice($ids, $index + 1, $cap);
        $shown = array_merge($above, $below);

        $users = \App\Models\User::whereIn('id', $shown)->get(['id', 'name'])->keyBy('id');
        $mains = Consultant::whereIn('user_id', $shown)->where('is_hidden', false)
            ->get(['id', 'user_id', 'scn_code', 'consultant_code'])->keyBy('user_id');
        $srns = Student::whereIn('user_id', $shown)->pluck('student_code', 'user_id');
        $account = Consultant::find($where['account_id']);
        $seesAll = $this->canSee($where['account_id']);

        $row = function (int $id, int $position) use ($users, $mains, $srns, $seesAll) {
            $main = $mains->get($id);
            $visible = $seesAll || ($main !== null && $this->canSee((int) $main->id));

            return [
                'user_id' => $id,
                'position' => $position,
                'is_consultant' => $main !== null,
                'visible' => $visible,
                'name' => $visible ? ($users->get($id)?->name ?? '—') : null,
                'code' => $visible ? ($main ? ($main->scn_code ?? $main->consultant_code) : $srns->get($id)) : null,
            ];
        };

        $position = $index + 1;

        return [
            'account_id' => (int) $where['account_id'],
            'account' => $account?->scn_code ?? $account?->consultant_code ?? '—',
            'leg' => $where['leg'],
            'position' => $position,
            'total' => count($ids),
            'above' => array_map(fn ($id, $i) => $row($id, $position - $i - 1), $above, array_keys($above)),
            'above_more' => max(0, $index - $cap),
            'below' => array_map(fn ($id, $i) => $row($id, $position + $i + 1), $below, array_keys($below)),
            'below_more' => max(0, count($ids) - $index - 1 - $cap),
        ];
    }

    /**
     * ⚠️ THE WHOLE UPLINE, IN ORDER — one person per row, nearest first.
     *
     * Asked for 2026-09-27: *"upline eka hariyt penwan na … hama ehekm upline
     * eka piliwelata enn"*. The popup named ONE account above the person ("Added
     * under SCN-00131") and stopped, so nobody could see who was above that.
     *
     * ⚠️ BUILT FROM THE ENGINE'S OWN CLIMB, not from a second walk of the tree.
     * ManualCommissionEntry::chainFrom() is, hop for hop and leg for leg, the
     * path a 2,500 takes when this person brings in a student — pinned against
     * the engine by ManualCommissionEntryTest. So "who is above me" on this
     * screen and "who earns from me" in the ledger are the same list by
     * construction; a separate display walk is exactly how the two came apart
     * before (see downline(): the branch-root misread of 2026-09-22). It writes
     * nothing — chainFrom() only reads.
     *
     * ⚠️ NOTHING ABOVE THE VIEWER. The walk stops at the first account the
     * viewer may not read (canSee), so a consultant sees the line up to and
     * including themself and no further — the rule every other part of this
     * popup keeps. Staff see the whole line.
     *
     * Consecutive accounts of one person are one row ("SCN-00131 → SCN-00130"),
     * because the question is who is above whom, not which bucket.
     *
     * @return array<int, array{name: string, codes: array<int, string>, main: ?string, leg: ?int, is_viewer: bool}>
     */
    protected function uplineChain(Student $student, ?Consultant $consultant): array
    {
        // A consultant's line starts above their own main. A plain student's
        // starts AT the account they were registered under — that account is
        // the first to earn from them.
        if ($consultant) {
            $start = $consultant;
            $skipFirst = true;
        } else {
            $start = $student->consultant_id ? Consultant::with('owner')->find($student->consultant_id) : null;
            $skipFirst = false;
        }

        if (! $start) {
            return [];
        }

        $hops = app(\App\Services\ManualCommissionEntry::class)->chainFrom($start);

        if ($skipFirst) {
            $hops = $hops->slice(1)->values();
        }

        $viewerPersonId = $this->viewer()?->owningPerson()?->id;
        $rows = [];

        foreach ($hops as $hop) {
            $account = $hop['account'];

            if (! $this->canSee((int) $account->id)) {
                break;
            }

            $person = $account->owningPerson();
            $code = $account->scn_code ?: $account->consultant_code;
            $last = count($rows) - 1;

            if ($last >= 0 && $rows[$last]['person_id'] === (int) $person->id) {
                $rows[$last]['codes'][] = $code;

                if (! $account->is_hidden) {
                    $rows[$last]['main'] = $code;
                }

                continue;
            }

            $rows[] = [
                'person_id' => (int) $person->id,
                'name' => '—', // filled in one query below
                'codes' => [$code],
                'main' => $account->is_hidden ? null : $code,
                // The leg of the FIRST account reached in this person's tree —
                // the side the money arrives on.
                'leg' => in_array((int) $hop['leg'], [1, 2], true) ? (int) $hop['leg'] : null,
                'is_viewer' => $viewerPersonId !== null && (int) $person->id === (int) $viewerPersonId,
            ];
        }

        // Names in ONE query — reading ->user per hop would lazy-load, which
        // preventLazyLoading turns into a 500 outside production.
        $names = Consultant::with('user:id,name')
            ->whereIn('id', array_column($rows, 'person_id'))
            ->get(['id', 'user_id'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => $c->user?->name])
            ->all();

        return array_map(function ($row) use ($names) {
            $row['name'] = $names[$row['person_id']] ?? '—';
            unset($row['person_id']);

            return $row;
        }, $rows);
    }

    /** Never render more seated people than this; the rest are counted instead. */
    protected const PLACED_LIMIT = 150;

    /**
     * ⚠️ EVERYONE SEATED ON THIS PERSON'S ACCOUNTS — INCLUDING PLAIN STUDENTS.
     *
     * Reported 2026-09-23: *"eya student kenk iddi thama placement krla
     * thiyenne, student inn kaleth penan na … meyata eyage yatath einn
     * kattiyagen point calculate wenwane, eka nisa meyage yatathe inn kattiya
     * meyage service group eke penwann one."*
     *
     * The Downline table above walks `consultants.parent_consultant_id`, so it
     * can only ever show people who were PROMOTED to consultant. Measured on
     * the 21 Sep data: of 9,056 seats on Service Group accounts, 6,897 belong
     * to people who are still only students — invisible on every downline
     * screen, while their 2,500 sat on the leg.
     *
     * A seat is the right source here and the tree is not: awardSlot() writes
     * the seat and the commission for the SAME account, so a seat on this
     * account IS the money this account earned from that person. That is
     * exactly the question being asked.
     *
     * ⚠️ READS SEATS, CHANGES NOTHING. No placement, no climb, no commission —
     * this is the existing ledger, listed.
     *
     * @return array{rows: array<int, array<string, mixed>>, more: int, total: int}
     */
    protected function placed(Consultant $consultant): array
    {
        $accountIds = $consultant->accountIds();

        if ($accountIds === []) {
            return ['rows' => [], 'more' => 0, 'total' => 0];
        }

        $base = fn () => GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->whereIn('groups.consultant_id', $accountIds);

        $total = (int) $base()->count();

        if ($total === 0) {
            return ['rows' => [], 'more' => 0, 'total' => 0];
        }

        // Newest first: the far tail of a big account is the least interesting
        // part, and it is the part the cap drops.
        $seats = $base()
            ->leftJoin('users', 'users.id', '=', 'group_members.user_id')
            ->orderByDesc('group_members.id')
            ->limit(self::PLACED_LIMIT)
            ->get([
                'group_members.user_id as user_id',
                'groups.consultant_id as account_id',
                'group_members.tier as tier',
                'group_members.joined_at as joined_at',
                'users.name as person_name',
            ]);

        $codes = $this->codesFor($accountIds);
        $userIds = $seats->pluck('user_id')->filter()->unique()->all();

        // Their own number, whichever they hold: an SCN once they were promoted,
        // otherwise the SRN they registered with.
        $scn = Consultant::whereIn('user_id', $userIds)
            ->where('is_hidden', false)
            ->get(['user_id', 'scn_code', 'consultant_code'])
            ->mapWithKeys(fn ($c) => [(int) $c->user_id => (string) ($c->scn_code ?: $c->consultant_code)])
            ->all();

        $srn = Student::whereIn('user_id', $userIds)
            ->pluck('student_code', 'user_id')
            ->all();

        $rows = $seats->map(fn ($seat) => [
            'name' => $seat->person_name ?: 'Deleted user',
            // ⚠️ The SCN wins when they hold one: a promoted person is the same
            // human as the student who was seated, and showing their old SRN
            // beside a downline row carrying their SCN reads as two people.
            'code' => $scn[(int) $seat->user_id] ?? ($srn[(int) $seat->user_id] ?? null),
            'is_consultant' => isset($scn[(int) $seat->user_id]),
            'account' => $codes[(int) $seat->account_id] ?? ('#'.$seat->account_id),
            'leg' => in_array((int) $seat->tier, [1, 2], true) ? (int) $seat->tier : null,
            'joined' => $seat->joined_at?->format('M d, Y'),
        ])->values()->all();

        return ['rows' => $rows, 'more' => max(0, $total - count($rows)), 'total' => $total];
    }

    /**
     * SCN codes for a set of account ids, in one query.
     *
     * AccountPlacementMap has its own codeOf(), but it is protected and takes a
     * loaded Consultant — it is for the map's internals, not for callers. Same
     * fallback rule as its version: `?:` not `??`, because an empty scn_code is
     * as absent as a null one.
     *
     * @param  array<int>  $ids
     * @return array<int, string>
     */
    protected function codesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Consultant::whereIn('id', $ids)
            ->get(['id', 'scn_code', 'consultant_code'])
            ->mapWithKeys(fn ($a) => [(int) $a->id => (string) ($a->scn_code ?: $a->consultant_code)])
            ->all();
    }

    /**
     * Where each of their SCN accounts actually sits.
     *
     * ⚠️ AccountPlacementMap, NEVER Consultant::serviceGroupLeg(). The map
     * resolves this by CLIMBING; serviceGroupLeg() answers the account's
     * ordinal within its own set, and substituting it is precisely the cross
     * reported on 2026-08-23. The map's own docblock forbids the swap.
     */
    protected function placements(Consultant $consultant): array
    {
        $map = app(AccountPlacementMap::class)->for($consultant);

        $codes = $this->codesFor(array_keys($map));

        $out = [];

        foreach ($consultant->orderedAccountIds() as $id) {
            $entry = $map[$id] ?? null;

            if (! $entry) {
                continue;
            }

            $out[] = [
                'code' => $codes[$id] ?? ('#'.$id),
                'under' => $entry['under_code'] ?? null,
                'kind' => $entry['kind'] ?? 'unknown',
                'owner_group' => $entry['owner_group'] ?? null,
                // ⚠️ A foreign placement is a WARNING, not a parent — the
                // purchase was attributed to an account this person does not
                // hold. Drawing it as a parent would be a lie.
                'is_warning' => ($entry['kind'] ?? null) === 'bought-foreign',
            ];
        }

        return $out;
    }

    /**
     * The people below them: who each one sits under, and which leg they came
     * down.
     *
     * downlineBranchesBelow() keeps the branch each person descended through,
     * which downlineIds() throws away. Capped by the method itself (depth,
     * breadth and a cycle guard).
     *
     * ⚠️ TWO DIFFERENT ANSWERS, AND THEY ARE NOT INTERCHANGEABLE.
     *   - parent_account_id = the account this person was added UNDER — their
     *     sponsor, one hop up.
     *   - root_account_id  = which of the SUBJECT'S OWN accounts the branch
     *     hangs off, i.e. the leg the whole branch belongs to.
     * Until 2026-09-22 the "Placement" column printed the root for every row,
     * so a five-level-deep person read as if they sat directly under the
     * subject's own account — the owner read that as the tree being wrong.
     * Both are shown now, in their own columns.
     */
    protected function downline(Consultant $consultant): array
    {
        $branches = Consultant::downlineBranchesBelow($consultant->accountIds(), [$consultant->id]);

        if ($branches === []) {
            return [];
        }

        $ids = array_keys($branches);

        $people = Consultant::whereIn('id', $ids)
            ->with(['user:id,name', 'owner.user:id,name'])
            ->get(['id', 'user_id', 'scn_code', 'consultant_code', 'owner_consultant_id', 'is_hidden']);

        // Which of the subject's own accounts each branch root is — that is the
        // leg the whole branch belongs to.
        $map = app(AccountPlacementMap::class)->for($consultant);

        $codes = $this->codesFor(array_keys($map));

        // The sponsoring accounts. A sponsor can be a hidden placement account,
        // which carries no user of its own — its name is its owner's, so the
        // row reads "under SCN-08117 (Navini Navodaya)" rather than a code with
        // nobody's name against it.
        $sponsors = Consultant::whereIn('id', array_values(array_unique(
            array_map(fn ($b) => (int) ($b['parent_account_id'] ?? 0), $branches)
        )))
            ->with(['user:id,name', 'owner.user:id,name'])
            ->get(['id', 'user_id', 'scn_code', 'consultant_code', 'owner_consultant_id'])
            ->mapWithKeys(fn ($a) => [(int) $a->id => [
                'code' => (string) ($a->scn_code ?: $a->consultant_code),
                'name' => $a->user?->name ?? $a->owner?->user?->name,
            ]])
            ->all();

        return $people->map(function ($person) use ($branches, $map, $codes, $sponsors) {
            $branch = $branches[$person->id];
            $root = (int) $branch['root_account_id'];
            $sponsorId = (int) ($branch['parent_account_id'] ?? 0);
            $sponsor = $sponsors[$sponsorId] ?? null;

            return [
                'id' => (int) $person->id,
                // The PERSON directly above — null at level 1, where it is the
                // subject themself. Used only to order the rows (inTreeOrder).
                'parent_id' => $branch['parent'] !== null ? (int) $branch['parent'] : null,
                'code' => $person->scn_code ?: $person->consultant_code,
                'name' => $person->user?->name ?? '—',
                'level' => (int) $branch['level'],
                // Who they sit under — one hop, the real sponsor.
                'under' => $sponsor['code'] ?? ('#'.$sponsorId),
                'under_name' => $sponsor['name'] ?? null,
                // Which of the subject's own accounts the whole branch hangs
                // off. This is a BRANCH, not a parent.
                'branch' => $codes[$root] ?? ('#'.$root),
                // ⚠️ owner_group, resolved by CLIMBING from the branch root.
                // NOT serviceGroupLeg(), which answers the account's ordinal
                // inside its own set — the 2026-08-23 cross.
                'leg' => $map[$root]['owner_group'] ?? null,
            ];
        })
            ->pipe(fn ($rows) => $this->inTreeOrder($rows->all()));
    }

    /**
     * ⚠️ EACH PERSON FOLLOWED BY THEIR OWN DOWNLINE (2026-09-27).
     *
     * The table was sorted level-then-code, so every level-2 person came before
     * every level-3 person regardless of whose they were — a five-branch
     * network read as five unrelated lists interleaved. Asked for with the
     * upline: *"downline ekath e wagem piliwelata"*. Depth-first: a person, then
     * everyone under them, then the next person at their level. Siblings stay
     * in code order.
     *
     * Iterative, not recursive — a deep network would otherwise hit PHP's stack.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function inTreeOrder(array $rows): array
    {
        $byId = [];
        $children = [];

        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }

        foreach ($rows as $row) {
            // A parent outside this list (capped walk, odd data) is treated as
            // the top, so no row is ever dropped from the table.
            $parent = ($row['parent_id'] !== null && isset($byId[$row['parent_id']])) ? $row['parent_id'] : 0;
            $children[$parent][] = $row['id'];
        }

        foreach ($children as &$ids) {
            usort($ids, fn ($a, $b) => strcmp((string) $byId[$a]['code'], (string) $byId[$b]['code']));
        }
        unset($ids);

        $ordered = [];
        $stack = array_reverse($children[0] ?? []);
        $seen = [];

        while ($stack !== []) {
            $id = array_pop($stack);

            if (isset($seen[$id])) {
                continue; // a cycle in hand-edited data — never loop forever
            }

            $seen[$id] = true;
            $ordered[] = $byId[$id];

            foreach (array_reverse($children[$id] ?? []) as $childId) {
                $stack[] = $childId;
            }
        }

        return $ordered;
    }

    public function render()
    {
        return view('livewire.scn-account-summary');
    }
}
