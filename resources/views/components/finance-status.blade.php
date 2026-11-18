@props(['status'])

@php
    $map = [
        'active' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'restricted' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'suspended' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
    ];
    $classes = $map[$status] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
    $label = $status === '—' ? '—' : ucfirst($status);
@endphp

<span class="badge {{ $classes }}">{{ $label }}</span>
