<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Payment;

class AccountantDashboardController extends Controller
{
    public function __invoke()
    {
        $stats = [
            'pending_payments' => Payment::where('status', 'pending')->count(),
            'verified_today' => Payment::where('status', 'verified')->whereDate('verified_at', today())->count(),
            'total_verified' => (float) Payment::where('status', 'verified')->sum('amount'),
            'pending_commissions' => Commission::whereIn('status', ['pending', 'approved'])->count(),
            'commissions_payable' => (float) Commission::where('status', 'approved')->sum('amount'),
            'commissions_paid' => (float) Commission::where('status', 'paid')->sum('amount'),
            'company_revenue' => (float) CompanyRevenue::sum('amount'),
            'rejected' => Payment::where('status', 'rejected')->count(),
        ];

        $pendingPayments = Payment::with(['user', 'course'])
            ->where('status', 'pending')
            ->latest('paid_at')
            ->take(8)
            ->get();

        // Approved money whose owner has since been restricted/suspended stays
        // 'approved' (never retro-unapproved — see ConsultantFinanceService)
        // but markPaid() will silently refuse it, so it is filtered out here
        // rather than shown as an actionable row that dead-ends on click.
        // Over-fetch before filtering so the widget still has ~6 rows.
        $payableCommissions = Commission::with(['consultant.user', 'consultant.owner.user'])
            ->where('status', 'approved')
            ->latest()
            ->take(30)
            ->get()
            ->filter(fn ($c) => $c->consultant?->owningPerson()?->isFinanceActive() ?? true)
            ->take(6)
            ->values();

        return view('dashboard.accountant.index', compact('stats', 'pendingPayments', 'payableCommissions'));
    }
}
