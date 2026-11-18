@if ($errors->any())
    <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php $assignment = $assignment ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label for="title" class="label">Title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $assignment->title ?? '') }}" required class="input">
    </div>
    <div>
        <label for="course_id" class="label">Course</label>
        <select id="course_id" name="course_id" required class="input">
            <option value="">Select a course</option>
            @foreach ($courses as $c)
                <option value="{{ $c->id }}" @selected(old('course_id', $assignment->course_id ?? '') == $c->id)>{{ $c->title }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="due_date" class="label">Due date <span class="font-normal text-slate-400">(optional)</span></label>
        <input id="due_date" name="due_date" type="datetime-local" value="{{ old('due_date', optional($assignment?->due_date)->format('Y-m-d\TH:i')) }}" class="input">
    </div>
    <div>
        <label for="total_marks" class="label">Total marks</label>
        <input id="total_marks" name="total_marks" type="number" min="1" max="1000" value="{{ old('total_marks', $assignment->total_marks ?? 100) }}" required class="input">
    </div>
    <div>
        <label for="status" class="label">Status</label>
        <select id="status" name="status" required class="input">
            @foreach (['draft', 'published', 'closed'] as $s)
                <option value="{{ $s }}" @selected(old('status', $assignment->status ?? 'published') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label for="description" class="label">Description</label>
        <textarea id="description" name="description" rows="4" class="input">{{ old('description', $assignment->description ?? '') }}</textarea>
    </div>
    <div class="sm:col-span-2">
        <label for="instructions" class="label">Submission instructions <span class="font-normal text-slate-400">(optional)</span></label>
        <textarea id="instructions" name="instructions" rows="3" class="input">{{ old('instructions', $assignment->instructions ?? '') }}</textarea>
    </div>
    <div class="sm:col-span-2">
        <label for="attachment" class="label">Attachment <span class="font-normal text-slate-400">(brief/spec file, optional)</span></label>
        <input id="attachment" name="attachment" type="file" accept=".pdf,.doc,.docx,.zip,.png,.jpg" class="input">
        @if ($assignment?->attachment_path)
            <p class="mt-1 text-xs text-slate-400">Current: {{ basename($assignment->attachment_path) }}</p>
        @endif
    </div>
</div>
