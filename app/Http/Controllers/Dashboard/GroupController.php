<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Group;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $stats = [
            'total' => Group::count(),
            'open' => Group::where('status', 'open')->count(),
            'completed' => Group::where('status', 'completed')->count(),
            'closed' => Group::where('status', 'closed')->count(),
        ];

        $groups = Group::query()
            ->with(['consultant.user', 'consultant.owner.user'])
            ->withCount('members')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q');
                $q->where('group_code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhereHas('consultant.user', fn ($u) => $u->where('name', 'like', "%{$term}%"));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // Per-consultant Service Group 1 / 2 income, split by KIND. "Service
        // Group" income is slot income only (type = bonus); the direct-add
        // bonus is its own column, because adding them together is what made
        // an account with no group income at all still show a figure.
        $slotOnly = fn ($q) => $q->where('type', 'bonus')->whereIn('status', ['approved', 'paid']);

        // ⚠️ PAGINATED SINCE 2026-09-15. This built EVERY consultant with an SCN
        // — 1,551 on live — each carrying three correlated SUM subqueries, so
        // simply opening this page cost ~4,650 subqueries and was the reason it
        // crawled ("okkom consultant la load wela ... system eka godk slow").
        // 25 a page means 75. Its own page/search keys ('sp'/'sq') so it cannot
        // fight with the $groups paginator lower down the same screen.
        $scnSearch = trim((string) $request->input('sq'));

        $consultantTiers = Consultant::query()
            ->whereNotNull('scn_code')
            // display_name falls back to the owner's name for placement accounts.
            ->with(['user', 'owner.user'])
            ->when($scnSearch !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('scn_code', 'like', "%{$scnSearch}%")
                ->orWhere('consultant_code', 'like', "%{$scnSearch}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$scnSearch}%"))
                ->orWhereHas('owner.user', fn ($u) => $u->where('name', 'like', "%{$scnSearch}%"))))
            ->withSum(['commissions as tier1_total' => fn ($q) => $slotOnly($q)->where('tier', 1)], 'amount')
            ->withSum(['commissions as tier2_total' => fn ($q) => $slotOnly($q)->where('tier', 2)], 'amount')
            ->withSum(['commissions as direct_total' => fn ($q) => $q->where('type', 'referral')->whereIn('status', ['approved', 'paid'])], 'amount')
            ->orderBy('scn_code')
            ->paginate(25, ['*'], 'sp')
            ->withQueryString();

        // Students placed on each leg — the seats themselves, not the money.
        $tierMemberTotals = [
            1 => \App\Models\GroupMember::where('tier', 1)->count(),
            2 => \App\Models\GroupMember::where('tier', 2)->count(),
        ];

        $settings = \App\Models\CommissionSetting::current();

        // ⚠️ THE HAND-ENTRY ACCOUNT LIST (2026-09-15).
        //
        // Every SCN account, searched in the browser — the same shape the
        // consultant Service Groups picker uses. Deliberately carries NO sum
        // subqueries: it only needs a label and an id, so all 1,551 cost three
        // queries rather than 4,653. Super admin only, because only they see
        // the form at all.
        //
        // 'user_id' is what the Info button opens the account summary by, and a
        // placement account has none of its own — it falls back to the person
        // who owns it, exactly as the commission itself does.
        $accountOptions = $request->user()?->hasRole('super_admin')
            ? Consultant::query()
                ->whereNotNull('scn_code')
                ->with(['user:id,name', 'owner.user:id,name'])
                ->orderBy('scn_code')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'label' => ($c->scn_code ?? $c->consultant_code).' — '.$c->display_name
                        .($c->is_hidden ? '  (placement account)' : ''),
                    'user_id' => $c->user_id ?: $c->owner?->user_id,
                ])
                ->values()
            : collect();

        // For the hand-entry form: courses that pay their own direct (2026-10-22).
        $courseDirectRates = \App\Support\CourseMoney::coursesWithOwnDirect();

        return view('dashboard.groups.index', compact('groups', 'stats', 'consultantTiers', 'tierMemberTotals', 'settings', 'accountOptions', 'scnSearch', 'courseDirectRates'));
    }

    /**
     * ⚠️ HAND-ENTER A COMMISSION THE ENGINE MISSED.
     *
     * Asked for before go-live: with 10,000+ students there has to be a way to
     * put a missing 2,500 / 4,000 in by hand rather than wait for a code fix —
     * "manual 2500 add wen widiytath... option dekak one: SCN eka group ehekt
     * withrk ynn, saha upline group load krla e okkotam add krnn".
     *
     * SUPER-ADMIN ONLY. This creates money; it is not an accountant's button.
     * See ManualCommissionEntry for the duplicate guard and the climb.
     */
    public function addManualCommission(Request $request, \App\Services\ManualCommissionEntry $entry)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can enter a commission by hand.');

        $data = $request->validate([
            'consultant_id' => ['required', 'integer', 'exists:consultants,id'],
            // Optional since 2026-09-15 — see ManualCommissionEntry::add().
            'student_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:slot,direct'],
            'tier' => ['required', 'in:1,2'],
            'scope' => ['required', 'in:account,upline'],
            // The day this income belongs to, so a System Run settling up to
            // that date picks it up — see ManualCommissionEntry::add().
            'earned_on' => ['nullable', 'date'],
            // A course with its own direct amount (2026-10-22); blank = the
            // standard. Deleted courses count — old payments were made on them.
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
        ]);

        $account = Consultant::with('owner')->findOrFail($data['consultant_id']);
        $student = ! empty($data['student_user_id'])
            ? \App\Models\User::findOrFail($data['student_user_id'])
            : null;

        try {
            $result = $entry->add(
                $account,
                $student,
                $data['type'],
                (int) $data['tier'],
                $data['scope'],
                $request->user()->id,
                // ⚠️ MIDDAY ON PURPOSE. Only the DATE part carries meaning, and
                // a timestamp near either edge of the day can read as the day
                // before or after once a Colombo/UTC conversion is applied. Noon
                // is the one time of day no such reading can move.
                ! empty($data['earned_on'])
                    ? \Illuminate\Support\Carbon::parse($data['earned_on'])->startOfDay()->addHours(12)
                    : null,
                ! empty($data['course_id']) ? (int) $data['course_id'] : null,
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $codes = collect($result['created'])->pluck('code')->implode(', ');
        $total = collect($result['created'])->sum('amount');

        $mailed = $this->alertAdminsOfHandEntry($request, $data, $account, $student, $result['created']);

        return back()->with('success', sprintf(
            'Added LKR %s across %d account(s): %s.',
            number_format($total), count($result['created']), $codes,
        ).$mailed);
    }

    /**
     * Email the admins every hand-entered commission (2026-10-29). See
     * App\Services\ManualCommissionAlert. Runs after the entry is saved and can
     * never undo it; returns a note for the confirmation message.
     *
     * @param  array<string, mixed>  $data  the validated form
     * @param  array<int, array{code: string, amount: float, type: string, leg?: int}>  $created
     */
    protected function alertAdminsOfHandEntry(Request $request, array $data, Consultant $account, ?\App\Models\User $student, array $created): string
    {
        if ($created === [] || \App\Services\ManualCommissionAlert::recipients() === []) {
            return '';
        }

        $isDirect = $data['type'] === 'direct';
        $each = (float) $created[0]['amount'];
        $total = (float) collect($created)->sum('amount');
        $course = $isDirect && ! empty($data['course_id'])
            ? \App\Models\Course::withTrashed()->find($data['course_id'])
            : null;

        $amount = ($isDirect ? 'Direct bonus' : 'Service Group').' — LKR '.number_format($each)
            .(count($created) > 1 ? ' each · LKR '.number_format($total).' in total across '.count($created).' accounts' : '');

        $details = array_filter([
            'Name' => $account->display_name,
            'SCN' => $account->scn_code ?? $account->consultant_code,
            'Service Group' => 'Service Group '.$data['tier'],
            'Amount' => $amount,
            'Course (direct bonus only)' => $isDirect
                ? ($course ? $course->title.' (LKR '.number_format((float) $course->effective_price).')' : 'Any other course (standard)')
                : '—',
            'Date' => ! empty($data['earned_on'])
                ? \Illuminate\Support\Carbon::parse($data['earned_on'])->format('D, j M Y')
                : 'Not given (dated today)',
            'Who receives it' => $data['scope'] === 'upline' ? 'This account and its whole upline' : 'This SCN account only',
            'Student' => $student ? $student->name.' ('.$student->email.')' : null,
            'Added on' => now('Asia/Colombo')->format('D, j M Y, g:i A').' (Sri Lanka time)',
            'Added by' => $request->user()->name.' ('.$request->user()->email.')',
        ], fn ($v) => $v !== null);

        $result = \App\Services\ManualCommissionAlert::send(
            $details,
            $created,
            'Commission added by hand — LKR '.number_format($total).' · '.($account->scn_code ?? $account->consultant_code),
        );

        return $result['failed'] > 0
            ? ' (The alert email to the admins could not be sent — check Settings → Email.)'
            : ' The admins have been emailed.';
    }

    public function show(Group $group)
    {
        $group->load(['consultant.user', 'consultant.owner.user', 'members.user', 'commissions']);

        $settings = \App\Models\CommissionSetting::current();

        // Only approved (or already paid) money counts as earned — the same
        // rule every other income screen uses.
        $earned = fn () => Commission::where('group_id', $group->id)->whereIn('status', ['approved', 'paid']);

        // The two income kinds are separate figures and must never be summed:
        // the progress bar below is measured against the SLOT cap, which the
        // direct-add bonus has nothing to do with.
        $slotEarned = (float) $earned()->where('type', 'bonus')->sum('amount');
        $directEarned = (float) $earned()->where('type', 'referral')->sum('amount');

        $rate = (float) $settings->commission_per_student;
        $legs = [];

        foreach ([1, 2] as $leg) {
            $capacity = (int) ($leg === 1 ? $settings->first_tier_students : $settings->second_tier_students);

            $legs[$leg] = [
                'capacity' => $capacity,
                'members' => $group->members->where('tier', $leg)->count(),
                'earned' => (float) $earned()->where('type', 'bonus')->where('tier', $leg)->sum('amount'),
                'max' => $capacity * $rate,
            ];
        }

        $slotMax = $legs[1]['max'] + $legs[2]['max'];

        // Per-student slot income, so each name can show what it actually paid
        // rather than a stale column on the seat row.
        // Cancelled excluded (2026-09-06): a seat whose income was removed by
        // hand, or forfeited to the company by a System Run, has no slot income
        // to print. The view falls back to 0 for a missing seat, which is the
        // truthful figure — leaving it in showed the removed 2,500 still sitting
        // beside the student's name. Mirrors BackfillCommissions::resyncMemberAmounts().
        $memberSlots = Commission::where('group_id', $group->id)
            ->where('type', 'bonus')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('group_member_id')
            ->pluck('amount', 'group_member_id');

        return view('dashboard.groups.show', compact(
            'group', 'settings', 'slotEarned', 'directEarned', 'legs', 'slotMax', 'memberSlots', 'rate',
        ));
    }

    /**
     * Open an extra earning account (a new SCN + its own Service Group 1 / 2)
     * for a consultant who has used up the slots on their existing accounts.
     * Extra accounts pay on the normal "leg fills" rule, not the one-time 1-to-1.
     */
    public function addAccount(Consultant $consultant, \App\Services\HiddenAccountService $accounts)
    {
        if ($consultant->is_hidden) {
            $consultant = $consultant->owner;
        }

        if (! $consultant) {
            return back()->with('error', 'That is not a consultant account.');
        }

        $account = $accounts->addExtraAccount($consultant);

        return back()->with('success', "New earning account {$account->scn_code} opened for {$consultant->display_name}. It earns on the normal group-fill rule.");
    }
}
