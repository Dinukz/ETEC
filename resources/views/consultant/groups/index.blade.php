<x-layouts.app title="Service Groups" header="Service Groups">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Service Groups</h2>
        </div>

        {{-- Whose service groups to view: yourself, or anyone below you. --}}
        @if ($viewable->count() > 1)
            <form method="GET" action="{{ route('consultant.groups.index') }}" class="shrink-0 lg:w-80">
                <label for="consultant" class="label">Consultant</label>
                <select id="consultant" name="consultant" class="input" onchange="this.form.submit()">
                    @foreach ($viewable as $option)
                        <option value="{{ $option->id }}" @selected($option->id === $subject->id)>
                            {{ $option->scn_code ?? $option->consultant_code }} — {{ $option->display_name }}{{ $option->id === $consultant->id ? ' (You)' : '' }}
                        </option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    @if ($subject->id !== $consultant->id)
        <div class="mb-5 rounded-2xl border border-slate-200 bg-slate-50 px-5 py-3 text-sm dark:border-slate-700 dark:bg-slate-800/50">
            <span class="text-slate-700 dark:text-slate-300">
                Viewing <strong>{{ $subject->display_name }}</strong> ({{ $subject->scn_code ?? $subject->consultant_code }}) from your network.
            </span>
            <a href="{{ route('consultant.groups.index') }}" class="ml-2 font-semibold text-slate-900 underline dark:text-white">Back to mine</a>
        </div>
    @endif

    {{-- Headline --}}
    <div class="card mb-5 p-5 sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400">
                    {{ $subject->id === $consultant->id ? 'Your Service Group Code' : 'Service Group Code' }}
                </h3>
                <p class="mt-1 font-mono text-lg font-extrabold text-slate-900 dark:text-white">{{ $subject->scn_code ?? 'Not assigned yet' }}</p>
                @unless ($subject->scn_code)
                    <p class="mt-1 text-xs text-slate-400">Assigned automatically once their first student's payment is verified.</p>
                @endunless
            </div>
            <div class="grid grid-cols-3 gap-4 sm:text-right">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">SG1 Leg Balance</p>
                    {{-- ⚠️ LEADS WITH WHAT A RUN CAN SETTLE (2026-08-26).
                         This showed lifetime EARNED, and every question asked of
                         this page — can it flush, how much will I get — needs the
                         unflushed balance instead. Reported five times as "7500ta
                         wada thiyeno ne, ai system run eka krann den naththe":
                         Service Group 2 read 12,500 earned while only 5,000 was
                         still on the leg, the rest already settled by an earlier
                         run. Both figures were right; only one answers the
                         question, so that one leads now. --}}
                    {{-- "awaiting System Run release" and "of LKR X earned to date" removed
                         2026-09-06 — three lines of caption under one number, on every visit. --}}
                    <p class="mt-1 text-lg font-bold text-slate-900 dark:text-white sm:text-xl">LKR {{ number_format($availableLeg1) }}</p>
                    @if (($afterRunLeg1 ?? 0) > 0 && ($lastRun ?? null))
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            LKR {{ number_format($afterRunLeg1) }} came in after the last System Run (settled up to {{ $lastRun->cutoffLabel() }}) — it settles at the next run
                        </p>
                    @endif
                    {{-- ⚠️ A SHUT ACCOUNT MUST SAY SO, NOT JUST READ ZERO (2026-08-27).
                         The bold figure is what this account holds; money on an
                         account an admin force-shut never climbs to it, while the
                         earned figure beneath it drops nothing. Without this line the
                         two disagree with no way to tell why. (The automatic
                         12-student lock deliberately does NOT stop the climb, so that
                         money is already inside the bold figure.) --}}
                    @if ($lockedLeg1 > 0)
                        <p class="mt-0.5 text-xs font-medium text-rose-600 dark:text-rose-400">
                            {{ $lockedReason[1]['code'] ?? 'This account' }} is closed by an administrator — its own LKR {{ number_format($lockedLeg1) }} cannot settle until it is opened
                        </p>
                    @endif
                    {{-- "LKR X short of the LKR Y this side needs" removed 2026-09-06 —
                         the same shortfall the table below already states per account. --}}
                    @if ($pendingLeg1 > 0)
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            + LKR {{ number_format($pendingLeg1) }} pending review or on hold
                        </p>
                    @endif
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">SG2 Leg Balance</p>
                    <p class="mt-1 text-lg font-bold text-slate-900 dark:text-white sm:text-xl">LKR {{ number_format($availableLeg2) }}</p>
                    @if (($afterRunLeg2 ?? 0) > 0 && ($lastRun ?? null))
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            LKR {{ number_format($afterRunLeg2) }} came in after the last System Run (settled up to {{ $lastRun->cutoffLabel() }}) — it settles at the next run
                        </p>
                    @endif
                    {{-- See the Service Group 1 block above for why this line exists. --}}
                    @if ($lockedLeg2 > 0)
                        <p class="mt-0.5 text-xs font-medium text-rose-600 dark:text-rose-400">
                            {{ $lockedReason[2]['code'] ?? 'This account' }} is closed by an administrator — its own LKR {{ number_format($lockedLeg2) }} cannot settle until it is opened
                        </p>
                    @endif
                    @if ($pendingLeg2 > 0)
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            + LKR {{ number_format($pendingLeg2) }} pending review or on hold
                        </p>
                    @endif
                    @if (($pendingUngrouped ?? 0) > 0)
                        <p class="mt-0.5 text-xs text-rose-600 dark:text-rose-400">
                            + LKR {{ number_format($pendingUngrouped) }} on an account with no recorded placement — in neither group
                        </p>
                    @endif
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">
                        Direct Add Bonus
                        {{-- The rate in force, spelled out. A zero here means no bonus row
                             is ever created (awardDirectBonus() returns early), which
                             otherwise looks identical on screen to "nobody approved it
                             yet" — two very different problems, one of them a
                             misconfiguration only an admin can fix in Settings. --}}
                        <span class="normal-case text-slate-400">· LKR {{ number_format($settings->direct_commission) }}/student</span>
                        {{-- A course with its own rate (2026-10-22), e.g. Elliott Wave 5,000. --}}
                        @foreach ($courseDirectRates ?? [] as $courseRate)
                            <span class="block normal-case text-slate-400">{{ $courseRate['title'] }}: LKR {{ number_format($courseRate['direct']) }}/student</span>
                        @endforeach
                    </p>
                    <p class="mt-1 text-lg font-bold text-slate-900 dark:text-white sm:text-xl">LKR {{ number_format($directIncome) }}</p>
                    {{-- The bonus is earned the moment a payment is verified, but the
                         figure above counts approved money only — so without this line
                         a consultant who had just registered students saw a flat LKR 0
                         and concluded the 4,000 was never calculated. It is; it is
                         waiting on the accountant. --}}
                    @if ($directPending > 0)
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            + LKR {{ number_format($directPending) }} pending review or on hold
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @php $mine = $subject->id === $consultant->id; @endphp

    {{-- Per-account income table --}}
    <div class="card mb-5 overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800 sm:px-6">
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ $mine ? 'Your' : 'Their' }} Service Group accounts</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-3 font-semibold sm:px-6">#</th>
                        <th class="px-5 py-3 font-semibold sm:px-6">Group Code</th>
                        <th class="px-5 py-3 font-semibold sm:px-6">SG1 Leg Balance</th>
                        <th class="px-5 py-3 font-semibold sm:px-6">SG2 Leg Balance</th>
                        <th class="px-5 py-3 font-semibold sm:px-6">Direct Bonus</th>
                        <th class="px-5 py-3 font-semibold sm:px-6">View</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($accountRows as $i => $row)
                        <tr class="{{ $row['id'] === $selected->id ? 'bg-slate-100 dark:bg-slate-800/60' : '' }}">
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400 sm:px-6">{{ $i + 1 }}</td>
                            <td class="px-5 py-3 font-mono font-semibold text-slate-900 dark:text-white sm:px-6">
                                {{ $row['code'] }}
                                @if ($row['is_owner'])<span class="badge ml-2 bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200">Main</span>@endif

                                {{-- WHERE THIS ACCOUNT HANGS (2026-08-22).
                                     Without this the table was nine bare SCN codes
                                     with nothing saying how they relate, so once a
                                     consultant owned more than the opening three
                                     there was no way to tell which purchase produced
                                     which accounts, or which account a bought set
                                     was placed under. --}}
                                {{-- ⚠️ Use the BLOCK form here, never the one-line
                                     parenthesised form. This file opens a raw PHP
                                     block further down, and Blade's raw-block regex
                                     pairs a one-line opener with that later closer —
                                     swallowing every directive in between into a
                                     single unterminated tag, which 500s the page with
                                     an "unexpected endforeach" parse error. --}}
                                @php
                                    $place = $row['placement'] ?? null;
                                @endphp
                                @if ($place)
                                    <p class="mt-1 font-sans text-xs font-normal leading-relaxed text-slate-500 dark:text-slate-400">
                                        @if ($place['kind'] === 'main')
                                            Your own account — every account below belongs to it
                                        @elseif ($place['kind'] === 'unknown')
                                            Placement not recorded
                                        @elseif ($place['kind'] === 'bought-foreign')
                                            Opened by a course purchase
                                        @else
                                            @if ($place['kind'] === 'bought')Placed under @else Inside @endif
                                            <span class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $place['under_code'] }}</span>
                                            @if ($place['tier'])
                                                · Service Group {{ $place['tier'] }}
                                            @endif
                                        @endif
                                    </p>
                                    @if ($place['kind'] === 'bought')
                                        <p class="font-sans text-xs font-normal text-sky-600 dark:text-sky-400">Opened by a course purchase</p>
                                    @elseif ($place['kind'] === 'bought-foreign')
                                        {{-- Not drawn as a parent: the purchase that opened
                                             this set was attributed to an account the owner
                                             does not hold, so the income went elsewhere.
                                             Shown rather than hidden — it is a money problem
                                             the consultant needs to see and report. --}}
                                        <p class="font-sans text-xs font-normal text-slate-500 dark:text-slate-400">
                                            Purchase credited to <span class="font-mono font-semibold">{{ $place['under_code'] }}</span>, which is not your account
                                        </p>
                                    @endif
                                @endif

                                {{-- ⚠️ MOVED HERE FROM THE "Current Balance + Direct" COLUMN
                                     (2026-09-26). The owner had that column removed for every
                                     consultant — "current balance + direct kiyla mark krla
                                     thiyene eka hide krla denn" — and these lines happened to
                                     live inside its cell. They are not a balance: they say why
                                     an account cannot settle, and (super admin only) what the
                                     next run would do with it. Dropping the column must not
                                     drop them, so they sit with the account they describe. --}}
                                @php
                                    $fl = $row['flush'];
                                @endphp
                                @if ($fl['locked'])
                                    <p class="mt-1 font-sans text-xs font-normal text-slate-400">Locked — needs {{ $fl['unlock_needed'] }} more student(s)</p>
                                @elseif ($fl['can_flush'])
                                    @role('super_admin')
                                        <p class="mt-1 font-sans text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                            {{ $fl['label'] }} ready — LKR {{ number_format($fl['releasable']) }} at the next run
                                        </p>
                                    @endrole
                                    @if (($fl['already_paid'] ?? 0) > 0)
                                        <p class="font-sans text-xs font-normal text-slate-400">
                                            LKR {{ number_format($fl['already_paid']) }} already approved before this run
                                        </p>
                                    @endif
                                    @role('super_admin')
                                        @if ($fl['forfeit'] > 0)
                                            <p class="font-sans text-xs font-normal text-rose-500">LKR {{ number_format($fl['forfeit']) }} would go to the company</p>
                                        @endif
                                    @endrole
                                @endif
                            </td>
                            @foreach ([1, 2] as $leg)
                                @php
                                    $seated = $row['sg'.$leg.'_members'];
                                    $f = $row['flush'];
                                    // The BALANCE sitting on this leg — what a flush would
                                    // act on — not lifetime approved income. It grows as
                                    // students land and drops to 0 the moment the account
                                    // is flushed, which is the whole point of the model.
                                    // The full balance is what the consultant is accumulating.
                                    // A run settles everything entered up to the date it is
                                    // given, so what is still here after a run is explained by
                                    // $row['after_last_run'] — money that run could not have
                                    // settled — rather than by "today" (2026-09-13).
                                    $balance = $f['leg'.$leg.'_total'];
                                    $inScope = $f['leg'.$leg];
                                    $needs = $f['need_leg'.$leg];
                                    // What the seated students on this leg are
                                    // actually doing, so the money figure above
                                    // is never an unexplained number — reported
                                    // 2026-08-17 as "SCN-00107's 2,500 left the
                                    // account and went to Main". It never moved:
                                    // the seat and its commission are always
                                    // created on the SAME account
                                    // (placeInto()/awardSlot() never split them).
                                    $status = $row['sg'.$leg.'_status'] ?? ['paid_on_leg' => 0, 'held' => 0, 'swept' => 0, 'forfeited' => 0, 'removed' => 0];
                                    // Still ON the leg and counted in the figure
                                    // above — stated positively, because these
                                    // are NOT missing money.
                                    $onLegNotes = [];
                                    if ($status['paid_on_leg'] > 0) $onLegNotes[] = $status['paid_on_leg'].' already paid (still counts toward '.number_format($f['threshold']).')';
                                    // Genuinely absent from the figure above.
                                    $gapReasons = [];
                                    if ($status['held'] > 0) $gapReasons[] = $status['held'].' held while finance-restricted';
                                    if ($status['swept'] > 0) $gapReasons[] = $status['swept'].' settled by an earlier System Run';
                                    if ($status['forfeited'] > 0) $gapReasons[] = $status['forfeited'].' forfeited to the company at an earlier System Run';
                                    // ⚠️ REMOVALS ARE NOT NAMED TO THE CONSULTANT (2026-09-10).
                                    // This line used to say "N removed by the office as a
                                    // correction". The owner's rule is now that a removal shows
                                    // nowhere — "kohewath penwann ba" — so it is counted out of
                                    // the waiting figure below but never printed. $status['removed']
                                    // is still computed because that subtraction needs it.
                                    // ⚠️ ONLY THE SEATS STILL ON THE LEG ARE "WAITING" (2026-08-22).
                                    //
                                    // $seated counts every student ever placed on this leg,
                                    // including the ones an earlier System Run already settled
                                    // or forfeited. Printing that beside the live balance
                                    // produced a flat contradiction — "1 student(s) · waiting
                                    // to flush" sitting directly above "LKR 0" — which reads
                                    // as money that went missing. Each of these is already
                                    // spelled out in $gapReasons; the count just has to agree.
                                    $waiting = max(0, $seated - $status['swept'] - $status['forfeited'] - ($status['removed'] ?? 0) - $status['held']);
                                @endphp
                                <td class="px-5 py-3 sm:px-6">
                                    @if ($row['is_rollup'] ?? false)
                                        {{-- ⚠️ THE MAIN IS ONE THING NOW (2026-09-02).
                                             It was two at once — a rollup of everything every
                                             account had EARNED, and an account with its own leg
                                             balance — and holding both meanings in one row is
                                             what let the screen and the System Run disagree
                                             about the same account in the same minute.
                                             It holds its own money and settles it, full stop.
                                             The branch survives only because this row still
                                             carries the person-wide DIRECT bonus and the "Main"
                                             label; it is no longer a different KIND of figure.
                                             (Gating the flush section on it is what made
                                             SCN-00106 unflushable — do not put that back.) --}}
                                        {{-- ⚠️ LEADS WITH WHAT A RUN CAN SETTLE (2026-08-26).
                                             The Main row led with LIFETIME EARNED, which never
                                             drops — so after a run that emptied the group the row
                                             still showed 7,500 and the account looked untouched:
                                             "main eka system run eken passe 0 wen na". The sub
                                             rows already led with their balance; this makes the
                                             Main row agree with them and with the headline. --}}
                                        <span class="font-semibold text-slate-900 dark:text-white">LKR {{ number_format($balance) }}</span>
                                        {{-- ⚠️ "all your accounts" until 2026-09-02, when this
                                             stopped being a rollup. The Main account now holds and
                                             settles its OWN Service Group 1 and 2, like every
                                             other account — and this figure is what the next
                                             System Run will actually move here. --}}
                                        {{-- ⚠️ THE CAPTIONS UNDER THIS FIGURE WERE REMOVED (2026-09-06).
                                             "on this group now", "of LKR X earned to date",
                                             "Includes N already paid", the seat counts, and
                                             "This account IS Service Group N" all printed on EVERY
                                             row on every visit, so the table was four lines of
                                             explanation per number. Owner: "me wadipura thiyena word
                                             tika ain karala denna."

                                             ⚠️ WHAT STAYS IS ONLY WHAT APPEARS WHEN SOMETHING IS
                                             ACTUALLY WRONG — money an admin has shut away, and money
                                             too new for the next run. Those are not captions, they
                                             are the reason a figure looks smaller than the
                                             consultant expects, and dropping them would turn a
                                             visible explanation into silently missing income. --}}
                                        @if ((${'lockedLeg'.$leg} ?? 0) > 0)
                                            <span class="mt-0.5 block text-xs font-medium text-rose-600 dark:text-rose-400">
                                                {{ $lockedReason[$leg]['code'] ?? 'This account' }} is closed by an administrator — its own LKR {{ number_format(${'lockedLeg'.$leg}) }} cannot settle until it is opened
                                            </span>
                                        @endif
                                    @else
                                        <span class="font-semibold text-slate-900 dark:text-white">LKR {{ number_format($balance) }}</span>
                                    @endif
                                    {{-- ⚠️ WHY A LEG IS NOT ZERO RIGHT AFTER A RUN (2026-09-13).
                                         This replaces a "registered today" line that could never
                                         show: the page measures against the end of today, so
                                         nothing was ever after its own cutoff. Run #39 settled up
                                         to 9 Sep, left 40,000 on SCN-00136 that was entered or
                                         verified later, and the page said nothing — so a correct
                                         run was reported as broken. --}}
                                    @if (($row['after_last_run'][$leg] ?? 0) > 0 && ($lastRun ?? null))
                                        <span class="block text-xs text-slate-400">
                                            LKR {{ number_format($row['after_last_run'][$leg]) }} came in after the last System Run (settled up to {{ $lastRun->cutoffLabel() }}) — it settles at the next run
                                        </span>
                                    @endif
                                    {{-- ⚠️ "READY" MEANS THE ACCOUNT CAN SETTLE, NOT THAT ONE SIDE IS FULL.
                                         It was judged per side, so a leg read Ready while the other
                                         side was short and the run then refused the account. --}}
                                    @if ($f['locked'])
                                        <span class="block text-xs text-slate-400">Locked</span>
                                    @elseif ($f['can_flush'])
                                        <span class="block text-xs text-emerald-600 dark:text-emerald-400">Ready</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-5 py-3 sm:px-6">
                                <span class="font-semibold text-slate-900 dark:text-white">LKR {{ number_format($row['direct']) }}</span>
                                {{-- "all accounts combined" / "registered students" removed
                                     2026-09-06 — printed on every row and said nothing the
                                     column header does not. The pending line below stays: it
                                     only appears when money is genuinely held back. --}}
                                @if (($row['direct_pending'] ?? 0) > 0)
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">
                                        + LKR {{ number_format($row['direct_pending']) }} pending review or on hold
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3 sm:px-6">
                                <a href="{{ route('consultant.groups.index', ['consultant' => $subject->id, 'account' => $row['id']]) }}"
                                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    Members
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Member picker --}}
    <div class="card mb-5 p-5 sm:p-6">
        <h3 class="text-sm font-bold text-slate-900 dark:text-white">View members for</h3>

        {{-- ⚠️ THE OWN ACCOUNTS ARE ON SCREEN, NOT BEHIND A CLICK (2026-09-02).
             This was the search box below and nothing else. It is a real picker
             over the whole network and always has been, but closed it looks
             exactly like a disabled field reading "SCN-00712 — mohan", so the
             page appeared to offer only the main account: "methana pennanne
             main account eka withariy, eka waradi".

             A consultant has three accounts (nine, twelve...) and switching
             between them is the single most common thing done on the most-used
             page in the product. That does not belong behind a focus event, so
             every one of their own ACTIVE accounts is a button, showing what it
             is and what it has earned. The search stays underneath for the
             downline, which is far too large to list. --}}
        @php
            $ownPickable = collect($accountRows)->filter(fn ($r) => ($r['status'] ?? 'active') === 'active');
        @endphp

        @if ($ownPickable->count() > 1)
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Your accounts — pick one to see the students inside it.</p>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($ownPickable as $row)
                    @php
                        $isSelected = $selected->id === $row['id'];
                        $role = ($row['is_owner'] ?? false)
                            ? 'Main account'
                            : (in_array($row['represents_leg'] ?? null, [1, 2], true)
                                ? 'Service Group '.$row['represents_leg']
                                : 'Placement account');
                    @endphp
                    <a href="{{ route('consultant.groups.index', ['consultant' => $subject->id, 'account' => $row['id']]) }}"
                        @class([
                            'block rounded-xl border p-4 transition',
                            'border-brand-500 bg-brand-50 ring-1 ring-brand-500 dark:bg-brand-500/10' => $isSelected,
                            'border-slate-200 hover:border-brand-400 hover:bg-slate-50 dark:border-slate-700 dark:hover:border-brand-500 dark:hover:bg-slate-800/50' => ! $isSelected,
                        ])
                        @if ($isSelected) aria-current="true" @endif>
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-sm font-bold text-slate-900 dark:text-white">{{ $row['code'] }}</span>
                            @if ($isSelected)
                                <span class="rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">Showing</span>
                            @endif
                        </div>
                        <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ $role }}</span>
                        {{-- ⚠️ NO AMOUNT ON THESE TILES (2026-10-02): "View members for eke
                             SCN group wala LKR amount eka hide krla denn, methana withri".
                             Only here — the account table above keeps its figures. --}}
                        @if (($row['flush']['locked'] ?? false))
                            <span class="mt-1 block text-[11px] font-medium text-slate-500 dark:text-slate-400">Account locked</span>
                        @endif
                    </a>
                @endforeach
            </div>

            <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">Or search anyone below you in the network:</p>
        @endif

        {{-- Searchable account picker. Filtering is client-side over the same
             list the server already authorised, so it can never reach an
             account outside this consultant's network. --}}
        <div x-data="{
                open: false,
                search: '',
                options: {{ \Illuminate\Support\Js::from($pickable) }},
                base: '{{ route('consultant.groups.index') }}',
                subject: '{{ $subject->id }}',
                get filtered() {
                    if (! this.search.trim()) return this.options;
                    const q = this.search.trim().toLowerCase();
                    return this.options.filter(o => o.label.toLowerCase().includes(q));
                },
                href(option) {
                    return `${this.base}?consultant=${this.subject}&account=${option.id}`;
                },
            }" class="relative mt-4">
            <label for="account-search" class="sr-only">Search accounts</label>
            <input id="account-search" type="text" autocomplete="off"
                x-model="search"
                @focus="open = true"
                @click="open = true"
                @keydown.escape="open = false"
                placeholder="{{ $selected->scn_code ?? $selected->consultant_code }} — {{ $selected->display_name }}"
                class="input">

            <div x-show="open" x-cloak @click.outside="open = false"
                class="absolute left-0 right-0 z-30 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                <template x-for="option in filtered" :key="option.id">
                    <a :href="href(option)"
                        class="block border-b border-slate-100 px-4 py-2.5 text-sm text-slate-700 last:border-b-0 hover:bg-brand-50 dark:border-slate-800 dark:text-slate-200 dark:hover:bg-brand-500/10"
                        x-text="option.label"></a>
                </template>
                <p x-show="filtered.length === 0" class="px-4 py-3 text-sm text-slate-400">Nothing matches that search.</p>
            </div>
        </div>

        @if ($searchError)
            <p class="mt-2 text-xs font-medium text-rose-500">{{ $searchError }}</p>
        @endif

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/50">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Showing</p>
                <p class="mt-0.5 font-mono text-sm font-bold text-slate-900 dark:text-white">
                    {{ $selected->scn_code ?? $selected->consultant_code }}
                    <span class="font-sans font-medium text-slate-500 dark:text-slate-400">— {{ $selected->display_name }}</span>
                </p>
            </div>
            @if (request()->hasAny(['scn', 'account']))
                <a href="{{ route('consultant.groups.index', ['consultant' => $subject->id]) }}" class="btn-secondary shrink-0 text-center text-xs">Reset</a>
            @endif
        </div>
    </div>

    {{-- ⚠️ THESE TABS ARE THE TEAM, NOT A SEAT LIST (2026-09-07).

         They listed GroupMember seats on the selected account until today. The
         owner asked for the people below them to show here instead — "meyage
         yatathe inn kattiya methanat penwan widiyta... eyala aithi wena SCN
         account widiyta SG1 and SG2 ewa wala" — because a seat only exists once
         a payment is verified, so a half payment, a free card, an unverified
         registration and a downline with no sponsor link recorded were all
         invisible here and always would be.

         The seat number has NOT been lost: each panel prints it beside the team
         count, and the names are one click away in the collapsed section. See
         GroupController::index()'s team block for the whole argument. --}}
    @php
        $memberTabs = ['all' => $team['legs']['all'], 1 => $team['legs'][1], 2 => $team['legs'][2]];
        $seatRows = ['all' => $legs[1]->merge($legs[2])->sortBy('id')->values(), 1 => $legs[1], 2 => $legs[2]];
    @endphp
    <div class="card overflow-hidden" x-data="{ tab: 'all' }">
        <div class="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-4 dark:border-slate-800 sm:px-6">
            @foreach ($memberTabs as $key => $t)
                <button type="button" @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}'
                        ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition">
                    {{ $t['label'] }} <span class="opacity-70">({{ number_format($t['students_total']) }})</span>
                </button>
            @endforeach
        </div>

        @foreach ($memberTabs as $key => $t)
            <div x-show="tab === '{{ $key }}'" x-cloak>
                {{-- BOTH NUMBERS, always. The tab counts the team; this line
                     counts what actually fills the leg. Printing only one of
                     them is how "my numbers jumped" becomes a support call. --}}
                <div class="border-b border-slate-100 px-5 py-3 dark:border-slate-800 sm:px-6">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        <strong class="text-slate-700 dark:text-slate-200">{{ number_format($t['people_total']) }}</strong> consultant(s),
                        <strong class="text-slate-700 dark:text-slate-200">{{ number_format($t['students_total']) }}</strong> student(s)
                        {{ $key === 'all' ? 'in your team' : 'on this side' }}.
                        <span class="text-slate-600 dark:text-slate-300">
                            {{ number_format($t['seated']) }} seated{{ $key === 'all' ? '' : ' on this leg' }}@if ($key !== 'all') — what counts toward LKR {{ number_format($flushThreshold) }}@endif.
                        </span>
                        Counted from registrations, so it includes half payments and students still awaiting verification.
                    </p>
                    @if ($key === 'all' && ($team['unattributed'] ?? 0) > 0)
                        {{-- Same promise the money side already makes for
                             $pendingUngrouped: anything in neither group is
                             named, never quietly dropped. --}}
                        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                            {{ number_format($team['unattributed']) }} consultant(s) are not attributed to a Service Group — their placement was never recorded, so they appear here but in neither side.
                        </p>
                    @endif
                    @if ($team['truncated'] ?? false)
                        <p class="mt-1 text-xs text-slate-400">Counted from the most recent registrations.</p>
                    @endif
                </div>

                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($t['rows'] as $row)
                        @continue ($row['is_root'] && $row['count'] === 0 && count($t['rows']) > 1)
                        <li x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }">
                            {{-- Indented by depth, the same convention the Network
                                 tree uses (consultant/network/index.blade.php). --}}
                            <button type="button" @click="open = !open"
                                class="flex w-full items-center justify-between gap-3 py-3 pr-5 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/40 sm:pr-6"
                                style="padding-left: {{ 20 + $row['level'] * 22 }}px">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-slate-900 dark:text-white">
                                        @if ($row['level'] > 0)<span class="mr-1 text-slate-300 dark:text-slate-600">└</span>@endif
                                        <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $row['code'] }}</span>
                                        — {{ $row['name'] }}
                                        @if ($row['is_root'])
                                            <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">This account</span>
                                        @endif
                                    </span>
                                    @if ($row['children'] > 0)
                                        <span class="mt-0.5 block text-xs text-slate-400">{{ number_format($row['children']) }} consultant(s) below them</span>
                                    @endif
                                </span>
                                <span class="flex shrink-0 items-center gap-2">
                                    <span class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                        {{ number_format($row['count']) }} student(s)
                                    </span>
                                    <svg class="h-4 w-4 text-slate-400 transition" :class="open && 'rotate-180'"
                                        fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </span>
                            </button>

                            <div x-show="open" x-cloak class="bg-slate-50/60 pb-3 pr-5 dark:bg-slate-800/20 sm:pr-6"
                                style="padding-left: {{ 32 + $row['level'] * 22 }}px">
                                @if ($row['students'] === [])
                                    <p class="py-2 text-xs text-slate-400">No students registered yet.</p>
                                @else
                                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                                        @foreach ($row['students'] as $student)
                                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                                <span class="min-w-0">
                                                    <span class="block truncate text-sm text-slate-800 dark:text-slate-200">{{ $student['name'] }}</span>
                                                    <span class="font-mono text-xs text-slate-400">{{ $student['code'] }}</span>
                                                </span>
                                                @if ($student['payment'])
                                                    <x-payment-status :status="$student['payment']->status" :type="$student['payment']->payment_type" />
                                                @else
                                                    <span class="text-xs text-slate-400">No payment yet</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($row['more'] > 0)
                                        <p class="pt-2 text-xs text-slate-400">and {{ number_format($row['more']) }} more.</p>
                                    @endif
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($t['people_total'] === 0 && $t['rows'][0]['count'] === 0)
                    <p class="px-5 py-10 text-center text-sm text-slate-400 sm:px-6">
                        Nobody here yet{{ $key === 'all' ? '' : ' on this side' }}.
                    </p>
                @endif

                @if ($t['people_total'] > $t['people_shown'])
                    <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400 dark:border-slate-800 sm:px-6">
                        Showing the {{ number_format($t['people_shown']) }} busiest of {{ number_format($t['people_total']) }} consultants.
                        Pick a specific SCN above to narrow this down.
                    </p>
                @endif

                {{-- ⚠️ THE SEAT LIST SURVIVES, one click away. The flush pays on
                     seats, so "12 seated" with no way to see WHICH 12 would
                     leave a number nobody can check — consultant.groups.show
                     exists but nothing links to it. Collapsed, not a new tab. --}}
                @if ($seatRows[$key]->isNotEmpty())
                    <details class="border-t border-slate-100 dark:border-slate-800">
                        <summary class="cursor-pointer px-5 py-3 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800/40 sm:px-6">
                            Seated{{ $key === 'all' ? '' : ' on this leg' }} ({{ number_format($t['seated']) }}) — the students filling this service group
                        </summary>
                        <ul class="divide-y divide-slate-100 border-t border-slate-100 dark:divide-slate-800 dark:border-slate-800">
                            @foreach ($seatRows[$key] as $m)
                                @php
                                    // Who this seat is actually FROM — a registering
                                    // consultant other than $selected means it climbed
                                    // the sponsor chain from a downline.
                                    $from = $m->payment && (int) $m->payment->consultant_id !== (int) $selected->id
                                        ? $m->payment->consultant
                                        : null;
                                @endphp
                                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2 sm:px-6">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm text-slate-800 dark:text-slate-200">{{ $m->user->name ?? 'Unknown' }}</span>
                                        <span class="font-mono text-xs text-slate-400">
                                            {{ $m->user?->displayCode() ?? '—' }}
                                            @if ($key === 'all') · Group 0{{ $m->tier }} @endif
                                        </span>
                                        @if ($from)
                                            <span class="block truncate text-xs text-brand-600 dark:text-brand-400">via {{ $from->display_name }}</span>
                                        @endif
                                    </span>
                                    @if ($m->payment)
                                        <x-payment-status :status="$m->payment->status" :type="$m->payment->payment_type" />
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($t['seated'] > $seatRows[$key]->count())
                            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400 dark:border-slate-800 sm:px-6">
                                Showing the first {{ $seatRows[$key]->count() }} of {{ number_format($t['seated']) }}.
                            </p>
                        @endif
                    </details>
                @endif
            </div>
        @endforeach
    </div>
</x-layouts.app>
