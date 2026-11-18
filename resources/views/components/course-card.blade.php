@props(['course'])

@php
    $price = (float) ($course->discount_price ?? $course->price);
    $hasDiscount = $course->discount_price && $course->discount_price < $course->price;
    $currency = $course->currency ?? 'LKR';
    $url = route('courses.show', $course->slug);
@endphp

<article class="card group flex flex-col overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
    <a href="{{ $url }}" class="relative block aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800">
        @if ($course->thumbnail)
            <img src="{{ $course->thumbnail_url }}" alt="{{ $course->title }}" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
        @else
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-500 to-brand-700 text-white">
                <svg class="h-12 w-12 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.25L4 3l8 3.25L20 3l-8 3.25zM4 3v7c2.67 3 11.33 3 14 0V3" /></svg>
            </div>
        @endif
        @if ($course->is_featured)
            <span class="badge absolute left-3 top-3 bg-accent-500 text-slate-900 shadow">Featured</span>
        @endif
        @if ($course->level)
            <span class="badge absolute right-3 top-3 bg-white/90 text-slate-700 backdrop-blur dark:bg-slate-900/80 dark:text-slate-200">{{ ucfirst($course->level) }}</span>
        @endif
    </a>
S
    <div class="flex flex-1 flex-col p-5">
        @if ($course->category)
            <span class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-400">{{ $course->category }}</span>
        @endif
        <h3 class="mt-1.5 line-clamp-2 text-base font-bold leading-snug text-slate-900 dark:text-white">
            <a href="{{ $url }}" class="transition hover:text-brand-600 dark:hover:text-brand-400">{{ $course->title }}</a>
        </h3>
        @if ($course->subtitle)
            <p class="mt-2 line-clamp-2 text-sm text-slate-500 dark:text-slate-400">{{ $course->subtitle }}</p>
        @endif

        <div class="mt-4 flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
            @if ($course->duration_text)
                <span class="inline-flex items-center gap-1">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg>
                    {{ $course->duration_text }}
                </span>
            @endif
            @if ($course->relationLoaded('lecturer') && $course->lecturer)
                <span class="inline-flex items-center gap-1 truncate">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 100-8 4 4 0 000 8zM4 20a8 8 0 0116 0"/></svg>
                    {{ $course->lecturer->name }}
                </span>
            @endif
        </div>

        <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
            <div class="flex items-baseline gap-2">
                <span class="text-lg font-extrabold text-slate-900 dark:text-white">{{ $currency }} {{ number_format($price) }}</span>
                @if ($hasDiscount)
                    <span class="text-sm text-slate-400 line-through">{{ number_format($course->price) }}</span>
                @endif
            </div>
            <a href="{{ $url }}" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 transition group-hover:gap-2 dark:text-brand-400">
                View
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
        </div>
    </div>
</article>
