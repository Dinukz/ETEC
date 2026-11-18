<x-layouts.app title="Grade Submissions" header="Grade Submissions">
    <div class="mb-6">
        <a href="{{ route('manage.assignments.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to assignments
        </a>
    </div>

    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $assignment->title }}</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">
            {{ $assignment->course->title ?? '' }} · {{ $assignment->total_marks }} marks
            · Due {{ optional($assignment->due_date)->format('M d, Y H:i') ?? 'no deadline' }}
            · {{ $assignment->submissions->count() }} submission(s)
        </p>
    </div>

    @if ($assignment->submissions->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">No submissions yet.</div>
    @else
        <div class="space-y-4">
            @foreach ($assignment->submissions as $submission)
                <div class="card p-5" x-data="{ grading: false }">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <x-user-avatar :user="$submission->student" size="h-10 w-10" text="text-sm" />
                            <div>
                                <div class="font-semibold text-slate-900 dark:text-white">{{ $submission->student->name ?? 'Student' }}</div>
                                <div class="text-xs text-slate-400">Submitted {{ optional($submission->submitted_at)->format('M d, Y H:i') }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @php $ss = ['graded' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'late' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400', 'submitted' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400'][$submission->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                            <span class="badge {{ $ss }}">{{ ucfirst($submission->status) }}</span>
                            @if ($submission->status === 'graded')
                                <span class="text-sm font-bold text-slate-900 dark:text-white">{{ $submission->marks_obtained }}/{{ $assignment->total_marks }}</span>
                            @endif
                            <button @click="grading = !grading" class="btn-secondary py-1.5 text-xs" x-text="grading ? 'Close' : '{{ $submission->status === 'graded' ? 'Regrade' : 'Grade' }}'"></button>
                        </div>
                    </div>

                    @if ($submission->submission_text)
                        <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">{{ $submission->submission_text }}</div>
                    @endif
                    @if ($submission->file_path)
                        <a href="{{ asset('storage/'.$submission->file_path) }}" target="_blank" class="mt-3 inline-flex items-center gap-2 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Download submission file
                        </a>
                    @endif
                    @if ($submission->feedback)
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400"><span class="font-semibold">Feedback:</span> {{ $submission->feedback }}</p>
                    @endif

                    <form x-show="grading" x-cloak method="POST" action="{{ route('manage.submissions.grade', $submission) }}" class="mt-4 grid gap-3 border-t border-slate-100 pt-4 dark:border-slate-800 sm:grid-cols-4">
                        @csrf @method('PATCH')
                        <div>
                            <label class="label" for="marks-{{ $submission->id }}">Marks (max {{ $assignment->total_marks }})</label>
                            <input id="marks-{{ $submission->id }}" name="marks_obtained" type="number" min="0" max="{{ $assignment->total_marks }}" value="{{ $submission->marks_obtained }}" required class="input">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label" for="feedback-{{ $submission->id }}">Feedback <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="feedback-{{ $submission->id }}" name="feedback" type="text" value="{{ $submission->feedback }}" class="input">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="btn-primary w-full">Save Grade</button>
                        </div>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.app>
