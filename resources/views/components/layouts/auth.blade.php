<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head')
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-screen">
    <div class="grid min-h-screen lg:grid-cols-2">
        {{-- Brand panel --}}
        <div class="relative hidden overflow-hidden bg-gradient-to-br from-brand-700 via-brand-800 to-brand-950 lg:block">
            <div class="pointer-events-none absolute inset-0 opacity-50">
                <div class="absolute -left-20 top-10 h-72 w-72 rounded-full bg-brand-400/30 blur-3xl"></div>
                <div class="absolute -right-10 bottom-10 h-72 w-72 rounded-full bg-accent-400/20 blur-3xl"></div>
            </div>
            <div class="relative flex h-full flex-col justify-between p-12 text-white">
                <x-logo class="h-10" />

                <div class="flex flex-1 items-center justify-center py-12">
                    <img src="{{ asset('images/etec-logo.jpeg') }}" alt="ETEC College" class="max-h-80 w-auto rounded-2xl shadow-2xl">
                </div>

                <p class="text-sm text-white/50">&copy; {{ date('Y') }} ETEC. All rights reserved.</p>
            </div>
        </div>

        {{-- Form panel --}}
        <div class="flex items-center justify-center px-4 py-10 sm:px-6 lg:px-12">
            <div class="w-full max-w-md">
                {{-- Mobile logo --}}
                <div class="mb-8 flex items-center justify-between lg:hidden">
                    <x-logo />
                    <x-theme-toggle />
                </div>
                <div class="mb-8 hidden justify-end lg:flex">
                    <x-theme-toggle />
                </div>

                {{ $slot }}
            </div>
        </div>
    </div>
    {{-- Endpoint + CSRF token for the Livewire runtime bundled in app.js.
         Required on EVERY layout: config/livewire.php sets inject_assets to
         false, so this is the only thing that tells Livewire where to post.
         Harmless on pages with no Livewire component. --}}
    @livewireScriptConfig
</body>
</html>
