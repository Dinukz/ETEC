@props(['active' => false, 'href' => '#'])

@php
    $classes = $active
        ? 'text-brand-600 dark:text-brand-400'
        : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white';
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => "relative text-sm font-medium transition-colors $classes"]) }}>
    {{ $slot }}
    @if ($active)
        <span class="absolute -bottom-1.5 left-0 h-0.5 w-full rounded-full bg-brand-500"></span>
    @endif
</a>
