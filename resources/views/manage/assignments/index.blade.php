<x-layouts.app title="Assignments" header="Assignments">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Assignments</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Create assignments and grade student submissions.</p>
        </div>
        <a href="{{ route('manage.assignments.create') }}" class="btn-primary">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            New Assignment
        </a>
    </div>

    <form method="GET" action="{{ route('manage.assignments.index') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
        <select name="course_id" class="input lg:w-64" onchange="this.form.submit()">
            <option value="">All courses</option>
            @foreach ($courses as $c)
                <option value="{{ $c->id }}" @selected(request('course_id') == $c->id)>{{ $c->title }}</option>
            @endforeach
        </select>
        <select name="status" class="input lg:w-40" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['draft', 'published', 'closed'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    @if ($assignments->isEmpty())
        <div class="card p-12 text-center">
            <h3 class="font-bold text-slate-900 dark:text-white">No assignments yet</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Create an assignment for one of your courses.</p>
            <a href="{{ route('manage.assignments.create') }}" class="btn-primary mt-6">New Assignment</a>
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-6 py-4 font-semibold">Assignment</th>
                            <th class="px-6 py-4 font-semibold">Course</th>
                            <th class="px-6 py-4 font-semibold">Due</th>
                            <th class="px-6 py-4 font-semibold">Submissions</th>
                            <th class="px-6 py-4 font-semibold">Status</th>
                            <th class="px-6 py-4 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($assignments as $a)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="px-6 py-4 font-semibold text-slate-900 dark:text-white">{{ $a->title }}</td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($a->course->title ?? '—', 28) }}</td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ optional($a->due_date)->format('M d, Y') ?? 'No deadline' }}</td>
                                <td class="px-6 py-4">
                                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ $a->submissions_count }}</span>
                                    @if ($a->ungraded_count > 0)
                                        <span class="badge ml-1 bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">{{ $a->ungraded_count }} to grade</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @php $as = ['published' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'draft' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'closed' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$a->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                                    <span class="badge {{ $as }}">{{ ucfirst($a->status) }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('manage.assignments.show', $a) }}" class="badge bg-brand-50 text-brand-600 transition hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-400">Grade</a>
                                        <a href="{{ route('manage.assignments.edit', $a) }}" class="badge bg-slate-100 text-slate-600 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300">Edit</a>
                                        <form method="POST" action="{{ route('manage.assignments.destroy', $a) }}" onsubmit="return confirm('Delete this assignment and all its submissions?')">
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
        </div>

        <div class="mt-8">{{ $assignments->links() }}</div>
    @endif
</x-layouts.app>
