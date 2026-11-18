<x-layouts.app title="Exam Results" header="Exam Results">
    <div class="mb-6">
        <a href="{{ route('manage.exams.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to exams
        </a>
    </div>

    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $exam->title }} — Results</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">{{ $exam->course->title ?? '' }} · pass mark {{ $exam->pass_marks }}/{{ $exam->total_marks }} · {{ $exam->results->count() }} attempt(s)</p>
        </div>
        <div class="flex flex-wrap gap-2">
            {{-- 2026-10-18: the marks as a spreadsheet (opens in Excel, Sinhala names intact). --}}
            <a href="{{ route('manage.exams.results', [$exam, 'export' => 'csv']) }}" class="btn-secondary">Download marks (Excel)</a>
            @if ($exam->results->whereNotNull('submitted_at')->where('is_published', false)->isNotEmpty())
                <form method="POST" action="{{ route('manage.exams.publishResults', $exam) }}">
                    @csrf @method('PATCH')
                    <button class="btn-primary">Release Results to Students</button>
                </form>
            @endif
        </div>
    </div>

    @if ($exam->results->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">No attempts yet.</div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-6 py-4 font-semibold">Student</th>
                            <th class="px-6 py-4 font-semibold">Marks</th>
                            <th class="px-6 py-4 font-semibold">Grade</th>
                            <th class="px-6 py-4 font-semibold">Outcome</th>
                            <th class="px-6 py-4 font-semibold">Submitted</th>
                            <th class="px-6 py-4 font-semibold">Released</th>
                            <th class="px-6 py-4 text-right font-semibold">Adjust</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($exam->results as $result)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900 dark:text-white">{{ $result->student->name ?? '—' }}</div>
                                    <div class="font-mono text-xs text-slate-400">{{ $result->student?->student?->student_code }}</div>
                                    {{-- One student's whole paper, question by question. --}}
                                    <a href="{{ route('manage.results.sheet', $result) }}" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View answers</a>
                                </td>
                                @if ($result->submitted_at)
                                    <td class="px-6 py-4 font-bold text-slate-900 dark:text-white">{{ $result->marks_obtained }}/{{ $result->total_marks }} <span class="font-normal text-slate-400">({{ $result->percentage }}%)</span></td>
                                    <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ $result->grade ?? '—' }}</td>
                                    <td class="px-6 py-4">
                                        <span class="badge {{ $result->passed ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' }}">{{ $result->passed ? 'Passed' : 'Failed' }}</span>
                                    </td>
                                @else
                                    {{-- Not handed in yet: no marks to show, so none are shown. --}}
                                    <td colspan="3" class="px-6 py-4 text-slate-400">—</td>
                                @endif
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">
                                    @if ($result->submitted_at)
                                        {{ \App\Casts\SriLankaWallClock::show($result->submitted_at, 'M d, H:i') }}
                                    @elseif (\App\Services\ExamGrader::isOver($exam, $result, \App\Services\ExamGrader::SUBMIT_GRACE_SECONDS))
                                        Time ran out — marked when they come back
                                    @else
                                        Sitting it now (until {{ \App\Casts\SriLankaWallClock::show(\App\Services\ExamGrader::deadline($exam, $result), 'H:i') }})
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <span class="badge {{ $result->is_published ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' }}">{{ $result->is_published ? 'Yes' : 'Pending' }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    @if ($result->submitted_at)
                                        <form method="POST" action="{{ route('manage.results.grade', $result) }}" class="flex items-center justify-end gap-2">
                                            @csrf @method('PATCH')
                                            <input name="marks_obtained" type="number" min="0" max="{{ $result->total_marks }}" value="{{ $result->marks_obtained }}" class="input w-20 py-1 text-xs">
                                            <button class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">Save</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-layouts.app>
