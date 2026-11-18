<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\ConsultantFinancePeriod;
use App\Models\User;
use App\Services\ConsultantFinanceService;
use App\Support\FinanceWeek;
use Illuminate\Http\Request;

/**
 * Who met the weekly student quota, who didn't, and who is restricted or
 * suspended. Dual-audience like ReportController's admissions/payments
 * reports: staff (admin/accountant) see every consultant, a consultant sees
 * only their own row and cannot widen that with a query parameter.
 */
class FinanceReportController extends Controller
{
    use ExportsCsv;

    public function __construct(protected ConsultantFinanceService $finance)
    {
    }

    public function index(Request $request)
    {
        // Every other entry point this feature has (PaymentService::verify(),
        // StudentController::store(), SettingController::updateCommission(),
        // ...) runs CommissionSchemaRepair::ensure() first, so by the time an
        // admin gets here the schema is normally already there. But this page
        // can be the very FIRST thing anyone opens on a fresh deploy — before
        // any payment has ever been verified — so it needs its own guard too.
        // Safe here: plain GET, no open transaction (see FinanceSchemaRepair's
        // own docblock for why that ordering matters).
        try {
            \App\Services\CommissionSchemaRepair::ensure();
        } catch (\Throwable $e) {
            report($e);
        }

        $viewerConsultant = $this->viewerConsultant();
        $periods = FinanceWeek::lastN(max(1, min(52, (int) $request->input('periods', 8))));
        $current = $periods[0];

        $selected = $request->filled('period')
            ? FinanceWeek::fromKey($request->string('period')->toString())
            : $current;

        $rows = $viewerConsultant
            ? $this->rowsForOne($viewerConsultant, $periods)
            : $this->rowsForAll($selected, $request);

        $header = ['SCN / Code', 'Account Name', 'Period', 'Required', 'Completed', 'Remaining', 'Status'];

        $exportRows = fn () => collect($rows)->map(fn ($r) => [
            $r['code'], $r['name'], $r['period']->label(),
            $r['required'], $r['completed'], $r['remaining'], ucfirst($r['status']),
        ]);

        if ($request->input('export') === 'csv') {
            return $this->csvResponse("finance-report-{$selected->key()}.csv", $header, $exportRows());
        }

        if ($request->input('export') === 'pdf') {
            return view('dashboard.reports.print', [
                'title' => 'Finance Week Report',
                'subtitle' => $selected->label().($viewerConsultant ? ' · '.$viewerConsultant->display_name : ''),
                'headers' => $header,
                'rows' => $exportRows(),
                'csvUrl' => $request->fullUrlWithQuery(['export' => 'csv']),
            ]);
        }

        // Staff-only lookup data — not even built for a consultant viewer.
        // ->with('owner.user') because display_name falls back to the owner's
        // name for any "real" consultant that somehow has no linked user row
        // (Model::preventLazyLoading() throws instead of silently N+1ing).
        $consultants = $viewerConsultant ? collect() : Consultant::real()->whereNotNull('scn_code')->with(['user', 'owner.user'])->orderBy('scn_code')->get();

        $config = FinanceWeek::config();

        return view('dashboard.reports.finance', compact(
            'rows', 'periods', 'selected', 'current', 'viewerConsultant', 'consultants', 'config',
        ));
    }

