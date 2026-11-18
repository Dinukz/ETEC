<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head')
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-screen bg-slate-100 dark:bg-slate-950">
<div x-data="{ sidebarOpen: false }" class="min-h-screen">
    @php
        $user = auth()->user();
        $navItems = \App\Support\Navigation::for($user);
        $roleName = $user->primaryRole();
        $roleLabel = \Illuminate\Support\Str::headline($roleName);
        $roleColors = [
            'super_admin' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
            'admin' => 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400',
            'accountant' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
            'lecturer' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-400',
            'group_supporter' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
            'consultant' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
            'student' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400',
        ];
        $roleBadge = $roleColors[$roleName] ?? $roleColors['student'];

        // The number this person is known by, surfaced across the dashboard
        // chrome. A consultant's SCN, or a student's SRN — since 2026-09-01 they
        // are the same digits with a different prefix, so a promoted student
        // sees SRN-00104 become SCN-00104 and nothing else about them changes.
        //
        // ⚠️ displayCode() already prefers the SCN when both exist, so this must
        // NOT be gated on the role name: a consultant who is also a student
        // would otherwise flip back to their SRN depending on which role
        // happened to be first in the list.
        $consultantScn = $user->displayCode();
    @endphp

    {{-- Mobile backdrop --}}
    <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" x-transition.opacity class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-sm lg:hidden"></div>

    {{-- Sidebar --}}
    <aside
        class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200 bg-white transition-transform duration-300 dark:border-slate-800 dark:bg-slate-900 lg:translate-x-0"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
        <div class="flex h-16 items-center justify-between border-b border-slate-200 px-6 dark:border-slate-800">
            <x-logo />
            <button @click="sidebarOpen = false" class="text-slate-400 lg:hidden"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>

        {{-- Role badge --}}
        <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <span class="badge w-full justify-center py-1.5 {{ $roleBadge }}">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ $roleLabel }}
            </span>
            @if ($consultantScn)
                <div class="mt-2 text-center">
                    <span class="block truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $user->name }}</span>
                    <span class="font-mono text-xs font-bold text-brand-600 dark:text-brand-400">{{ $consultantScn }}</span>
                </div>
            @endif
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto p-4">
            @foreach ($navItems as $item)
                @php $isActive = request()->routeIs($item['pattern']); @endphp
                <a href="{{ $item['url'] }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition {{ $isActive ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        {{-- Explore card (students only) --}}
        @if ($roleName === 'student')
            <div class="p-4">
                <div class="rounded-2xl bg-gradient-to-br from-brand-600 to-brand-800 p-5 text-white">
                    <h4 class="text-sm font-bold">Explore new skills</h4>
                    <p class="mt-1 text-xs text-white/70">Discover courses to advance your career.</p>
                    <a href="{{ route('courses.index') }}" class="mt-3 inline-block rounded-lg bg-white/15 px-3 py-1.5 text-xs font-semibold backdrop-blur transition hover:bg-white/25">Browse now</a>
                </div>
            </div>
        @endif
    </aside>

    {{-- Main --}}
    <div class="lg:pl-72">
        {{-- ⚠️ LOGGED IN AS SOMEBODY ELSE (2026-09-14). On every page, so a super
             admin never forgets whose account they are acting in. The button posts
             to impersonation.leave, which both global middlewares let through. --}}
        @if (session()->has(\App\Http\Controllers\Dashboard\ImpersonationController::SESSION_KEY))
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2 dark:border-amber-900/50 dark:bg-amber-950/30 sm:px-6">
                <p class="text-sm text-amber-900 dark:text-amber-200">
                    <strong>You are logged in as {{ $user->name }}</strong> ({{ $user->email }}). Everything you do here is done as them.
                </p>
                <form method="POST" action="{{ route('impersonation.leave') }}">
                    @csrf
                    <button type="submit" class="btn-secondary text-sm">Return to my account</button>
                </form>
            </div>
        @endif
        {{-- Topbar --}}
        <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/90 px-4 backdrop-blur-lg dark:border-slate-800 dark:bg-slate-900/90 sm:px-6">
            <button @click="sidebarOpen = true" class="text-slate-500 lg:hidden"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg></button>

            <div class="flex-1">
                <h1 class="text-lg font-bold text-slate-900 dark:text-white">{{ $header ?? 'Dashboard' }}</h1>
            </div>

            <x-theme-toggle />

            {{-- Notifications bell --}}
            @php $unreadNotifications = $user->unreadNotifications()->take(8)->get(); @endphp
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="relative grid h-9 w-9 place-items-center rounded-xl text-slate-500 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="Notifications">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    @if ($unreadNotifications->isNotEmpty())
                        <span class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-rose-500"></span>
                    @endif
                </button>

                <div x-show="open" x-cloak @click.outside="open = false" x-transition class="absolute right-0 mt-2 w-80 rounded-2xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2 dark:border-slate-800">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">Notifications</p>
                        @if ($unreadNotifications->isNotEmpty())
                            <form method="POST" action="{{ route('notifications.readAll') }}">
                                @csrf
                                <button class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Mark all read</button>
                            </form>
                        @endif
                    </div>
                    @if ($unreadNotifications->isEmpty())
                        <p class="px-3 py-6 text-center text-sm text-slate-500 dark:text-slate-400">You're all caught up.</p>
                    @else
                        <ul class="max-h-80 overflow-y-auto">
                            @foreach ($unreadNotifications as $n)
                                <li>
                                    <form method="POST" action="{{ route('notifications.read', $n->id) }}">
                                        @csrf
                                        <button type="submit" class="block w-full rounded-lg px-3 py-2.5 text-left transition hover:bg-slate-100 dark:hover:bg-slate-800">
                                            <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $n->data['title'] ?? 'Notification' }}</span>
                                            <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($n->data['message'] ?? '', 90) }}</span>
                                            <span class="mt-0.5 block text-[10px] text-slate-400">{{ $n->created_at->diffForHumans() }}</span>
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <a href="{{ route('notifications.index') }}" class="mt-1 block border-t border-slate-100 px-3 py-2 text-center text-sm font-medium text-brand-600 hover:underline dark:border-slate-800 dark:text-brand-400">View all</a>
                </div>
            </div>

            {{-- User dropdown --}}
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="flex items-center gap-2 rounded-xl p-1.5 transition hover:bg-slate-100 dark:hover:bg-slate-800">
                    <x-user-avatar :user="$user" size="h-9 w-9" text="text-sm" />
                    <span class="hidden text-left sm:block">
                        <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ \Illuminate\Support\Str::limit($user->name, 16) }}</span>
                        <span class="block text-xs text-slate-400">{{ $consultantScn ? $consultantScn : ucfirst($user->getRoleNames()->first() ?? 'Member') }}</span>
                    </span>
                    <svg class="hidden h-4 w-4 text-slate-400 sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>

                <div x-show="open" x-cloak @click.outside="open = false" x-transition class="absolute right-0 mt-2 w-56 rounded-2xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-800 dark:bg-slate-900">
                    <div class="border-b border-slate-100 px-3 py-2 dark:border-slate-800">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $user->name }}</p>
                        <p class="truncate text-xs text-slate-400">{{ $user->email }}</p>
                        @if ($consultantScn)
                            <p class="mt-0.5 font-mono text-xs font-bold text-brand-600 dark:text-brand-400">{{ $consultantScn }}</p>
                        @endif
                    </div>
                    <a href="{{ route('profile.edit') }}" class="mt-1 flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        My Profile
                    </a>
                    @if ($user->isStaff() || $user->hasRole('consultant'))
                        <a href="{{ url('/') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                            Visit Website
                        </a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-slate-100 pt-1 dark:border-slate-800">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="p-4 sm:p-6 lg:p-8">
            @if (session()->has('success') || session()->has('error') || session()->has('status'))
                <x-flash />
            @endif

            {{ $slot }}
        </main>
    </div>
</div>
    {{-- Endpoint + CSRF token for the Livewire runtime bundled in app.js.
         Required on EVERY layout: config/livewire.php sets inject_assets to
         false, so this is the only thing that tells Livewire where to post.
         Harmless on pages with no Livewire component. --}}
    @livewireScriptConfig
</body>
</html>
