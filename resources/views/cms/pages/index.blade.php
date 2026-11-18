<x-layouts.app title="Pages" header="Website Content — Pages">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Pages</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Static page content (e.g. About Us). The "about-us" page powers the public About section.</p>
        </div>
        <a href="{{ route('cms.pages.create') }}" class="btn-primary">New Page</a>
    </div>

    @if ($pages->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">No pages yet.</div>
    @else
        <div class="card overflow-hidden">
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($pages as $page)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-5">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-900 dark:text-white">{{ $page->title }}</div>
                            <div class="mt-0.5 font-mono text-xs text-slate-400">/{{ $page->slug }}</div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="badge {{ $page->status === 'published' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' }}">{{ ucfirst($page->status) }}</span>
                            <a href="{{ route('cms.pages.edit', $page) }}" class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">Edit</a>
                            <form method="POST" action="{{ route('cms.pages.destroy', $page) }}" onsubmit="return confirm('Delete this page?')">
                                @csrf @method('DELETE')
                                <button class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Delete</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="mt-8">{{ $pages->links() }}</div>
    @endif
</x-layouts.app>
