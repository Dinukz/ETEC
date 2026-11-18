<div>
    {{-- ⚠️ EMPTY UNTIL A ROW'S BUTTON IS PRESSED. The whole reason this is a
         component and not another _*-modal partial: the partials render their
         contents into every one of the 12 rows on page load, and a full SCN
         summary is ~30 queries per person. Nothing below runs until open(). --}}
    {{-- UserAccountSummary opens by user instead (User Management). --}}
    @if ($studentId !== null || ($userId ?? null) !== null)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 sm:p-8">
            <div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl dark:bg-slate-900">

                {{-- Header --}}
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5 dark:border-slate-800">
                    <div class="min-w-0">
                        <h3 class="truncate text-lg font-bold text-slate-900 dark:text-white">
                            {{ $summary['name'] ?? 'Account summary' }}
                        </h3>
                        @if (! empty($summary['student_code']))
                            <p class="mt-0.5 font-mono text-xs text-slate-400">{{ $summary['student_code'] }}</p>
                        @endif
                        {{-- Only set from User Management, where a row can be staff. --}}
                        @if (! empty($summary['role']))
                            <span class="badge mt-1.5 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ $summary['role'] }}</span>
                        @endif
                    </div>
                    <button type="button" wire:click="close" class="shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        <span class="sr-only">Close</span>
                    </button>
                </div>

                @if ($error)
                    <div class="p-5">
                        <p class="text-sm text-rose-600 dark:text-rose-400">{{ $error }}</p>
                    </div>
                @else
                    <div class="space-y-6 p-5">

                        {{-- 1. The SCN accounts they hold --}}
                        <section>
                            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-400">SCN accounts</h4>
                            @if (empty($summary['accounts']))
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                    Not a consultant yet — this person holds no SCN account.
                                </p>
                            @else
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                    Holds {{ $summary['account_count'] }} {{ \Illuminate\Support\Str::plural('account', $summary['account_count']) }}.
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($summary['accounts'] as $account)
                                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 dark:border-slate-700">
                                            <span class="font-mono text-sm font-semibold text-slate-900 dark:text-white">{{ $account['code'] }}</span>
                                            {{-- The main account is the person; the rest are placement
                                                 accounts, which are service-group buckets rather than
                                                 separate people. --}}
                                            <span class="text-[11px] text-slate-400">{{ $account['is_main'] ? 'Main' : 'Placement' }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </section>

                        {{-- 2. Service groups on each account --}}
                        @if (! empty($summary['groups']))
                            <section>
                                <h4 class="text-xs font-bold uppercase tracking-wide text-slate-400">Service groups</h4>
                                <div class="mt-2 overflow-x-auto">
                                    <table class="w-full text-left text-sm">
                                        <thead class="text-[11px] uppercase text-slate-400">
                                            <tr>
                                                {{-- The SCN account, not the GRP-XXXXXX key: that code
                                                     means nothing to a reader (asked 2026-09-22). --}}
                                                <th class="py-1 pr-4 font-semibold">Account</th>
                                                <th class="py-1 pr-4 font-semibold">Service Group 1</th>
                                                <th class="py-1 font-semibold">Service Group 2</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            @foreach ($summary['groups'] as $group)
                                                <tr>
                                                    <td class="py-1.5 pr-4 text-slate-600 dark:text-slate-300">
                                                        <span class="font-mono text-xs font-semibold text-slate-900 dark:text-white">{{ $group['code'] }}</span>
                                                        <span class="ml-1 text-[11px] text-slate-400">{{ ($group['is_main'] ?? false) ? 'Main' : 'Placement' }}</span>
                                                    </td>
                                                    <td class="py-1.5 pr-4 text-slate-900 dark:text-white">{{ $group['leg1'] }} seated</td>
                                                    <td class="py-1.5 text-slate-900 dark:text-white">{{ $group['leg2'] }} seated</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        @endif

                        {{-- 3. Courses bought --}}
                        <section>
                            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-400">Courses bought</h4>
                            @if (empty($summary['courses']))
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No course purchase on record.</p>
                            @else
                                <ul class="mt-2 space-y-1.5">
                                    @foreach ($summary['courses'] as $course)
                                        <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                            <span class="font-medium text-slate-900 dark:text-white">{{ $course['title'] }}</span>
                                            <x-payment-status :status="$course['status']" :type="$course['plan']" />
                                            <span class="text-xs text-slate-400">LKR {{ number_format($course['amount'], 2) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>

                        {{-- 4 + 5. Where they came from --}}
                        <section>
                            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-400">How they joined</h4>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $summary['origin']['label'] ?? '—' }}</p>
                            {{-- Shown only when it says something the "Added under" line
                                 below does not. The two now read the same sponsor, so for
                                 most people this line would just repeat it (2026-09-27). --}}
                            @if (! empty($summary['origin']['code']) && $summary['origin']['code'] !== ($summary['upline']['code'] ?? null))
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    Sponsoring account <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $summary['origin']['code'] }}</span>
                                </p>
                            @endif
                            @if (! empty($summary['upline']['code']))
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    Added under <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $summary['upline']['code'] }}</span>
                                    @if (! empty($summary['upline']['leg']))
                                        , Service Group {{ $summary['upline']['leg'] }}
                                    @endif
                                </p>
                            @elseif (! empty($summary['upline']['above_you']))
                                {{-- The tree only reads downward. Naming an account above the
                                     viewer would show them somebody else's upline. --}}
                                <p class="mt-1 text-xs text-slate-400">The account above this one sits outside your network, so it is not shown.</p>
                            @endif
                        </section>

                        {{-- ================= Stage 2 ================= --}}
                        {{-- Placement and the downline walk are ~25 queries on their own.
                             Loaded only when asked for — the "part wise" load. --}}
                        <section class="border-t border-slate-100 pt-5 dark:border-slate-800">
                            @if (! $deepLoaded)
                                <button type="button" wire:click="loadDeep" wire:loading.attr="disabled" wire:target="loadDeep" class="btn-secondary text-sm">
                                    <span wire:loading.remove wire:target="loadDeep">Show upline, placement &amp; downline</span>
                                    <span wire:loading wire:target="loadDeep">Loading…</span>
                                </button>
                                <p class="mt-2 text-xs text-slate-400">This part is heavier, so it loads separately.</p>
                            @else
                                {{-- ⚠️ THE WHOLE UPLINE, NEAREST FIRST (2026-09-27): "hama ehekm
                                     upline eka piliwelata enn". Built from the engine's own
                                     climb, so this is also exactly who earns from this person.
                                     A consultant viewer sees it up to and including themself. --}}
                                <h4 class="text-xs font-bold uppercase tracking-wide text-slate-400">Upline</h4>
                                @if (empty($deep['upline_chain']))
                                    <p class="mt-2 mb-5 text-sm text-slate-500 dark:text-slate-400">Nobody above — this is the top of the line.</p>
                                @else
                                    <ol class="mt-2 mb-5 space-y-1.5 text-sm">
                                        @foreach ($deep['upline_chain'] as $i => $up)
                                            <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                                <span class="w-5 shrink-0 text-right text-xs text-slate-400">{{ $i + 1 }}</span>
                                                <span class="font-medium text-slate-900 dark:text-white">{{ $up['name'] }}</span>
                                                @if ($up['is_viewer'])
                                                    <span class="badge bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">You</span>
                                                @endif
                                                <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ implode(' → ', $up['codes']) }}</span>
                                                @if ($up['leg'])
                                                    <span class="text-xs text-slate-400">· Service Group {{ $up['leg'] }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif

                                {{-- ⚠️ THE LINE (2026-10-01): everyone added on the same account's
                                     Service Group, in the order they were added — students and
                                     consultants. Where this person STANDS, not who earns from them
                                     (that is the Upline list above). --}}
                                @if (! empty($deep['line']))
                                    @php $line = $deep['line']; @endphp
                                    <h4 class="text-xs font-bold uppercase tracking-wide text-slate-400">Line</h4>
                                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                                        Added on <span class="font-mono font-semibold">{{ $line['account'] }}</span> · Service Group {{ $line['leg'] }}
                                        — <span class="font-semibold">#{{ $line['position'] }} of {{ $line['total'] }}</span>
                                    </p>
                                    <div class="mt-2 mb-5 grid gap-4 sm:grid-cols-2">
                                        @foreach (['above' => 'Above on the line', 'below' => 'Below on the line'] as $side => $title)
                                            <div>
                                                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $title }} ({{ count($line[$side]) + $line[$side.'_more'] }})</p>
                                                @if (empty($line[$side]))
                                                    <p class="mt-1 text-sm text-slate-400">{{ $side === 'above' ? 'First on the line.' : 'Nobody after them yet.' }}</p>
                                                @else
                                                    <ol class="mt-1 space-y-1 text-sm">
                                                        @foreach ($line[$side] as $member)
                                                            <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                                                <span class="w-8 shrink-0 text-right text-xs text-slate-400">#{{ $member['position'] }}</span>
                                                                @if ($member['visible'])
                                                                    <span class="font-medium text-slate-900 dark:text-white">{{ $member['name'] }}</span>
                                                                    <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $member['code'] }}</span>
                                                                @else
                                                                    <span class="text-slate-400">not in your network</span>
                                                                @endif
                                                                @if ($member['is_consultant'])
                                                                    <span class="badge bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">Consultant</span>
                                                                @else
                                                                    <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Student</span>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ol>
                                                    @if ($line[$side.'_more'] > 0)
                                                        <p class="mt-1 text-xs text-slate-400">+ {{ number_format($line[$side.'_more']) }} more</p>
                                                    @endif
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if (! empty($deep['not_a_consultant']))
                                    <p class="text-sm text-slate-500 dark:text-slate-400">No SCN account, so there is no placement or downline to show.</p>
                                @else
                                    <h4 class="text-xs font-bold uppercase tracking-wide text-slate-400">Placement</h4>
                                    @if (empty($deep['placements']))
                                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No placement on record.</p>
                                    @else
                                        <ul class="mt-2 space-y-1.5 text-sm">
                                            @foreach ($deep['placements'] as $placement)
                                                <li class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $placement['code'] }}</span>
                                                    @if ($placement['kind'] === 'main')
                                                        <span class="text-slate-500 dark:text-slate-400">their own main account</span>
                                                    @elseif ($placement['is_warning'])
                                                        {{-- ⚠️ NOT drawn as a parent. A bought-foreign set was
                                                             attributed to an account this person does not hold,
                                                             so calling it their placement would be a lie. --}}
                                                        <span class="text-amber-600 dark:text-amber-400">
                                                            bought under {{ $placement['under'] ?? 'another account' }}, which this person does not hold — needs checking
                                                        </span>
                                                    @elseif ($placement['kind'] === 'unknown')
                                                        <span class="text-slate-400">placement not recorded</span>
                                                    @elseif (! empty($placement['under']))
                                                        <span class="text-slate-500 dark:text-slate-400">under <span class="font-mono">{{ $placement['under'] }}</span></span>
                                                    @endif
                                                    @if (! empty($placement['owner_group']))
                                                        <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Service Group {{ $placement['owner_group'] }}</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <h4 class="mt-5 text-xs font-bold uppercase tracking-wide text-slate-400">Downline</h4>
                                    @if (empty($deep['downline']))
                                        {{-- Said in full, because "Nobody under this account yet" on
                                             its own was read as the tree being broken (2026-09-22):
                                             the person was correctly placed, nobody had simply been
                                             added below them. --}}
                                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                            Nobody has been added under this person yet — none of their own SCN accounts has anyone below it.
                                            People sitting beside them on the same upline account belong to that other person's downline, not to theirs.
                                        </p>
                                    @else
                                        <div class="mt-2 overflow-x-auto">
                                            <table class="w-full text-left text-sm">
                                                <thead class="text-[11px] uppercase text-slate-400">
                                                    <tr>
                                                        <th class="py-1 pr-4 font-semibold">Level</th>
                                                        <th class="py-1 pr-4 font-semibold">SCN</th>
                                                        <th class="py-1 pr-4 font-semibold">Name</th>
                                                        {{-- Two different answers, kept apart: the account this
                                                             person was added under, and the leg of the SUBJECT
                                                             their whole branch hangs off. One column showing the
                                                             branch for both was the 2026-09-22 misread. --}}
                                                        <th class="py-1 pr-4 font-semibold">Added under</th>
                                                        <th class="py-1 font-semibold">Branch</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                                    @foreach ($deep['downline'] as $member)
                                                        <tr>
                                                            <td class="py-1.5 pr-4 text-slate-400">{{ $member['level'] }}</td>
                                                            <td class="py-1.5 pr-4 font-mono text-xs text-slate-900 dark:text-white">{{ $member['code'] }}</td>
                                                            {{-- Indented by level, so the tree order below reads as
                                                                 a tree. An inline style, not a class: a padding
                                                                 computed from data has no Tailwind class to exist in
                                                                 the built CSS. --}}
                                                            <td class="py-1.5 pr-4 text-slate-600 dark:text-slate-300" style="padding-left: {{ max(0, ($member['level'] - 1)) * 0.9 }}rem">
                                                                @if ($member['level'] > 1)<span class="text-slate-300 dark:text-slate-600">└ </span>@endif{{ $member['name'] }}
                                                            </td>
                                                            <td class="py-1.5 pr-4 text-slate-500 dark:text-slate-400">
                                                                <span class="font-mono text-xs">{{ $member['under'] }}</span>
                                                                @if (! empty($member['under_name']))
                                                                    <span class="block text-xs text-slate-400">{{ $member['under_name'] }}</span>
                                                                @endif
                                                            </td>
                                                            <td class="py-1.5 text-slate-500 dark:text-slate-400">
                                                                <span class="font-mono text-xs">{{ $member['branch'] ?? $member['under'] }}</span>
                                                                @if (! empty($member['leg']))
                                                                    <span class="block text-xs text-slate-400">Service Group {{ $member['leg'] }}</span>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif

                                    {{-- ⚠️ THE PEOPLE THE DOWNLINE TABLE CANNOT SHOW (2026-09-23).
                                         That table walks parent_consultant_id, so it only ever
                                         lists people who were PROMOTED to consultant. Most of the
                                         network is still students — they are seated on these
                                         accounts and their 2,500 is on these legs, so leaving
                                         them out made a working account read as empty. --}}
                                    <h4 class="mt-5 text-xs font-bold uppercase tracking-wide text-slate-400">Placed on these accounts</h4>
                                    @if (empty($deep['placed']))
                                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nobody has been placed on any of these accounts yet.</p>
                                    @else
                                        <p class="mt-2 text-xs text-slate-400">
                                            {{ number_format($deep['placed_total'] ?? count($deep['placed'])) }} placed in total — everyone whose Service Group income reached these accounts, students included.
                                        </p>
                                        <div class="mt-2 overflow-x-auto">
                                            <table class="w-full text-left text-sm">
                                                <thead class="text-[11px] uppercase text-slate-400">
                                                    <tr>
                                                        <th class="py-1 pr-4 font-semibold">Name</th>
                                                        <th class="py-1 pr-4 font-semibold">Number</th>
                                                        <th class="py-1 pr-4 font-semibold">On account</th>
                                                        <th class="py-1 font-semibold">Service Group</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                                    @foreach ($deep['placed'] as $seat)
                                                        <tr>
                                                            <td class="py-1.5 pr-4 text-slate-900 dark:text-white">
                                                                {{ $seat['name'] }}
                                                                @if ($seat['joined'])
                                                                    <span class="block text-xs text-slate-400">{{ $seat['joined'] }}</span>
                                                                @endif
                                                            </td>
                                                            <td class="py-1.5 pr-4 font-mono text-xs text-slate-600 dark:text-slate-300">
                                                                {{ $seat['code'] ?? '—' }}
                                                                @unless ($seat['is_consultant'])
                                                                    <span class="block font-sans text-xs text-slate-400">Student</span>
                                                                @endunless
                                                            </td>
                                                            <td class="py-1.5 pr-4 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $seat['account'] }}</td>
                                                            <td class="py-1.5 text-slate-500 dark:text-slate-400">
                                                                {{ $seat['leg'] ? 'Service Group '.$seat['leg'] : '—' }}
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        @if (($deep['placed_more'] ?? 0) > 0)
                                            {{-- Never silently truncated: a cut-off list that says
                                                 nothing reads as "that is everybody". --}}
                                            <p class="mt-2 text-xs text-slate-400">
                                                Showing the most recent {{ count($deep['placed']) }} — {{ number_format($deep['placed_more']) }} more are placed on these accounts.
                                            </p>
                                        @endif
                                    @endif
                                @endif
                            @endif
                        </section>
                    </div>
                @endif

                <div class="border-t border-slate-100 p-4 text-right dark:border-slate-800">
                    <button type="button" wire:click="close" class="btn-secondary text-sm">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>
