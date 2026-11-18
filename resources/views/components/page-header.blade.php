@props(['title', 'subtitle' => null, 'breadcrumbs' => []])

<section class="relative overflow-hidden border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="pointer-events-none absolute inset-0 -z-10 opacity-60">
        <div class="absolute -left-20 -top-20 h-64 w-64 rounded-full bg-brand-400/10 blur-3xl"></div>
        <div class="absolute right-0 top-0 h-64 w-64 rounded-full bg-accent-400/10 blur-3xl"></div>
    </div>
    <div class="container-app py-12 lg:py-16">
        @if (! empty($breadcrumbs))
            <nav class="mb-4 flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                <a href="{{ url('/') }}" class="hover:text-brand-600 dark:hover:text-brand-400">Home</a>
                @foreach ($breadcrumbs as $label => $url)
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    @if ($url && ! $loop->last)
                        <a href="{{ $url }}" class="hover:text-brand-600 dark:hover:text-brand-400">{{ $label }}</a>
                    @else
                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ $label }}</span>
                    @endif
                @endforeach
            </nav>
        @endif
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-3 max-w-2xl text-base text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
        @endif
    </div>
</section>
