<x-layouts.app title="Admin Dashboard" header="Admin Dashboard">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Welcome back, {{ explode(' ', auth()->user()->name)[0] }} 👋</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Manage users, courses, and platform operations.</p>
    </div>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Total Users" :value="number_format($stats['users'])" color="brand" icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z" />
        <x-stat-card label="Published Courses" :value="number_format($stats['courses'])" color="violet" icon="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253" />
        <x-stat-card label="Enrollments" :value="number_format($stats['enrollments'])" color="sky" icon="M12 14l9-5-9-5-9 5 9 5z" />
        <x-stat-card label="Revenue" :value="'LKR ' . number_format($stats['revenue'])" color="emerald" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
    </div>

    {{-- Attention needed --}}
    <div class="mt-6 grid gap-5 sm:grid-cols-3">
        <a href="{{ route('users.index') }}" class="card flex items-center gap-4 p-5 transition hover:shadow-md">
            <span class="grid h-12 w-12 place-items-center rounded-xl bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
            <div><div class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $stats['pending_payments'] }}</div><div class="text-sm text-slate-500 dark:text-slate-400">Pending payments</div></div>
        </a>
        <a href="{{ route('users.index', ['role' => 'consultant']) }}" class="card flex items-center gap-4 p-5 transition hover:shadow-md">
            <span class="grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></span>
            <div><div class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $stats['pending_consultants'] }}</div><div class="text-sm text-slate-500 dark:text-slate-400">Consultants pending</div></div>
        </a>
        <div class="card flex items-center gap-4 p-5">
            <span class="grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg></span>
            <div><div class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $stats['new_messages'] }}</div><div class="text-sm text-slate-500 dark:text-slate-400">New messages</div></div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- Recent enrollments --}}
        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-5 dark:border-slate-800"><h3 class="font-bold text-slate-900 dark:text-white">Recent enrollments</h3></div>
            @if ($recentEnrollments->isEmpty())
                <p class="p-8 text-center text-sm text-slate-500 dark:text-slate-400">No enrollments yet.</p>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($recentEnrollments as $e)
                        <li class="flex items-center gap-3 p-4">
                            <x-user-avatar :user="$e->user" size="h-9 w-9" text="text-sm" gradient="from-sky-400 to-sky-600" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $e->user->name ?? 'Unknown' }}</div>
                                <div class="truncate text-xs text-slate-400">{{ $e->course->title ?? 'Course' }}</div>
                            </div>
                            <span class="text-xs text-slate-400">{{ optional($e->enrolled_at)->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Recent messages --}}
        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-5 dark:border-slate-800"><h3 class="font-bold text-slate-900 dark:text-white">Recent contact messages</h3></div>
            @if ($recentMessages->isEmpty())
                <p class="p-8 text-center text-sm text-slate-500 dark:text-slate-400">No messages yet.</p>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($recentMessages as $m)
                        <li class="p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $m->name }}</span>
                                @if ($m->status === 'new')<span class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">New</span>@endif
                            </div>
                            <p class="mt-1 line-clamp-1 text-xs text-slate-500 dark:text-slate-400">{{ $m->subject ? $m->subject.' — ' : '' }}{{ $m->message }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-layouts.app>
