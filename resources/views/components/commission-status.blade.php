@props(['status'])

@php
    $map = [
        'pending' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        // A held commission is still status='pending' underneath (see
        // Commission::getDisplayStatusAttribute()) — this is a distinct
        // display-only key, never a real stored value.
        'held' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
        'approved' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400',
        'paid' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'cancelled' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
        // Struck out by an admin, not forfeited to the company by a System Run.
        // Another display-only key — see Commission::getDisplayStatusAttribute().
        // Deliberately grey rather than rose: nothing went wrong, a wrong figure
        // was corrected, and rose here would read as money lost.
        'removed' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
    ];
    $classes = $map[$status] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
    $label = $status === 'held' ? 'On Hold' : ucfirst($status);
@endphp

<span class="badge {{ $classes }}">{{ $label }}</span>
