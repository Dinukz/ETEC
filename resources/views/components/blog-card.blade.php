@props(['post'])

@php $url = route('blog.show', $post->slug); @endphp

<article class="card group flex flex-col overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
    <a href="{{ $url }}" class="relative block aspect-[16/10] overflow-hidden bg-slate-100 dark:bg-slate-800">
        @if ($post->featured_image)
            <img src="{{ $post->featured_image_url }}" alt="{{ $post->title }}" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
        @else
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-slate-700 to-slate-900 text-white/50">
                <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m0 13a2 2 0 002-2V8a2 2 0 00-2-2h-2m0 14V6"/></svg>
            </div>
        @endif
        @if ($post->category)
            <span class="badge absolute left-3 top-3 bg-white/90 text-slate-700 backdrop-blur dark:bg-slate-900/80 dark:text-slate-200">{{ $post->category }}</span>
        @endif
    </a>
    <div class="flex flex-1 flex-col p-5">
        <div class="flex items-center gap-2 text-xs text-slate-400">
            <span>{{ optional($post->published_at)->format('M d, Y') }}</span>
            @if ($post->relationLoaded('author') && $post->author)
                <span>·</span><span>{{ $post->author->name }}</span>
            @endif
        </div>
        <h3 class="mt-2 line-clamp-2 text-base font-bold leading-snug text-slate-900 dark:text-white">
            <a href="{{ $url }}" class="transition hover:text-brand-600 dark:hover:text-brand-400">{{ $post->title }}</a>
        </h3>
        @if ($post->excerpt)
            <p class="mt-2 line-clamp-2 flex-1 text-sm text-slate-500 dark:text-slate-400">{{ $post->excerpt }}</p>
        @endif
        <a href="{{ $url }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-brand-600 transition group-hover:gap-2 dark:text-brand-400">
            Read more
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </div>
</article>
