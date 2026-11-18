{{-- Session flash messages (success / error / status). Auto-dismisses. --}}
@php
    $types = [
        'success' => ['bg' => 'bg-emerald-50 dark:bg-emerald-500/10', 'text' => 'text-emerald-800 dark:text-emerald-300', 'ring' => 'ring-emerald-200 dark:ring-emerald-500/30'],
        'error'   => ['bg' => 'bg-rose-50 dark:bg-rose-500/10', 'text' => 'text-rose-800 dark:text-rose-300', 'ring' => 'ring-rose-200 dark:ring-rose-500/30'],
        'status'  => ['bg' => 'bg-brand-50 dark:bg-brand-500/10', 'text' => 'text-brand-800 dark:text-brand-300', 'ring' => 'ring-brand-200 dark:ring-brand-500/30'],
    ];
@endphp

@foreach ($types as $key => $style)
    @if (session($key))
        <div
            x-data="{ show: true }"
            x-show="show"
            x-init="setTimeout(() => show = false, 6000)"
            x-transition
            class="mb-4 flex items-start gap-3 rounded-xl px-4 py-3 text-sm font-medium ring-1 {{ $style['bg'] }} {{ $style['text'] }} {{ $style['ring'] }}"
        >
            <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                @if ($key === 'success')
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                @elseif ($key === 'error')
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" />
                @else
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                @endif
            </svg>
            <p class="flex-1">{{ session($key) }}</p>
            <button @click="show = false" type="button" class="shrink-0 opacity-60 hover:opacity-100">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    @endif
@endforeach
