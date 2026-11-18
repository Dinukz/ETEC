<x-layouts.app title="Dashboard" header="Dashboard">
    {{-- Welcome --}}
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Welcome back, {{ explode(' ', $user->name)[0] }} 👋</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Here's what's happening with your learning journey.</p>
    </div>

    {{-- Stat cards --}}
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Active Courses', 'value' => $stats['active'], 'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253', 'color' => 'brand'],
                ['label' => 'Completed', 'value' => $stats['completed'], 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => 'emerald'],
                ['label' => 'Certificates', 'value' => $stats['certificates'], 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622', 'color' => 'accent'],
                ['label' => 'Avg. Progress', 'value' => $stats['avg_progress'] . '%', 'icon' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6', 'color' => 'violet'],
            ];
            $cMap = [
                'brand' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400',
                'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
                'accent' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
                'violet' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400',
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="card p-6">
                <div class="flex items-center justify-between">
                    <span class="grid h-12 w-12 place-items-center rounded-xl {{ $cMap[$card['color']] }}"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $card['icon'] }}"/></svg></span>
                </div>
                <div class="mt-4 text-3xl font-extrabold text-slate-900 dark:text-white">{{ $card['value'] }}</div>
                <div class="text-sm text-slate-500 dark:text-slate-400">{{ $card['label'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- Continue learning --}}
    <div class="mt-8">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Continue learning</h3>
            <a href="{{ route('student.courses') }}" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">View all</a>
        </div>

        @if ($continueLearning->isEmpty())
            <div class="card p-12 text-center">
                <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253"/></svg>
                </span>
                <h4 class="mt-5 text-lg font-bold text-slate-900 dark:text-white">Start your learning journey</h4>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">You're not enrolled in any courses yet. Explore our catalog to get started.</p>
                <a href="{{ route('courses.index') }}" class="btn-primary mt-6">Browse courses</a>
            </div>
        @else
            <div class="grid gap-5 lg:grid-cols-3">
                @foreach ($continueLearning as $enrollment)
                    @include('dashboard.partials.enrollment-card', ['enrollment' => $enrollment])
                @endforeach
            </div>
        @endif
    </div>

    {{-- Recommended (if user has no or few courses) --}}
    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="card p-6">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Quick actions</h3>
            <div class="mt-4 grid grid-cols-2 gap-3">
                @foreach ([
                    ['t' => 'Browse Courses', 'u' => route('courses.index'), 'i' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
                    ['t' => 'My Certificates', 'u' => route('student.certificates'), 'i' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ['t' => 'Edit Profile', 'u' => route('profile.edit'), 'i' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
                    ['t' => 'Get Support', 'u' => route('contact'), 'i' => 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z'],
                ] as $qa)
                    <a href="{{ $qa['u'] }}" class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 transition hover:border-brand-300 hover:bg-brand-50 dark:border-slate-800 dark:hover:border-brand-500/40 dark:hover:bg-brand-500/10">
                        <svg class="h-5 w-5 text-brand-600 dark:text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $qa['i'] }}"/></svg>
                        <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $qa['t'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="card flex flex-col justify-center bg-gradient-to-br from-accent-500 to-accent-600 p-6 text-slate-900">
            <h3 class="text-lg font-extrabold">Refer & earn with ETEC</h3>
            <!-- <p class="mt-2 text-sm text-slate-900/80">Know someone who'd love to learn? Become a consultant and earn up to LKR 15,000 per group of students you refer.</p> -->
            <a href="{{ route('about') }}" class="mt-4 inline-flex w-fit items-center gap-1.5 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">
                Learn more
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
        </div>
    </div>
</x-layouts.app>
