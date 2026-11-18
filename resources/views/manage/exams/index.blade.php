<x-layouts.app title="Exams" header="Exams & Quizzes">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Exams & Quizzes</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Build question papers, publish them, and release results.</p>
        </div>
        <a href="{{ route('manage.exams.create') }}" class="btn-primary">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            New Exam
        </a>
    </div>

    <form method="GET" action="{{ route('manage.exams.index') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
        <select name="course_id" class="input lg:w-64" onchange="this.form.submit()">
            <option value="">All courses</option>
            @foreach ($courses as $c)
                <option value="{{ $c->id }}" @selected(request('course_id') == $c->id)>{{ $c->title }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    @if ($exams->isEmpty())
        <div class="card p-12 text-center">
            <h3 class="font-bold text-slate-900 dark:text-white">No exams yet</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Create your first exam or quiz.</p>
            <a href="{{ route('manage.exams.create') }}" class="btn-primary mt-6">New Exam</a>
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-6 py-4 font-semibold">Exam</th>
                            <th class="px-6 py-4 font-semibold">Course</th>
                            <th class="px-6 py-4 font-semibold">Questions</th>
                            <th class="px-6 py-4 font-semibold">Attempts</th>
                            <th class="px-6 py-4 font-semibold">Status</th>
                            <th class="px-6 py-4 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($exams as $exam)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900 dark:text-white">{{ $exam->title }}</div>
                                    <div class="text-xs text-slate-400">{{ ucfirst($exam->type) }} · {{ $exam->duration_minutes }} min · pass {{ $exam->pass_marks }}/{{ $exam->total_marks }}</div>
                                </td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($exam->course->title ?? '—', 28) }}</td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ $exam->questions_count }}</td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ $exam->results_count }}</td>
                                <td class="px-6 py-4">
                                    <span class="badge {{ $exam->is_published ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' }}">{{ $exam->is_published ? 'Published' : 'Draft' }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('manage.exams.results', $exam) }}" class="badge bg-violet-50 text-violet-600 transition hover:bg-violet-100 dark:bg-violet-500/10 dark:text-violet-400">Results</a>
                                        <a href="{{ route('manage.exams.edit', $exam) }}" class="badge bg-slate-100 text-slate-600 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300">Edit</a>
                                        <form method="POST" action="{{ route('manage.exams.publish', $exam) }}">
                                            @csrf @method('PATCH')
                                            <button class="badge bg-brand-50 text-brand-600 transition hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-400">{{ $exam->is_published ? 'Unpublish' : 'Publish' }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('manage.exams.destroy', $exam) }}" onsubmit="return confirm('Delete this exam and all its results?')">
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

        <div class="mt-8">{{ $exams->links() }}</div>
    @endif
</x-layouts.app>
