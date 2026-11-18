@props(['status'])

@php
    $map = [
        'active' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'inactive' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        'suspended' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
    ];
    $classes = $map[$status] ?? $map['inactive'];
@endphp

<span class="badge {{ $classes }}">{{ ucfirst($status) }}</span>
