<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Group;
use App\Models\Student;
use App\Services\ConsultantFinanceService;
use App\Support\FinanceWeek;

class ConsultantDashboardController extends Controller
{
    public function __construct(protected ConsultantFinanceService $financeService)
    {
    }

    public function __invoke()
    {
        $user = auth()->user();
        $consultant = $user->consultant;

        // A consultant profile should always exist, but guard defensively.
        if (! $consultant) {
            return view('dashboard.consultant.index', [
                'consultant' => null,
                'stats' => [],
                'finance' => null,
            ]);
        }

        // Roll totals up across the consultant's own account and their hidden accounts.
        $accountIds = $consultant->accountIds();

        // "Total Earned" / "Total Paid" on THIS dashboard are deliberately
        // narrower than consultants.total_earned/total_paid (the full figure,
        // used everywhere else — the Service Groups page, the admin queue —
        // where it is correct as-is).
        //
        // The owner's rule for this card: it may only show whole releases and
        // direct bonuses — "1 to 1, 2 to 2, 3 to 3 e tiaki direct service ekai
        // add unanm athi... 5000, 10000, 15000 me tika add unanm athi". A flush
        // always releases the same amount from BOTH legs, so a lone 2,500 here
        // could only have been approved outside one.
        //
        // ONE filter does that: slot income counts once a flush stamped
        // `released_at`. The direct-add bonus is never flushed, so it is exempt
        // and always counts.
        //
        // ⚠️ The separate isUplineCredit() rejection that used to sit here was
        // REMOVED on 2026-08-14. It existed because downline income was then
        // auto-approved at verification without ever passing through a flush,
        // so it could never be part of a clean 5,000/10,000/15,000. Downline
        // income now accumulates on the ancestor's legs and settles in a System
        // Run like everything else, so it arrives exactly the way the owner
        // said this card should show — "group balance wela ena ewa withri" —
        // and excluding it would now understate a release the consultant really
        // did earn.
        //
        // ⚠️ THE CALCULATION LIVES IN App\Services\SettledIncome (2026-09-13), so
        // Service Income's top figures are these exact numbers instead of a
        // second sum of consultants.total_earned that read higher.
        $settled = \App\Services\SettledIncome::totals($accountIds);

        $stats = [
            'total_earned' => $settled['earned'],
            'total_paid' => $settled['paid'],
            'pending_balance' => $settled['pending'],
            'total_students' => Student::whereIn('consultant_id', $accountIds)->count(),
            'active_groups' => Group::whereIn('consultant_id', $accountIds)->where('status', 'open')->count(),
            'completed_groups' => Group::whereIn('consultant_id', $accountIds)->where('status', 'completed')->count(),
            'status' => $consultant->status,
        ];

        // Purely informational — this dashboard never evaluates or writes
        // finance state itself, that is EnforceConsultantFinanceStatus's job
        // on every request. Only shown once the schema exists.
        $finance = null;

        if (\App\Services\FinanceSchemaRepair::isReady() && $consultant->isFinanceRestricted()) {
            $config = FinanceWeek::config();
            $current = FinanceWeek::current();

            // Reuses the exact same counting rule the state machine itself
            // uses (verified-payment filter, hidden-account rollup) — a
            // hand-rolled duplicate here could show a different number than
            // what actually releases the restriction.
            $completed = min($config['quota'], $this->financeService->countFor($consultant, $current));

            $finance = [
                'quota' => $config['quota'],
                'completed' => $completed,
                'remaining' => max(0, $config['quota'] - $completed),
                'period' => $current,
                // Cancelled excluded (2026-09-06): a removed or forfeited row
                // that happened to be held still counted here, so the
                // consultant kept seeing money that had already left.
                'held' => (float) Commission::whereIn('consultant_id', $accountIds)
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('held_at')->sum('amount'),
            ];
        }

        return view('dashboard.consultant.index', compact('consultant', 'stats', 'finance'));
    }
}
