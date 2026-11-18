{{-- One marked question (2026-10-18) — the answer given, the right one, and the
     explanations, laid out like the owner's own quiz page. Used by the
     student's review and by the lecturer/admin answer sheet.

     Needs: $question (ExamQuestion), $number (1-based), $answer (?string, as
     stored — the option's text). --}}
@php
    $chosen = $question->optionIndex($answer);
    $correct = $question->correctIndex();
    $notes = (array) ($question->option_explanations ?? []);
    $isEssay = $question->type === 'essay';
    $gotIt = ! $isEssay && $chosen !== null && $chosen === $correct;
@endphp
<div class="card p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Question {{ $number }} · {{ $question->marks }} {{ \Illuminate\Support\Str::plural('mark', $question->marks) }}</p>
        @unless ($isEssay)
            <span class="badge {{ $gotIt ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' }}">
                {{ $gotIt ? '✓ Correct' : ($chosen === null ? 'Not answered' : '✗ Incorrect') }}
            </span>
        @endunless
    </div>
    <p class="mt-2 font-medium text-slate-900 dark:text-white">{{ $question->question }}</p>

    @if ($isEssay)
        <div class="mt-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700 dark:border-slate-700 dark:text-slate-200">
            {!! nl2br(e($answer ?? '— no answer —')) !!}
        </div>
    @else
        <div class="mt-4 space-y-2">
            @foreach ((array) $question->options as $i => $option)
                @php
                    $isChosen = $chosen === $i;
                    $isRight = $correct === $i;
                    $note = $notes[$i] ?? null;
                @endphp
                <div class="rounded-xl border px-4 py-3 text-sm {{ $isRight ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10' : ($isChosen ? 'border-rose-200 bg-rose-50 dark:border-rose-500/30 dark:bg-rose-500/10' : 'border-slate-200 dark:border-slate-700') }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="text-slate-800 dark:text-slate-100"><span class="font-bold">{{ \App\Models\ExamQuestion::letter($i) }}.</span> {{ $option }}</p>
                        <div class="flex flex-wrap gap-1">
                            @if ($isChosen)
                                <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Your answer</span>
                            @endif
                            @if ($isRight)
                                <span class="badge bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">Correct answer</span>
                            @elseif ($isChosen)
                                <span class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Incorrect</span>
                            @endif
                        </div>
                    </div>
                    {{-- The note for the option they picked and for the right one. --}}
                    @if (filled($note) && ($isChosen || $isRight))
                        <p class="mt-2 border-t border-slate-200 pt-2 text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ $note }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if (filled($question->explanation ?? null))
        <div class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
            <span class="font-semibold">Explanation:</span> {{ $question->explanation }}
        </div>
    @endif

</div>
