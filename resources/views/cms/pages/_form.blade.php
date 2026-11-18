@if ($errors->any())
    <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php $page = $page ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div>
        <label for="title" class="label">Title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $page->title ?? '') }}" required class="input">
    </div>
    <div>
        <label for="slug" class="label">Slug @if($page)<span class="font-normal text-slate-400">(cannot be changed)</span>@endif</label>
        <input id="slug" name="slug" type="text" value="{{ old('slug', $page->slug ?? '') }}" class="input font-mono text-xs" placeholder="auto-generated from title" @disabled($page !== null)>
    </div>
    <div class="sm:col-span-2">
        <label for="content" class="label">Content <span class="font-normal text-slate-400">(HTML allowed)</span></label>
        <textarea id="content" name="content" rows="14" required class="input font-mono text-xs">{{ old('content', $page->content ?? '') }}</textarea>
    </div>
    <div>
        <label for="meta_title" class="label">Meta title <span class="font-normal text-slate-400">(SEO, optional)</span></label>
        <input id="meta_title" name="meta_title" type="text" value="{{ old('meta_title', $page->meta_title ?? '') }}" class="input">
    </div>
    <div>
        <label for="status" class="label">Status</label>
        <select id="status" name="status" required class="input">
            @foreach (['draft', 'published'] as $s)
                <option value="{{ $s }}" @selected(old('status', $page->status ?? 'published') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label for="meta_description" class="label">Meta description <span class="font-normal text-slate-400">(SEO, optional)</span></label>
        <textarea id="meta_description" name="meta_description" rows="2" class="input">{{ old('meta_description', $page->meta_description ?? '') }}</textarea>
    </div>
</div>
