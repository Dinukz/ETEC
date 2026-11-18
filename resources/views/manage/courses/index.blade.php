<x-layouts.app title="Course Management" header="Course Management">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Courses</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Create courses, build the curriculum, and publish when ready.</p>
        </div>
        <a href="{{ route('manage.courses.create') }}" class="btn-primary">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            New Course
        </a>
    </div>

    <form method="GET" action="{{ route('manage.courses.index') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search courses..." class="input pl-11">
        </div>
        <select name="status" class="input lg:w-40" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['draft', 'published', 'archived'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Search</button>
    </form>

    @if ($courses->isEmpty())
        <div class="card p-12 text-center">
            <h3 class="font-bold text-slate-900 dark:text-white">No courses yet</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Create your first course to start building your curriculum.</p>
            <a href="{{ route('manage.courses.create') }}" class="btn-primary mt-6">New Course</a>
        </div>
    @else
        <div class="card hidden overflow-hidden lg:block">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Course</th>
                        <th class="px-6 py-4 font-semibold">Price</th>
                        <th class="px-6 py-4 font-semibold">Lessons</th>
                        <th class="px-6 py-4 font-semibold">Students</th>
                        <th class="px-6 py-4 font-semibold">Status</th>
                        <th class="px-6 py-4 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($courses as $course)
                        <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900 dark:text-white">{{ $course->title }}</div>
                                <div class="text-xs text-slate-400">{{ $course->category ?? 'Uncategorised' }} · {{ ucfirst($course->level ?? 'all levels') }}</div>
                            </td>
                            <td class="px-6 py-4 font-medium text-slate-700 dark:text-slate-200">LKR {{ number_format($course->effective_price) }}</td>
                            <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ $course->lessons_count }}</td>
                            <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ $course->enrollments_count }}</td>
                            <td class="px-6 py-4">
                                @php $cs = ['published' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'draft' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'archived' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$course->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                                <span class="badge {{ $cs }}">{{ ucfirst($course->status) }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('manage.courses.show', $course) }}" class="badge bg-brand-50 text-brand-600 transition hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-400">Curriculum</a>
                                    <a href="{{ route('manage.courses.edit', $course) }}" class="badge bg-slate-100 text-slate-600 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300">Edit</a>
                                    <form method="POST" action="{{ route('manage.courses.destroy', $course) }}" onsubmit="return confirm('Delete this course? Enrollments and history are kept.')">
                                        @csrf @method('DELETE')
                                        <button class="badge bg-rose-50 text-rose-600 transition hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-400">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="space-y-4 lg:hidden">
            @foreach ($courses as $course)
                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <span class="font-semibold text-slate-900 dark:text-white">{{ $course->title }}</span>
                        @php $cs = ['published' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'draft' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'archived' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$course->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                        <span class="badge {{ $cs }}">{{ ucfirst($course->status) }}</span>
                    </div>
                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">LKR {{ number_format($course->effective_price) }} · {{ $course->lessons_count }} lessons · {{ $course->enrollments_count }} students</div>
                    <div class="mt-3 flex gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <a href="{{ route('manage.courses.show', $course) }}" class="text-sm font-medium text-brand-600 dark:text-brand-400">Curriculum</a>
                        <a href="{{ route('manage.courses.edit', $course) }}" class="text-sm font-medium text-slate-600 dark:text-slate-300">Edit</a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-8">{{ $courses->links() }}</div>
    @endif
</x-layouts.app>
