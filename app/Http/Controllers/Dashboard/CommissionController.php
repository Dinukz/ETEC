<?php

namespace App\Http\Controllers\Dashboard;

use App\Concerns\ManagesServiceIncome;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Concerns\FiltersByDateRange;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\CompanyRevenue;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    use ExportsCsv, FiltersByDateRange, ManagesServiceIncome;

    public function index()
    {
        // ⚠️ THE REMOVE BUTTON ON THIS PAGE NEEDS COLUMNS THIS PAGE MUST CREATE.
        //
        // Production has no CLI, so `removed_at` / `removed_by` /
        // `removed_amount` only ever come into existence when some request runs
        // the repair. Every other entry point that needs them
        // (PaymentService::verify(), SettingController, SystemRunController...)
        // already does — but an accountant can open /commissions and press
        // Remove without any of those having run first, and removeOne() would
        // then quietly fall back to leaving the amount printed. Same guard, and
        // the same reasoning, as FinanceReportController::index().
        //
        // Safe here: plain GET, no open transaction — see FinanceSchemaRepair's
        // docblock for why an ALTER inside one is the thing to avoid.
        try {
            \App\Services\CommissionSchemaRepair::ensure();

            // ⚠️ THE READINESS FLAG IS CACHED FOR AN HOUR, AND THAT BIT US.
            // removalReady() memoises through Cache::remember(..., 3600). A
            // `false` cached BEFORE ensure() created the columns therefore
            // survives for up to an hour afterwards — and in that window
            // removeOne() skips zeroing `amount` while still decrementing
            // total_earned, so the summary page prints the full 2,500 for ever.
            // ensure() has just run, so the answer is knowable now.
            \Illuminate\Support\Facades\Cache::forget('finance.removal.ready');
            \App\Services\FinanceSchemaRepair::forgetMemo();
        } catch (\Throwable $e) {
            report($e);
        }

        // ⚠️ CANCELLED EXCLUDED FROM EVERY CARD (2026-09-06).
        //
        // 'total' and 'held' had no status filter at all, so a commission
        // removed by hand — or closed off to the company by a System Run —
        // kept its full amount in "Total Service Income". That card is the
        // first thing on the page, which made a removal look like it had done
        // nothing: "SCN ekat adala hama thaninm 2500 hari 4000 hari 0 wenn
        // one." The other three cards key on a specific status and already
        // excluded it.
        $notCancelled = fn () => Commission::where('status', '!=', 'cancelled');

        $stats = [
            'total' => (float) $notCancelled()->sum('amount'),
            // "Pending" here means genuinely approvable — held income is its
            // own figure below, since an accountant clicking Approve All
            // cannot move it (see creditApproved()'s finance-status guard).
            'pending' => (float) Commission::where('status', 'pending')->whereNull('held_at')->sum('amount'),
            'approved' => (float) Commission::where('status', 'approved')->sum('amount'),
            'paid' => (float) Commission::where('status', 'paid')->sum('amount'),
            'count_pending' => Commission::where('status', 'pending')->whereNull('held_at')->count(),
            'held' => (float) $notCancelled()->whereNotNull('held_at')->sum('amount'),
            'count_held' => $notCancelled()->whereNotNull('held_at')->count(),
            'company_revenue' => (float) CompanyRevenue::sum('amount'),
        ];

        $settings = CommissionSetting::current();

        $incomeGroups = $this->recentIncomeByConsultant();

        $topConsultants = Commission::query()
            // Same exclusion as the cards above: money a System Run forfeited
            // to the company was never this consultant's, and money an admin
            // removed is not theirs any more. Ranking on it would put someone
            // at the top of "Top earners" for income they do not have.
            ->where('status', '!=', 'cancelled')
            ->selectRaw('consultant_id, SUM(amount) as total')
            ->with(['consultant.user', 'consultant.owner.user'])
            ->groupBy('consultant_id')
            ->orderByDesc('total')
            ->take(5)
            ->get();

        return view('dashboard.commissions.index', compact('stats', 'settings', 'incomeGroups', 'topConsultants'));
    }

    public function history(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        // ⚠️ A REMOVED ROW IS NOT PART OF THE SUMMARY (2026-09-10).
        //
        // Owner: "Service Income Dashboard eken commission eka remove kraham...
        // kohewath penwann ba." This page had no exclusion at all, so a removed
        // row still appeared in the table, was selectable from the status
        // filter, went into the CSV export, and — the part that showed real
        // money — was counted by $totals['count'] below, which is a COUNT(*)
        // and so does not care that the amount was zeroed.
        //
        // ⚠️ REMOVED, NOT CANCELLED. A row a SYSTEM RUN forfeited is also
        // 'cancelled', and that money genuinely went to the company: it must
        // stay visible and auditable here. removed_at is what tells the two
        // apart (Commission::wasRemovedByHand()). Guarded because the column
        // does not exist until the schema has healed, and on such a host
        // nothing has ever been removed.
        $query = Commission::query()
            ->when(
                \App\Services\FinanceSchemaRepair::removalReady(),
                fn ($q) => $q->whereNull('removed_at'),
            )
            // 'payment' keeps the sourceConsultant() chain below loadable.
            // 'payment.consultant.user' / '.owner.user' are needed by
            // Commission::sourceConsultant() — the row for the downline
            // consultant who actually registered the student, shown so an
            // ancestor's income can be traced to WHO earned it, not just
            // which account it landed on.
            ->with([
                'consultant.user', 'consultant.owner.user', 'student', 'group', 'groupMember', 'payment',
                'payment.consultant.user', 'payment.consultant.owner.user',
            ])
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = $request->string('status')->toString();

                // 'held' is a display-only status — the row is really still
                // 'pending' underneath (see Commission::display_status).
                return $status === 'held'
                    ? $q->where('status', 'pending')->whereNotNull('held_at')
                    : $q->where('status', $status)->whereNull('held_at');
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            // ⚠️ Ordered by the income date, not by insert order (2026-09-10).
            // A payslip from the 1st verified on the 8th used to jump to the
            // top of the list above income that genuinely arrived later.
            ->orderByRaw(\App\Services\FinanceSchemaRepair::incomeDateSql().' desc')
            ->orderByDesc('id');

        // Matched against the day the money ARRIVED — the "Date" column on
        // screen — not the day an accountant verified it, and not when it was
        // later approved or paid. See Commission::incomeDate().
        //
        // ⚠️ Not applyDateRange(): that takes a column NAME, and this is an
        // expression. DATE() keeps both ends inclusive exactly as whereDate
        // does, and is valid on MySQL and SQLite alike.
        $dateSql = 'DATE('.\App\Services\FinanceSchemaRepair::incomeDateSql().')';

        $query->when($from, fn ($q) => $q->whereRaw($dateSql.' >= ?', [$from->toDateString()]))
            ->when($to, fn ($q) => $q->whereRaw($dateSql.' <= ?', [$to->toDateString()]));

        // Totals for whatever the filter currently selects, so the figure on
        // screen always describes the rows on screen.
        $totals = [
            'count' => (clone $query)->count(),
            'amount' => (float) (clone $query)->sum('amount'),
        ];

        if ($request->input('export') === 'csv') {
            return $this->csvResponse(
                'service-income-'.now()->format('Y-m-d').'.csv',
                ['Consultant', 'Student', 'Service Group', 'Type', 'Tier', 'Amount', 'Status', 'Date'],
                $query->get()->map(fn ($c) => [
                    $c->consultant?->display_name ?? '—', $c->student->name ?? '—',
                    $c->group->group_code ?? '—', $c->type, $c->tier,
                    $c->amount, $c->display_status, optional($c->incomeDate())->toDateString(),
                ]),
            );
        }

        $commissions = $query->paginate(20)->withQueryString();

        // Same "is this actually approvable yet" flag as the main Service
        // Income dashboard (recentIncomeByConsultant()) — without it this
        // page offered an "Approve" button on every pending row regardless
        // of staging, which then failed with an honest error when clicked
        // (see approve()'s docblock). Cheap here: at most 20 rows/page.
        foreach ($commissions as $c) {
            if ($c->status === 'pending' && ! $c->held_at && $c->type !== 'referral') {
                $c->is_released = $this->slotIsReleased($c);
            }
        }

        return view('dashboard.commissions.history', compact('commissions', 'totals', 'from', 'to'));
    }

    /**
     * Accountant approves ONE pending commission.
     *
     * The staged pair rule is deliberately overridable here (owner-confirmed
     * 2026-08-12): the queue used to replace the button with a dead "Not yet
     * balanced" label, which left money with no way out whenever a leg could
     * never fill. A single-row approve is now always offered and always
     * honoured — the screen warns that the stage has not balanced, and the
     * accountant decides. The hold / finance-status gate is NOT overridable
     * and still stops a restricted consultant's money (see CommissionApproval).
     *
     * Bulk actions keep respecting the balance: approveAll(),
     * approveAllForConsultant() and approveStage() all approve unforced, so
     * "approve everything" can never quietly release a half-finished stage.
     */
    /**
     * Every action below is a thin HTTP wrapper. The decisions — which gates
     * apply, what actually moved, what to say about it — live in
     * ManagesServiceIncome, because App\Livewire\ServiceIncomeBoard performs
     * the same actions without a redirect and the two must never drift.
     */
    public function approve(Commission $commission)
    {
        return $this->flash($this->approveOne($commission));
    }

    public function approveAll()
    {
        return $this->flash($this->approveEverything());
    }

    public function approveAllForConsultant(\App\Models\Consultant $consultant)
    {
        return $this->flash($this->approveAllOwnedBy($consultant));
    }

    public function approveStage(\App\Models\Consultant $consultant)
    {
        return $this->flash($this->approveBalancedStage($consultant));
    }

    public function markPaid(Commission $commission)
    {
        return $this->flash($this->payOne($commission));
    }

    /**
     * A result array from ManagesServiceIncome, as a redirect back with a
     * flash message. `level` decides which of the two banners the layout
     * renders — an 'error' here is a refusal (held income, missing group),
     * never a crash.
     */
    protected function flash(array $result)
    {
        return back()->with($result['level'] === 'error' ? 'error' : 'success', $result['message']);
    }

    public function revenue(Request $request)
    {
        $revenues = CompanyRevenue::query()
            ->with(['payment', 'course'])
            ->when($request->filled('source'), fn ($q) => $q->where('source_type', $request->string('source')))
            ->latest('recorded_at')
            ->paginate(20)
            ->withQueryString();

        $total = (float) CompanyRevenue::sum('amount');
        $bySource = CompanyRevenue::selectRaw('source_type, SUM(amount) as total')->groupBy('source_type')->pluck('total', 'source_type');

        return view('dashboard.commissions.revenue', compact('revenues', 'total', 'bySource'));
    }
}
