<x-layouts.app title="Service Income" header="Service Income Dashboard">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Service Income Overview</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Track consultant service income, payouts, and company revenue.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('commissions.history') }}" class="btn-secondary">Service Income Summary</a>
            <a href="{{ route('commissions.revenue') }}" class="btn-secondary">Company Revenue</a>
        </div>
    </div>

    @if ($stats['count_pending'] > 0)
        {{-- Under the current rule, normal service income auto-approves when a
             payment is verified. Rows left pending are exceptions: held income,
             imported/manual rows, or repair/backfill output that still needs a
             human check. --}}
        <div class="card mb-6 flex flex-wrap items-center justify-between gap-4 border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/10">
            <div>
                <p class="font-bold text-amber-800 dark:text-amber-300">
                    {{ $stats['count_pending'] }} service income exception(s) pending review — LKR {{ number_format($stats['pending']) }}
                </p>
                <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                    Normal 2,500 slot income and direct bonus auto-approve at payment verification. These rows need attention because they did not.
                </p>
            </div>
            <form method="POST" action="{{ route('commissions.approve-all') }}"
                onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Approving…';">
                @csrf @method('PATCH')
                <button type="submit" class="btn-primary">Approve All</button>
            </form>
        </div>
    @endif

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat-card label="Total Service Income" :value="'LKR ' . number_format($stats['total'])" color="brand" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
        <x-stat-card label="Pending Review" :value="'LKR ' . number_format($stats['pending'])" color="amber" :hint="$stats['count_pending'] . ' items'" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        {{-- Held income (weekly quota missed) is kept apart from "Pending" —
             Approve All cannot move it, so counting it as pending would
             overstate what that button can actually do. --}}
        <x-stat-card label="On Hold" :value="'LKR ' . number_format($stats['held'])" color="rose" :hint="$stats['count_held'] . ' items'" icon="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
        <x-stat-card label="Paid Out" :value="'LKR ' . number_format($stats['paid'])" color="emerald" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Company Revenue" :value="'LKR ' . number_format($stats['company_revenue'])" color="violet" icon="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
    </div>

    {{-- Active settings summary --}}
    <div class="card mt-6 flex flex-wrap items-center justify-between gap-4 bg-gradient-to-r from-brand-50 to-transparent p-5 dark:from-brand-500/10">
        <div class="flex flex-wrap gap-6 text-sm">
            <div><span class="text-slate-400">Per student:</span> <span class="font-bold text-slate-900 dark:text-white">LKR {{ number_format($settings->commission_per_student) }}</span></div>
            <div><span class="text-slate-400">Max / group:</span> <span class="font-bold text-slate-900 dark:text-white">LKR {{ number_format($settings->max_commission) }}</span></div>
            <div><span class="text-slate-400">Group size:</span> <span class="font-bold text-slate-900 dark:text-white">{{ $settings->group_size }}</span></div>
        </div>
        @can('settings.manage')
            <a href="{{ route('settings.index') }}" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">Edit settings →</a>
        @endcan
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        {{-- Recent Service Income — a Livewire component so approving or paying
             a row updates in place instead of reloading the page and collapsing
             every dropdown. See App\Livewire\ServiceIncomeBoard. --}}
        <livewire:service-income-board />


        {{-- Top consultants --}}
        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-5 dark:border-slate-800"><h3 class="font-bold text-slate-900 dark:text-white">Top earners</h3></div>
            @if ($topConsultants->isEmpty())
                <p class="p-10 text-center text-sm text-slate-500 dark:text-slate-400">No data yet.</p>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($topConsultants as $i => $tc)
                        <li class="flex items-center gap-3 p-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-bold text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">{{ $i + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $tc->consultant?->display_name ?? 'Consultant' }}</div>
                            </div>
                            <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($tc->total) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-layouts.app>
