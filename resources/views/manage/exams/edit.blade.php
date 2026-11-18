<x-layouts.app title="Edit Exam" header="Exam Builder">
    <div class="mb-6">
        <a href="{{ route('manage.exams.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to exams
        </a>
    </div>
    @if ($errors->any())
        <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
            <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $exam->title }}</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">
                {{ $exam->questions->count() }} questions · {{ $exam->questions->sum('marks') }} marks allocated of {{ $exam->total_marks }}
                · {{ $exam->duration_minutes }} min
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('manage.exams.results', $exam) }}" class="btn-secondary">Marks</a>
            <form method="POST" action="{{ route('manage.exams.publish', $exam) }}">
                @csrf @method('PATCH')
                <button class="{{ $exam->is_published ? 'btn-secondary' : 'btn-primary' }}">{{ $exam->is_published ? 'Unpublish' : 'Publish Exam' }}</button>
            </form>
        </div>
    </div>

    @if ($exam->questions->sum('marks') !== (int) $exam->total_marks && $exam->questions->isNotEmpty())
        <div class="mb-6 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
            The questions add up to {{ $exam->questions->sum('marks') }} marks, but the paper is set to {{ $exam->total_marks }}.
            Change "Total marks" in the settings so percentages come out right.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Questions --}}
        <div class="space-y-4 lg:col-span-2">
            @forelse ($exam->questions as $i => $question)
                @php
                    $correct = $question->correctIndex();
                    $notes = (array) ($question->option_explanations ?? []);
                @endphp
                <div class="card p-5" x-data="{ editing: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-brand-50 text-xs font-bold text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">{{ $i + 1 }}</span>
                                <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ strtoupper($question->type) }}</span>
                                <span class="text-xs font-semibold text-slate-400">{{ $question->marks }} {{ \Illuminate\Support\Str::plural('mark', $question->marks) }}</span>
                                @if ($question->type !== 'essay' && $correct === null)
                                    <span class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">No correct answer — fix this</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm font-medium text-slate-900 dark:text-white">{{ $question->question }}</p>
                            @if ($question->type !== 'essay' && $question->options)
                                <ul class="mt-2 space-y-1 text-sm">
                                    @foreach ($question->options as $n => $option)
                                        <li class="{{ $correct === $n ? 'font-semibold text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400' }}">
                                            {{ \App\Models\ExamQuestion::letter($n) }}. {{ $option }} {{ $correct === $n ? '✓' : '' }}
                                            @if (filled($notes[$n] ?? null))
                                                <span class="block text-xs font-normal text-slate-400" style="padding-left:1.25rem">{{ $notes[$n] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (filled($question->explanation ?? null))
                                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400"><span class="font-semibold">Explanation:</span> {{ $question->explanation }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-2">
                            <button type="button" class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300" @click="editing = ! editing" x-text="editing ? 'Close' : 'Edit'">Edit</button>
                            <form method="POST" action="{{ route('manage.questions.destroy', $question) }}" onsubmit="return confirm('Remove this question?')">
                                @csrf @method('DELETE')
                                <button class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Remove</button>
                            </form>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('manage.questions.update', $question) }}" class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800"
                        x-show="editing" style="display:none">
                        @csrf @method('PATCH')
                        @include('manage.exams._question-fields', ['question' => $question, 'prefix' => 'q'.$question->id])
                        <button type="submit" class="btn-primary mt-4">Save question</button>
                    </form>
                </div>
            @empty
                <div class="card p-10 text-center text-sm text-slate-500 dark:text-slate-400">
                    No questions yet — import a whole paper or add questions one by one on the right.
                </div>
            @endforelse
        </div>

        {{-- Import + add question + settings --}}
        <div class="space-y-6">
            {{-- ⚠️ A WHOLE PAPER AT ONCE (2026-10-18). See App\Support\ExamPaperImport.
                 Nothing is added unless every question reads correctly. --}}
            <div class="card p-6" x-data="{ how: 'file' }">
                <h3 class="font-bold text-slate-900 dark:text-white">Import a paper</h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Upload the quiz HTML file (with its explanations), a JSON file, or a text file — or paste the text.
                    PDFs cannot be read: copy the questions into the text format below instead.
                </p>
                <form method="POST" action="{{ route('manage.exams.import', $exam) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                    @csrf
                    <div class="flex gap-4 text-sm">
                        <label class="flex items-center gap-2"><input type="radio" x-model="how" value="file" class="h-4 w-4"> Upload a file</label>
                        <label class="flex items-center gap-2"><input type="radio" x-model="how" value="paste" class="h-4 w-4"> Paste text</label>
                    </div>
                    <div x-show="how === 'file'">
                        <input type="file" name="paper" accept=".html,.htm,.json,.txt" class="input text-sm" :disabled="how !== 'file'">
                    </div>
                    <div x-show="how === 'paste'" style="display:none">
                        <textarea name="content" rows="8" class="input font-mono text-xs" :disabled="how !== 'paste'"
                            placeholder="1. Question text&#10;A. first option&#10;B. second option&#10;C. third option&#10;D. fourth option&#10;Answer: B&#10;Explanation: (optional)&#10;&#10;2. Next question…">{{ old('content') }}</textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="marks_each">Marks per question</label>
                            <input id="marks_each" name="marks_each" type="number" min="1" max="100" value="1" class="input">
                        </div>
                        <label class="flex items-end gap-2 pb-2 text-xs text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="match_total" value="1" checked class="h-4 w-4 rounded border-slate-300">
                            Set the paper's total to match
                        </label>
                    </div>
                    <button type="submit" class="btn-primary w-full">Import questions</button>
                </form>
            </div>

            <div class="card p-6">
                <h3 class="font-bold text-slate-900 dark:text-white">Add a question</h3>
                <form method="POST" action="{{ route('manage.questions.store', $exam) }}" class="mt-4">
                    @csrf
                    @include('manage.exams._question-fields', ['question' => null, 'prefix' => 'new'])
                    <button type="submit" class="btn-primary mt-4 w-full">Add Question</button>
                </form>
            </div>

            <div class="card p-6">
                <h3 class="font-bold text-slate-900 dark:text-white">Exam settings</h3>
                <form method="POST" action="{{ route('manage.exams.update', $exam) }}" class="mt-4 space-y-5">
                    @csrf @method('PATCH')
                    @include('manage.exams._form')
                    <button type="submit" class="btn-secondary w-full">Save Settings</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
