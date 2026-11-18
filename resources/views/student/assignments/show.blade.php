<x-layouts.app title="Assignment" header="Assignment">
    <div class="mb-6">
        <a href="{{ route('student.assignments.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to assignments
        </a>
    </div>

    <div class="mx-auto max-w-3xl space-y-6">
        <div class="card p-6 lg:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-extrabold text-slate-900 dark:text-white">{{ $assignment->title }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ $assignment->course->title ?? '' }}
                        · Due {{ optional($assignment->due_date)->format('M d, Y H:i') ?? 'no deadline' }}
                        · {{ $assignment->total_marks }} marks
                    </p>
                </div>
                @if ($assignment->due_date && now()->gt($assignment->due_date) && $assignment->status === 'published')
                    <span class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Past due — submissions marked late</span>
                @endif
            </div>

            @if ($assignment->description)
                <div class="mt-5 text-sm text-slate-600 dark:text-slate-300">{!! nl2br(e($assignment->description)) !!}</div>
            @endif
            @if ($assignment->instructions)
                <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
                    <span class="font-semibold">Instructions:</span> {!! nl2br(e($assignment->instructions)) !!}
                </div>
            @endif
            @if ($assignment->attachment_path)
                <a href="{{ asset('storage/'.$assignment->attachment_path) }}" target="_blank" class="mt-4 inline-flex items-center gap-2 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Download brief
                </a>
            @endif
        </div>

        @if ($submission)
            <div class="card p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-bold text-slate-900 dark:text-white">Your submission</h3>
                    @php $ss = ['graded' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'late' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400', 'submitted' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400'][$submission->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                    <span class="badge {{ $ss }}">{{ ucfirst($submission->status) }}</span>
                </div>
                <p class="mt-1 text-xs text-slate-400">Submitted {{ optional($submission->submitted_at)->format('M d, Y H:i') }}</p>
                @if ($submission->status === 'graded')
                    <div class="mt-4 rounded-xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
                        <p class="text-lg font-extrabold text-emerald-700 dark:text-emerald-300">{{ $submission->marks_obtained }}/{{ $assignment->total_marks }}</p>
                        @if ($submission->feedback)<p class="mt-1 text-sm text-emerald-800 dark:text-emerald-200">{{ $submission->feedback }}</p>@endif
                    </div>
                @endif
                @if ($submission->submission_text)
                    <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">{{ $submission->submission_text }}</div>
                @endif
            </div>
        @endif

        @if ($assignment->status === 'published' && (! $submission || $submission->status !== 'graded'))
            <div class="card p-6">
                <h3 class="font-bold text-slate-900 dark:text-white">{{ $submission ? 'Resubmit' : 'Submit your work' }}</h3>

                @if ($errors->any())
                    <div class="mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
                        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('student.assignments.submit', $assignment) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="submission_text" class="label">Answer text</label>
                        <textarea id="submission_text" name="submission_text" rows="6" class="input" placeholder="Type your answer, or attach a file below">{{ old('submission_text', $submission->submission_text ?? '') }}</textarea>
                    </div>
                    <div>
                        <label for="file" class="label">Attachment <span class="font-normal text-slate-400">(PDF/DOC/ZIP/image, max 20MB)</span></label>
                        <input id="file" name="file" type="file" accept=".pdf,.doc,.docx,.zip,.png,.jpg,.jpeg" class="input">
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary">{{ $submission ? 'Resubmit Assignment' : 'Submit Assignment' }}</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
