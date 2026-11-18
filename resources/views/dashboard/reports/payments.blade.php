<x-layouts.app title="Payment Summary Report" header="Payment Summary Report">
    <div class="mb-6 sm:mb-8">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Payment Summary Report</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            @if ($viewerConsultant)
                Your income and your team's — everyone below you — for a date range: how many 2,500s, 4,000s and 5,000s each account earned.
            @else
                Direct-add bonuses and service-group income per consultant for a date range.
            @endif
        </p>
        {{-- ⚠️ THE WHOLE RULE OF THIS PAGE, IN ONE LINE (2026-09-24). Everything
             here is money that actually reached the consultant inside the
             selected dates — nothing waiting on a run, nothing outside the
             range. Said out loud because the figures got smaller when the
             out-of-range columns came off. --}}
        <p class="mt-1 text-sm text-slate-400">
            Service Group income is read from the System Runs themselves — each run is counted on the date it settled up to,
            so these figures are exactly what the runs paid out. The direct add bonus is counted on its payslip date.
            Income still waiting on a run is on the Service Groups page.
        </p>
    </div>

    <form method="GET" action="{{ route('reports.payments') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:flex-wrap lg:items-end">
        @unless ($viewerConsultant)
            <div>
                <label for="marketer" class="label">Marketer Code</label>
                <select id="marketer" name="marketer" class="input lg:w-56">
                    <option value="">All marketers</option>
                    @foreach ($marketers as $m)
                        <option value="{{ $m->id }}" @selected(request('marketer') == $m->id)>{{ $m->scn_code }} — {{ $m->display_name }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
        <div>
            <label for="from" class="label">From</label>
            <input id="from" name="from" type="date" value="{{ $from->toDateString() }}" class="input">
        </div>
        <div>
            <label for="to" class="label">To</label>
            <input id="to" name="to" type="date" value="{{ $to->toDateString() }}" class="input">
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn-primary flex-1 justify-center sm:flex-none">Generate Report</button>
            <a href="{{ route('reports.payments', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn-secondary flex-1 justify-center sm:flex-none">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Excel
            </a>
            <a href="{{ route('reports.payments', array_merge(request()->query(), ['export' => 'pdf'])) }}" target="_blank" rel="noopener" class="btn-secondary flex-1 justify-center sm:flex-none">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                PDF
            </a>
            {{-- The PDF's bank deposit list (one line per person, NIC + bank) as
                 an .xlsx — the "Excel" button above is the per-account table. --}}
            <a href="{{ route('reports.payments', array_merge(request()->query(), ['export' => 'bank-excel'])) }}" class="btn-secondary flex-1 justify-center sm:flex-none">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Bank Deposit Excel
            </a>
        </div>
    </form>

    {{-- For a consultant this is now THEIR OWN students' payments, not the
         company's total course revenue — so it has to be labelled as such, or
         it reads like money they earned. --}}
    <div class="mb-6 grid gap-5 {{ $viewerConsultant ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
        <x-stat-card label="{{ $viewerConsultant ? 'Payments from your students' : 'Verified payments in range' }}" :value="'LKR ' . number_format($totals['payments'])" color="emerald" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="{{ $viewerConsultant ? 'Your service income' : 'Service income recorded' }}" :value="'LKR ' . number_format($totals['commissions'])" color="brand" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
        @if ($viewerConsultant)
            <x-stat-card label="You and your team (everyone below you)" :value="'LKR ' . number_format($totals['team'])" color="amber" icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z" />
        @endif
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-left text-sm">
                {{-- ⚠️ COUNTS, SRN AND SCN (2026-10-25). Owner chose counts: how many
                     2,500s / 4,000s / 5,000s each account earned in the range, with
                     the money under each count. A consultant sees their whole team
                     here in tree order; see ReportController::withCountsAndSrn(). --}}
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">SRN</th>
                        <th class="px-6 py-4 font-semibold">SCN</th>
                        <th class="px-6 py-4 font-semibold">Name</th>
                        <th class="px-6 py-4 font-semibold">{{ number_format($slotRate) }} ×<span class="block font-normal normal-case">Service Group</span></th>
                        @foreach ($directAmounts as $amount)
                            <th class="px-6 py-4 font-semibold">{{ number_format((float) $amount) }} ×<span class="block font-normal normal-case">Direct bonus</span></th>
                        @endforeach
                        <th class="px-6 py-4 font-semibold">Total Income</th>
                        {{-- ⚠️ THREE COLUMNS WERE REMOVED HERE (2026-09-24): Released
                             Today, Released This Week and Pending. The first two
                             ignored the date filter (fixed today / finance-week
                             windows) and the third was slot money NO RUN HAS
                             RELEASED — which is exactly what the owner has been
                             reporting as this report "showing more" than the
                             consultants were paid. What is left is in-range money
                             only: the direct bonus, and stage income a System Run
                             released. Un-released money belongs on the Service
                             Groups page, which exists to show it. --}}
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($commissions as $row)
                        <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-4 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $row->srn ?? '—' }}</td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $row->consultant?->scn_code ?? $row->consultant?->consultant_code ?? '—' }}</td>
                            <td class="px-6 py-4 font-semibold text-slate-900 dark:text-white" @if ($row->level) style="padding-left: {{ 1.5 + min($row->level, 8) * 1.25 }}rem" @endif>
                                {{ $row->consultant?->display_name ?? '—' }}
                                @if ($ownIds !== null && in_array($row->consultant_id, $ownIds, true))
                                    <span class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">You</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                <span class="font-bold text-slate-900 dark:text-white">{{ $row->slot_count + 0 }}</span>
                                <span class="block text-xs text-slate-400">LKR {{ number_format($row->group_income) }}</span>
                            </td>
                            @foreach ($directAmounts as $amount)
                                <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                    <span class="font-bold text-slate-900 dark:text-white">{{ $row->direct_counts[$amount] ?? 0 }}</span>
                                    <span class="block text-xs text-slate-400">LKR {{ number_format(((int) ($row->direct_counts[$amount] ?? 0)) * (float) $amount) }}</span>
                                </td>
                            @endforeach
                            <td class="px-6 py-4 font-bold text-slate-900 dark:text-white">LKR {{ number_format($row->total_income, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 5 + count($directAmounts) }}" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">No service income in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- What each System Run paid out, date by date (2026-09-22). Read from the
         run's own record of what it released — ReportController::runPayouts(). --}}
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Paid out by each System Run</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $viewerConsultant ? 'What each run released to your accounts' : 'What each run released' }}
                between {{ $from->format('M d, Y') }} and {{ $to->format('M d, Y') }}. Undone runs are left out.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[36rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Run date</th>
                        <th class="px-6 py-4 font-semibold">Settled up to</th>
                        <th class="px-6 py-4 font-semibold">Accounts paid</th>
                        <th class="px-6 py-4 text-right font-semibold">Paid out</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($runPayouts as $run)
                        <tr>
                            <td class="px-6 py-4 text-slate-700 dark:text-slate-200">
                                {{ \Illuminate\Support\Carbon::parse($run['date'])->format('D, M d, Y') }}
                                <span class="block text-xs text-slate-400">{{ $run['time'] }} · Run #{{ $run['run_id'] }}</span>
                            </td>
                            <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Carbon::parse($run['settled_up_to'])->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ number_format($run['accounts']) }}</td>
                            <td class="px-6 py-4 text-right font-semibold {{ $run['amount'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400' }}">LKR {{ number_format($run['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">No System Run in this range.</td></tr>
                    @endforelse
                </tbody>
                @if ($runPayouts->isNotEmpty())
                    <tfoot class="border-t border-slate-200 dark:border-slate-800">
                        <tr>
                            <td colspan="3" class="px-6 py-4 font-semibold text-slate-700 dark:text-slate-200">Total paid out in this range</td>
                            <td class="px-6 py-4 text-right font-bold text-slate-900 dark:text-white">LKR {{ number_format($runPayouts->sum('amount'), 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</x-layouts.app>
