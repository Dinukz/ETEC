<x-layouts.app title="Payments" header="Payment Verification">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Payments</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Review, verify, or reject student payments. Verification activates enrollment and records commission.</p>
    </div>

    {{-- ⚠️ THE CARDS STAY IN THE CONTROLLER, BY THE OWNER'S CHOICE (2026-09-10,
         "Popup and table only"). They are rendered from the request, so they
         follow the filters on a full page load but NOT on a Livewire round
         trip. Both places that can go stale say so out loud — the note below
         and the banner in the modal — rather than quietly disagreeing with the
         database, which is the 2026-09-06 Service Income bug. --}}
    {{-- total_verified is null for a group supporter — the card is not drawn. --}}
    <div @class(['grid gap-5 sm:grid-cols-2', 'lg:grid-cols-4' => $stats['total_verified'] !== null, 'lg:grid-cols-3' => $stats['total_verified'] === null])>
        <x-stat-card label="Pending" :value="number_format($stats['pending'])" color="amber" hint="Awaiting" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Verified" :value="number_format($stats['verified'])" color="emerald" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Rejected" :value="number_format($stats['rejected'])" color="rose" icon="M6 18L18 6M6 6l12 12" />
        @if ($stats['total_verified'] !== null)
            <x-stat-card label="Total Verified" :value="'LKR ' . number_format($stats['total_verified'])" color="brand" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
        @endif
    </div>

    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
        These counts are read when the page loads. Reload to bring them in line after verifying or changing the filters.
        @if ($from || $to)
            They currently cover payments paid
            @if ($from && $to)
                between {{ $from->format('M d, Y') }} and {{ $to->format('M d, Y') }}.
            @elseif ($from)
                on or after {{ $from->format('M d, Y') }}.
            @else
                on or before {{ $to->format('M d, Y') }}.
            @endif
        @endif
    </p>

    {{-- The filters, the table and the review popup. Review opens the popup
         instead of navigating, so the filters cannot be lost by verifying —
         they live in the query string as #[Url] props and the page never
         reloads. payments.show is untouched and still reachable from inside
         the popup and directly by URL. --}}
    @livewire('payment-queue')
</x-layouts.app>
