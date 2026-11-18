@if ($errors->any())
    <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php $faq = $faq ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label for="question" class="label">Question</label>
        <input id="question" name="question" type="text" value="{{ old('question', $faq->question ?? '') }}" required class="input">
    </div>
    <div class="sm:col-span-2">
        <label for="answer" class="label">Answer</label>
        <textarea id="answer" name="answer" rows="4" required class="input">{{ old('answer', $faq->answer ?? '') }}</textarea>
    </div>
    <div>
        <label for="category" class="label">Category <span class="font-normal text-slate-400">(optional, groups the FAQ page)</span></label>
        <input id="category" name="category" type="text" value="{{ old('category', $faq->category ?? '') }}" class="input" placeholder="e.g. Payments">
    </div>
    <div>
        <label for="sort_order" class="label">Sort order</label>
        <input id="sort_order" name="sort_order" type="number" min="0" value="{{ old('sort_order', $faq->sort_order ?? 0) }}" class="input">
    </div>
    <div class="flex items-center gap-2">
        <input id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $faq->is_active ?? true)) class="h-4 w-4 rounded border-slate-300 text-brand-600">
        <label for="is_active" class="text-sm font-medium text-slate-700 dark:text-slate-200">Visible on the website</label>
    </div>
</div>
