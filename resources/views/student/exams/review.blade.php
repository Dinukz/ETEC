<x-layouts.app :title="$exam->title" header="Your Answers">
    <div class="mx-auto max-w-3xl">
        <div class="card mb-6 flex flex-wrap items-center justify-between gap-4 p-6">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900 dark:text-white">{{ $exam->title }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $exam->course->title ?? '' }} · submitted {{ \App\Casts\SriLankaWallClock::show($result->submitted_at, 'M d, Y H:i') }}</p>
            </div>
            <div class="text-right">
                <p class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $result->marks_obtained }}/{{ $result->total_marks }}</p>
                <span class="badge {{ $result->passed ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' }}">
                    {{ $result->percentage }}% · {{ $result->grade }} · {{ $result->passed ? 'Passed' : 'Not passed' }}
                </span>
            </div>
        </div>

        <div class="space-y-5">
            @foreach ($exam->questions as $i => $question)
                @include('exams._marked-question', ['question' => $question, 'number' => $i + 1, 'answer' => ((array) $result->answers)[$question->id] ?? null])
            @endforeach
        </div>

        <div class="mt-8 flex justify-between">
            <a href="{{ route('student.exams.results') }}" class="btn-secondary">My Results</a>
            <a href="{{ route('student.exams.index') }}" class="btn-secondary">All Papers</a>
        </div>
    </div>
</x-layouts.app>
