@if ($errors->any())
    <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php $meeting = $meeting ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label for="title" class="label">Meeting title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $meeting->title ?? '') }}" required class="input">
    </div>
    <div class="sm:col-span-2">
        <label for="course_id" class="label">Course</label>
        <select id="course_id" name="course_id" required class="input">
            <option value="">Select a course</option>
            @foreach ($courses as $c)
                <option value="{{ $c->id }}" @selected(old('course_id', $meeting->course_id ?? '') == $c->id)>{{ $c->title }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label for="join_url" class="label">Join URL <span class="font-normal text-slate-400">(from your Zoom account)</span></label>
        <input id="join_url" name="join_url" type="url" value="{{ old('join_url', $meeting->join_url ?? '') }}" required class="input" placeholder="https://zoom.us/j/...">
    </div>
    <div class="sm:col-span-2">
        <label for="start_url" class="label">Host start URL <span class="font-normal text-slate-400">(optional, kept private)</span></label>
        <input id="start_url" name="start_url" type="url" value="{{ old('start_url', $meeting->start_url ?? '') }}" class="input">
    </div>
    <div>
        <label for="meeting_id" class="label">Meeting ID <span class="font-normal text-slate-400">(optional)</span></label>
        <input id="meeting_id" name="meeting_id" type="text" value="{{ old('meeting_id', $meeting->meeting_id ?? '') }}" class="input">
    </div>
    <div>
        <label for="passcode" class="label">Passcode <span class="font-normal text-slate-400">(optional)</span></label>
        <input id="passcode" name="passcode" type="text" value="{{ old('passcode', $meeting->passcode ?? '') }}" class="input">
    </div>
    <div>
        <label for="start_at" class="label">Starts at</label>
        <input id="start_at" name="start_at" type="datetime-local" value="{{ old('start_at', optional($meeting?->start_at)->format('Y-m-d\TH:i')) }}" required class="input">
    </div>
    <div>
        <label for="duration_minutes" class="label">Duration (minutes)</label>
        <input id="duration_minutes" name="duration_minutes" type="number" min="15" max="600" value="{{ old('duration_minutes', $meeting->duration_minutes ?? 60) }}" required class="input">
    </div>
    <div>
        <label for="status" class="label">Status</label>
        <select id="status" name="status" required class="input">
            @foreach (['scheduled', 'live', 'ended', 'cancelled'] as $s)
                <option value="{{ $s }}" @selected(old('status', $meeting->status ?? 'scheduled') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="recording_url" class="label">Recording URL <span class="font-normal text-slate-400">(after the session)</span></label>
        <input id="recording_url" name="recording_url" type="url" value="{{ old('recording_url', $meeting->recording_url ?? '') }}" class="input">
    </div>
    <div class="sm:col-span-2">
        <label for="agenda" class="label">Agenda <span class="font-normal text-slate-400">(optional)</span></label>
        <textarea id="agenda" name="agenda" rows="3" class="input">{{ old('agenda', $meeting->agenda ?? '') }}</textarea>
    </div>
</div>
