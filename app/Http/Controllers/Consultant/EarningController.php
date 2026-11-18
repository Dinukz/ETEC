<?php

namespace App\Http\Controllers\Consultant;

use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Consultant;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EarningController extends Controller
{
    use ExportsCsv;

    /** Ledger rows shown per page. */
    private const PER_PAGE = 25;

    protected function consultant()
    {
        $consultant = auth()->user()->consultant;
        abort_if(! $consultant, 403, 'No consultant profile found for your account.');

        return $consultant;
    }

    public function index(Request $request)
    {
        $consultant = $this->consultant();
        $accountIds = $consultant->accountIds();

        // Grand totals across every account the consultant owns — these stay
        // put no matter which single account is being inspected below.
        //
        // ⚠️ THE DASHBOARD'S FIGURES, NOT consultants.total_earned (2026-09-13).
        // Those columns count every approved row, service group income still
        // waiting on the legs for a System Run included, so "Total Income" read
        // above the Dashboard's "Total Earned" for the same person. See
        // App\Services\SettledIncome.
        $totals = \App\Services\SettledIncome::totals($accountIds);

        // Each SCN account is inspected on its own — a consultant holds three
        // and their income is kept separately per account.
        $accounts = Consultant::whereIn('id', $accountIds)->orderBy('id')->get();

        $selectedId = $request->integer('account');

        if (! in_array($selectedId, $accountIds, true)) {
            $selectedId = $consultant->id;
        }

        $selected = $accounts->firstWhere('id', $selectedId) ?? $consultant;

        // Which account each one was placed under, so the cards say how they
        // relate instead of listing bare SCN codes (2026-08-22).
        $placements = app(\App\Services\AccountPlacementMap::class)->for($consultant);

        $accountCards = $accounts->map(fn ($a) => [
            'id' => $a->id,
            'code' => $a->scn_code ?? $a->consultant_code,
            'is_owner' => $a->id === $consultant->id,
            'selected' => $a->id === $selectedId,
            'earned' => (float) $a->total_earned,
            'placement' => $placements[$a->id] ?? null,
        ])->sortByDesc('is_owner')->values();

        // ⚠️ THE MAIN TAB IS A ROLLUP (2026-08-22).
        //
        // Since upline income moved onto the PLACEMENT accounts (2026-08-21) a
        // consultant's main account routinely holds nothing of its own, so
        // selecting it showed Total Income / Payout / Pending all as 0 while
        // the summary above the tabs read 26,500 — reported as "methana total
        // income, payout, pending okkom 0 widiyt thiyenne, dashboard eke wage
        // ammount tika methanat enn one".
        //
        // Selecting Main now reports the PERSON's combined position across
        // every account they own, which is the same convention the Service
        // Groups page already uses for its Main row, and makes these figures
        // agree with the headline instead of contradicting it. A placement
        // account still reports only its own.
        $isMainSelected = $selectedId === $consultant->id;
        $scopeIds = $isMainSelected ? $accountIds : [$selectedId];

        // The same calculation as the grand totals above, for this scope.
        $accountTotals = \App\Services\SettledIncome::totals($scopeIds);

        // Income kinds for the selected scope, counting only what has been
        // approved — a pending record is not the consultant's money yet.
        $approved = fn () => Commission::whereIn('consultant_id', $scopeIds)->whereIn('status', ['approved', 'paid']);

        // "Awaiting" and "On hold" are kept apart: awaiting money just needs an
        // accountant's click, held money needs the consultant to meet their
        // weekly quota again first — very different next steps for the reader.
        $pendingForAccount = Commission::whereIn('consultant_id', $scopeIds)->where('status', 'pending');

        $stats = [
            // ⚠️ SERVICE GROUP INCOME COUNTS ONLY WHAT A SYSTEM RUN RELEASED (2026-09-14).
            //
            // This summed every APPROVED slot row, so a 2,500 showed here the
            // moment it was approved — while the "Total Earned" figure above (and
            // the consultant Dashboard) count a slot only once a System Run has
            // released it. The two disagreed, and the owner reported the leak:
            // "dashboard ekai service income ekai normal 2500 ... add weno,
            // ethanat add wenn one system run eken out wen gan withri". Gating on
            // released_at is exactly App\Services\SettledIncome's rule, so this
            // card, the Total Earned figure and the Dashboard now agree. The
            // accruing (unreleased) slot is still shown on the Service Groups
            // page as the leg balance — it is not hidden, only kept off the
            // "earned" figure until a run makes it payable.
            'slot_income' => (float) $approved()->where('type', 'bonus')->whereNotNull('released_at')->sum('amount'),
            'direct_income' => (float) $approved()->where('type', 'referral')->sum('amount'),
            'awaiting' => (float) (clone $pendingForAccount)->whereNull('held_at')->sum('amount'),
            'on_hold' => (float) (clone $pendingForAccount)->whereNotNull('held_at')->sum('amount'),
        ];

        // The statement covers the same accounts as the cards. It lists every
        // APPROVED row, so since 2026-09-13 its balance can sit above "Pending
        // balance" by the approved service group income still waiting on the
        // legs for a System Run — see App\Services\SettledIncome.
        $ledger = $this->ledger($selected, $scopeIds);

        if (in_array($request->input('export'), ['csv', 'pdf'], true)) {
            return $this->export($request, $selected, $ledger, $accountTotals);
        }

        return view('consultant.earnings.index', [
            'consultant' => $consultant,
            'totals' => $totals,
            'accountCards' => $accountCards,
            'selected' => $selected,
            'accountTotals' => $accountTotals,
            'stats' => $stats,
            'ledger' => $this->paginate($ledger, $request),
        ]);
    }

    /**
     * A running-balance statement for one account.
     *
     * Every approved commission is a CREDIT on the day it was approved (that
     * is when it became the consultant's money), and every commission that has
     * since been paid out adds a matching DEBIT on the day it was released.
     * The balance is therefore what the company still owes this account, which
     * is exactly `total_earned - total_paid`.
     *
     * @return Collection<int, array<string, mixed>>
     */
    /**
     * @param  array<int>|null  $scopeIds  Accounts to include. Defaults to just
     *                                     $account; the Main tab passes the
     *                                     person's whole set so the statement
     *                                     matches the rolled-up cards above it.
     */
    protected function ledger(Consultant $account, ?array $scopeIds = null): Collection
    {
        $commissions = Commission::whereIn('consultant_id', $scopeIds ?? [$account->id])
            ->whereIn('status', ['approved', 'paid'])
            ->with(['student.student', 'student.consultant', 'group'])
            ->get();

        $rows = collect();

        foreach ($commissions as $c) {
            $srn = $c->student?->displayCode() ?? $c->student?->name ?? '—';

            $rows->push([
                'at' => $c->approved_at ?? $c->created_at,
                'description' => $c->type === 'referral'
                    ? "Direct Add Bonus [{$srn}]"
                    : 'Service Group Income [GROUP 0'.($c->tier ?? 1).": {$srn}]",
                'credit' => (float) $c->amount,
                'debit' => 0.0,
            ]);

            if ($c->status === 'paid') {
                $bank = $account->bank_account_number;

                $rows->push([
                    'at' => $c->paid_at ?? $c->approved_at ?? $c->created_at,
                    'description' => $bank
                        ? "Service income payout to Bank Account Number {$bank}"
                        : 'Service income payout',
                    'credit' => 0.0,
                    'debit' => (float) $c->amount,
                ]);
            }
        }

        // Accumulate oldest-first so each row carries the balance AS AT that
        // line, then hand back newest-first, the way a statement reads.
        $running = 0.0;

        return $rows
            ->sortBy(fn ($r) => optional($r['at'])->timestamp ?? 0)
            ->values()
            ->map(function ($row) use (&$running) {
                $running += $row['credit'] - $row['debit'];
                $row['balance'] = $running;

                return $row;
            })
            ->reverse()
            ->values();
    }

    /** The ledger is built in memory (it needs a running balance), so page it here. */
    protected function paginate(Collection $ledger, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input('page', 1));

        return new LengthAwarePaginator(
            $ledger->forPage($page, self::PER_PAGE)->values(),
            $ledger->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    /** The statement as a CSV or a print/save-as-PDF sheet. */
    protected function export(Request $request, Consultant $account, Collection $ledger, array $accountTotals)
    {
        $header = ['Date', 'Description', 'Credit (LKR)', 'Debit (LKR)', 'Balance (LKR)'];

        $rows = $ledger->map(fn ($r) => [
            optional($r['at'])->format('Y-m-d'),
            $r['description'],
            number_format($r['credit'], 2, '.', ''),
            number_format($r['debit'], 2, '.', ''),
            number_format($r['balance'], 2, '.', ''),
        ]);

        $code = $account->scn_code ?? $account->consultant_code;
        $stamp = now()->format('Y-m-d');

        if ($request->input('export') === 'csv') {
            return $this->csvResponse("service-income-{$code}-{$stamp}.csv", $header, $rows);
        }

        return view('dashboard.reports.print', [
            'title' => "Service Income Statement — {$code}",
            'subtitle' => 'Total earned LKR '.number_format($accountTotals['earned'], 2)
                .' · Payout LKR '.number_format($accountTotals['paid'], 2)
                .' · Pending balance LKR '.number_format($accountTotals['pending'], 2)
                .' · Generated '.$stamp,
            'headers' => $header,
            'rows' => $rows,
            'csvUrl' => $request->fullUrlWithQuery(['export' => 'csv']),
        ]);
    }
}
