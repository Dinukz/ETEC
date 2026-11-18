@props(['label', 'value', 'icon', 'color' => 'brand', 'hint' => null])

@php
    $map = [
        'brand' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400',
        'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
        'violet' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400',
        'sky' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400',
    ];
    $classes = $map[$color] ?? $map['brand'];
@endphp

<div class="card p-6">
    <div class="flex items-start justify-between">
        <span class="grid h-12 w-12 place-items-center rounded-xl {{ $classes }}">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
        </span>
        @if ($hint)
            <span class="badge {{ $classes }}">{{ $hint }}</span>
        @endif
    </div>
    <div class="mt-4 text-3xl font-extrabold text-slate-900 dark:text-white">{{ $value }}</div>
    <div class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</div>
</div>
