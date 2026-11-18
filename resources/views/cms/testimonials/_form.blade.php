@if ($errors->any())
    <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php $testimonial = $testimonial ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div>
        <label for="name" class="label">Name</label>
        <input id="name" name="name" type="text" value="{{ old('name', $testimonial->name ?? '') }}" required class="input">
    </div>
    <div>
        <label for="designation" class="label">Designation <span class="font-normal text-slate-400">(optional)</span></label>
        <input id="designation" name="designation" type="text" value="{{ old('designation', $testimonial->designation ?? '') }}" class="input" placeholder="e.g. Digital Marketing Student">
    </div>
    <div class="sm:col-span-2">
        <label for="content" class="label">Testimonial</label>
        <textarea id="content" name="content" rows="4" required class="input">{{ old('content', $testimonial->content ?? '') }}</textarea>
    </div>
    <div>
        <label for="rating" class="label">Rating</label>
        <select id="rating" name="rating" required class="input">
            @foreach (range(5, 1) as $r)
                <option value="{{ $r }}" @selected(old('rating', $testimonial->rating ?? 5) == $r)>{{ $r }} star{{ $r > 1 ? 's' : '' }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="status" class="label">Status</label>
        <select id="status" name="status" required class="input">
            @foreach (['pending', 'approved', 'rejected'] as $s)
                <option value="{{ $s }}" @selected(old('status', $testimonial->status ?? 'approved') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="sort_order" class="label">Sort order</label>
        <input id="sort_order" name="sort_order" type="number" min="0" value="{{ old('sort_order', $testimonial->sort_order ?? 0) }}" class="input">
    </div>
    <div class="flex items-center gap-2 pt-6">
        <input id="is_featured" name="is_featured" type="checkbox" value="1" @checked(old('is_featured', $testimonial->is_featured ?? false)) class="h-4 w-4 rounded border-slate-300 text-brand-600">
        <label for="is_featured" class="text-sm font-medium text-slate-700 dark:text-slate-200">Featured</label>
    </div>
</div>
