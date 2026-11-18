<x-layouts.app title="Service Income" header="Service Income">
    <div class="mb-6 flex flex-col gap-4 sm:mb-8 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Service Income</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                View and manage service group income across each of your Service Group accounts.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" target="_blank" rel="noopener" class="btn-primary text-center">Download PDF</a>
            <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-secondary text-center">CSV</a>
        </div>
    </div>

    {{-- Grand totals across every account --}}
    <div class="card mb-6 grid gap-6 p-6 sm:grid-cols-3">
        <div class="text-center">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Total Earned</p>
            <p class="mt-1 text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 sm:text-3xl">{{ number_format($totals['earned']) }}</p>
        </div>
        <div class="text-center">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Total Payout</p>
            <p class="mt-1 text-2xl font-extrabold text-brand-600 dark:text-brand-400 sm:text-3xl">{{ number_format($totals['paid']) }}</p>
        </div>
        <div class="text-center">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Pending balance</p>
            <p class="mt-1 text-2xl font-extrabold text-amber-500 dark:text-amber-400 sm:text-3xl">{{ number_format($totals['pending']) }}</p>
        </div>
    </div>

    {{-- Pick which of the consultant's accounts to inspect --}}
    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        @foreach ($accountCards as $card)
            <a href="{{ request()->fullUrlWithQuery(['account' => $card['id'], 'page' => 1]) }}"
                class="card p-5 text-center transition hover:shadow-md {{ $card['selected'] ? 'ring-2 ring-brand-500' : '' }}">
                <p class="font-mono text-sm font-bold {{ $card['selected'] ? 'text-brand-600 dark:text-brand-400' : 'text-slate-600 dark:text-slate-300' }}">
                    {{ $card['code'] }}
                </p>
                @if ($card['is_owner'])
                    <span class="badge mt-1 bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">Main</span>
                @endif

                {{-- Where this account hangs, so the picker is not nine bare
                     SCN codes with nothing saying how they relate. --}}
                {{-- Block form on purpose — see the note in the Service Groups
                     table: a one-line opener pairs with any later raw-PHP closer
                     in the same file and swallows the directives between them. --}}
                @php
                    $place = $card['placement'] ?? null;
                @endphp
                @if ($place && $place['kind'] === 'bought-foreign')
                    {{-- Never drawn as a parent — see the Service Groups table. --}}
                    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        Purchase credited to <span class="font-mono">{{ $place['under_code'] }}</span> — not your account
                    </p>
                @elseif ($place && $place['kind'] !== 'main' && $place['kind'] !== 'unknown')
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        @if ($place['kind'] === 'bought')Placed under @else Inside @endif
                        <span class="font-mono">{{ $place['under_code'] }}</span>@if ($place['tier']) · SG{{ $place['tier'] }}@endif
                    </p>
                @endif
            </a>
        @endforeach
    </div>

    {{-- Totals for the selected account only --}}
    <div class="mb-6 grid gap-6 sm:grid-cols-3">
        <div class="text-center">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Total Earned</p>
            <p class="mt-1 text-xl font-extrabold text-emerald-600 dark:text-emerald-400 sm:text-2xl">{{ number_format($accountTotals['earned']) }}</p>
        </div>
        <div class="text-center">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Total Payout</p>
            <p class="mt-1 text-xl font-extrabold text-brand-600 dark:text-brand-400 sm:text-2xl">{{ number_format($accountTotals['paid']) }}</p>
        </div>
        <div class="text-center">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Pending balance</p>
            <p class="mt-1 text-xl font-extrabold text-amber-500 dark:text-amber-400 sm:text-2xl">{{ number_format($accountTotals['pending']) }}</p>
        </div>
    </div>

    {{-- The two income kinds are separate figures and are never added together
         into one "commission" number — see CommissionService. --}}
    <div class="mb-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Service Group Income" :value="'LKR ' . number_format($stats['slot_income'])" color="brand" icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
        <x-stat-card label="Direct Add Bonus" :value="'LKR ' . number_format($stats['direct_income'])" color="emerald" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
        <x-stat-card label="Pending Review" :value="'LKR ' . number_format($stats['awaiting'])" color="amber" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        @if ($stats['on_hold'] > 0)
            <x-stat-card label="On Hold" :value="'LKR ' . number_format($stats['on_hold'])" color="rose" icon="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
        @endif
    </div>

    @if ($stats['on_hold'] > 0)
        <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm dark:border-rose-500/30 dark:bg-rose-500/10">
            <span class="font-semibold text-rose-700 dark:text-rose-300">Some of your income is on hold.</span>
            <span class="text-rose-600 dark:text-rose-400">You missed this account's weekly student quota — this money is safe and will be released automatically once you meet the quota again.</span>
        </div>
    @endif

    {{-- Running-balance statement --}}
    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800 sm:px-6">
            <h3 class="font-bold text-slate-900 dark:text-white">
                Statement — <span class="font-mono">{{ $selected->scn_code ?? $selected->consultant_code }}</span>
            </h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Income is credited when payment verification auto-approves it, then debited when it is marked paid. Leg balances on the Service Groups page are tracked separately until System Run release.
            </p>
        </div>

        @if ($ledger->isEmpty())
            <p class="p-10 text-center text-sm text-slate-500 dark:text-slate-400">
                No approved service income for this account yet. Normal income appears here after payment verification; held or exception rows appear after review.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold sm:px-6">Date</th>
                            <th class="px-5 py-3 font-semibold sm:px-6">Description</th>
                            <th class="px-5 py-3 text-right font-semibold sm:px-6">Credit</th>
                            <th class="px-5 py-3 text-right font-semibold sm:px-6">Debit</th>
                            <th class="px-5 py-3 text-right font-semibold sm:px-6">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($ledger as $row)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="whitespace-nowrap px-5 py-3 text-slate-500 dark:text-slate-400 sm:px-6">{{ optional($row['at'])->format('Y-m-d') }}</td>
                                <td class="px-5 py-3 text-slate-700 dark:text-slate-200 sm:px-6">{{ $row['description'] }}</td>
                                <td class="px-5 py-3 text-right font-semibold sm:px-6 {{ $row['credit'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400' }}">
                                    {{ number_format($row['credit']) }}
                                </td>
                                <td class="px-5 py-3 text-right font-semibold sm:px-6 {{ $row['debit'] > 0 ? 'text-rose-500 dark:text-rose-400' : 'text-slate-400' }}">
                                    {{ number_format($row['debit']) }}
                                </td>
                                <td class="px-5 py-3 text-right font-bold text-slate-900 dark:text-white sm:px-6">{{ number_format($row['balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-8">{{ $ledger->links() }}</div>
</x-layouts.app>
