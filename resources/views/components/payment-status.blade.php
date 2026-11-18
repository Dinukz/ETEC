@props(['status', 'type' => null])

@php
    $map = [
        'pending' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'verified' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'rejected' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
        'refunded' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    ];
    $classes = $map[$status] ?? $map['refunded'];
    $label = ucfirst($status);

    if ($status === 'pending' && ! $type) {
        $label = 'Awaiting Confirmation';
    }

    if ($status === 'verified' && $type) {
        $label = match ($type) {
            'full' => 'Full Paid',
            'half' => 'Partial Paid',
            'free_card' => 'Free',
            'balance' => 'Balance Paid',
            default => 'Verified',
        };
    }

    if ($status === 'pending' && $type === 'balance') {
        $label = 'Balance Pending';
    }
@endphp

<span class="badge {{ $classes }}">{{ $label }}</span>
