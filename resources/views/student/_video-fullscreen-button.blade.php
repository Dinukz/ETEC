{{-- Fullscreen for the whole frame — video AND the E-TEC mark. The browser's own
     fullscreen button is switched off because it takes the bare video only. --}}
<button type="button" data-etec-fullscreen @click="toggleFullscreen()"
    class="absolute right-3 z-20 inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-bold"
    style="bottom:3.5rem;background:rgba(0,0,0,.55);color:#fff"
    title="Full screen">
    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4"/></svg>
    <span>Full screen</span>
</button>
