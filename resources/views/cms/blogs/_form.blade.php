@if ($errors->any())
    <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php $blog = $blog ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label for="title" class="label">Title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $blog->title ?? '') }}" required class="input">
    </div>
    <div class="sm:col-span-2">
        <label for="excerpt" class="label">Excerpt <span class="font-normal text-slate-400">(short summary for cards)</span></label>
        <textarea id="excerpt" name="excerpt" rows="2" class="input">{{ old('excerpt', $blog->excerpt ?? '') }}</textarea>
    </div>
    <div class="sm:col-span-2">
        <label for="content" class="label">Content <span class="font-normal text-slate-400">(HTML allowed)</span></label>
        <textarea id="content" name="content" rows="12" required class="input font-mono text-xs">{{ old('content', $blog->content ?? '') }}</textarea>
    </div>
    <div>
        <label for="category" class="label">Category</label>
        <input id="category" name="category" type="text" value="{{ old('category', $blog->category ?? '') }}" class="input" placeholder="e.g. News">
    </div>
    <div>
        <label for="status" class="label">Status</label>
        <select id="status" name="status" required class="input">
            @foreach (['draft', 'published'] as $s)
                <option value="{{ $s }}" @selected(old('status', $blog->status ?? 'draft') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="featured_image" class="label">Featured image <span class="font-normal text-slate-400">(max 4MB)</span></label>
        <input id="featured_image" name="featured_image" type="file" accept="image/*" class="input">
        @if ($blog?->featured_image)<p class="mt-1 text-xs text-slate-400">Current: {{ basename($blog->featured_image) }}</p>@endif
    </div>
    <div class="flex items-center gap-2 pt-6">
        <input id="is_featured" name="is_featured" type="checkbox" value="1" @checked(old('is_featured', $blog->is_featured ?? false)) class="h-4 w-4 rounded border-slate-300 text-brand-600">
        <label for="is_featured" class="text-sm font-medium text-slate-700 dark:text-slate-200">Feature this post</label>
    </div>
</div>
