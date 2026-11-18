<x-layouts.app title="Answer Sheet" header="Answer Sheet">
    <div class="mx-auto max-w-3xl">
        <div class="mb-6">
            <a href="{{ route('manage.exams.results', $exam) }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Back to the marks for this paper
            </a>
        </div>

        <div class="card mb-6 flex flex-wrap items-center justify-between gap-4 p-6">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900 dark:text-white">{{ $result->student->name ?? 'Student' }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ $result->student?->student?->student_code }} · {{ $result->student->email ?? '' }}
                </p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ $exam->title }} · {{ $exam->course->title ?? '' }}
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    Started {{ \App\Casts\SriLankaWallClock::show($result->started_at, 'M d, H:i') ?: '—' }}
                    · {{ $result->submitted_at ? 'submitted '.\App\Casts\SriLankaWallClock::show($result->submitted_at, 'M d, H:i') : 'not submitted yet' }}
                </p>
            </div>
            @if ($result->submitted_at)
                <div class="text-right">
                    <p class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $result->marks_obtained }}/{{ $result->total_marks }}</p>
                    <span class="badge {{ $result->passed ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' }}">
                        {{ $result->percentage }}% · {{ $result->grade }} · {{ $result->passed ? 'Passed' : 'Failed' }}
                    </span>
                </div>
            @endif
        </div>

        <div class="space-y-5">
            @foreach ($exam->questions as $i => $question)
                @include('exams._marked-question', ['question' => $question, 'number' => $i + 1, 'answer' => ((array) $result->answers)[$question->id] ?? null])
            @endforeach
        </div>
    </div>
</x-layouts.app>
