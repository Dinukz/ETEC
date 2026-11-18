<x-layouts.app title="My Results" header="My Results">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Exam Results</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Your submitted exams and released results.</p>
        </div>
        <a href="{{ route('student.exams.index') }}" class="btn-secondary">Available Exams</a>
    </div>

    @if ($results->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">You haven't taken any exams yet.</div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-6 py-4 font-semibold">Exam</th>
                            <th class="px-6 py-4 font-semibold">Course</th>
                            <th class="px-6 py-4 font-semibold">Marks</th>
                            <th class="px-6 py-4 font-semibold">Grade</th>
                            <th class="px-6 py-4 font-semibold">Outcome</th>
                            <th class="px-6 py-4 font-semibold">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($results as $result)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900 dark:text-white">{{ $result->exam->title ?? '—' }}</div>
                                    @if ($result->is_published && $result->exam && ($result->exam->show_answers ?? true))
                                        <a href="{{ route('student.exams.review', $result->exam) }}" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View answers</a>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($result->exam->course->title ?? '—', 26) }}</td>
                                @if ($result->is_published)
                                    <td class="px-6 py-4 font-bold text-slate-900 dark:text-white">{{ $result->marks_obtained }}/{{ $result->total_marks }} <span class="font-normal text-slate-400">({{ $result->percentage }}%)</span></td>
                                    <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ $result->grade ?? '—' }}</td>
                                    <td class="px-6 py-4">
                                        <span class="badge {{ $result->passed ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' }}">{{ $result->passed ? 'Passed' : 'Failed' }}</span>
                                    </td>
                                @else
                                    <td colspan="3" class="px-6 py-4">
                                        <span class="badge bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">Awaiting release</span>
                                    </td>
                                @endif
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ \App\Casts\SriLankaWallClock::show($result->submitted_at, 'M d, Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-8">{{ $results->links() }}</div>
    @endif
</x-layouts.app>
