<x-layouts.app title="Exams" header="My Exams">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Exams & Quizzes</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Available exams from your enrolled courses.</p>
        </div>
        <a href="{{ route('student.exams.results') }}" class="btn-secondary">My Results</a>
    </div>

    @if ($exams->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">No exams available yet.</div>
    @else
        <div class="space-y-4">
            @foreach ($exams as $exam)
                @php
                    $result = $results[$exam->id] ?? null;
                    $closed = $exam->end_at && now()->gt($exam->end_at);
                    $notOpen = $exam->start_at && now()->lt($exam->start_at);
                @endphp
                <div class="card flex flex-wrap items-center justify-between gap-3 p-5">
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-900 dark:text-white">{{ $exam->title }}</div>
                        <div class="mt-0.5 text-xs text-slate-400">
                            {{ $exam->course->title ?? '' }}
                            · {{ ucfirst($exam->type) }} · {{ $exam->questions_count }} questions · {{ $exam->duration_minutes }} min
                            · pass {{ $exam->pass_marks }}/{{ $exam->total_marks }}
                            @if ($exam->start_at) · opens {{ $exam->start_at->format('M d, H:i') }} @endif
                            @if ($exam->end_at) · closes {{ $exam->end_at->format('M d, H:i') }} @endif
                        </div>
                    </div>
                    <div>
                        @if ($result?->submitted_at)
                            @if ($result->is_published)
                                <span class="badge {{ $result->passed ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' }}">{{ $result->marks_obtained }}/{{ $result->total_marks }} — {{ $result->passed ? 'Passed' : 'Failed' }}</span>
                                @if ($exam->show_answers ?? true)
                                    <a href="{{ route('student.exams.review', $exam) }}" class="ml-2 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">View answers</a>
                                @endif
                            @else
                                <span class="badge bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">Awaiting results</span>
                            @endif
                        @elseif ($notOpen)
                            <span class="badge bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">Not open yet</span>
                        @elseif ($closed)
                            <span class="badge bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">Closed</span>
                        @else
                            <a href="{{ route('student.exams.take', $exam) }}" class="btn-primary">{{ $result ? 'Resume Exam' : 'Start Exam' }}</a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-8">{{ $exams->links() }}</div>
    @endif
</x-layouts.app>
