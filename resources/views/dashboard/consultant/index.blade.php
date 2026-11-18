<x-layouts.app title="Dashboard" header="Dashboard">
    @if (! $consultant)
        <div class="card p-12 text-center">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Consultant profile not found</h3>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Please contact an administrator to set up your consultant profile.</p>
        </div>
    @else
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Welcome, {{ explode(' ', auth()->user()->name)[0] }} 👋</h2>
            </div>
            @php $statusBadge = ['active' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'pending' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'suspended' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400'][$stats['status']] ?? 'bg-slate-100 text-slate-600'; @endphp
            <span class="badge {{ $statusBadge }} px-3 py-1.5">Status: {{ ucfirst($stats['status']) }}</span>
        </div>

        @if ($finance)
            <div class="card mb-6 border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/10">
                <p class="font-bold text-amber-800 dark:text-amber-300">
                    Your account is restricted — {{ $finance['remaining'] }} more student(s) needed this finance week
                </p>
                <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                    {{ $finance['completed'] }} of {{ $finance['quota'] }} registered so far this week ({{ $finance['period']->label() }}).
                    @if ($finance['held'] > 0)
                        LKR {{ number_format($finance['held']) }} of your service income is on hold and will be released as soon as you meet the quota.
                    @endif
                </p>
                <a href="{{ route('consultant.students.create') }}" class="btn-primary mt-3 inline-block">Register a student now</a>
            </div>
        @endif

        {{-- Identity banner --}}
        <div class="card mb-6 overflow-hidden bg-gradient-to-br from-brand-700 to-brand-950 p-6 text-white lg:p-8">
            <div class="flex flex-wrap items-center justify-between gap-6">
                <div class="flex flex-wrap gap-8">
                    <div>
                        <p class="text-sm text-white/70">Consultant Code</p>
                        <span class="mt-2 inline-block rounded-xl bg-white/10 px-4 py-2 font-mono text-2xl font-bold tracking-widest backdrop-blur">{{ $consultant->consultant_code }}</span>
                    </div>
                    <div>
                        <p class="text-sm text-white/70">SCN Code</p>
                        <span class="mt-2 inline-block rounded-xl bg-white/10 px-4 py-2 font-mono text-2xl font-bold tracking-widest backdrop-blur">{{ $consultant->scn_code ?? 'Not assigned yet' }}</span>
                    </div>
                </div>
                {{-- "Pending balance" removed for every consultant (2026-09-22):
                     "Consultant la hamogenma Dashboard eke pending income eka hide
                     krla denn". The controller still computes it; nothing shows it. --}}
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Total Earned" :value="'LKR ' . number_format($stats['total_earned'])" color="emerald" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
            <x-stat-card label="Total Paid" :value="'LKR ' . number_format($stats['total_paid'])" color="sky" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            <x-stat-card label="Student participation count" :value="number_format($stats['total_students'])" color="brand" icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z" />
            <x-stat-card label="Active Service Groups" :value="number_format($stats['active_groups'])" color="amber" icon="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
        </div>
    @endif
</x-layouts.app>
