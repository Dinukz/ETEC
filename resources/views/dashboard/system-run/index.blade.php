<x-layouts.app title="System Run" header="System Run">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Daily System Run</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">
            Settles the previous day's activations. Every balanced service group is flushed at once:
            the matched pair is released for approval, and whatever is left unmatched on the heavier
            side is closed off to company revenue.
        </p>
    </div>

    {{-- Step 1 — the notice --}}
    <div class="card mb-6 p-5 sm:p-6">
        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400">Step 1 · Notify consultants</h3>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
            Tells every active consultant that the run is happening today, so they can clear pending
            payments and report mistakes first. Send this well before running.
        </p>

        <form method="POST" action="{{ route('system-run.announce') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="run_at" class="label">Run time</label>
                <input id="run_at" name="run_at" type="text" value="{{ old('run_at', '1.00 pm') }}" required maxlength="40" class="input sm:w-48" placeholder="1.00 pm">
            </div>
            <button type="submit" class="btn-secondary">Send notice to all consultants</button>
        </form>

        {{-- The outbox. Notices are QUEUED, not sent inline - several hundred
             blocking SMTP calls in one request is what put this page over the
             proxy timeout. The scheduler drains it every minute if cron is
             running `schedule:run`; this is the manual fallback, and the count
             is what makes a stalled queue visible at all. --}}
        @if (! empty($pendingNotices))
            <div class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                <p class="text-sm text-amber-900 dark:text-amber-200">
                    <strong>{{ $pendingNotices }}</strong> email(s) waiting to go out.
                </p>
                <form method="POST" action="{{ route('system-run.notices.send') }}">
                    @csrf
                    <button type="submit" class="btn-secondary">Send pending emails</button>
                </form>
            </div>
        @endif
    </div>

    {{-- Step 2 — the preview --}}
    <div class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800 sm:p-6">
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400">Step 2 · Check what will happen</h3>
            @php
                $today = \App\Services\ServiceGroupFlush::latestSelectableCutoff();
                $settlingToday = $preview['cutoff']->isSameDay($today);
            @endphp

            {{-- ⚠️ THE DATE SCOPES THE RUN (restored 2026-09-14, after one evening
                 without it). What changed is the second sentence: an account that
                 settles at 3-to-3 ends at zero, so its money entered after the date
                 goes to the company too — see ServiceGroupFlush::legPlan(). --}}
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                Settling everything registered on or before
                <strong class="text-slate-900 dark:text-white">{{ $preview['cutoff']->format('l, j F Y') }}</strong>
                (11:59 pm). Students registered after that wait for a later run — except on an account that
                settles at 3-to-3, which ends at zero.
            </p>

            <form method="GET" action="{{ route('system-run.index') }}" class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="cutoff" class="label">Settle up to</label>
                    {{-- Today IS selectable. The default stays yesterday (the daily
                         routine), but capping the input at yesterday made same-day
                         registrations impossible to settle at all. --}}
                    <input id="cutoff" name="cutoff" type="date" value="{{ $preview['cutoff']->toDateString() }}"
                        max="{{ $today->toDateString() }}" class="input sm:w-48">
                </div>
                <button type="submit" class="btn-secondary">Recheck</button>
                @role('super_admin')
                    <a href="{{ route('system-run.rules') }}" class="btn-secondary">Rules</a>
                @endrole
                @unless ($settlingToday)
                    <a href="{{ route('system-run.index', ['cutoff' => $today->toDateString()]) }}" class="btn-secondary">
                        Include today
                    </a>
                @endunless
            </form>

            @if ($settlingToday)
                <p class="mt-3 text-sm font-semibold text-amber-600 dark:text-amber-400">
                    Settling TODAY as well — anything registered up to now is included. Make sure consultants
                    were told, because whatever is unmatched at the run is closed off to the company.
                </p>
            @endif

            {{-- ⚠️ An earlier date than the last run's (2026-09-13): run #39 used
                 9 Sep after 10 Sep had already been settled, silently. --}}
            @if ($earlierThanLast ?? false)
                <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <p class="font-semibold">This date is earlier than the last System Run.</p>
                    <p class="mt-1">
                        Run #{{ $lastRun->id }} already settled up to {{ $lastRun->cutoffLabel() }}. This run counts payments
                        entered up to {{ $preview['cutoff']->format('D j M') }}, and an account that settles at 3-to-3 also
                        sends everything entered after that to the company.
                    </p>
                </div>
            @endif

            {{-- ⚠️ SLIPS THAT MISSED THEIR OWN RUN (2026-09-24). A payment banked
                 on the 21st but verified on the 22nd could not be in the 21st
                 run; the next run then sweeps it up and records it as its own
                 day's money — "eka penwann one 21 system run eke, nathuw 22
                 dawase newei". Named here so the date can be run again instead
                 of the money quietly landing on the wrong day. --}}
            {{-- ⚠️ REWORDED 2026-10-01. It said every row was a slip "verified after
                 Run #N" and offered a "Run <old date>" link. Both were wrong for what
                 the owner actually had: 52 rows dated 9 Sep, written by Commission
                 Health → Repair for OLD payments — not late slips. And an old-date
                 run is not a free way to re-file money: at 3-to-3 it closes off
                 everything entered after that date to the company (legPlan, the
                 owner's rule of 2026-09-14). The page now says WHY each row missed,
                 counts payments as payments, and recommends the safe choice. --}}
            @if (($lateArrivals ?? collect())->isNotEmpty())
                @php $sweptNow = $lateArrivals->filter(fn ($r) => $r['date'] < $preview['cutoff']->copy()->setTimezone('Asia/Colombo')->toDateString()); @endphp
                <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-semibold">Some money dated before Run #{{ $lastRun->id }} was recorded after it.</p>
                    <p class="mt-1">
                        Run #{{ $lastRun->id }} settled up to {{ $lastRun->cutoffLabel() }}. These records did not exist yet when it ran, so it could not settle them.
                        Nothing is lost — they settle with the next run.
                    </p>
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($lateArrivals as $late)
                            <li>
                                <span class="font-semibold">{{ \Illuminate\Support\Carbon::parse($late['date'])->format('D j M') }}</span>
                                · LKR {{ number_format($late['amount'], 2) }}
                                <span class="text-xs opacity-75">
                                    · {{ $late['rows'] }} {{ \Illuminate\Support\Str::plural('record', $late['rows']) }}
                                    @if ($late['payments'] > 0)
                                        from {{ $late['payments'] }} {{ \Illuminate\Support\Str::plural('payment', $late['payments']) }}
                                    @endif
                                </span>
                                {{-- Why they missed the run, so a repair is never read as
                                     a pile of late slips. --}}
                                <span class="block text-xs opacity-75">
                                    @if ($late['late_slip'] > 0)
                                        {{ $late['late_slip'] }} {{ \Illuminate\Support\Str::plural('slip', $late['late_slip']) }} verified after the run.
                                    @endif
                                    @if ($late['repaired'] > 0)
                                        {{ $late['repaired'] }} older {{ \Illuminate\Support\Str::plural('payment', $late['repaired']) }} completed afterwards by Commission Health → Repair.
                                    @endif
                                    @if ($late['hand'] > 0)
                                        {{ $late['hand'] }} entered by hand.
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($sweptNow->isNotEmpty())
                        <p class="mt-3 font-semibold">
                            Recommended: run {{ $preview['cutoff']->format('D j M') }} as normal. LKR {{ number_format((float) $sweptNow->sum('amount'), 2) }}
                            is settled in this run and filed under {{ $preview['cutoff']->format('j M') }}.
                        </p>
                        <p class="mt-1 text-xs">
                            Running an older date instead would file it under that date, but it is an old-date run: any account that settles at
                            3-to-3 in it sends everything entered after that date to company revenue.
                        </p>
                    @endif
                </div>
            @endif

            {{-- ⚠️ Spell out that a run settles ONE stage per account, or the
                 screen looks broken: an account holding 30,000 flushes 5,000
                 and the rest is still sitting there afterwards, which reads as
                 "hariyata adu wela na". It is not stuck — it moved up a stage
                 and the next run takes the next slice. --}}
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                Each account settles <strong>one stage per run</strong> — 1-to-1 pays LKR 5,000, 2-to-2 pays
                LKR 10,000, 3-to-3 pays LKR 15,000 — then moves up a stage. Run it again to settle the
                next stage.
                {{-- ⚠️ ONE RULE FOR ALL THREE STAGES SINCE 2026-08-27. This used
                     to say a side settles ALONE at 3-to-3; that let SCN-00103
                     release 7,500 against an empty other side and close 30,000
                     off to the company. Both sides are required at every stage
                     now — only what happens to the SURPLUS still differs. --}}
                <span class="block mt-1">
                    <strong>Both service groups must reach the stage amount</strong> — 2,500 each at 1-to-1,
                    5,000 each at 2-to-2, 7,500 each at 3-to-3. If either side is short, the account does not
                    take part in the run at all and keeps everything it holds.
                </span>
                <span class="block mt-1">
                    Once it does qualify: on the <strong>ramp</strong> (1-to-1, 2-to-2) anything above the
                    stage amount stays on the leg for next time. At <strong>3-to-3</strong> each side releases
                    7,500 and everything else on both legs is closed off to the company — payments entered
                    after the date above included — so the account ends at zero.
                </span>
                {{-- ⚠️ Rewritten six times because the rule behind it kept
                     flipping, and conditional for one day while it was a
                     setting. There is nothing conditional left to say: since
                     2026-09-02 the main is an ordinary account holding its own
                     money, so ONE sentence covers every account. --}}
                <span class="block mt-1">
                    The rule above is applied to <strong>each SCN account on its own</strong>, including the
                    consultant's <strong>main account</strong> — it holds its own Service Group 1 and 2, settles
                    when both sides reach its stage, and takes nothing from the accounts inside it. An account
                    that has not balanced <strong>cannot be touched by anything</strong>.
                </span>
            </p>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-3 sm:p-6">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Accounts to flush</p>
                <p class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-white">{{ number_format($preview['count']) }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Released to consultants</p>
                <p class="mt-1 text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($preview['payout']) }}</p>
                {{-- The run's own record used to disagree with this figure: it
                     showed the nominal stage amount while the run released only
                     the rows still unpaid. Saying so up front is what stops the
                     "preview said 5,000, run logged 0" report recurring. --}}
                @if (($preview['already_paid'] ?? 0) > 0)
                    <p class="text-xs text-slate-400">
                        + LKR {{ number_format($preview['already_paid']) }} already paid when approved — this run only clears it off the legs
                    </p>
                @endif
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Goes to company</p>
                <p class="mt-1 text-2xl font-extrabold text-rose-500">LKR {{ number_format($preview['forfeit']) }}</p>
                <p class="text-xs text-slate-400">cannot be recovered</p>
            </div>
        </div>

        @if ($preview['count'] > 0)
            <div class="overflow-x-auto border-t border-slate-100 dark:border-slate-800">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Account</th>
                            <th class="px-5 py-3 font-semibold">Service Group 1</th>
                            <th class="px-5 py-3 font-semibold">Service Group 2</th>
                            <th class="px-5 py-3 font-semibold">Releases</th>
                            <th class="px-5 py-3 font-semibold">To company</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($preview['accounts'] as $a)
                            <tr>
                                <td class="px-5 py-3">
                                    <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $a['code'] }}</span>
                                    {{-- $a['label'] reads "1-to-1 → 2-to-2" when this run
                                         walks more than one stage on the account, which is
                                         why its payout can exceed what its CURRENT stage is
                                         worth. Without that the figure looks wrong. --}}
                                    <span class="block text-xs text-slate-400">{{ $a['name'] }} · {{ $a['label'] }}</span>
                                    @if (($a['rounds'] ?? 1) > 1)
                                        <span class="block text-xs text-emerald-600 dark:text-emerald-400">
                                            settles {{ $a['rounds'] }} stages in this one run
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">LKR {{ number_format($a['leg1']) }}</td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">LKR {{ number_format($a['leg2']) }}</td>
                                <td class="px-5 py-3 font-semibold text-emerald-600 dark:text-emerald-400">
                                    LKR {{ number_format($a['payout']) }}
                                    @if (($a['already_paid'] ?? 0) > 0)
                                        <span class="block text-xs font-normal text-slate-400">
                                            of LKR {{ number_format($a['stage_value']) }} at this stage — LKR {{ number_format($a['already_paid']) }} was already paid on approval
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 {{ $a['forfeit'] > 0 ? 'font-semibold text-rose-500' : 'text-slate-400' }}">LKR {{ number_format($a['forfeit']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="border-t border-slate-100 p-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                No account has both service groups balanced for this date — running now would change nothing.
            </p>
        @endif

        {{-- ⚠️ ON DEMAND (2026-09-14). Computing this list walks every account
             holding money and made the page slow; it now loads behind a button.
             The count is cheap, so it can still be named. --}}
        @if ($preview['skipped_hidden'] ?? false)
            @if (($preview['holding_count'] ?? 0) > 0)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 dark:border-slate-800 sm:px-6">
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wide text-amber-600 dark:text-amber-400">
                            Holding money but not flushing — {{ number_format($preview['holding_count']) }} account(s)
                        </h4>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Nothing here is lost. It is left off to keep this page fast — load it only when you need it.
                        </p>
                    </div>
                    <a href="{{ route('system-run.index', ['cutoff' => $preview['cutoff']->toDateString(), 'holding' => 1]) }}" class="btn-secondary shrink-0">
                        Show these accounts
                    </a>
                </div>
            @endif
        @elseif (! empty($preview['skipped']))
            <div class="border-t border-slate-100 dark:border-slate-800">
                <div class="px-5 py-4 sm:px-6">
                    <h4 class="text-xs font-bold uppercase tracking-wide text-amber-600 dark:text-amber-400">
                        Holding money but not flushing — {{ count($preview['skipped']) }} account(s), LKR {{ number_format($preview['waiting_total']) }}
                    </h4>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Nothing here is lost. It stays on the legs and settles at a future run once the reason clears.
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Account</th>
                                <th class="px-5 py-3 font-semibold">Service Group 1</th>
                                <th class="px-5 py-3 font-semibold">Service Group 2</th>
                                <th class="px-5 py-3 font-semibold">Why not</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($preview['skipped'] as $s)
                                <tr>
                                    <td class="px-5 py-3">
                                        <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $s['code'] }}</span>
                                        <span class="block text-xs text-slate-400">{{ $s['name'] }}</span>
                                    </td>
                                    {{-- leg*_total − leg* is money registered too late
                                         for THIS run only. Both figures are measured in
                                         the account's settlement scope, so for a Main
                                         they describe its two Service Groups — the same
                                         numbers it is judged on. --}}
                                    @foreach ([1, 2] as $leg)
                                        <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                            LKR {{ number_format($s['leg'.$leg]) }}
                                            @if ($s['leg'.$leg.'_total'] > $s['leg'.$leg])
                                                <span class="block text-xs text-slate-400">LKR {{ number_format($s['leg'.$leg.'_total']) }} total incl. after cutoff</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-5 py-3 text-xs text-amber-600 dark:text-amber-400">{{ $s['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    {{-- Step 3 — the run --}}
    <div class="card mb-6 border-rose-200 bg-rose-50 p-5 dark:border-rose-500/30 dark:bg-rose-500/10 sm:p-6">
        <h3 class="text-xs font-bold uppercase tracking-wide text-rose-700 dark:text-rose-300">Step 3 · Run it</h3>
        <p class="mt-2 text-sm text-rose-800 dark:text-rose-300">
            This cannot be undone. LKR {{ number_format($preview['forfeit']) }} will be permanently closed
            off to company revenue across {{ number_format($preview['count']) }} account(s).
            Only run this once everyone has had the chance to fix their pending students.
        </p>

        {{-- ⚠️ THE DATE THIS BUTTON RUNS (2026-11-15). The run posts the date the
             figures above were checked for - the hidden cutoff below - NOT whatever
             the calendar shows now. Picking a new date and pressing Start without
             Recheck used to run the OLD date with no warning, and Recent runs would
             then list a date nobody picked. The date is named here, and the script
             at the end of this card holds the button until Recheck is pressed. --}}
        <p class="mt-2 text-sm text-rose-800 dark:text-rose-300">
            Date selected for this run:
            <strong id="run-date-selected" data-date="{{ $preview['cutoff']->toDateString() }}">{{ $preview['cutoff']->format('l, j F Y') }}</strong>
        </p>
        <p id="run-date-changed" hidden class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            The calendar date was changed. Press Recheck in Step 2 to see that date's figures before running.
        </p>

        <form method="POST" action="{{ route('system-run.run') }}" class="mt-4 flex flex-wrap items-end gap-3"
            onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Running…';">
            @csrf
            <input type="hidden" name="cutoff" value="{{ $preview['cutoff']->toDateString() }}">
            <div>
                <label for="confirm" class="label text-rose-800 dark:text-rose-300">Type RUN to confirm</label>
                <input id="confirm" name="confirm" type="text" required autocomplete="off" class="input sm:w-48" placeholder="RUN">
            </div>
            @if ($earlierThanLast ?? false)
                <label class="flex w-full items-center gap-2 text-sm font-medium text-rose-800 dark:text-rose-300">
                    <input type="checkbox" name="confirm_earlier" value="1" required class="rounded border-rose-300 text-rose-600">
                    I understand this run is for {{ $preview['cutoff']->format('D j M') }}, earlier than the last run
                </label>
            @endif

            {{-- The other direction: an OLDER day's money folded into this run.
                 Same shape as the guard above, and checked in the controller
                 too — see the note there. --}}
            @php $sweptOnSubmit = ($lateArrivals ?? collect())->filter(fn ($r) => $r['date'] < $preview['cutoff']->copy()->setTimezone('Asia/Colombo')->toDateString()); @endphp
            @if ($sweptOnSubmit->isNotEmpty())
                <label class="flex w-full items-center gap-2 text-sm font-medium text-rose-800 dark:text-rose-300">
                    <input type="checkbox" name="confirm_sweeps_earlier" value="1" required class="rounded border-rose-300 text-rose-600">
                    Settle the LKR {{ number_format((float) $sweptOnSubmit->sum('amount'), 2) }} dated earlier in this run too (filed under {{ $preview['cutoff']->format('D j M') }})
                </label>
            @endif
            <button type="submit" id="start-system-run" class="btn bg-rose-600 text-white shadow-sm" @disabled($preview['count'] === 0)>
                Start System Run
            </button>

            {{-- ⚠️ SUPER ADMIN ONLY. The run was never time-limited; what made
                 it unusable for testing is that every run messages EVERY active
                 consultant. This skips only that. The money moves exactly the
                 same way either way — it is not a dry run. The role is checked
                 again in the controller, because a checkbox is not a
                 permission. --}}
            @role('super_admin')
                <label class="flex items-center gap-2 text-xs text-rose-800 dark:text-rose-300">
                    <input type="checkbox" name="silent" value="1" class="rounded border-rose-300 text-rose-600">
                    Silent test run — settle normally, but don't notify consultants
                </label>
            @endrole
        </form>

        {{-- Holds Start System Run while the calendar shows a different date from
             the one checked. Display only: the server runs the posted date either
             way, exactly as before. --}}
        <script>
            (function () {
                var calendar = document.getElementById('cutoff');
                var selected = document.getElementById('run-date-selected');
                var notice = document.getElementById('run-date-changed');
                var button = document.getElementById('start-system-run');
                if (!calendar || !selected || !notice || !button) { return; }
                var checkedDate = selected.getAttribute('data-date');
                var couldRun = !button.disabled;
                var sync = function () {
                    var changed = calendar.value !== checkedDate;
                    notice.hidden = !changed;
                    button.disabled = changed || !couldRun;
                };
                calendar.addEventListener('input', sync);
                calendar.addEventListener('change', sync);
            })();
        </script>
    </div>

    {{-- History --}}
    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800 sm:px-6">
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400">Recent runs</h3>
        </div>
        @if ($recentRuns->isEmpty())
            <p class="p-8 text-center text-sm text-slate-500 dark:text-slate-400">No System Run has been recorded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                        {{-- ⚠️ DATE SELECTED (2026-11-15). The owner asked to see, beside the
                             time a run was pressed, the date picked on the calendar for it:
                             "calender eken select krana date ekath add krnn one recent ekata".
                             That date is cutoff_at - the form posts exactly the date the
                             figures were checked for, and the run settles payments dated on
                             or before it. It sat third, labelled Settled up to, so it was
                             not read as the picked date. Both dates are Sri Lanka time: Run
                             at used to print created_at in UTC (the app timezone), 5h30m
                             behind the clock in the office. --}}
                        <tr>
                            <th class="px-5 py-3 font-semibold">Run at</th>
                            <th class="px-5 py-3 font-semibold">Date selected</th>
                            <th class="px-5 py-3 font-semibold">By</th>
                            <th class="px-5 py-3 font-semibold">Accounts</th>
                            <th class="px-5 py-3 font-semibold">Released</th>
                            <th class="px-5 py-3 font-semibold">To company</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($recentRuns as $run)
                            @php
                                $settled = $run->settledAccounts();
                                $ranAt = $run->created_at?->copy()->setTimezone('Asia/Colombo');
                                $pickedDate = $run->cutoffDate();
                                // Whole days between the picked date and the day it was run, read
                                // from the two date strings so no timezone can shift it.
                                $daysBefore = ($ranAt && $pickedDate !== '')
                                    ? (int) round((strtotime($ranAt->toDateString()) - strtotime($pickedDate)) / 86400)
                                    : null;
                            @endphp
                            {{-- Expandable: "1 account" is not an audit trail without
                                 naming WHICH one. Runs recorded before the accounts
                                 column existed have nothing to expand. --}}
                            <tr x-data="{ open: false }">
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $ranAt?->format('M d, Y g:i a') }}</td>
                                <td class="px-5 py-3">
                                    @if ($pickedDate !== '')
                                        <span class="font-semibold text-slate-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($pickedDate)->format('D, M d, Y') }}</span>
                                        @if ($daysBefore === 0)
                                            <span class="block text-xs text-amber-600 dark:text-amber-400">same day as the run</span>
                                        @elseif ($daysBefore === 1)
                                            <span class="block text-xs text-slate-400">the day before the run</span>
                                        @elseif ($daysBefore > 1)
                                            <span class="block text-xs text-slate-400">{{ $daysBefore }} days before the run</span>
                                        @endif
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $run->runBy->name ?? 'System' }}</td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                    @if ($settled)
                                        <button type="button" @click="open = ! open"
                                            class="inline-flex items-center gap-1.5 font-semibold text-brand-600 hover:underline dark:text-brand-400">
                                            <svg class="h-3.5 w-3.5 transition-transform" :class="open && 'rotate-90'"
                                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                            </svg>
                                            {{ number_format($run->accounts_flushed) }}
                                        </button>
                                        <div x-show="open" x-cloak class="mt-2 flex flex-col gap-1">
                                            @foreach ($settled as $a)
                                                <div class="rounded-lg bg-slate-50 px-3 py-1.5 dark:bg-slate-800/60">
                                                    <span class="font-mono text-xs font-semibold text-slate-900 dark:text-white">{{ $a['code'] ?? '—' }}</span>
                                                    <span class="block text-xs text-slate-400">{{ $a['name'] ?? '' }}</span>
                                                    <span class="block text-xs">
                                                        <span class="text-emerald-600 dark:text-emerald-400">released LKR {{ number_format($a['released'] ?? 0) }}</span>
                                                        @if (($a['forfeited'] ?? 0) > 0)
                                                            · <span class="text-rose-500">LKR {{ number_format($a['forfeited']) }} to company</span>
                                                        @endif
                                                    </span>

                                                    {{-- ⚠️ Undo this ONE account's settlement. Super admin
                                                         only — it un-books company revenue and re-credits a
                                                         consultant's totals, so the role is checked again in
                                                         the controller. Rows already marked PAID are left
                                                         alone by the reversal itself. --}}
                                                    @if (! empty($a['reversed_at']))
                                                        <span class="mt-1 block text-xs text-amber-600 dark:text-amber-400">
                                                            Reversed {{ $a['reversed_at'] }}
                                                        </span>
                                                    @elseif (! empty($a['account_id']))
                                                        @role('super_admin')
                                                            <form method="POST" action="{{ route('system-run.reverse', $run) }}" class="mt-1"
                                                                onsubmit="return confirm('Reverse {{ $a['code'] ?? 'this account' }}?\n\nIts records go back onto their service groups, any amount closed off to the company is taken back, and its stage returns to what it was. Records already marked PAID are left alone.');">
                                                                @csrf
                                                                <input type="hidden" name="account_id" value="{{ $a['account_id'] }}">
                                                                <button type="submit" class="text-xs font-semibold text-rose-600 hover:underline dark:text-rose-400">Reverse this account</button>
                                                            </form>
                                                        @endrole
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        {{ number_format($run->accounts_flushed) }}
                                        @if ($run->accounts_flushed > 0)
                                            <span class="block text-xs text-slate-400">not recorded for this run</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-5 py-3 font-semibold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($run->total_released) }}</td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">LKR {{ number_format($run->total_forfeited) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
