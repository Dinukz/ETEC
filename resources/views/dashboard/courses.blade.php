<x-layouts.app title="My Courses" header="My Courses">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">My Courses</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Track your progress across all enrolled courses.</p>
        </div>
        <a href="{{ route('courses.index') }}" class="btn-primary">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Enroll in more
        </a>
    </div>

    @php
        // Courses paid for that have no enrollment row behind them — see
        // DashboardController::courses(). Normally empty.
        $unlinked = $unlinked ?? collect();
        $nothingAtAll = $enrollments->isEmpty() && $unlinked->isEmpty();
    @endphp

    @if ($nothingAtAll)
        <div class="card p-16 text-center">
            <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253"/></svg>
            </span>
            <h3 class="mt-5 text-lg font-bold text-slate-900 dark:text-white">No courses yet</h3>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Browse our catalog and enroll in your first course.</p>
            <a href="{{ route('courses.index') }}" class="btn-primary mt-6">Browse courses</a>
        </div>
    @else
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($enrollments as $enrollment)
                @include('dashboard.partials.enrollment-card', ['enrollment' => $enrollment])
            @endforeach

            {{-- Paid for, but with no enrollment row. Rendered with the same
                 card so it looks like any other course waiting to be verified. --}}
            @foreach ($unlinked as $enrollment)
                @include('dashboard.partials.enrollment-card', ['enrollment' => $enrollment])
            @endforeach
        </div>

        @if ($enrollments->hasPages())
            <div class="mt-8">{{ $enrollments->links() }}</div>
        @endif
    @endif
</x-layouts.app>
