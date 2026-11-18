<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    @include('layouts.partials.head')
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-screen">
    @include('layouts.partials.navbar')

    <main>
        @if (session()->has('success') || session()->has('error') || session()->has('status'))
            <div class="container-app pt-6">
                <x-flash />
            </div>
        @endif

        {{ $slot }}
    </main>

    @include('layouts.partials.footer')

    {{-- Scroll-reveal for the public pages.
         See the .reveal rules in app.css. The `js` class is what arms the
         hidden state, so a browser that never runs this renders everything
         visible instead of a blank page. --}}
    <script>
        document.documentElement.classList.add('js');

        document.addEventListener('DOMContentLoaded', function () {
            var targets = document.querySelectorAll('.reveal');

            if (!('IntersectionObserver' in window)) {
                targets.forEach(function (el) { el.classList.add('is-visible'); });
                return;
            }

            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-visible');
                    io.unobserve(entry.target);
                });
            }, { rootMargin: '0px 0px -10% 0px', threshold: 0.05 });

            targets.forEach(function (el) { io.observe(el); });
        });
    </script>

    {{-- Endpoint + CSRF token for the Livewire runtime bundled in app.js.
         Required on EVERY layout: config/livewire.php sets inject_assets to
         false, so this is the only thing that tells Livewire where to post.
         Harmless on pages with no Livewire component. --}}
    @livewireScriptConfig
</body>
</html>
