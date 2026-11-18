<x-layouts.public title="Blog">
    <x-page-header
        title="ETEC Blog"
        subtitle="Insights, tips, and stories to help you learn and grow."
        :breadcrumbs="['Blog' => null]"
    />

    <section class="container-app py-12">
        {{-- Featured post --}}
        @if ($featured && ! request()->hasAny(['q', 'category']))
            <a href="{{ route('blog.show', $featured->slug) }}" class="card group mb-12 grid overflow-hidden lg:grid-cols-2">
                <div class="relative aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800 lg:aspect-auto">
                    @if ($featured->featured_image)
                        <img src="{{ $featured->featured_image_url }}" alt="{{ $featured->title }}" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                    @else
                        <div class="flex h-full min-h-[240px] w-full items-center justify-center bg-gradient-to-br from-brand-600 to-brand-900 text-white/40"><svg class="h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m0 13a2 2 0 002-2V8a2 2 0 00-2-2h-2m0 14V6"/></svg></div>
                    @endif
                    <span class="badge absolute left-4 top-4 bg-accent-500 text-slate-900">Featured</span>
                </div>
                <div class="flex flex-col justify-center p-8 lg:p-10">
                    <div class="flex items-center gap-2 text-xs text-slate-400">
                        @if ($featured->category)<span class="badge bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ $featured->category }}</span>@endif
                        <span>{{ optional($featured->published_at)->format('M d, Y') }}</span>
                    </div>
                    <h2 class="mt-4 text-2xl font-extrabold leading-tight text-slate-900 dark:text-white lg:text-3xl">{{ $featured->title }}</h2>
                    @if ($featured->excerpt)<p class="mt-3 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($featured->excerpt, 180) }}</p>@endif
                    <span class="mt-6 inline-flex items-center gap-1 font-semibold text-brand-600 transition group-hover:gap-2 dark:text-brand-400">Read article <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                </div>
            </a>
        @endif

        {{-- Filter bar --}}
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('blog.index') }}" class="badge {{ ! request('category') ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300' }} px-3 py-1.5">All</a>
                @foreach ($categories as $cat)
                    <a href="{{ route('blog.index', ['category' => $cat]) }}" class="badge {{ request('category') === $cat ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300' }} px-3 py-1.5">{{ $cat }}</a>
                @endforeach
            </div>
            <form method="GET" action="{{ route('blog.index') }}" class="relative sm:w-64">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Search articles..." class="input pl-11">
            </form>
        </div>

        @if ($posts->isEmpty())
            <div class="card p-16 text-center">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">No articles found</h3>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Check back soon for new content.</p>
            </div>
        @else
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    <x-blog-card :post="$post" />
                @endforeach
            </div>
            <div class="mt-10">{{ $posts->links() }}</div>
        @endif
    </section>
</x-layouts.public>
