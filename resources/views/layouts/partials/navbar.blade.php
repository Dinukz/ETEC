@php
    $nav = [
        // Order mirrors the reference the owner supplied. "News & Events" is the
        // existing blog route relabelled — a second news system would have been
        // two places to publish the same thing.
        ['label' => 'Home', 'route' => 'home', 'url' => url('/')],
        ['label' => 'About Us', 'route' => 'about', 'url' => route('about')],
        ['label' => 'Courses', 'route' => 'courses.*', 'url' => route('courses.index')],
        ['label' => 'Services', 'route' => 'services', 'url' => route('services')],
        ['label' => 'News & Events', 'route' => 'blog.*', 'url' => route('blog.index')],
        ['label' => 'Careers', 'route' => 'careers', 'url' => route('careers')],
        ['label' => 'Contact Us', 'route' => 'contact', 'url' => route('contact')],
    ];
@endphp

<header
    x-data="{ open: false, scrolled: false }"
    @scroll.window="scrolled = window.scrollY > 20"
    class="sticky top-0 z-50 transition-all duration-300"
    :class="scrolled ? 'bg-white/90 shadow-sm backdrop-blur-lg dark:bg-slate-950/90' : 'bg-transparent'"
>
    <nav class="container-app flex h-16 items-center justify-between gap-4 lg:h-20">
        <x-logo />

        {{-- Desktop nav --}}
        <div class="hidden items-center gap-8 lg:flex">
            @foreach ($nav as $item)
                <x-nav-link :href="$item['url']" :active="request()->routeIs($item['route'])">{{ $item['label'] }}</x-nav-link>
            @endforeach
        </div>

        {{-- Right actions --}}
        <div class="flex items-center gap-2">
            <x-theme-toggle />

            @auth
                <a href="{{ url('/dashboard') }}" class="btn-primary hidden sm:inline-flex">Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="btn-ghost hidden sm:inline-flex">Sign in</a>
                <!-- <a href="{{ route('register') }}" class="btn-primary hidden sm:inline-flex">Get started</a> -->
            @endauth

            {{-- Mobile toggle --}}
            <button @click="open = !open" type="button" class="grid h-10 w-10 place-items-center rounded-xl text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 lg:hidden" aria-label="Menu">
                <svg x-show="!open" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <svg x-show="open" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </nav>

    {{-- Mobile menu --}}
    <div x-show="open" x-cloak x-collapse class="border-t border-slate-200 bg-white lg:hidden dark:border-slate-800 dark:bg-slate-950">
        <div class="container-app space-y-1 py-4">
            @foreach ($nav as $item)
                <a href="{{ $item['url'] }}" class="block rounded-xl px-4 py-3 text-base font-medium {{ request()->routeIs($item['route']) ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400' : 'text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800' }}">{{ $item['label'] }}</a>
            @endforeach
            <div class="!mt-4 flex gap-3 border-t border-slate-200 pt-4 dark:border-slate-800">
                @auth
                    <a href="{{ url('/dashboard') }}" class="btn-primary flex-1">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn-secondary flex-1">Sign in</a>
                    <a href="{{ route('register') }}" class="btn-primary flex-1">Get started</a>
                @endauth
            </div>
        </div>
    </div>
</header>
