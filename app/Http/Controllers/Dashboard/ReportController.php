<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Payment;
use App\Models\Student;
use App\Services\CommissionService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ExportsCsv;

    /**
     * The payment kinds that make a registration an ADMISSION for this report.
     *
     * Everything except 'free_card' (see admissions()). Kept as a constant so
     * the screen, the CSV and the PDF cannot drift apart on what they counted.
     */
    public const PAID_ADMISSION_TYPES = ['full', 'half', 'balance'];

    /** Admission Report — students registered per consultant within a date range. */
    public function admissions(Request $request)
    {
        [$from, $to] = $this->range($request);
        $viewerConsultant = $this->viewerConsultant();
        $excludeIds = $this->excludedConsultantIds($request);

        // ⚠️ FREE CARDS ARE NOT ADMISSIONS (2026-09-23).
        //
        // *"admission report eke free card kattiya penwann nathuw thiyenn, half
        // or full payment krla thiyan ayaw withrk penwann."* A free card is
        // handed out rather than bought — it seats the person and opens their
        // set, but nobody paid for it, so counting it made a marketer's number
        // say something the money never said. On the 21 Sep data free cards are
        // the MAJORITY of payment rows, so this changes every figure on the
        // page; it is the figure the owner asked for.
        //
        // A 'balance' row is the second half of a half payment, so it is here
        // only for completeness — those students already have their 'half' row.
        //
        // Judged on the payment TYPE, not its status: a slip that is still
        // waiting for the accountant is a registration that was paid for, and
        // dropping it would make the report move every time somebody clicks
        // Verify.
        $paidOnly = fn ($q) => $q->whereExists(fn ($sub) => $sub
            ->selectRaw('1')
            ->from('payments')
            ->whereColumn('payments.user_id', 'students.user_id')
            ->whereIn('payments.payment_type', self::PAID_ADMISSION_TYPES));

        // ⚠️ HOW FAR THIS VIEWER MAY SEE (2026-09-02).
        //
        //   staff                       -> everyone (null, no id filter)
        //   consultant, not granted     -> their OWN accounts only (unchanged)
        //   consultant, granted         -> their own accounts PLUS every account
        //                                  held by anyone below them
        //
        // The grant is per person and defaults to off, so a consultant who signs
        // up tomorrow lands on the middle case and nothing about their report
        // changes. See Consultant::canViewDownlineReport().
        //
        // ⚠️ THIS IS THE SECURITY BOUNDARY. It is computed from the SIGNED-IN
        // consultant and is the only thing standing between one consultant and
        // another's admissions — every filter below narrows within it and none
        // may widen it. reachableAccountIds() is the same boundary the Service
        // Groups page uses, so the two cannot drift apart.
        // ⚠️ 2026-09-22: a consultant reaches this page only with the Admission
        // Report grant (ConsultantPageAccess), and that grant IS the whole-team
        // view — "thamange team eke okkom downline eka load wenn one". The old
        // downline switch still counts (Consultant::hasPageAccess()).
        $seesDownline = $viewerConsultant && $viewerConsultant->hasPageAccess('admissions');

        $visibleIds = match (true) {
            ! $viewerConsultant => null,
            $seesDownline => $viewerConsultant->reachableAccountIds(),
            default => $viewerConsultant->accountIds(),
        };

        // Within that boundary, a granted consultant may narrow to one of their
        // people — the same picker staff get, over a smaller list.
        $picked = $request->filled('marketer') ? Consultant::find($request->input('marketer')) : null;

        // ⚠️ A PICK LOADS EVERYTHING UNDER THE ACCOUNT (2026-10-01). *"SCN account
        // ekak select karoth ekata yatathe inn okkom account tika load wenn one."*
        // A main = the whole person and everyone below; a placement account =
        // the branch hanging off it. Still intersected with $visibleIds below.
        $pickedIds = $picked
            ? Consultant::subtreeAccountIds($picked->is_hidden ? [$picked->id] : $picked->accountIds())
            : null;

        $rows = Consultant::query()
            ->with([
                'user',
                // display_name falls back to the owner for placement accounts.
                'owner.user',
                // The "Under" column — see underLabel().
                'parent.user',
                'parent.owner.user',
                'students' => fn ($q) => $paidOnly($q->whereBetween('created_at', [$from, $to]))->with('user.consultant')->latest(),
            ])
            // ⚠️ THE SAME FILTER ON BOTH, ALWAYS. The count and the list are
            // read side by side; letting one of them keep free cards would put
            // "12 students" over a list of four.
            ->withCount(['students as student_count' => fn ($q) => $paidOnly($q->whereBetween('created_at', [$from, $to]))])
            // A consultant's students can sit in any of their accounts (their own
            // SCN or a placement account), so match the whole set — filtering on
            // the single id silently dropped most of their admissions.
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->when($picked, function ($q) use ($pickedIds, $visibleIds) {
                $ids = $pickedIds;

                // ⚠️ INTERSECTED, NEVER SUBSTITUTED. Narrowing to a marketer must
                // stay inside what this viewer may already see, or ?marketer=
                // becomes a way to read any consultant in the company by id.
                //
                // The $visibleIds clause above ALREADY excludes anyone outside
                // the network — verified by removing this intersection and
                // watching the boundary test still pass — so this is the second
                // lock, not the first. It is kept because the two clauses are
                // separated by several lines and a future edit that reorders or
                // conditionalises the one above would otherwise open the door
                // silently.
                $q->whereIn('id', $visibleIds === null ? $ids : array_intersect($ids, $visibleIds));
            })
            ->when($excludeIds, fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get();

        $passes = fn ($c) => ($c->student_count > 0 || ! $c->is_hidden)
            && $c->student_count >= (int) $request->input('min', 0)
            && (! $request->filled('max') || $c->student_count <= (int) $request->input('max'));

        $tree = $picked ? $this->admissionTree($picked) : null;

        if ($tree === null) {
            // No pick: exactly as before — most students first.
            $rows = $rows->filter($passes)->sortByDesc('student_count')->values();
            $rows->each(fn ($c) => $c->tree_level = null);
        } else {
            // A pick: the tree, top down. A row a filter would drop is KEPT while
            // anyone under it is shown, or the people below would hang under
            // nothing. Only rows already inside the boundary are in $rows.
            $keep = [(int) $picked->id => true];

            foreach ($rows as $c) {
                if (! $passes($c)) {
                    continue;
                }

                $keep[(int) $c->id] = true;
                $person = (int) ($c->is_hidden ? ($c->owner_consultant_id ?? $c->id) : $c->id);

                for ($i = 0; $person && $i < 60; $i++) {
                    $keep[$person] = true;
                    $person = $tree['parentOf'][$person] ?? null;
                }
            }

            $position = array_flip(array_keys($tree['order']));

            $rows = $rows->filter(fn ($c) => isset($keep[(int) $c->id]))
                ->sortBy(fn ($c) => $position[(int) $c->id] ?? PHP_INT_MAX)
                ->values();
            $rows->each(fn ($c) => $c->tree_level = $tree['order'][(int) $c->id] ?? null);
        }

        // Students who signed up with no consultant at all — a company-wide
        // figure that means nothing to an individual consultant and is not
        // theirs to see. Same leak as the payments total below; staff only.
        $direct = $viewerConsultant
            ? 0
            // Same free-card rule as the rows above — this figure sits on the
            // same screen as their total and must be counted the same way.
            : $paidOnly(Student::whereNull('consultant_id')->whereBetween('created_at', [$from, $to]))->count();

        // display_name falls back to the owner's name, so placement accounts are
        // no longer reported as "—".
        // "Under" (2026-09-22): "kage yatathe inne kiylath penwann one".
        $rows->each(fn ($c) => $c->under_label = $this->underLabel($c));

        // "SCN Account", not "Marketer Code" (2026-10-01). With a pick the level
        // travels with each row, so the tree survives into Excel.
        $header = $tree
            ? ['SCN Account', 'Marketer Name', 'Under', 'Level', 'Student Count']
            : ['SCN Account', 'Marketer Name', 'Under', 'Student Count'];
        $data = $rows->map(fn ($c) => $tree
            ? [$c->scn_code ?? $c->consultant_code, $c->display_name, $c->under_label, $c->tree_level, $c->student_count]
            : [$c->scn_code ?? $c->consultant_code, $c->display_name, $c->under_label, $c->student_count]);

        if ($request->input('export') === 'csv') {
            return $this->csvResponse("admission-report-{$from->toDateString()}-{$to->toDateString()}.csv", $header, $data);
        }

        if ($request->input('export') === 'pdf') {
            return view('dashboard.reports.print', [
                'title' => 'Admission Report',
                'subtitle' => "{$from->toDateString()} to {$to->toDateString()}",
                'headers' => $header,
                'rows' => $data,
                'csvUrl' => $request->fullUrlWithQuery(['export' => 'csv']),
            ]);
        }

        // Who this viewer may narrow the report to.
        //
        // Staff get every SCN account. A GRANTED consultant gets every account
        // in their network. An ungranted consultant gets nothing, and the view
        // renders no picker at all, so the feature is invisible rather than
        // merely disabled.
        $marketers = match (true) {
            ! $viewerConsultant => Consultant::whereNotNull('scn_code')->with(['user', 'owner.user'])->orderBy('scn_code')->get(),
            // Placement accounts included since 2026-10-01: the picker is now an
            // "SCN Account" picker, and a placement account loads its branch.
            $seesDownline => Consultant::whereIn('id', $visibleIds)
                ->whereNotNull('scn_code')
                ->with(['user', 'owner.user'])
                ->orderBy('scn_code')
                ->get(),
            default => collect(),
        };

        return view('dashboard.reports.admissions', compact(
            'rows', 'direct', 'from', 'to', 'marketers', 'viewerConsultant', 'seesDownline',
        ));
    }

    /*
     * payoutWindows() lived here until 2026-09-24. It produced the Released
     * Today / Released This Week / Pending columns for the Payment Summary,
     * asked for on 2026-08-24 and removed when the owner reported the report
     * as showing more than the consultants were paid: the first two ignored
     * the date filter and the third was money no System Run had released.
     * Nothing called it afterwards, so it is not kept as dead code — the
     * reasoning it carried is recorded in payments() above $header, and in
     * PaymentSummaryPayoutWindowsTest.
     */

    /**
     * What every System Run RELEASED, per account, for runs settling in range.
     *
     * ⚠️ READ FROM THE RUN'S OWN SNAPSHOT, never re-derived from commission
     * rows. A run records what it released per account, and that figure is a
     * whole stage (5,000 / 10,000 / 15,000). Summing the underlying 2,500 rows
     * instead is what let half a stage land in one report and half in another —
     * the 2,500 and 7,500 lines the owner reported on 2026-09-25.
     *
     * ⚠️ DATED BY `cutoff_at`, THE PAYMENT DATE THE OPERATOR PICKED, not by
     * when the button was pressed. A run performed on the 24th that settles up
     * to the 23rd is the 23rd's money — which is also what makes a re-run of an
     * old date report on that old date.
     *
     * An account reversed after the run is left out, exactly as runPayouts()
     * leaves it out, so the two can never disagree.
     *
     * @param  array<int>|null  $only  account ids the viewer may see; null = everyone
     * @param  array<int>  $excludeIds
     * @return \Illuminate\Support\Collection<int, float>  keyed by account id
     */
    protected function runReleasesByAccount($from, $to, ?array $only, array $excludeIds): \Illuminate\Support\Collection
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('system_runs')) {
            return collect();
        }

        $runs = $this->runsSettlingBetween($from, $to);

        $totals = $runs
            ->flatMap(fn ($run) => collect($run->settledAccounts()))
            ->filter(fn ($a) => empty($a['reversed_at']))
            ->map(fn ($a) => [
                'account' => (int) ($a['account_id'] ?? 0),
                'amount' => (float) ($a['released'] ?? 0),
            ])
            ->filter(fn ($a) => $a['account'] > 0 && $a['amount'] > 0)
            ->filter(fn ($a) => $only === null || in_array($a['account'], $only, true))
            ->reject(fn ($a) => $only === null && in_array($a['account'], $excludeIds, true))
            ->groupBy('account')
            ->map(fn ($rows) => round((float) $rows->sum('amount'), 2));

        return $this->lessStruckOffRows($runs, $totals);
    }

    /**
     * ⚠️ MONEY STRUCK OFF AFTER THE RUN COMES BACK OUT OF THE FIGURE.
     *
     * A run's snapshot is a record of what it released at the time, and it is
     * never rewritten. An admin can afterwards REMOVE a wrong 2,500 (cancelled,
     * amount zeroed, the original kept in `removed_amount` — the 2026-09-06
     * rule), and a bank deposit list built from the raw snapshot would still
     * tell the bank to pay it.
     *
     * So the snapshot is the source, and this is the one correction applied to
     * it: any row the run released that is now cancelled is subtracted from the
     * account it was released on. Nothing else about the figure is re-derived.
     *
     * ⚠️ `removed_amount` FIRST, THEN `amount` — a removal zeroes `amount`, so
     * summing that alone would subtract nothing at all. Guarded on the column
     * existing, because FinanceSchemaRepair adds it lazily.
     *
     * A FORFEIT is also cancelled, but forfeited rows were never in
     * `released_ids`, so they cannot reach this subtraction.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\SystemRun>  $runs
     * @param  \Illuminate\Support\Collection<int, float>  $totals  keyed by account id
     * @return \Illuminate\Support\Collection<int, float>
     */
    protected function lessStruckOffRows(\Illuminate\Support\Collection $runs, \Illuminate\Support\Collection $totals): \Illuminate\Support\Collection
    {
        $releasedIds = $runs
            ->flatMap(fn ($run) => collect($run->settledAccounts())
                ->filter(fn ($a) => empty($a['reversed_at']))
                ->flatMap(fn ($a) => $a['released_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($releasedIds->isEmpty() || $totals->isEmpty()) {
            return $totals;
        }

        $amount = \App\Services\FinanceSchemaRepair::removalReady()
            ? 'COALESCE(commissions.removed_amount, commissions.amount)'
            : 'commissions.amount';

        $struckOff = Commission::whereIn('id', $releasedIds)
            ->where('status', 'cancelled')
            ->selectRaw("consultant_id, SUM({$amount}) as total")
            ->groupBy('consultant_id')
            ->pluck('total', 'consultant_id');

        if ($struckOff->isEmpty()) {
            return $totals;
        }

        return $totals
            ->map(fn ($total, $accountId) => round($total - (float) ($struckOff[$accountId] ?? 0), 2))
            ->filter(fn ($total) => $total > 0);
    }

    /**
     * The runs whose SETTLED-UP-TO date falls in the range.
     *
     * `cutoff_at` is stored as Sri Lanka wall clock (see SystemRun::cutoffDate),
     * so it is compared as a plain date — no timezone conversion, which is what
     * would otherwise push a run settling "23 Sep 23:59:59" onto the 24th.
     */
    protected function runsSettlingBetween($from, $to): \Illuminate\Support\Collection
    {
        return \App\Models\SystemRun::query()
            ->whereRaw('date(cutoff_at) between ? and ?', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('cutoff_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * One row per account: its direct bonus and the stage money a run released.
     *
     * Built by hand rather than by a query because the two halves come from two
     * different places — the commissions table and the runs' own snapshots —
     * and the screen, the CSV, the Excel and the bank PDF all read these same
     * objects, so they cannot drift from one another.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function incomeRows(\Illuminate\Support\Collection $directByAccount, \Illuminate\Support\Collection $releasedByAccount): \Illuminate\Support\Collection
    {
        $ids = $directByAccount->keys()
            ->merge($releasedByAccount->keys())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        // Eager-loaded: the view reads display_name, which falls back to the
        // owner for a placement account, and preventLazyLoading is on outside
        // production.
        $accounts = Consultant::with('user', 'owner.user')->whereIn('id', $ids)->get()->keyBy('id');

        return $ids
            ->map(function (int $id) use ($directByAccount, $releasedByAccount, $accounts) {
                $direct = (float) ($directByAccount[$id] ?? 0);
                $group = (float) ($releasedByAccount[$id] ?? 0);

                return (object) [
                    'consultant_id' => $id,
                    'consultant' => $accounts[$id] ?? null,
                    'direct_income' => $direct,
                    'group_income' => $group,
                    'total_income' => $direct + $group,
                ];
            })
            ->filter(fn ($row) => $row->total_income > 0)
            ->sortByDesc('total_income')
            ->values();
    }

    /**
     * The direct-bonus amounts the Payment Summary counts in columns of their
     * own (2026-10-25): the standard (4,000), every course's own amount (Elliott
     * Wave 5,000), and any other amount actually present in the range — so no
     * money is ever left without a column.
     *
     * @param  array<int, array<string, int>>  $directCounts
     * @return list<string> amounts as keys ("4000", "5000"), smallest first
     */
    protected function directAmountColumns(array $directCounts): array
    {
        $amounts = collect([(float) (\App\Models\CommissionSetting::current()->direct_commission ?? 0)])
            ->merge(\App\Support\CourseMoney::coursesWithOwnDirect()->pluck('direct'))
            ->filter(fn ($a) => (float) $a > 0)
            ->map(fn ($a) => (string) round((float) $a, 2));

        foreach ($directCounts as $byAmount) {
            $amounts = $amounts->merge(array_keys($byAmount));
        }

        return $amounts->map(fn ($a) => (string) $a)->unique()->sortBy(fn ($a) => (float) $a)->values()->all();
    }

    /**
     * ⚠️ COUNTS AND SRN (2026-10-25). Owner: "2500 and 4000 and 5000 ewidiyt enn
     * one SRN and SCN numbers ekk" — chose counts: how many 2,500s, 4,000s and
     * 5,000s each account earned in the range, with the money beside them.
     *
     * The 2,500 count is the System Run money ÷ the slot rate. A run pays whole
     * stages (1-to-1 = 2 × 2,500, 2-to-2 = 4 × 2,500 ...), and this page reads
     * the run's own figure, so the count is derived from it rather than from
     * commission rows, which could split a stage across two dates.
     *
     * SRN is the owning person's student code (a placement account shows its
     * owner's). For a consultant the rows run in tree order — their own accounts
     * first, then everyone below them branch by branch, as on the Admission
     * Report — with the level for indenting; staff keep the biggest first.
     *
     * @param  array<int, array<string, int>>  $directCounts
     */
    protected function withCountsAndSrn(\Illuminate\Support\Collection $rows, array $directCounts, float $slotRate, ?Consultant $viewerConsultant): \Illuminate\Support\Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $personUserIds = $rows->map(fn ($r) => $r->consultant?->owningPerson()->user_id)->filter()->unique()->values();
        $srn = Student::whereIn('user_id', $personUserIds)->pluck('student_code', 'user_id');

        $order = $viewerConsultant ? $this->admissionTree($viewerConsultant)['order'] : [];
        $position = array_flip(array_keys($order));

        $rows = $rows->map(function ($r) use ($srn, $slotRate, $directCounts, $order) {
            $r->srn = $srn[$r->consultant?->owningPerson()->user_id] ?? null;
            $r->slot_count = $slotRate > 0 ? round($r->group_income / $slotRate, 1) : 0;
            $r->direct_counts = $directCounts[$r->consultant_id] ?? [];
            $r->level = $order[$r->consultant_id] ?? null;

            return $r;
        });

        return $viewerConsultant
            ? $rows->sortBy(fn ($r) => $position[$r->consultant_id] ?? PHP_INT_MAX)->values()
            : $rows;
    }

    /**
     * What each System Run paid out, date by date (2026-09-22): "Payment summary
     * eketh system run krana parak gane out wechcha gana date wise penwann one".
     *
     * ⚠️ READ FROM THE RUN'S OWN SNAPSHOT, not from commissions.released_at. A run
     * records, per account, the amount it RELEASED (SystemRun::settledAccounts()),
     * which is exactly "what went out" on that day. released_at also stamps rows a
     * run only swept off the leg (already paid) and rows it forfeited, so summing
     * it would overstate the payout. An account reversed afterwards is left out.
     *
     * Same scoping as every other figure on this page: a consultant only ever
     * sees their own accounts; staff see everyone, or one marketer.
     *
     * @return \Illuminate\Support\Collection<int, array{run_id: int, date: string, time: string, settled_up_to: string, amount: float, accounts: int}>
     */
    protected function runPayouts(?Consultant $viewerConsultant, array $excludeIds, $marketer, $from, $to)
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('system_runs')) {
            return collect();
        }

        $only = match (true) {
            (bool) $viewerConsultant => $viewerConsultant->accountIds(),
            (bool) $marketer => ($picked = Consultant::find($marketer)) ? $picked->accountIds() : [(int) $marketer],
            default => null,
        };

        // ⚠️ LISTED BY THE DATE IT SETTLED UP TO, NOT THE MINUTE IT RAN
        // (2026-09-25). A run performed on the 24th settling up to the 23rd is
        // the 23rd's money, and this table has to agree with the income
        // columns above it, which now read the same runs the same way.
        return $this->runsSettlingBetween($from, $to)
            ->map(function ($run) use ($only, $excludeIds) {
                $ranAt = $run->created_at->copy()->setTimezone('Asia/Colombo');

                $entries = collect($run->settledAccounts())
                    ->filter(fn ($a) => empty($a['reversed_at']))
                    ->filter(fn ($a) => $only === null || in_array((int) ($a['account_id'] ?? 0), $only, true))
                    ->reject(fn ($a) => $only === null && in_array((int) ($a['account_id'] ?? 0), $excludeIds, true));

                return [
                    'run_id' => (int) $run->id,
                    'date' => $ranAt->toDateString(),
                    'time' => $ranAt->format('g:i A'),
                    'settled_up_to' => $run->cutoffDate(),
                    'amount' => round((float) $entries->sum(fn ($a) => (float) ($a['released'] ?? 0)), 2),
                    'accounts' => $entries->filter(fn ($a) => (float) ($a['released'] ?? 0) > 0)->count(),
                ];
            })
            ->values();
    }

    /** Payment Summary Report — direct (tier 1) + group (tier 2) income per consultant. */
    public function payments(Request $request)
    {
        [$from, $to] = $this->range($request);
        $viewerConsultant = $this->viewerConsultant();
        $excludeIds = $this->excludedConsultantIds($request);

        // "Direct" vs "Group" is about the KIND of income (the direct-add bonus
        // vs service-group slot income), not about tier 1 / tier 2 — those are
        // the two service groups and splitting on them reported nonsense.
        // Only approved (or already paid) commissions count as earned, same
        // rule as the Service Groups and Service Income pages — otherwise this
        // report can show money that hasn't cleared approval yet.
        // ⚠️⚠️ THE STAGE MONEY IS READ FROM THE SYSTEM RUN ITSELF (2026-09-25).
        //
        // Reported with a real PDF: a report filtered to 23 Sep listed accounts
        // that were not in the 23 Sep run at all, and showed figures like 2,500
        // and 7,500 — *"mn kiynne me direct bonus eka gana newei, 2500 pointe
        // eka gana … system run ekat adal data withri load wenn one, ekath ekk
        // bonus 4000 ethanat load wenn one."*
        //
        // Both symptoms have the same cause: this column was built by SUMMING
        // COMMISSION ROWS. A run releases a whole STAGE (1-to-1 = 5,000,
        // 2-to-2 = 10,000, 3-to-3 = 15,000) made of 2,500 rows on two legs, and
        // any rule that dates those rows one by one can split a stage across two
        // reports — which is exactly what a 2,500 or a 7,500 on this page is.
        //
        // So the figure is no longer derived: it is READ from what the run
        // recorded it released for that account (SystemRun::settledAccounts()),
        // and a run belongs to the date it SETTLED UP TO — the Payment Date the
        // operator picked — not to the minute the button was pressed. Image of
        // run #… : "RUN AT Sep 24 5:41pm · SETTLED UP TO Sep 23" belongs to the
        // 23rd, which is the day the money is for.
        //
        // This also settles the 2026-09-24 complaint it replaces: a slip banked
        // on the 21st and verified late settles when the 21st is re-run, and
        // that run reports on the 21st. Dating the ROWS by their payslip (the
        // rule that stood for one day) could not do this, because it filed half
        // a stage under one date and half under another.
        //
        // The direct bonus is unchanged: it never goes through a run, so it is
        // counted by its own payslip date — and the owner asked for it to stay
        // on this page beside the run money.
        // ⚠️ A CONSULTANT SEES THEIR WHOLE TEAM (2026-10-25). Owner: "consultant
        // ge eyta aithi downline line ekem hamogem income tika penn habai eyage
        // group eke withri penwann one ... SRN and SCN numbers ekk". Every
        // account in their tree — reachableAccountIds(), the same boundary the
        // Admission Report uses — and never anyone outside it. The two cards,
        // the run payouts and the BANK DEPOSIT list stay their OWN: a downline's
        // NIC and bank account are not the upline's to see.
        $ownIds = $viewerConsultant ? array_map('intval', $viewerConsultant->accountIds()) : null;

        $onlyIds = match (true) {
            (bool) $viewerConsultant => array_map('intval', $viewerConsultant->reachableAccountIds()),
            $request->filled('marketer') => ($picked = Consultant::find($request->input('marketer')))
                ? $picked->accountIds()
                : [(int) $request->input('marketer')],
            default => null,
        };

        $directRows = Commission::query()
            ->where('type', CommissionService::TYPE_DIRECT)
            // Only approved (or already paid) money is the consultant's.
            ->whereIn('status', ['approved', 'paid'])
            // ⚠️ THE DAY THE MONEY ARRIVED, NOT THE DAY IT WAS VERIFIED
            // (2026-09-10). The row is written when an accountant presses
            // Verify, which is routinely days after the payslip went in.
            ->whereRaw(\App\Services\FinanceSchemaRepair::incomeDateSql().' between ? and ?', [$from, $to])
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('consultant_id', $onlyIds))
            ->when($onlyIds === null && $excludeIds, fn ($q) => $q->whereNotIn('consultant_id', $excludeIds))
            // By AMOUNT as well (2026-10-25): the table counts the 4,000s and
            // the 5,000s (a course with its own direct) separately.
            ->selectRaw('consultant_id, amount, COUNT(*) as n')
            ->groupBy('consultant_id', 'amount')
            ->get();

        $directByAccount = $directRows->groupBy('consultant_id')
            ->map(fn ($g) => round($g->sum(fn ($r) => (float) $r->amount * (int) $r->n), 2));

        $directCounts = [];

        foreach ($directRows as $r) {
            $key = (string) round((float) $r->amount, 2);
            $directCounts[(int) $r->consultant_id][$key] = ($directCounts[(int) $r->consultant_id][$key] ?? 0) + (int) $r->n;
        }

        $releasedByAccount = $this->runReleasesByAccount($from, $to, $onlyIds, $excludeIds);

        $commissions = $this->incomeRows($directByAccount, $releasedByAccount);

        // SRN, the count of each amount, and a consultant's tree order (2026-10-25).
        $slotRate = (float) (\App\Models\CommissionSetting::current()->commission_per_student ?? 0);
        $directAmounts = $this->directAmountColumns($directCounts);
        $commissions = $this->withCountsAndSrn($commissions, $directCounts, $slotRate, $viewerConsultant);

        // ⚠️ THE DAY / WEEK / PENDING COLUMNS WERE REMOVED FROM THIS REPORT
        // (2026-09-24) — see the note above $header, and the one where
        // payoutWindows() used to be defined.

        // Scoped to the viewer, like every other figure on this page.
        //
        // Unscoped, this summed EVERY verified payment in the system, so a
        // consultant opening their own Payment Summary was shown the company's
        // total course revenue (reported 2026-08-14 as "LKR 1,288,000" on a
        // consultant login — "company eke detail one na methanata"). That is
        // both the wrong number for them and company data they should not see.
        // Staff still get the company-wide figure.
        $totals = [
            'payments' => (float) Payment::where('status', 'verified')
                // ⚠️ The PAYMENT DATE, not verified_at, and not created_at
                // (2026-09-11). Same window as the commission columns beside
                // it: a payslip banked in one month and verified in the next
                // belongs to the month it was banked, or this total and those
                // columns describe two different periods. created_at is when
                // the registration row was written, which is a third date again.
                ->whereRaw('COALESCE(payments.paid_at, payments.created_at) BETWEEN ? AND ?', [$from, $to])
                ->when($viewerConsultant, fn ($q) => $q->whereIn('consultant_id', $viewerConsultant->accountIds()))
                ->when(! $viewerConsultant && $request->filled('marketer'), function ($q) use ($request) {
                    $picked = Consultant::find($request->input('marketer'));
                    $q->whereIn('consultant_id', $picked ? $picked->accountIds() : [$request->input('marketer')]);
                })
                ->when(! $viewerConsultant && $excludeIds, fn ($q) => $q->whereNotIn('consultant_id', $excludeIds))
                ->sum('amount'),
            // A consultant's card is their OWN income; the team is a card of its own.
            'commissions' => (float) ($ownIds !== null ? $commissions->whereIn('consultant_id', $ownIds) : $commissions)->sum('total_income'),
            'team' => $viewerConsultant ? (float) $commissions->sum('total_income') : null,
        ];

        // The bank deposit list (PDF + Excel) is a consultant's OWN line only.
        $bankRows = $ownIds !== null ? $commissions->whereIn('consultant_id', $ownIds) : $commissions;

        // ⚠️ EVERY COLUMN ON THIS REPORT IS NOW IN-RANGE MONEY, AND NOTHING ELSE
        // (2026-09-24).
        //
        // Three columns used to sit beside the income figures — Released Today,
        // Released This Week, and Pending (not yet released). They were asked
        // for on 2026-08-24 as a payout-desk aid, and they are what the owner is
        // now reporting as the report "showing more": two of them ignore the
        // date filter entirely (they are fixed today / finance-week windows),
        // and the third is slot money NO RUN HAS RELEASED — LKR 9,077,500
        // network-wide on the 21 Sep data, sitting in the same table as income
        // that really was paid.
        //
        // The rule the owner has now stated three times: *"system run eken out
        // wena stage 1to1, 2to2, 3to3 tikai, consultant hambena direct bonus
        // 4000i withri enn one"*, and *"api select karana date ekata ena tika"*.
        // So this page carries the direct bonus and run-released stage money for
        // the selected dates, full stop. Un-released money lives on the Service
        // Groups page, which is the screen that exists to show it.
        //
        // The query behind them (payoutWindows) was deleted with them; see the
        // note where it used to live, above.
        $header = array_merge(
            ['SRN', 'Marketer Code', 'Marketer Name', number_format($slotRate).' count'],
            array_map(fn ($a) => number_format((float) $a).' count', $directAmounts),
            ['Direct Income', 'Service Group Income', 'Total Income'],
        );
        $data = $commissions->map(fn ($c) => [
            $c->srn ?? '—',
            $c->consultant?->scn_code ?? $c->consultant?->consultant_code ?? '—',
            $c->consultant?->display_name ?? '—',
            $c->slot_count,
            ...array_map(fn ($a) => $c->direct_counts[$a] ?? 0, $directAmounts),
            number_format((float) $c->direct_income, 2, '.', ''),
            number_format((float) $c->group_income, 2, '.', ''),
            number_format((float) $c->total_income, 2, '.', ''),
        ]);

        if ($request->input('export') === 'csv') {
            return $this->csvResponse("payment-summary-{$from->toDateString()}-{$to->toDateString()}.csv", $header, $data);
        }

        // ⚠️ THE PDF'S BANK DEPOSIT LIST AS AN EXCEL FILE (2026-09-17): "PDF eke
        // tikama Excel ekat enn one". Same header, same rows, same scoping as the
        // PDF below — a real .xlsx with every NIC and account number stored as
        // TEXT, so Excel cannot turn them into 2.00E+11 or drop a leading zero.
        if ($request->input('export') === 'bank-excel') {
            $name = "bank-deposit-list-{$from->toDateString()}-{$to->toDateString()}";
            $rows = $this->bankDepositRows($bankRows, numericIncome: true);

            if (! \App\Support\SimpleXlsx::available()) {
                // No zip extension on this server: a CSV still opens in Excel.
                return $this->csvResponse("{$name}.csv", $this->bankDepositHeader(), $rows);
            }

            return response(\App\Support\SimpleXlsx::build('Bank Deposit List', $this->bankDepositHeader(), $rows), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$name.'.xlsx"',
                'Cache-Control' => 'no-store',
            ]);
        }

        if ($request->input('export') === 'pdf') {
            return view('dashboard.reports.print', [
                'title' => 'Payment Summary Report — Bank Deposit List',
                'subtitle' => "{$from->toDateString()} to {$to->toDateString()} · one line per marketer (all their accounts combined), with bank details for the deposit",
                'headers' => $this->bankDepositHeader(),
                'rows' => $this->bankDepositRows($bankRows),
                // The download on this page is THIS list, not the per-account CSV.
                'excelUrl' => $request->fullUrlWithQuery(['export' => 'bank-excel']),
            ]);
        }

        $marketers = $viewerConsultant ? collect() : Consultant::whereNotNull('scn_code')->with(['user', 'owner.user'])->orderBy('scn_code')->get();

        // Each System Run's payout, date by date (2026-09-22) — see runPayouts().
        $runPayouts = $this->runPayouts(
            $viewerConsultant,
            $excludeIds,
            $viewerConsultant ? null : $request->input('marketer'),
            $from,
            $to,
        );

        return view('dashboard.reports.payments', compact(
            'commissions', 'totals', 'from', 'to', 'marketers', 'viewerConsultant', 'runPayouts',
            'slotRate', 'directAmounts', 'ownIds',
        ));
    }

    /**
     * @return list<string>
     *
     * Direct/Service Group are deliberately NOT split out here — a bank
     * deposit slip only needs the one figure actually being deposited.
     * Requested 2026-08-18: "total income eka withrk thibba nm athi, PDF
     * ekata wena mukuth one na." The breakdown stays on the on-screen table
     * and CSV, which are for internal reconciliation, not the bank.
     */
    protected function bankDepositHeader(): array
    {
        // NIC added 2026-09-17: "Payment summary pdf ekat Consultant ge NIC
        // number ekath load wenn one". The owning person's own NIC — a placement
        // account has none, exactly like its bank details.
        return ['Marketer Code', 'Marketer Name', 'NIC', 'Total Income', 'Bank', 'Branch', 'Account Name', 'Account Number'];
    }

    /**
     * The PDF's own rollup: one line per REAL person, not per SCN account.
     *
     * $commissions is grouped by consultant_id, which counts a person's hidden
     * placement accounts (SCN-00104, SCN-00105, ...) as separate rows — useful
     * on screen for auditing, but this PDF exists to be handed to the bank for
     * an actual deposit run ("bank ekat denn one salli deposit krann eka
     * nisa", reported 2026-08-18), and a placement account cannot be paid: it
     * has no bank details of its own, the same way it has no display name of
     * its own (Consultant::getDisplayNameAttribute() borrows the owner's for
     * exactly this reason). So every account is rolled up to whoever actually
     * OWNS it — owningPerson(), the same resolution CommissionService uses
     * when downline money climbs to an ancestor's main account — combining
     * every account's income into one line, carrying that one person's own
     * bank details.
     *
     * Requires 'consultant.owner' eager-loaded on $commissions (already true
     * of the query in payments()) — owningPerson() reads $this->owner, and
     * Model::preventLazyLoading() is active outside production.
     *
     * @param  \Illuminate\Support\Collection<int, Commission>  $commissions
     * @return \Illuminate\Support\Collection<int, list<string>>
     */
    protected function bankDepositRows($commissions, bool $numericIncome = false)
    {
        return $commissions
            // A commission whose consultant row no longer exists cannot be
            // identified for a bank deposit at all — excluded rather than
            // crashing on a null owningPerson() call.
            ->filter(fn ($c) => $c->consultant)
            ->groupBy(fn ($c) => $c->consultant->owningPerson()->id)
            ->map(fn ($group) => [
                'person' => $group->first()->consultant->owningPerson(),
                'total_income' => (float) $group->sum('total_income'),
            ])
            ->sortByDesc('total_income')
            ->map(fn ($row) => [
                $row['person']->scn_code ?? $row['person']->consultant_code ?? '—',
                $row['person']->display_name,
                $row['person']->nic ?: '—',
                // A real number in Excel, so the column can be summed there.
                $numericIncome ? round($row['total_income'], 2) : number_format($row['total_income'], 2, '.', ''),
                $row['person']->bank_name ?: '—',
                $row['person']->bank_branch ?: '—',
                $row['person']->bank_account_name ?: '—',
                $row['person']->bank_account_number ?: '—',
            ])
            ->values();
    }

    /**
     * The order an Admission Report lists the accounts under a pick (2026-10-01):
     * the picked account(s) first, then each person below depth-first — their
     * main, then their placement accounts — so a branch is read top to bottom
     * before the next one starts.
     *
     * Built from Consultant::downlineBranchesBelow(), the same walk the Service
     * Groups tabs use; one query per level plus one for the placement accounts.
     *
     * @return array{order: array<int, int>, parentOf: array<int, ?int>}
     *               order: account id => level, in display order;
     *               parentOf: person id => the person above them (null at level 1)
     */
    protected function admissionTree(Consultant $picked): array
    {
        $rootPerson = (int) ($picked->is_hidden ? ($picked->owner_consultant_id ?? $picked->id) : $picked->id);

        $roots = $picked->is_hidden
            ? [(int) $picked->id]
            : array_map('intval', array_merge(
                [$picked->id],
                Consultant::where('owner_consultant_id', $picked->id)->where('is_hidden', true)->orderBy('id')->pluck('id')->all(),
            ));

        $branches = Consultant::downlineBranchesBelow($roots, [$rootPerson]);

        $children = [];

        foreach ($branches as $personId => $branch) {
            $children[(int) ($branch['parent'] ?? 0)][] = (int) $personId;
        }

        foreach ($children as &$list) {
            sort($list);
        }
        unset($list);

        $placements = $branches === []
            ? collect()
            : Consultant::whereIn('owner_consultant_id', array_keys($branches))
                ->where('is_hidden', true)
                ->orderBy('id')
                ->get(['id', 'owner_consultant_id'])
                ->groupBy(fn ($account) => (int) $account->owner_consultant_id);

        $order = [];

        foreach ($roots as $id) {
            $order[$id] = 0;
        }

        $stack = array_reverse($children[0] ?? []);

        while ($stack !== []) {
            $person = array_pop($stack);
            $level = (int) $branches[$person]['level'];

            $order[$person] = $level;

            foreach ($placements->get($person, collect()) as $account) {
                $order[(int) $account->id] = $level;
            }

            foreach (array_reverse($children[$person] ?? []) as $child) {
                $stack[] = $child;
            }
        }

        return [
            'order' => $order,
            'parentOf' => array_map(fn ($branch) => $branch['parent'] === null ? null : (int) $branch['parent'], $branches),
        ];
    }

    /**
     * Who an Admission Report row sits under (2026-09-22).
     *
     * A placement account belongs to its owner, so it reads "SCN-00229 · name
     * (own account)". A person reads the exact account they were sponsored
     * through and whose it is — "SCN-00230 · Lakshan maduranga" — which is the
     * answer to "whose downline is this". "—" at the top of a tree.
     */
    protected function underLabel(Consultant $row): string
    {
        if ($row->is_hidden) {
            $owner = $row->owner;

            return $owner ? ($owner->scn_code ?? $owner->consultant_code).' · '.$owner->display_name.' (own account)' : '—';
        }

        $sponsor = $row->parent;

        if (! $sponsor) {
            return '—';
        }

        $person = $sponsor->is_hidden ? $sponsor->owner : $sponsor;

        return ($sponsor->scn_code ?? $sponsor->consultant_code).' · '.($person?->user?->name ?? $sponsor->display_name);
    }

    /** A consultant only ever sees their own row — staff (admin/accountant) see everyone. */
    protected function viewerConsultant(): ?Consultant
    {
        $user = auth()->user();

        if ($user->isStaff()) {
            return null;
        }

        if ($user->hasRole('consultant')) {
            abort_if(! $user->consultant, 403, 'No consultant profile found for your account.');

            return $user->consultant;
        }

        return null;
    }

    /** @return list<int> */
    protected function excludedConsultantIds(Request $request): array
    {
        return Consultant::whereIn('scn_code', (array) $request->input('exclude', []))->pluck('id')->all();
    }

    /** @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon} */
    protected function range(Request $request): array
    {
        $from = $request->filled('from') ? $request->date('from')->startOfDay() : now()->subDays(30)->startOfDay();
        $to = $request->filled('to') ? $request->date('to')->endOfDay() : now()->endOfDay();

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }
}
