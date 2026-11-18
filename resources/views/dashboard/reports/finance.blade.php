<x-layouts.app title="Finance Report" header="Finance Week Report">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Finance Week Report</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">
            @php $startDayName = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$config['start_day']]; @endphp
            @if ($viewerConsultant)
                Your weekly student quota history. Each finance week runs {{ $startDayName }}
                to the day before the following week, Sri Lanka time.
            @else
                Who has met this week's student quota, and who is restricted or suspended.
            @endif
        </p>
    </div>

    @unless ($viewerConsultant)
        <form method="GET" action="{{ route('reports.finance') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:flex-wrap lg:items-end">
            <div>
                <label for="period" class="label">Finance week</label>
                <select id="period" name="period" class="input lg:w-64">
                    @foreach ($periods as $p)
                        <option value="{{ $p->key() }}" @selected($selected->key() === $p->key())>
                            {{ $p->label() }}{{ $p->key() === $current->key() ? ' (current, open)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter" class="label">Completion</label>
                <select id="filter" name="filter" class="input lg:w-44">
                    <option value="">Everyone</option>
                    <option value="completed" @selected(request('filter') === 'completed')>Completed this week</option>
                    <option value="not_completed" @selected(request('filter') === 'not_completed')>Not completed</option>
                </select>
            </div>
            <div>
                <label for="status" class="label">Status</label>
                <select id="status" name="status" class="input lg:w-40">
                    <option value="">All statuses</option>
                    @foreach (['active', 'restricted', 'suspended'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="consultant" class="label">Consultant</label>
                <select id="consultant" name="consultant" class="input lg:w-56">
                    <option value="">Everyone</option>
                    @foreach ($consultants as $c)
                        <option value="{{ $c->id }}" @selected((int) request('consultant') === $c->id)>{{ $c->scn_code }} — {{ $c->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primary flex-1 justify-center sm:flex-none">Filter</button>
                <a href="{{ route('reports.finance', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn-secondary flex-1 justify-center sm:flex-none">Excel</a>
                <a href="{{ route('reports.finance', array_merge(request()->query(), ['export' => 'pdf'])) }}" target="_blank" rel="noopener" class="btn-secondary flex-1 justify-center sm:flex-none">PDF</a>
            </div>
        </form>

        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div class="grid flex-1 gap-4 sm:grid-cols-4">
                <x-stat-card label="Completed" :value="number_format(collect($rows)->where('met', true)->count())" color="emerald" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                <x-stat-card label="Not completed" :value="number_format(collect($rows)->where('met', false)->count())" color="amber" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                <x-stat-card label="Restricted" :value="number_format(collect($rows)->where('status', 'restricted')->count())" color="amber" icon="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                <x-stat-card label="Suspended" :value="number_format(collect($rows)->where('status', 'suspended')->count())" color="rose" icon="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
            </div>
            <form method="POST" action="{{ route('finance.evaluate') }}" class="shrink-0"
                onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Re-evaluating…';">
                @csrf
                <button type="submit" class="btn-secondary">Re-evaluate all consultants now</button>
            </form>
        </div>
    @endunless

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[50rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">SCN / Code</th>
                        <th class="px-6 py-4 font-semibold">Account Name</th>
                        @if ($viewerConsultant)
                            <th class="px-6 py-4 font-semibold">Finance Week</th>
                        @endif
                        <th class="px-6 py-4 font-semibold">Required</th>
                        <th class="px-6 py-4 font-semibold">Completed</th>
                        <th class="px-6 py-4 font-semibold">Remaining</th>
                        <th class="px-6 py-4 font-semibold">Progress</th>
                        <th class="px-6 py-4 font-semibold">Status</th>
                        @unless ($viewerConsultant)
                            <th class="px-6 py-4 text-right font-semibold">Action</th>
                        @endunless
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($rows as $row)
                        @php $pct = $row['required'] > 0 ? min(100, round($row['completed'] / $row['required'] * 100)) : 100; @endphp
                        <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-4 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $row['code'] }}</td>
                            <td class="px-6 py-4 font-semibold text-slate-900 dark:text-white">{{ $row['name'] }}</td>
                            @if ($viewerConsultant)
                                <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                    {{ $row['period']->label() }}
                                    @if ($row['period']->key() === $current->key())
                                        <span class="badge ml-1 bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">current</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ $row['required'] }}</td>
                            <td class="px-6 py-4 font-semibold {{ $row['met'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-900 dark:text-white' }}">{{ $row['completed'] }}</td>
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ $row['remaining'] }}</td>
                            <td class="px-6 py-4">
                                <div class="h-2 w-24 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                    <div class="h-full rounded-full {{ $row['met'] ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $pct }}%"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4"><x-finance-status :status="$row['status']" /></td>
                            @unless ($viewerConsultant)
                                <td class="px-6 py-4 text-right">
                                    @if ($row['status'] === 'suspended')
                                        <form method="POST" action="{{ route('finance.reactivate', $row['consultant']) }}"
                                            x-data
                                            @submit.prevent="confirm('Reactivate ' + @js($row['name']) + '? Their held income will be released.') && $el.submit()">
                                            @csrf
                                            <button type="submit" class="btn-primary py-1.5 text-xs">Reactivate</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                            @endunless
                        </tr>
                    @empty
                        <tr><td colspan="{{ $viewerConsultant ? 7 : 8 }}" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">No consultants match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
