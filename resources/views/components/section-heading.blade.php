@props(['eyebrow' => null, 'title', 'subtitle' => null, 'center' => false])

<div class="{{ $center ? 'mx-auto max-w-2xl text-center' : 'max-w-2xl' }}">
    @if ($eyebrow)
        <span class="badge bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ $eyebrow }}</span>
    @endif
    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $title }}</h2>
    @if ($subtitle)
        <p class="mt-4 text-base leading-relaxed text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
    @endif
</div>
