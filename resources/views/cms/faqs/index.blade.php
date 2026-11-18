<x-layouts.app title="FAQs" header="Website Content — FAQs">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">FAQs</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Questions shown on the public FAQ page.</p>
        </div>
        <a href="{{ route('cms.faqs.create') }}" class="btn-primary">New FAQ</a>
    </div>

    <form method="GET" action="{{ route('cms.faqs.index') }}" class="card mb-6 flex gap-3 p-4">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search FAQs..." class="input pl-11">
        </div>
        <button type="submit" class="btn-secondary">Search</button>
    </form>

    @if ($faqs->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">No FAQs yet.</div>
    @else
        <div class="card overflow-hidden">
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($faqs as $faq)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-5">
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-slate-900 dark:text-white">{{ $faq->question }}</div>
                            <div class="mt-1 truncate text-sm text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($faq->answer, 100) }}</div>
                            <div class="mt-1 text-xs text-slate-400">{{ $faq->category ?? 'General' }} · order {{ $faq->sort_order }}</div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="badge {{ $faq->is_active ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">{{ $faq->is_active ? 'Active' : 'Hidden' }}</span>
                            <a href="{{ route('cms.faqs.edit', $faq) }}" class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">Edit</a>
                            <form method="POST" action="{{ route('cms.faqs.destroy', $faq) }}" onsubmit="return confirm('Delete this FAQ?')">
                                @csrf @method('DELETE')
                                <button class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Delete</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="mt-8">{{ $faqs->links() }}</div>
    @endif
</x-layouts.app>
