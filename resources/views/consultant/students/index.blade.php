<x-layouts.app title="Direct Registrations" header="Direct Registrations">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Direct Registrations</h2>
            {{-- The referral CODE stays: it is the only place a consultant can
                 read it, and it is the thing they hand out. Only the "students
                 you've referred" wording was asked to go. --}}
            <p class="mt-1 text-slate-500 dark:text-slate-400">Your total student count and new student enrollments. Share your referral code <span class="font-mono font-semibold text-brand-600 dark:text-brand-400">{{ $consultant->referral_code }}</span>.</p>
        </div>
        <a href="{{ route('consultant.students.create') }}" class="btn-primary">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Add Student
        </a>
    </div>

    <div class="grid gap-5 sm:grid-cols-3">
        <x-stat-card label="Total Students" :value="number_format($stats['total'])" color="brand" icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z" />
        <x-stat-card label="In a Service Group" :value="number_format($stats['grouped'])" color="emerald" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <a href="{{ route('consultant.students.pending') }}">
            <x-stat-card label="Payment Pending" :value="number_format($stats['pending_payment'])" color="amber" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </a>
    </div>

    <form method="GET" action="{{ route('consultant.students.index') }}" class="card mt-6 mb-6 flex gap-3 p-4">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search your students..." class="input pl-11">
        </div>
        <button type="submit" class="btn-secondary">Search</button>
    </form>

    {{-- ⚠️ ONE modal for the whole page, mounted outside the loop. The rows
         only dispatch an id at it; it holds nothing until then. --}}
    @livewire('scn-account-summary')

    @include('consultant.students._table', ['students' => $students, 'payments' => $payments, 'tiers' => $tiers, 'requestedTiers' => $requestedTiers, 'consultant' => $consultant])
</x-layouts.app>
