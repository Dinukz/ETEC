@if ($errors->any())
    <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php $lesson = $lesson ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-2" x-data="{ provider: @js(old('video_provider', $lesson->video_provider ?? '')) }">
    <div class="sm:col-span-2">
        <label for="title" class="label">Lesson title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $lesson->title ?? '') }}" required class="input">
    </div>
    <div>
        <label for="module_id" class="label">Module</label>
        <select id="module_id" name="module_id" class="input">
            <option value="">No module (unassigned)</option>
            @foreach ($modules as $module)
                <option value="{{ $module->id }}" @selected(old('module_id', $lesson->module_id ?? '') == $module->id)>{{ $module->title }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="duration_minutes" class="label">Duration (minutes)</label>
        <input id="duration_minutes" name="duration_minutes" type="number" min="1" max="600" value="{{ old('duration_minutes', $lesson->duration_minutes ?? '') }}" class="input">
    </div>
    <div>
        <label for="video_provider" class="label">Video source</label>
        <select id="video_provider" name="video_provider" x-model="provider" class="input">
            <option value="">No video (text lesson)</option>
            <option value="youtube">YouTube</option>
            <option value="vimeo">Vimeo</option>
            <option value="zoom">Zoom recording</option>
            <option value="upload">Upload video file</option>
        </select>
    </div>
    <div x-show="['youtube', 'vimeo', 'zoom'].includes(provider)" x-cloak>
        <label for="video_url" class="label">Video URL</label>
        <input id="video_url" name="video_url" type="url" value="{{ old('video_url', $lesson->video_url ?? '') }}" class="input" placeholder="https://...">
    </div>
    <div x-show="provider === 'upload'" x-cloak>
        <label for="video_file" class="label">Video file <span class="font-normal text-slate-400">(mp4/webm, max 500MB)</span></label>
        <input id="video_file" name="video_file" type="file" accept="video/mp4,video/webm,video/quicktime" class="input">
        @if ($lesson?->video_path)
            <p class="mt-1 text-xs text-slate-400">Current: {{ basename($lesson->video_path) }}</p>
        @endif
    </div>
    <div class="sm:col-span-2">
        <label for="content" class="label">Lesson content / notes <span class="font-normal text-slate-400">(optional)</span></label>
        <textarea id="content" name="content" rows="8" class="input">{{ old('content', $lesson->content ?? '') }}</textarea>
    </div>
    <div class="sm:col-span-2">
        <label for="materials" class="label">Attach materials <span class="font-normal text-slate-400">(PDF/DOC/PPT/ZIP, up to 10 files)</span></label>
        <input id="materials" name="materials[]" type="file" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip" class="input">
    </div>
    @if ($lesson && $lesson->materials->isNotEmpty())
        <div class="sm:col-span-2 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <h4 class="text-sm font-bold text-slate-900 dark:text-white">Current materials</h4>
            <ul class="mt-2 space-y-1">
                @foreach ($lesson->materials as $material)
                    <li class="flex items-center justify-between gap-3 text-sm">
                        <span class="truncate text-slate-600 dark:text-slate-300">{{ $material->title }}</span>
                        <button type="submit" form="delete-material-{{ $material->id }}" class="shrink-0 text-xs font-medium text-rose-600 dark:text-rose-400" onclick="return confirm('Remove this material?')">Remove</button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    <div>
        <label for="status" class="label">Status</label>
        <select id="status" name="status" required class="input">
            @foreach (['draft', 'published'] as $s)
                <option value="{{ $s }}" @selected(old('status', $lesson->status ?? 'published') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-center gap-2 pt-6">
        <input id="is_preview" name="is_preview" type="checkbox" value="1" @checked(old('is_preview', $lesson->is_preview ?? false)) class="h-4 w-4 rounded border-slate-300 text-brand-600">
        <label for="is_preview" class="text-sm font-medium text-slate-700 dark:text-slate-200">Free preview (visible before purchase)</label>
    </div>
</div>
