<x-layouts.app title="Super Admin Dashboard" header="Super Admin Dashboard">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">System Overview</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Full visibility across users, courses, and finances.</p>
    </div>

    {{-- Primary stats --}}
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Total Users" :value="number_format($stats['users'])" color="brand" icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z" />
        <x-stat-card label="Courses" :value="number_format($stats['courses'])" color="violet" icon="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253" />
        <x-stat-card label="Enrollments" :value="number_format($stats['enrollments'])" color="sky" icon="M12 14l9-5-9-5-9 5 9 5z" />
        <x-stat-card label="Total Revenue" :value="'LKR ' . number_format($stats['revenue'])" color="emerald" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        {{-- Users by role --}}
        <div class="card p-6">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Users by role</h3>
            <div class="mt-5 space-y-4">
                @php
                    $roleOrder = ['super_admin', 'admin', 'accountant', 'lecturer', 'consultant', 'student'];
                    $maxRole = max(1, $usersByRole->max() ?? 1);
                @endphp
                @foreach ($roleOrder as $role)
                    @php $count = $usersByRole[$role] ?? 0; @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium text-slate-600 dark:text-slate-300">{{ \Illuminate\Support\Str::headline($role) }}</span>
                            <span class="font-bold text-slate-900 dark:text-white">{{ $count }}</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ ($count / $maxRole) * 100 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <a href="{{ route('users.index') }}" class="btn-secondary mt-6 w-full">Manage users</a>
        </div>

        {{-- Secondary stats + recent users --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-stat-card label="Students" :value="number_format($stats['students'])" color="sky" icon="M12 14l9-5-9-5-9 5 9 5z" />
                <x-stat-card label="Consultants" :value="number_format($stats['consultants'])" color="amber" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
                <x-stat-card label="Pending Payments" :value="number_format($stats['pending_payments'])" color="rose" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </div>

            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 p-5 dark:border-slate-800">
                    <h3 class="font-bold text-slate-900 dark:text-white">Recent users</h3>
                    <a href="{{ route('users.index') }}" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">View all</a>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($recentUsers as $u)
                        <li class="flex items-center gap-3 p-4">
                            <x-user-avatar :user="$u" size="h-9 w-9" text="text-sm" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $u->name }}</div>
                                <div class="truncate text-xs text-slate-400">{{ $u->email }}</div>
                            </div>
                            <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ \Illuminate\Support\Str::headline($u->roles->first()?->name ?? '—') }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    {{-- Recent activity --}}
    @if ($recentActivity->isNotEmpty())
        <div class="card mt-6 p-6">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Recent activity</h3>
            <ul class="mt-4 space-y-3">
                @foreach ($recentActivity as $log)
                    <li class="flex items-start gap-3 text-sm">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-500"></span>
                        <div class="flex-1">
                            <span class="text-slate-700 dark:text-slate-200">{{ ucfirst($log->description) }}</span>
                            @if ($log->causer)<span class="text-slate-400"> by {{ $log->causer->name }}</span>@endif
                            <span class="block text-xs text-slate-400">{{ $log->created_at->diffForHumans() }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-layouts.app>
