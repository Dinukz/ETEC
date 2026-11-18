<x-layouts.app title="Commission Rule Audit" header="Commission Rule Audit">
    <div class="mb-6 sm:mb-8">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Does every rupee follow the rules?</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Checks every verified payment on this database — the LKR {{ number_format($rates['slot']) }} service-group
            income, the LKR {{ number_format($rates['direct']) }} direct bonus (or a course's own amount, where it
            sets one) and the upline climb — plus every account stage. <strong>This page changes nothing</strong>
            and is safe to open at any time.
        </p>
    </div>

    {{-- Scope control. A full walk on a large network is the honest default,
         but a spot check has to be possible without waiting for it. --}}
    <form method="GET" class="card mb-6 flex flex-wrap items-end gap-3 p-5">
        <div>
            <label for="limit" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                Newest payments to check
            </label>
            <input id="limit" name="limit" type="number" min="0" step="50" value="{{ $limit ?: '' }}"
                placeholder="all" class="input sm:w-48">
        </div>
        <button type="submit" class="btn-secondary">Run the check</button>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Leave blank to check everything. Checked this time: <strong>{{ number_format($checked) }}</strong> payment(s).
            {{-- Named rather than silently dropped: a payment whose income an
                 admin removed has no live 2,500 to check against, so auditing it
                 would report every deliberate correction as a fault. See
                 CommissionRuleAudit::run(). --}}
            @if (($skipped_removed ?? 0) > 0)
                <span class="text-slate-500 dark:text-slate-400">
                    {{ number_format($skipped_removed) }} payment(s) were left out because their income was removed by the office.
                </span>
            @endif
        </p>
    </form>

    {{-- The headline. Green is a real answer here, not an empty state, so it
         says what was checked rather than just "nothing found". --}}
    @if ($violations === [])
        <div class="card mb-6 border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <h3 class="font-bold text-emerald-700 dark:text-emerald-400">No rule broken</h3>
            <p class="mt-1 text-sm text-emerald-700/90 dark:text-emerald-300/90">
                All {{ number_format($checked) }} verified payment(s) recorded exactly the income they should,
                and every 2,500 climbed as far as it was meant to.
            </p>
        </div>
    @else
        <div class="card mb-6 border-rose-200 bg-rose-50 p-5 dark:border-rose-500/30 dark:bg-rose-500/10">
            <h3 class="font-bold text-rose-700 dark:text-rose-400">
                {{ number_format(count($violations)) }} problem(s) across {{ number_format($checked) }} payment(s)
            </h3>
            <p class="mt-1 text-sm text-rose-700/90 dark:text-rose-300/90">
                Each one names the payment and the account, so it can be opened directly.
            </p>
        </div>
    @endif

    {{-- Advisories: shapes worth a look that are NOT defects. Kept out of the
         headline count deliberately — a page that reports correct data as a
         problem stops being read. See CommissionRuleAudit::ADVISORY. --}}
    @if (($notices ?? []) !== [])
        <div class="card mb-6 overflow-hidden border-amber-200 dark:border-amber-500/30">
            <div class="border-b border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/10">
                <h3 class="font-bold text-amber-800 dark:text-amber-300">
                    {{ number_format(count($notices)) }} thing(s) worth checking — not necessarily wrong
                </h3>
                <p class="mt-1 text-sm text-amber-800/90 dark:text-amber-200/90">
                    These look like a fault but have an innocent explanation too, so they are
                    listed rather than counted as problems above.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[42rem] text-left text-sm">
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach (array_slice($notices, 0, 100) as $n)
                            <tr>
                                <td class="px-5 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $n['account'] ?? '' }}</td>
                                <td class="px-5 py-3 text-slate-700 dark:text-slate-200">{{ $n['detail'] ?? ($rules[$n['rule']] ?? $n['rule']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if (count($notices) > 100)
                    <p class="px-5 py-3 text-xs text-slate-500 dark:text-slate-400">
                        Showing the first 100 of {{ number_format(count($notices)) }}.
                    </p>
                @endif
            </div>
        </div>
    @endif

    {{-- Counts per rule first: which KIND of thing is wrong matters more than
         the individual rows when there are hundreds of them. --}}
    @if ($summary !== [])
        <div class="card mb-6 overflow-hidden">
            <div class="border-b border-slate-100 p-5 dark:border-slate-800">
                <h3 class="font-bold text-slate-900 dark:text-white">What went wrong, by rule</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($summary as $rule => $count)
                            <tr>
                                <td class="px-5 py-3 font-semibold text-rose-600 dark:text-rose-400">{{ number_format($count) }}</td>
                                <td class="px-5 py-3 text-slate-700 dark:text-slate-200">{{ $rules[$rule] ?? $rule }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ⚠️ STAGES ARE INFORMATION ONLY (2026-09-14). This used to call a stage
         above its recorded pairings "wrong" and offer to put it back — which sent
         admin-set stages back to 1-to-1. Stages only move forward now. --}}
    <div class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Account stages</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Accounts at a higher stage than the pairings a System Run has recorded — usually set by an admin,
                or when the account was unlocked. Stages only move forward, so these are listed and left as they are.
            </p>
        </div>

        @if (! $stage_checkable)
            <div class="p-5 text-sm text-amber-600 dark:text-amber-400">
                No System Run on this database has recorded which accounts it settled, so a stage cannot be
                checked against anything yet. This is not a clean result — it means the question cannot be
                answered until the next run.
            </div>
        @elseif ($stage_issues === [])
            <div class="p-5 text-sm text-emerald-600 dark:text-emerald-400">
                Every account stage matches the pairings it has completed.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Account</th>
                            <th class="px-5 py-3 font-semibold">Stage</th>
                            <th class="px-5 py-3 font-semibold">Recorded pairings</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($stage_issues as $issue)
                            <tr>
                                <td class="px-5 py-3 font-mono font-semibold text-slate-900 dark:text-white">{{ $issue['code'] }}</td>
                                <td class="px-5 py-3 text-slate-900 dark:text-white">{{ $issue['claimed_label'] }}</td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $issue['paired'] }} time(s)</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @endif
    </div>

    {{-- The individual rows, capped: past a couple of hundred the list stops
         being readable and the per-rule counts above are the useful view. --}}
    @if ($violations !== [])
        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-5 dark:border-slate-800">
                <h3 class="font-bold text-slate-900 dark:text-white">Every problem found</h3>
                @if (count($violations) > 200)
                    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        Showing the first 200 of {{ number_format(count($violations)) }}.
                    </p>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Payment</th>
                            <th class="px-5 py-3 font-semibold">Plan</th>
                            <th class="px-5 py-3 font-semibold">Account</th>
                            <th class="px-5 py-3 font-semibold">Problem</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach (array_slice($violations, 0, 200) as $v)
                            <tr>
                                <td class="px-5 py-3 font-mono text-slate-900 dark:text-white">{{ $v['payment'] }}</td>
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ $v['plan'] }}</td>
                                <td class="px-5 py-3 font-mono text-slate-600 dark:text-slate-300">{{ $v['account'] }}</td>
                                <td class="px-5 py-3">
                                    <span class="block font-semibold text-rose-600 dark:text-rose-400">{{ $rules[$v['rule']] ?? $v['rule'] }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $v['detail'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-layouts.app>
