@php $exam = $exam ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-3">
    <div class="sm:col-span-3">
        <label for="title" class="label">Title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $exam->title ?? '') }}" required class="input">
    </div>
    <div class="sm:col-span-2">
        <label for="course_id" class="label">Course</label>
        <select id="course_id" name="course_id" required class="input">
            <option value="">Select a course</option>
            @foreach ($courses as $c)
                <option value="{{ $c->id }}" @selected(old('course_id', $exam->course_id ?? '') == $c->id)>{{ $c->title }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="type" class="label">Type</label>
        <select id="type" name="type" required class="input">
            @foreach (['quiz', 'exam'] as $t)
                <option value="{{ $t }}" @selected(old('type', $exam->type ?? 'exam') === $t)>{{ ucfirst($t) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="total_marks" class="label">Total marks</label>
        <input id="total_marks" name="total_marks" type="number" min="1" max="1000" value="{{ old('total_marks', $exam->total_marks ?? 100) }}" required class="input">
    </div>
    <div>
        <label for="pass_marks" class="label">Pass marks</label>
        <input id="pass_marks" name="pass_marks" type="number" min="0" value="{{ old('pass_marks', $exam->pass_marks ?? 40) }}" required class="input">
    </div>
    <div>
        <label for="duration_minutes" class="label">Duration (minutes)</label>
        <input id="duration_minutes" name="duration_minutes" type="number" min="1" max="600" value="{{ old('duration_minutes', $exam->duration_minutes ?? 60) }}" required class="input">
    </div>
    {{-- ⚠️ A FULL ROW EACH (2026-10-25). Three to a row, these date-and-time
         boxes were so narrow in the Exam settings card (a third of the page)
         that the time was cut off and only the date showed. The saved value is
         also written out underneath, in Sri Lanka time. --}}
    <div class="sm:col-span-3">
        <label for="start_at" class="label">Opens at <span class="font-normal text-slate-400">(optional, Sri Lanka time)</span></label>
        <input id="start_at" name="start_at" type="datetime-local" value="{{ old('start_at', optional($exam?->start_at)->format('Y-m-d\TH:i')) }}" class="input">
        @if ($exam?->start_at)
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Opens {{ $exam->start_at->format('D, j M Y \a\t g:i A') }}</p>
        @endif
    </div>
    <div class="sm:col-span-3">
        <label for="end_at" class="label">Closes at <span class="font-normal text-slate-400">(optional, Sri Lanka time)</span></label>
        <input id="end_at" name="end_at" type="datetime-local" value="{{ old('end_at', optional($exam?->end_at)->format('Y-m-d\TH:i')) }}" class="input">
        @if ($exam?->end_at)
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Closes {{ $exam->end_at->format('D, j M Y \a\t g:i A') }}</p>
        @endif
    </div>
    <div class="sm:col-span-3">
        <label for="description" class="label">Description <span class="font-normal text-slate-400">(optional)</span></label>
        <textarea id="description" name="description" rows="3" class="input">{{ old('description', $exam->description ?? '') }}</textarea>
    </div>
    {{-- 2026-10-18: after submitting, the student sees every question with the
         right answer and the explanations — the way the owner's own quiz page
         works. Off for a paper whose answers must stay secret. --}}
    <div class="sm:col-span-3">
        <input type="hidden" name="show_answers" value="0">
        <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
            <input type="checkbox" name="show_answers" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300"
                @checked(old('show_answers', $exam?->show_answers ?? true))>
            <span>Show students the correct answers and explanations after they submit</span>
        </label>
    </div>
</div>
