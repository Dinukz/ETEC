{{-- The fields of one question — the "Add a question" form and each "Edit"
     form share them. $question is null when adding.

     ⚠️ The right answer is PICKED with a radio beside its option (2026-10-18);
     it used to be retyped "exactly", and one stray space marked every student
     wrong. --}}
@php
    $question = $question ?? null;
    $prefix = $prefix ?? 'new';
    $options = array_pad((array) ($question?->options ?? []), 4, '');
    $notes = (array) ($question?->option_explanations ?? []);
    $correct = $question?->correctIndex();
    $type = $question?->type ?? 'mcq';
@endphp
<div class="space-y-4" x-data="{ qtype: '{{ $type }}' }">
    <div>
        <label class="label" for="{{ $prefix }}_text">Question</label>
        <textarea id="{{ $prefix }}_text" name="question" rows="3" required class="input">{{ $question?->question }}</textarea>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="label" for="{{ $prefix }}_type">Type</label>
            <select id="{{ $prefix }}_type" name="type" x-model="qtype" class="input">
                <option value="mcq">Multiple choice</option>
                <option value="truefalse">True / False</option>
                <option value="essay">Essay (marked by hand)</option>
            </select>
        </div>
        <div>
            <label class="label" for="{{ $prefix }}_marks">Marks</label>
            <input id="{{ $prefix }}_marks" name="marks" type="number" min="1" max="100" value="{{ $question?->marks ?? 1 }}" required class="input">
        </div>
    </div>

    <div x-show="qtype === 'mcq'" style="{{ $type === 'mcq' ? '' : 'display:none' }}" class="space-y-3">
        <p class="label">Options — tick the correct one</p>
        @foreach (range(0, 5) as $n)
            <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                <div class="flex items-center gap-2">
                    <input type="radio" name="correct" value="{{ $n }}" class="h-4 w-4 border-slate-300 text-brand-600" title="This is the correct answer"
                        @checked($correct === $n)>
                    <span class="w-5 text-sm font-bold text-slate-500">{{ \App\Models\ExamQuestion::letter($n) }}.</span>
                    <input name="options[{{ $n }}]" type="text" class="input" value="{{ $options[$n] ?? '' }}"
                        placeholder="{{ $n < 2 ? 'Option '.\App\Models\ExamQuestion::letter($n) : 'Option '.\App\Models\ExamQuestion::letter($n).' (optional)' }}">
                </div>
                <input name="option_explanations[{{ $n }}]" type="text" class="input mt-2 text-xs" value="{{ $notes[$n] ?? '' }}"
                    placeholder="Why this option is right or wrong (optional — shown after submitting)">
            </div>
        @endforeach
    </div>

    <div x-show="qtype === 'truefalse'" style="{{ $type === 'truefalse' ? '' : 'display:none' }}">
        <label class="label" for="{{ $prefix }}_tf">Correct answer</label>
        <select id="{{ $prefix }}_tf" name="correct_answer" class="input" :disabled="qtype !== 'truefalse'">
            <option value="True" @selected($question?->correct_answer === 'True')>True</option>
            <option value="False" @selected($question?->correct_answer === 'False')>False</option>
        </select>
    </div>

    <div>
        <label class="label" for="{{ $prefix }}_explanation">Explanation <span class="font-normal text-slate-400">(optional — shown after submitting)</span></label>
        <textarea id="{{ $prefix }}_explanation" name="explanation" rows="2" class="input">{{ $question?->explanation }}</textarea>
    </div>
</div>
