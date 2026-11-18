<x-layouts.app title="Lecturer Dashboard" header="Lecturer Dashboard">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Welcome, {{ explode(' ', auth()->user()->name)[0] }} 👋</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Manage your courses, students, and grading.</p>
    </div>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="My Courses" :value="number_format($stats['courses'])" color="brand" icon="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253" />
        <x-stat-card label="Published" :value="number_format($stats['published'])" color="emerald" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Students" :value="number_format($stats['students'])" color="sky" icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z" />
        <x-stat-card label="To Grade" :value="number_format($stats['pending_grading'])" color="rose" hint="Pending" icon="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- My courses --}}
        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-5 dark:border-slate-800"><h3 class="font-bold text-slate-900 dark:text-white">My courses</h3></div>
            @if ($myCourses->isEmpty())
                <div class="p-10 text-center">
                    <p class="text-sm text-slate-500 dark:text-slate-400">You haven't been assigned any courses yet.</p>
                </div>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($myCourses as $course)
                        <li class="flex items-center gap-3 p-4">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253"/></svg></span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $course->title }}</div>
                                <div class="text-xs text-slate-400">{{ $course->enrollments_count }} students · {{ ucfirst($course->status) }}</div>
                            </div>
                            <a href="{{ route('courses.show', $course->slug) }}" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">View</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Pending submissions --}}
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-100 p-5 dark:border-slate-800">
                <h3 class="font-bold text-slate-900 dark:text-white">Submissions to grade</h3>
                <span class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">{{ $stats['pending_grading'] }}</span>
            </div>
            @if ($pendingSubmissions->isEmpty())
                <div class="p-10 text-center">
                    <svg class="mx-auto h-10 w-10 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nothing to grade right now.</p>
                </div>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($pendingSubmissions as $s)
                        <li class="flex items-center justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $s->student->name ?? 'Student' }}</div>
                                <div class="truncate text-xs text-slate-400">{{ $s->assignment->title ?? 'Assignment' }}</div>
                            </div>
                            <span class="text-xs text-slate-400">{{ optional($s->submitted_at)->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-layouts.app>
