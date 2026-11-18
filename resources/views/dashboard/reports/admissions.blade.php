<x-layouts.app title="Admission Report" header="Admission Report">
    @php
        // ⚠️ ONE FLAG, READ FROM THE CONTROLLER — never re-derived here.
        //
        // $seesDownline is computed next to the query that enforces it
        // (ReportController::admissions), so the controls a person is offered and
        // the rows they are allowed cannot drift apart. A second copy of this
        // test in the view is how a screen ends up offering a filter the query
        // refuses, or worse, the other way round.
        $canFilter = ! $viewerConsultant || ($seesDownline ?? false);
    @endphp

    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Admission Report</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">
            @if ($viewerConsultant && ($seesDownline ?? false))
                Students registered by you and by everyone in your network, for a date range.
            @elseif ($viewerConsultant)
                Students you've registered for a date range.
            @else
                Students registered per consultant for a date range.
            @endif
        </p>
        {{-- ⚠️ SAID OUT LOUD, BECAUSE IT MAKES EVERY NUMBER SMALLER (2026-09-23).
             Free cards are the majority of registrations, so without this line a
             marketer's count halving overnight reads as lost data. --}}
        <p class="mt-1 text-sm text-slate-400">Counts paid registrations only — half and full payments. Free cards are not included.</p>
    </div>

    <form method="GET" action="{{ route('reports.admissions') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:flex-wrap lg:items-end">
        @if ($canFilter)
            {{-- ⚠️ "SCN Account", searchable (2026-10-01). Picking one loads that
                 account and everything under it — see ReportController::admissions().
                 The query parameter stays "marketer" so old links keep working.
                 style="display:none" (not x-cloak) because the built CSS has no
                 [x-cloak] rule. --}}
            @php
                $accountOptions = $marketers->map(fn ($m) => ['id' => $m->id, 'label' => $m->scn_code.' — '.$m->display_name])->values();
                $chosenOption = $accountOptions->firstWhere('id', (int) request('marketer'));
            @endphp
            <div class="lg:w-56" x-data="{
                    open: false,
                    search: {{ \Illuminate\Support\Js::from($chosenOption['label'] ?? '') }},
                    chosenId: {{ \Illuminate\Support\Js::from($chosenOption['id'] ?? '') }},
                    options: {{ \Illuminate\Support\Js::from($accountOptions) }},
                    get filtered() {
                        const q = this.search.trim().toLowerCase();
                        const list = q ? this.options.filter(o => o.label.toLowerCase().includes(q)) : this.options;
                        return list.slice(0, 50);
                    },
                    choose(option) {
                        this.chosenId = option ? option.id : '';
                        this.search = option ? option.label : '';
                        this.open = false;
                    },
                }">
                <label for="marketer-search" class="label">SCN Account</label>
                <input type="hidden" name="marketer" :value="chosenId">
                <div class="relative">
                    <input id="marketer-search" type="text" autocomplete="off" class="input"
                        x-model="search"
                        @input="chosenId = ''"
                        @focus="open = true" @click="open = true" @keydown.escape="open = false"
                        placeholder="{{ $viewerConsultant ? 'You and everyone below you' : 'All accounts' }} — type a code or name">
                    <div x-show="open" style="display: none" @click.outside="open = false"
                        class="absolute left-0 right-0 z-30 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                        <button type="button" @click="choose(null)"
                            class="block w-full border-b border-slate-100 px-4 py-2.5 text-left text-sm font-semibold text-slate-500 hover:bg-brand-50 dark:border-slate-800 dark:text-slate-400 dark:hover:bg-brand-500/10">
                            {{ $viewerConsultant ? 'You and everyone below you' : 'All accounts' }}
                        </button>
                        <template x-for="option in filtered" :key="option.id">
                            <button type="button" @click="choose(option)"
                                class="block w-full border-b border-slate-100 px-4 py-2.5 text-left text-sm text-slate-700 last:border-b-0 hover:bg-brand-50 dark:border-slate-800 dark:text-slate-200 dark:hover:bg-brand-500/10"
                                x-text="option.label"></button>
                        </template>
                        <p x-show="filtered.length === 0" class="px-4 py-3 text-sm text-slate-400">Nothing matches that search.</p>
                    </div>
                </div>
                <p class="mt-1 text-xs text-slate-400">Loads the account and everyone under it.</p>
            </div>
        @endif
        <div>
            <label for="from" class="label">From</label>
            <input id="from" name="from" type="date" value="{{ $from->toDateString() }}" class="input">
        </div>
        <div>
            <label for="to" class="label">To</label>
            <input id="to" name="to" type="date" value="{{ $to->toDateString() }}" class="input">
        </div>
        @if ($canFilter)
            <div>
                <label for="exclude" class="label">Exclude SCN Accounts</label>
                <select id="exclude" name="exclude[]" multiple class="input lg:w-56">
                    @foreach ($marketers as $m)
                        <option value="{{ $m->scn_code }}" @selected(in_array($m->scn_code, (array) request('exclude', [])))>{{ $m->scn_code }} — {{ $m->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="min" class="label">Min student count</label>
                <input id="min" name="min" type="number" min="0" value="{{ request('min', 0) }}" class="input lg:w-32">
            </div>
            <div>
                <label for="max" class="label">Max student count</label>
                <input id="max" name="max" type="number" min="0" value="{{ request('max') }}" class="input lg:w-32" placeholder="Unlimited">
            </div>
        @endif
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn-primary flex-1 justify-center sm:flex-none">Generate Report</button>
            <a href="{{ route('reports.admissions', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn-secondary flex-1 justify-center sm:flex-none">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Excel
            </a>
            <a href="{{ route('reports.admissions', array_merge(request()->query(), ['export' => 'pdf'])) }}" target="_blank" rel="noopener" class="btn-secondary flex-1 justify-center sm:flex-none">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                PDF
            </a>
        </div>
    </form>

    {{-- "Direct (no consultant)" is a company-wide figure, so it is staff-only —
         a consultant sees just their own two. --}}
    <div class="mb-6 grid gap-5 {{ $viewerConsultant ? 'sm:grid-cols-2' : 'sm:grid-cols-3' }}">
        @php
            $accountsLabel = match (true) {
                $viewerConsultant && ($seesDownline ?? false) => 'Accounts with admissions',
                (bool) $viewerConsultant => 'Your accounts with admissions',
                default => 'Consultants with admissions',
            };
            $totalLabel = match (true) {
                $viewerConsultant && ($seesDownline ?? false) => 'Admissions across your network',
                (bool) $viewerConsultant => 'Your admissions',
                default => 'Total admissions',
            };
        @endphp
        <x-stat-card :label="$accountsLabel" :value="number_format($rows->where('student_count', '>', 0)->count())" color="brand" icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z" />
        <x-stat-card :label="$totalLabel" :value="number_format($rows->sum('student_count'))" color="emerald" icon="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13" />
        @unless ($viewerConsultant)
            <x-stat-card label="Direct (no consultant)" :value="number_format($direct)" color="amber" icon="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
        @endunless
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="w-10 px-3 py-4"></th>
                        <th class="px-6 py-4 font-semibold">SCN Account</th>
                        <th class="px-6 py-4 font-semibold">Marketer Name</th>
                        <th class="px-6 py-4 font-semibold">Under</th>
                        <th class="px-6 py-4 font-semibold">Student Count</th>
                    </tr>
                </thead>
                @forelse ($rows as $row)
                    <tbody x-data="{ open: false }" class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr>
                            <td class="px-3 py-4">
                                @if ($row->student_count > 0)
                                    <button type="button" @click="open = !open" class="grid h-6 w-6 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 dark:hover:bg-slate-800" :class="open && 'rotate-45'">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                    </button>
                                @endif
                            </td>
                            {{-- Indented by level under a pick, so who-is-under-whom reads
                                 down the page. Inline padding: no utility exists for a
                                 computed indent. --}}
                            <td class="px-6 py-4 font-mono text-xs text-slate-500 dark:text-slate-400"
                                @if ($row->tree_level) style="padding-left: {{ 1.5 + $row->tree_level * 1.25 }}rem" @endif>
                                @if ($row->tree_level)<span class="text-slate-300 dark:text-slate-600">└ </span>@endif{{ $row->scn_code ?? $row->consultant_code }}
                            </td>
                            <td class="px-6 py-4 font-semibold text-slate-900 dark:text-white">{{ $row->display_name }}</td>
                            {{-- Whose downline this row is (2026-09-22). --}}
                            <td class="px-6 py-4 text-xs text-slate-500 dark:text-slate-400">{{ $row->under_label }}</td>
                            <td class="px-6 py-4"><span class="badge bg-brand-50 font-bold text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">{{ $row->student_count }}</span></td>
                        </tr>
                        @if ($row->student_count > 0)
                            <tr x-show="open" x-cloak>
                                <td></td>
                                <td colspan="4" class="px-6 pb-4">
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-800">
                                        <table class="w-full text-left text-xs">
                                            <thead class="text-slate-400">
                                                <tr>
                                                    <th class="px-4 py-2 font-semibold">Student</th>
                                                    <th class="px-4 py-2 font-semibold">SRN</th>
                                                    <th class="px-4 py-2 font-semibold">Registered</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                                @foreach ($row->students as $s)
                                                    <tr>
                                                        <td class="px-4 py-2 text-slate-700 dark:text-slate-200">{{ $s->user->name ?? '—' }}</td>
                                                        <td class="px-4 py-2 font-mono text-slate-500 dark:text-slate-400">{{ $s->displayCode() }}</td>
                                                        <td class="px-4 py-2 text-slate-500 dark:text-slate-400">{{ optional($s->created_at)->format('M d, Y') }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">No admissions in this range.</td></tr>
                    </tbody>
                @endforelse
            </table>
        </div>
    </div>
</x-layouts.app>
