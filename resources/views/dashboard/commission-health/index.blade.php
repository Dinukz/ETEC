<x-layouts.app title="Commission Health" header="Commission Health">
    <div class="mb-6 sm:mb-8">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Commission Health</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Why a student's income is or isn't showing. This page reads the live system — nothing here is a guess.
        </p>
    </div>

    @if (session('repair_output'))
        <div class="card mb-6 overflow-hidden">
            <div class="border-b border-slate-100 p-4 dark:border-slate-800">
                <h3 class="font-bold text-slate-900 dark:text-white">What the repair did</h3>
            </div>
            <pre class="overflow-x-auto p-4 text-xs leading-relaxed text-slate-600 dark:text-slate-300">{{ session('repair_output') }}</pre>
        </div>
    @endif

    {{-- Verdict --}}
    <div class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Diagnosis</h3>
        </div>
        <div class="p-5">
            @if (empty($problems))
                <p class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">No problems found.</p>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    The engine is configured correctly and every verified payment has been processed. Normal direct,
                    hidden slot, and upline income auto-approve at payment verification. If a consultant still shows
                    LKR 0, check whether the row is held, finance-restricted, or still waiting for System Run release.
                </p>
            @else
                <ol class="space-y-3">
                    @foreach ($problems as $problem)
                        <li class="flex gap-3 text-sm">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-rose-100 text-xs font-bold text-rose-600 dark:bg-rose-500/20 dark:text-rose-400">{{ $loop->iteration }}</span>
                            <span class="text-slate-700 dark:text-slate-300">{{ $problem }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif

            <div class="mt-6 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('commission-health.repair') }}"
                    onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Repairing…';">
                    @csrf
                    <button type="submit" class="btn-primary">Run Repair</button>
                </form>

                @if (! empty($drifted))
                    <form method="POST" action="{{ route('commission-health.reconcile') }}"
                        onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Fixing…';">
                        @csrf
                        <button type="submit" class="btn-secondary">Fix Migration Records ({{ count($drifted) }})</button>
                    </form>
                @endif

                {{-- READ-ONLY. "Can each SCN number be its SRN number?" depends on
                     live data, and there is no CLI here — so it is counted in the
                     browser. A link, not a form: this page writes nothing. --}}
                <a href="{{ route('commission-health.scn-numbers') }}" class="btn-secondary">SCN / SRN Numbers</a>
                {{-- A branch cut loose by a deletion is invisible everywhere
                     else on the site, so the only way to find it is a page
                     that looks for it deliberately. --}}
                <a href="{{ route('commission-health.broken-uplines') }}" class="btn-secondary">Broken Uplines</a>
                {{-- Hand entries that skipped a main account before 2026-09-27.
                     A link to a preview, not a button: that page writes money. --}}
                @role('super_admin')
                    <a href="{{ route('commission-health.manual-mains') }}" class="btn-secondary">Hand Entries Missing a Main</a>
                @endrole

                {{-- ⚠️ A course bought on a free card opened no SCN accounts until
                     2026-09-14, so those consultants are missing a whole set. --}}
                @if (($missingAccountSets ?? 0) > 0)
                    @role('super_admin')
                        <form method="POST" action="{{ route('commission-health.open-account-sets') }}"
                            onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Opening…';">
                            @csrf
                            <button type="submit" class="btn-secondary">Open Missing Free-Card Accounts ({{ $missingAccountSets }})</button>
                        </form>
                    @endrole
                @endif

                {{-- ⚠️ Money hidden by a held_at stamp from before 2026-09-15,
                     when missing the weekly quota hid the whole balance. The
                     money is not lost — the stamp only hides it — so this puts
                     it straight back. --}}
                @if (($heldIncome['count'] ?? 0) > 0)
                    @role('super_admin')
                        <form method="POST" action="{{ route('commission-health.release-held') }}"
                            onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Putting back…';">
                            @csrf
                            <button type="submit" class="btn-secondary">Put Back Held Income ({{ number_format($heldIncome['count']) }} · LKR {{ number_format($heldIncome['amount']) }})</button>
                        </form>
                    @endrole
                @endif

                {{-- Upline money on the wrong Service Group. Correcting the rule
                     cannot move money already written, so this exists to move it. --}}
                @if (($legMismatches ?? collect())->isNotEmpty())
                    <form method="POST" action="{{ route('commission-health.repair-legs') }}"
                        onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Moving…';">
                        @csrf
                        <button type="submit" class="btn-secondary">Fix Service Group Sides ({{ $legMismatches->count() }})</button>
                    </form>
                @endif

                {{-- Income recorded before auto-approval existed. Those rules run
                     at CREATION time, so they do nothing for rows already in the
                     database. This applies today's rule to old direct/upline
                     rows only; System Run release remains separate. --}}
                @if (($historicApprovable['count'] ?? 0) > 0)
                    <form method="POST" action="{{ route('commission-health.approve-historic') }}"
                        onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Approving…';">
                        @csrf
                        <button type="submit" class="btn-secondary">
                            Approve Historic Income ({{ $historicApprovable['count'] }} · LKR {{ number_format($historicApprovable['amount']) }})
                        </button>
                    </form>
                @endif
            </div>

            @if (($legMismatches ?? collect())->isNotEmpty())
                <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm dark:bg-amber-500/10">
                    <p class="font-semibold text-amber-800 dark:text-amber-300">
                        {{ $legMismatches->count() }} upline commission(s) are on the wrong Service Group.
                    </p>
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                        Which side an upline earns on is named by the placement account the downline hangs off —
                        the first placement account IS Service Group 1, the second IS Service Group 2. These rows were
                        recorded under an older rule that used the leg the student sat on <em>inside</em> that account
                        instead. Correcting the rule cannot move money already written, so this moves it (and the
                        student's seat with it). Amounts never change, and rows already settled by a System Run are
                        left alone.
                    </p>
                    <ul class="mt-2 space-y-0.5 text-xs text-amber-700 dark:text-amber-400">
                        @foreach ($legMismatches->take(8) as $m)
                            <li>
                                {{ $m['commission']->consultant?->scn_code ?? '—' }} ·
                                LKR {{ number_format((float) $m['commission']->amount) }} —
                                Service Group {{ $m['recorded'] }} → {{ $m['expected'] }}
                            </li>
                        @endforeach
                        @if ($legMismatches->count() > 8)
                            <li>…and {{ $legMismatches->count() - 8 }} more.</li>
                        @endif
                    </ul>
                </div>
            @endif

            <p class="mt-3 text-xs text-slate-400">
                <strong>Run Repair</strong> corrects the commission settings, clears caches, then records any income
                that verified payments missed. Safe to run more than once — it never pays anything twice.
                @if (! empty($drifted))
                    <br><strong>Fix Migration Records</strong> only updates bookkeeping for tables that already exist.
                    It runs no SQL against your data and changes no money.
                @endif
            </p>
        </div>
    </div>

    {{-- Configuration --}}
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Service Group Slot" :value="'LKR ' . number_format($config['slot_rate'])" :color="$config['slot_rate'] > 0 ? 'brand' : 'rose'" icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
        <x-stat-card label="Direct Add Bonus" :value="'LKR ' . number_format($config['direct_rate'])" :color="$config['direct_rate'] > 0 ? 'emerald' : 'rose'" icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2" />
        <x-stat-card label="Course Price" :value="'LKR ' . number_format($config['course_price'])" color="sky" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Half Payment" :value="'LKR ' . number_format($config['half_amount'])" color="amber" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </div>

    @if ($config['direct_rate'] <= 0)
        <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-5 dark:border-rose-500/30 dark:bg-rose-500/10">
            <p class="text-sm font-bold text-rose-700 dark:text-rose-300">The Direct Add Bonus is LKR 0.</p>
            <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">
                No consultant can earn the bonus while this is zero, no matter how many students they register.
                Set it under <a href="{{ route('settings.index') }}" class="font-semibold underline">Settings → Service Groups &amp; Income</a>, then run Repair above.
            </p>
        </div>
    @endif

    {{-- Deployed code --}}
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Is the latest code live?</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">If any of these say "Missing", the uploaded files are out of date.</p>
        </div>
        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($code as $label => $ok)
                <div class="flex items-center justify-between gap-3 px-5 py-3">
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ $label }}</span>
                    <span class="badge shrink-0 {{ $ok ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-400' }}">
                        {{ $ok ? 'Live' : 'Missing' }}
                    </span>
                </div>
            @endforeach
            <div class="flex items-center justify-between gap-3 px-5 py-3">
                <span class="text-sm text-slate-700 dark:text-slate-300">Pending migrations</span>
                <span class="badge shrink-0 {{ empty($pending) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400' }}">
                    {{ empty($pending) ? 'None' : count($pending).' pending' }}
                </span>
            </div>
            <div class="flex items-center justify-between gap-3 px-5 py-3">
                <span class="text-sm text-slate-700 dark:text-slate-300">
                    Applied but unrecorded
                    <span class="block text-xs text-slate-400">Their table already exists — this is what breaks "migrate".</span>
                </span>
                <span class="badge shrink-0 {{ empty($drifted) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400' }}">
                    {{ empty($drifted) ? 'None' : count($drifted) }}
                </span>
            </div>
        </div>
        @if (! empty($pending) || ! empty($drifted))
            <div class="border-t border-slate-100 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-800/40">
                @foreach ($pending as $name)
                    <p class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $name }} <span class="text-amber-600">— not applied</span></p>
                @endforeach
                @foreach ($drifted as $name)
                    <p class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $name }} <span class="text-sky-600">— table exists, just unrecorded</span></p>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Recent payments --}}
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Last 10 payments</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Exactly what the engine recorded for each one.</p>
        </div>
        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($rows as $row)
                <div class="p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900 dark:text-white">{{ $row['student'] }}</p>
                            <p class="truncate font-mono text-xs text-slate-400">{{ $row['code'] }} · {{ $row['email'] }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="badge {{ $row['status'] === 'verified' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400' }}">
                                {{ ucfirst($row['status']) }}
                            </span>
                            <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                {{ $row['plan'] ? ucfirst(str_replace('_', ' ', $row['plan'])) : 'No plan chosen' }} · LKR {{ number_format($row['amount']) }}
                            </span>
                        </div>
                    </div>

                    @if ($row['status'] !== 'verified')
                        <p class="mt-3 text-sm text-amber-600 dark:text-amber-400">
                            Not verified yet — the accountant must verify this payment before any commission exists.
                        </p>
                    @elseif (! $row['consultant_id'])
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Direct signup with no consultant, so no commission is due.</p>
                    @else
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Seats filled</p>
                                @forelse ($row['seats'] as $seat)
                                    <p class="mt-1 font-mono text-xs text-slate-600 dark:text-slate-300">
                                        {{ $seat['account'] }} · Group {{ $seat['tier'] }}{{ $seat['settled'] > 0 ? ' · settled' : ' · not settled (past the leg cap)' }}
                                    </p>
                                @empty
                                    <p class="mt-1 text-xs text-rose-500">None — the student was never placed.</p>
                                @endforelse
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Money recorded</p>
                                @forelse ($row['money'] as $m)
                                    <p class="mt-1 font-mono text-xs text-emerald-600 dark:text-emerald-400">
                                        {{ $m['kind'] }} · LKR {{ number_format($m['amount']) }} · {{ $m['account'] }}
                                    </p>
                                @empty
                                    <p class="mt-1 text-xs text-rose-500">Nothing recorded.</p>
                                @endforelse
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <p class="p-8 text-center text-sm text-slate-400">No payments yet.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