    /** Every real consultant's standing for ONE period — the staff view. */
    protected function rowsForAll(\App\Support\FinancePeriod $period, Request $request): array
    {
        // ->with('owner.user') — same reason as index()'s lookup query above:
        // display_name falls back to the owner's name, so it must be
        // eager-loaded or a "real" consultant with no linked user throws.
        $consultants = Consultant::real()->with(['user', 'owner.user'])->orderBy('scn_code')->get();

        // Closed periods prefer the frozen ledger snapshot (fast, one query,
        // and its students_required is what was actually in force at the
        // time — a later settings change cannot retroactively alter it).
        // The still-open current period has no ledger row yet, so it always
        // falls back to a live count.
        $ledger = ConsultantFinancePeriod::where('period_start', $period->start->toDateString())
            ->get()
            ->keyBy('consultant_id');

        $liveCounts = $ledger->isEmpty() ? $this->finance->countAllFor($period) : [];
        $config = FinanceWeek::config();

        $rows = $consultants->map(function (Consultant $c) use ($period, $ledger, $liveCounts, $config) {
            $frozen = $ledger[$c->id] ?? null;
            $completed = $frozen ? (int) $frozen->students_completed : (int) ($liveCounts[$c->id] ?? 0);
            $required = $frozen ? (int) $frozen->students_required : $config['quota'];

            return [
                'consultant' => $c,
                'code' => $c->scn_code ?? $c->consultant_code,
                'name' => $c->display_name,
                'period' => $period,
                'required' => $required,
                'completed' => $completed,
                'remaining' => max(0, $required - $completed),
                'met' => $completed >= $required,
                // While the requirement is switched off nobody IS restricted,
                // so the live badge must not claim otherwise. Frozen history
                // below is left exactly as it was recorded.
                'status' => FinanceWeek::quotaEnabled() ? ($c->finance_status ?? 'active') : 'active',
            ];
        });

        if ($request->filled('status')) {
            $rows = $rows->where('status', $request->string('status')->toString());
        }

        if ($request->input('filter') === 'completed') {
            $rows = $rows->where('met', true);
        } elseif ($request->input('filter') === 'not_completed') {
            $rows = $rows->where('met', false);
        }

        if ($request->filled('consultant')) {
            $rows = $rows->where('consultant.id', (int) $request->input('consultant'));
        }

        return $rows->values()->all();
    }

    /** One consultant's own history across N periods — the consultant view. */
    protected function rowsForOne(Consultant $consultant, array $periods): array
    {
        $config = FinanceWeek::config();

        $ledger = ConsultantFinancePeriod::where('consultant_id', $consultant->id)
            ->whereIn('period_start', collect($periods)->map(fn ($p) => $p->start->toDateString()))
            ->get()
            ->keyBy(fn ($row) => $row->period_start->toDateString());

        return collect($periods)->map(function ($period) use ($consultant, $ledger, $config) {
            $frozen = $ledger[$period->key()] ?? null;
            $isOpen = $period->key() === FinanceWeek::current()->key();

            $completed = $frozen
                ? (int) $frozen->students_completed
                : ($isOpen ? $this->finance->countFor($consultant, $period) : 0);
            $required = $frozen ? (int) $frozen->students_required : $config['quota'];

            return [
                'consultant' => $consultant,
                'code' => $consultant->scn_code ?? $consultant->consultant_code,
                'name' => $consultant->display_name,
                'period' => $period,
                'required' => $required,
                'completed' => $completed,
                'remaining' => max(0, $required - $completed),
                'met' => $completed >= $required,
                // Only the OPEN period reflects the off switch — a closed week's
                // frozen outcome is history and is never rewritten.
                'status' => $isOpen
                    ? (FinanceWeek::quotaEnabled() ? ($consultant->finance_status ?? 'active') : 'active')
                    : ($frozen->outcome ?? '—'),
            ];
        })->all();
    }

    /** Re-evaluate every consultant right now — the lever an admin has since there is no cron. */
    public function evaluateAll()
    {
        try {
            \App\Services\CommissionSchemaRepair::ensure();
        } catch (\Throwable $e) {
            report($e);
        }

        $count = $this->finance->evaluateAll();

        return back()->with('success', "Re-evaluated {$count} consultant(s).");
    }

    /** The only way back from 'suspended'. */
    public function reactivate(Consultant $consultant)
    {
        // display_name needs ->user (or ->owner->user for a hidden account) —
        // route-model binding doesn't eager-load either, so load them now
        // rather than lazily.
        $consultant->loadMissing(['user', 'owner.user']);

        $this->finance->reactivate($consultant, auth()->user());

        return back()->with('success', $consultant->owningPerson()->display_name.' has been reactivated.');
    }

    /** A consultant only ever sees their own row — staff (admin/accountant) see everyone. Mirrors ReportController. */
    protected function viewerConsultant(): ?Consultant
    {
        /** @var User $user */
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
}
