<x-layouts.app title="Pending Students" header="Pending Students">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Pending Students</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Students awaiting payment verification by the accountant.</p>
        </div>
        <a href="{{ route('consultant.students.index') }}" class="btn-secondary">View all Direct Registrations</a>
    </div>

    <div class="grid gap-5 sm:grid-cols-3">
        <x-stat-card label="Total Students" :value="number_format($stats['total'])" color="brand" icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z" />
        <x-stat-card label="In a Service Group" :value="number_format($stats['grouped'])" color="emerald" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Payment Pending" :value="number_format($stats['pending_payment'])" color="amber" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </div>

    <form method="GET" action="{{ route('consultant.students.pending') }}" class="card mt-6 mb-6 flex gap-3 p-4">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search pending students..." class="input pl-11">
        </div>
        <button type="submit" class="btn-secondary">Search</button>
    </form>

    {{-- ⚠️ The table partial carries an Info button that dispatches at this
         component, so every page including the partial has to mount it —
         otherwise the button is inert markup here while working on the index. --}}
    @livewire('scn-account-summary')

    @include('consultant.students._table', [
        'students' => $students,
        'payments' => $payments,
        'tiers' => $tiers,
        'requestedTiers' => $requestedTiers,
        'consultant' => $consultant,
        'emptyTitle' => 'No pending students',
        'emptyMessage' => 'Every one of your students has a verified payment. Nice work!',
    ])
</x-layouts.app>
