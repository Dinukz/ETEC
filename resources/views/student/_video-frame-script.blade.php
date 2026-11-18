@once
    {{-- ⚠️ INLINE, NOT @push — this layout has no @stack, so a pushed script
         would silently render nowhere. Registered on alpine:init, which is safe
         because @vite emits a DEFERRED module in <head>: this inline script runs
         during parsing, Alpine.start() runs after. --}}
    <style>
        /* The frame, not the bare video, goes fullscreen — so the E-TEC mark
           comes with it. Inside it the picture fills the screen, letterboxed. */
        .etec-player:fullscreen { width: 100%; height: 100%; background: #000; }
        .etec-player:-webkit-full-screen { width: 100%; height: 100%; background: #000; }
        .etec-player:fullscreen video, .etec-player:fullscreen iframe { width: 100%; height: 100%; aspect-ratio: auto; object-fit: contain; }
        .etec-player:-webkit-full-screen video, .etec-player:-webkit-full-screen iframe { width: 100%; height: 100%; aspect-ratio: auto; object-fit: contain; }
    </style>
    <script>
        window.etecFullscreen = function (frame, video) {
            const current = document.fullscreenElement || document.webkitFullscreenElement;

            if (current) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                return;
            }

            if (frame.requestFullscreen) {
                frame.requestFullscreen().catch(() => {});
            } else if (frame.webkitRequestFullscreen) {
                frame.webkitRequestFullscreen();
            } else if (video && video.webkitEnterFullscreen) {
                // iPhone: the only fullscreen it has is its own player, which
                // cannot show anything from the page — the mark included.
                video.webkitEnterFullscreen();
            }
        };

        // A browser that still offers its own fullscreen (Firefox ignores
        // controlsList) would show the bare video without the mark — send it
        // back; the frame's own button is right there.
        document.addEventListener('fullscreenchange', () => {
            const el = document.fullscreenElement;

            if (el && el.tagName === 'VIDEO' && el.closest('.etec-player')) {
                document.exitFullscreen().catch(() => {});
            }
        });

        document.addEventListener('alpine:init', () => {
            Alpine.data('videoFrame', () => ({
                toggleFullscreen() {
                    window.etecFullscreen(this.$root, null);
                },
            }));
        });
    </script>
@endonce
