<x-layouts.app title="Blog Posts" header="Website Content — Blog">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Blog Posts</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Publish news and articles to the public website.</p>
        </div>
        <a href="{{ route('cms.blogs.create') }}" class="btn-primary">New Post</a>
    </div>

    <form method="GET" action="{{ route('cms.blogs.index') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search posts..." class="input pl-11">
        </div>
        <select name="status" class="input lg:w-40" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['draft', 'published'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Search</button>
    </form>

    @if ($blogs->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">No posts yet.</div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-6 py-4 font-semibold">Title</th>
                            <th class="px-6 py-4 font-semibold">Category</th>
                            <th class="px-6 py-4 font-semibold">Author</th>
                            <th class="px-6 py-4 font-semibold">Views</th>
                            <th class="px-6 py-4 font-semibold">Status</th>
                            <th class="px-6 py-4 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($blogs as $blog)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900 dark:text-white">{{ \Illuminate\Support\Str::limit($blog->title, 44) }}</div>
                                    @if ($blog->is_featured)<span class="badge mt-1 bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">Featured</span>@endif
                                </td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ $blog->category ?? '—' }}</td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ $blog->author->name ?? '—' }}</td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ number_format($blog->views) }}</td>
                                <td class="px-6 py-4">
                                    <span class="badge {{ $blog->status === 'published' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' }}">{{ ucfirst($blog->status) }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($blog->status === 'published')
                                            <a href="{{ route('blog.show', $blog->slug) }}" target="_blank" class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">View</a>
                                        @endif
                                        <a href="{{ route('cms.blogs.edit', $blog) }}" class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">Edit</a>
                                        <form method="POST" action="{{ route('cms.blogs.destroy', $blog) }}" onsubmit="return confirm('Delete this post?')">
                                            @csrf @method('DELETE')
                                            <button class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-8">{{ $blogs->links() }}</div>
    @endif
</x-layouts.app>
