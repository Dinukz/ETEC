<x-layouts.app title="Accountant Dashboard" header="Accountant Dashboard">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Finance Overview</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Verify payments, manage service income, and track revenue.</p>
    </div>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Pending Payments" :value="number_format($stats['pending_payments'])" color="rose" hint="Action needed" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Verified Total" :value="'LKR ' . number_format($stats['total_verified'])" color="emerald" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Service Income Payable" :value="'LKR ' . number_format($stats['commissions_payable'])" color="amber" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
        <x-stat-card label="Company Revenue" :value="'LKR ' . number_format($stats['company_revenue'])" color="brand" icon="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- Pending payments --}}
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-100 p-5 dark:border-slate-800">
                <h3 class="font-bold text-slate-900 dark:text-white">Payments to verify</h3>
                <span class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">{{ $stats['pending_payments'] }} pending</span>
            </div>
            @if ($pendingPayments->isEmpty())
                <div class="p-10 text-center">
                    <svg class="mx-auto h-10 w-10 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">All caught up — no pending payments.</p>
                </div>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($pendingPayments as $p)
                        <li class="flex items-center justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $p->user->name ?? 'Unknown' }}</div>
                                <div class="truncate text-xs text-slate-400">{{ $p->course->title ?? '—' }} · {{ $p->payment_code }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-bold text-slate-900 dark:text-white">LKR {{ number_format($p->amount) }}</div>
                                <div class="text-xs text-slate-400">{{ optional($p->paid_at)->format('M d') }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Payable commissions --}}
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-100 p-5 dark:border-slate-800">
                <h3 class="font-bold text-slate-900 dark:text-white">Service Income to pay</h3>
                <span class="badge bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">{{ $stats['pending_commissions'] }} queued</span>
            </div>
            @if ($payableCommissions->isEmpty())
                <div class="p-10 text-center">
                    <p class="text-sm text-slate-500 dark:text-slate-400">No service income awaiting payout.</p>
                </div>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($payableCommissions as $c)
                        <li class="flex items-center justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $c->consultant?->display_name ?? 'Consultant' }}</div>
                                <div class="truncate text-xs text-slate-400">{{ ucfirst($c->type) }} · Tier {{ $c->tier ?? '—' }}</div>
                            </div>
                            <div class="text-sm font-bold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($c->amount) }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{-- Summary strip --}}
    <div class="mt-6 grid gap-5 sm:grid-cols-3">
        <x-stat-card label="Verified Today" :value="number_format($stats['verified_today'])" color="emerald" icon="M5 13l4 4L19 7" />
        <x-stat-card label="Service Income Paid" :value="'LKR ' . number_format($stats['commissions_paid'])" color="sky" icon="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z" />
        <x-stat-card label="Rejected" :value="number_format($stats['rejected'])" color="rose" icon="M6 18L18 6M6 6l12 12" />
    </div>
</x-layouts.app>
