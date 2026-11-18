<x-layouts.app title="My Network" header="My Network">
    <div class="mb-6 sm:mb-8">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">My Network</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Every consultant below you. A student you register becomes a branch of your tree once they qualify as a
            consultant — and everything their branch registers keeps earning you service group income.
        </p>
    </div>

    <div class="grid gap-5 sm:grid-cols-3">
        <x-stat-card label="Consultants Below You" :value="$stats['downline']" color="brand" icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
        <x-stat-card label="Directly Sponsored" :value="$stats['direct']" color="emerald" icon="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
        <x-stat-card label="Levels Deep" :value="$stats['levels']" color="amber" icon="M4 6h16M4 12h16M4 18h16" />
    </div>

    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Your tree</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Each step to the right is one level further down your network.</p>
        </div>

        @if (count($tree) <= 1)
            <div class="p-8 text-center">
                <p class="text-sm font-semibold text-slate-900 dark:text-white">No consultants below you yet.</p>
                <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    When a student you registered finishes their course and is promoted to consultant, they appear here
                    automatically — and the students they bring in start earning you service group income.
                </p>
                <a href="{{ route('consultant.students.create') }}" class="btn-primary mt-5 inline-block">Register a Student</a>
            </div>
        @else
            {{-- Desktop --}}
            <div class="hidden overflow-x-auto sm:block">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3">Consultant</th>
                            <th class="px-5 py-3">Level</th>
                            <th class="px-5 py-3 text-right">Accounts</th>
                            <th class="px-5 py-3 text-right">Students</th>
                            <th class="px-5 py-3 text-right">Earned</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($tree as $row)
                            <tr class="{{ $row['level'] === 0 ? 'bg-brand-50/50 dark:bg-brand-500/5' : '' }}">
                                <td class="px-5 py-3">
                                    <div class="flex items-center" style="padding-left: {{ $row['level'] * 24 }}px">
                                        @if ($row['level'] > 0)
                                            <span class="mr-2 text-slate-300 dark:text-slate-600">└</span>
                                        @endif
                                        <div>
                                            <p class="font-semibold text-slate-900 dark:text-white">
                                                {{ $row['name'] }}
                                                @if ($row['level'] === 0)
                                                    <span class="badge ml-1 bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">You</span>
                                                @endif
                                            </p>
                                            <p class="font-mono text-xs text-slate-400">{{ $row['code'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                                    {{ $row['level'] === 0 ? '—' : 'Level '.$row['level'] }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-500 dark:text-slate-400">{{ $row['accounts'] }}</td>
                                <td class="px-5 py-3 text-right text-slate-500 dark:text-slate-400">{{ $row['students'] }}</td>
                                <td class="px-5 py-3 text-right font-semibold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($row['earned']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Mobile --}}
            <div class="divide-y divide-slate-100 sm:hidden dark:divide-slate-800">
                @foreach ($tree as $row)
                    <div class="p-4 {{ $row['level'] === 0 ? 'bg-brand-50/50 dark:bg-brand-500/5' : '' }}" style="padding-left: {{ 16 + $row['level'] * 16 }}px">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-900 dark:text-white">
                                    {{ $row['name'] }}
                                    @if ($row['level'] === 0)
                                        <span class="badge ml-1 bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">You</span>
                                    @endif
                                </p>
                                <p class="font-mono text-xs text-slate-400">{{ $row['code'] }}{{ $row['level'] > 0 ? ' · Level '.$row['level'] : '' }}</p>
                            </div>
                            <p class="shrink-0 text-sm font-semibold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($row['earned']) }}</p>
                        </div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $row['students'] }} student(s) · {{ $row['accounts'] }} account(s)</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
