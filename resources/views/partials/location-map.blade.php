{{-- ⚠️ THE CAMPUS ON A MAP (2026-11-02) — footer and Contact page. Owner: "footer
     ekat Map eka add krgann one ... Map ekak widiyt zoom in and zoom out krnn
     puluwn widiyta". A Google Maps embed: zoom, drag and full screen work with no
     API key. It searches the address from Settings → Contact address (the one the
     Contact page shows), so editing the address there moves the map too.
     $height: pixels (default 320). --}}
@php
    $mapAddress = trim((string) (\App\Models\Setting::get('contact_address') ?: 'No. 30A/01, Galle Road, Molligoda, Wadduwa'));
    $mapQuery = str_contains(strtolower($mapAddress), 'sri lanka') ? $mapAddress : $mapAddress.', Sri Lanka';
    $mapHeight = (int) ($height ?? 320);
@endphp
<div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-100 dark:border-slate-800 dark:bg-slate-900">
    <iframe
        src="https://maps.google.com/maps?q={{ urlencode($mapQuery) }}&amp;z=16&amp;output=embed"
        title="E-TEC location — {{ $mapAddress }}"
        style="display:block;width:100%;height:{{ $mapHeight }}px;border:0"
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade"
        allowfullscreen></iframe>
</div>
<a href="https://www.google.com/maps/search/?api=1&amp;query={{ urlencode($mapQuery) }}" target="_blank" rel="noopener"
    class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    Open in Google Maps
</a>
