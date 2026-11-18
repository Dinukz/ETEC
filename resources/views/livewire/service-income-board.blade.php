{{-- Rendered by App\Livewire\ServiceIncomeBoard. Only this card re-renders
     when an accountant approves or pays a row — the dropdowns stay open and
     the page never reloads. Every button is wire:click; there are no forms
     left in here on purpose, because a form post is what caused the reload. --}}
{{-- Livewire requires a SINGLE root element, and that root is now the grid
     child the card used to be — so the column span lives here, not on the
     card, or the queue would render one-third width beside "Top earners". --}}
<div class="lg:col-span-2">
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-100 p-5 dark:border-slate-800">
                <h3 class="font-bold text-slate-900 dark:text-white">Recent Service Income</h3>
                <a href="{{ route('commissions.history') }}" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">View all</a>
            </div>

            {{-- What the redirect's flash message used to say. Rendered here
                 rather than at the top of the page so the accountant reads the
                 outcome next to the row they just actioned, without scrolling.
                 Wording comes from ManagesServiceIncome, unchanged — including
                 the refusals ("income is currently held"). --}}
            @if ($message)
                <div @class([
                        'flex items-start gap-2 border-b px-5 py-3 text-sm',
                        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $level !== 'error',
                        'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300' => $level === 'error',
                    ])>
                    <span class="flex-1">{{ $message }}</span>
                    <button type="button" wire:click="$set('message', null)" class="shrink-0 font-bold opacity-60 hover:opacity-100" aria-label="Dismiss">&times;</button>
                </div>
            @endif
            @if ($incomeGroups->isEmpty())
                <p class="p-10 text-center text-sm text-slate-500 dark:text-slate-400">No service income recorded yet.</p>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($incomeGroups as $group)
                        @php
                            $owner = $group['owner'];
                            // ⚠️ Open/closed is SERVER state now, not Alpine's.
                            //
                            // It was `x-data="{ open: false }"`, which is fine
                            // for a static page but resets the moment Livewire
                            // swaps this DOM after an action — collapsing the
                            // dropdown the accountant is working in, which is
                            // the exact complaint this component fixes.
                            $isOpen = $open[$owner->id] ?? false;
                        @endphp
                        {{-- One row per PERSON. wire:key is required: without a
                             stable key Livewire re-uses DOM between renders by
                             position, so approving a row can leave another
                             person's buttons attached to the wrong record. --}}
                        <li wire:key="owner-{{ $owner->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                                <button type="button" wire:click="toggle({{ $owner->id }})" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform {{ $isOpen ? 'rotate-90' : '' }}"
                                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $owner->display_name }}</span>
                                        <span class="block truncate font-mono text-xs text-slate-500 dark:text-slate-400">
                                            {{ $owner->scn_code ?? $owner->consultant_code }} · {{ $group['row_count'] }} record(s)
                                        </span>
                                    </span>
                                </button>

                                <div class="flex items-center gap-3">
                                    @if ($group['pending_count'] > 0)
                                        {{-- ⚠️ No "Approve all" since 2026-08-25: income is
                                             approved when its payment is verified, so anything
                                             still pending is HELD and no bulk button can move
                                             it. What remains is a status, not an action. --}}
                                        <span class="text-right">
                                            <span class="block text-xs text-amber-600 dark:text-amber-400">
                                                LKR {{ number_format($group['pending_total']) }} held ({{ $group['pending_count'] }})
                                            </span>
                                        </span>
                                    @else
                                        <span class="badge bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">Nothing pending</span>
                                    @endif
                                </div>
                            </div>

                            {{-- One block per SCN account, and inside it the two
                                 service groups, each with its own approve action. --}}
                            <div @class([
                                    'space-y-3 border-t border-slate-200 bg-slate-100 p-4 dark:border-slate-800 dark:bg-slate-950/40',
                                    'hidden' => ! $isOpen,
                                ])>
                                @foreach ($group['accounts'] as $block)
                                    @php
                                        $account = $block['account'];
                                        // ⚠️ TWO COLOURS, ONE MEANING (2026-08-23).
                                        //
                                        // Nine near-identical blocks in one list gave no clue
                                        // which account belonged to which — "main account ekai
                                        // sub account ekai color dekin dala denn". A GROUP HEAD
                                        // (the person's main, or a bought set's own main) gets
                                        // the dark red band; the two legs hanging beneath it get
                                        // light grey. A bought set repeats the same pair, so the
                                        // shape of the tree is readable at a glance.
                                        $isHead = $block['is_head'] ?? false;
                                        $place = $block['placement'] ?? null;
                                    @endphp
                                    <div wire:key="account-{{ $account->id }}" @class([
                                            'overflow-hidden rounded-xl border',
                                            'border-rose-300 bg-white dark:border-rose-500/40 dark:bg-slate-900' => $isHead,
                                            'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900' => ! $isHead,
                                        ])>
                                        <div @class([
                                                'flex flex-wrap items-center gap-x-2 gap-y-1 border-b px-3 py-2',
                                                'border-rose-800 bg-rose-900 text-rose-50 dark:border-rose-500/40 dark:bg-rose-950' => $isHead,
                                                'border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800/60' => ! $isHead,
                                            ])>
                                            <span @class([
                                                    'font-mono text-sm font-bold',
                                                    'text-white' => $isHead,
                                                    'text-slate-700 dark:text-slate-200' => ! $isHead,
                                                ])>{{ $account->scn_code ?? $account->consultant_code }}</span>
                                            @if ($account->id === $owner->id)
                                                <span class="badge bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200">Main</span>
                                            @elseif ($isHead)
                                                <span class="badge bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200">Group head</span>
                                            @else
                                                <span class="badge bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300">Placement</span>
                                            @endif

                                            {{-- Which account this one hangs under, and on which
                                                 Service Group — the same line the consultant's own
                                                 Service Groups page shows, so the two agree. --}}
                                            @if ($place && ($place['under_code'] ?? null))
                                                <span @class([
                                                        'text-xs',
                                                        'text-rose-200' => $isHead,
                                                        'text-slate-500 dark:text-slate-400' => ! $isHead,
                                                    ])>
                                                    @if (($place['kind'] ?? '') === 'bought')Placed under @else Inside @endif
                                                    <span class="font-mono font-semibold">{{ $place['under_code'] }}</span>@if ($place['tier']) · SG{{ $place['tier'] }}@endif
                                                </span>
                                            @endif

                                            @if ($place && ($place['owner_group'] ?? null))
                                                <span @class([
                                                        'badge ml-auto',
                                                        'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200' => $isHead,
                                                        'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => ! $isHead,
                                                    ])>Service Group {{ $place['owner_group'] }}</span>
                                            @endif
                                        </div>

                                        {{-- Staged release: the money below is EARNED and shown, but only
                                             becomes payable once both legs balance a whole stage. Spell out
                                             exactly how many more students that takes. --}}
                                        @if ($f = $block['flush'])
                                            @php $readyNow = $block['releasable_amount'] > 0; @endphp
                                            <div class="border-b px-3 py-2 text-xs {{ $readyNow ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' }}">
                                                @if ($readyNow)
                                                    {{-- Already flushed by the consultant and now sitting in this
                                                         queue. The figure is what is ACTUALLY approvable, not the
                                                         next flush's headline value — those differ once part of a
                                                         release has already been approved. --}}
                                                    {{-- ⚠️ No button since 2026-08-25 — this income is already
                                                         approved, because approval happens at verification. It is
                                                         waiting to be PAID, which is the only manual step left. --}}
                                                    <span class="font-semibold">Released by a flush</span> —
                                                    LKR {{ number_format($block['releasable_amount']) }} ready to be marked paid.
                                                @elseif ($f['locked'])
                                                    <span class="font-semibold">Account locked</span> —
                                                    the previous account needs {{ $f['unlock_needed'] }} more student(s) before this one can release income.
                                                @elseif ($f['can_flush'])
                                                    {{-- Only the CONSULTANT can flush (it forfeits the heavier
                                                         side), so this tells the accountant what is waiting on
                                                         someone else rather than offering a button they cannot use. --}}
                                                    <span class="font-semibold">Ready to flush</span> —
                                                    the consultant can release LKR {{ number_format($f['payout']) }} from their Service Groups page.
                                                @else
                                                    @php
                                                        // Built in PHP rather than with inline @if directives:
                                                        // Blade's directive regex uses \B, so an @if written
                                                        // straight after a word character ("Group 1@if") is NOT
                                                        // recognised as a directive and its @endif is left
                                                        // orphaned — a fatal ParseError at render time.
                                                        $needs = [];
                                                        if ($f['need_leg1'] > 0) $needs[] = 'LKR '.number_format($f['need_leg1']).' more on Service Group 1';
                                                        if ($f['need_leg2'] > 0) $needs[] = 'LKR '.number_format($f['need_leg2']).' more on Service Group 2';
                                                    @endphp
                                                    <span class="font-semibold">Waiting for {{ $f['label'] }}</span> —
                                                    needs <strong>{{ implode(', ', $needs) }}</strong>
                                                    before the consultant can flush LKR {{ number_format($f['payout']) }}.
                                                @endif
                                                <span class="opacity-70">(Service Group 1: LKR {{ number_format($f['leg1']) }} · Service Group 2: LKR {{ number_format($f['leg2']) }})</span>
                                            </div>
                                        @endif

                                        <div class="divide-y divide-slate-200 dark:divide-slate-700">
                                            @foreach ($block['legs'] as $leg)
                                                @continue ($leg['rows']->isEmpty())
                                                <div class="p-3">
                                                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                                        <div>
                                                            <span class="text-xs font-bold uppercase tracking-wide text-brand-700 dark:text-brand-300">
                                                                Service Group {{ $leg['tier'] }}
                                                            </span>
                                                            <span class="ml-1 text-xs text-slate-600 dark:text-slate-400">
                                                                {{ $leg['filled'] }} student(s)
                                                            </span>
                                                        </div>

                                                    </div>

                                                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                                                        @foreach ($leg['rows'] as $c)
                                                            <li wire:key="slot-{{ $c->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                                                <div class="min-w-0">
                                                                    <div class="truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ $c->student->name ?? 'Student' }}</div>
                                                                    <div class="truncate text-xs text-slate-500 dark:text-slate-400">{{ optional($c->incomeDate())->format('M d, Y') }}</div>
                                                                    {{-- Who this row is actually FROM, for an upline
                                                                         credit — the downline consultant whose own
                                                                         registration climbed the sponsor chain to earn
                                                                         it. Absent for a consultant's own placement. --}}
                                                                    @if ($source = $c->sourceConsultant())
                                                                        <div class="truncate text-xs text-brand-600 dark:text-brand-400">
                                                                            via {{ $source->scn_code ?? $source->consultant_code }} — {{ $source->display_name }}
                                                                        </div>
                                                                    @endif
                                                                    {{-- WHY this 2,500 exists, when it did not come from
                                                                         registering somebody else: the consultant bought a
                                                                         course through their own account. Asked for
                                                                         2026-09-06 — "course ekak buy kra kiyla methan
                                                                         penwann, e buy krla thama methant 2500 add une kiyla
                                                                         danagann". Same wording as the consultant's own
                                                                         Service Groups page. --}}
                                                                    @if ($c->isCoursePurchase())
                                                                        <div class="truncate text-xs text-sky-600 dark:text-sky-400">
                                                                            Opened by a course purchase on this account
                                                                        </div>
                                                                    @endif
                                                                </div>
                                                                <div class="flex items-center gap-2">
                                                                    <span class="text-sm font-bold text-slate-900 dark:text-white">LKR {{ number_format($c->amount) }}</span>
                                                                    <x-commission-status :status="$c->display_status" />
                                                                    {{-- Says WHY a row is paid while its account still reads
                                                                         "waiting for 2-to-2". Without it that combination is
                                                                         unexplainable from any screen — reported 2026-08-15
                                                                         against SCN-00110. --}}
                                                                    @if ($c->wasManuallyReleased())
                                                                        <span class="text-[11px] font-semibold text-amber-600 dark:text-amber-400" title="Released by hand with the Approve button, not by a System Run">Manual</span>
                                                                    @endif
                                                                    @if ($c->held_at)
                                                                        {{-- no action — resolves automatically once the quota is met --}}
                                                                    @elseif ($c->status === 'pending')
                                                                        {{-- ⚠️ NO APPROVE BUTTON (2026-08-25). Slot income is
                                                                             approved when its payment is verified, so a row still
                                                                             pending here is HELD — the consultant is restricted or
                                                                             suspended, and approve() refuses it on the one gate
                                                                             nothing overrides. A button that always refuses is
                                                                             worse than no button. --}}
                                                                        <span class="text-[11px] font-semibold text-amber-600 dark:text-amber-400" title="Approved automatically at verification. Still pending means this consultant's income is currently held.">Held</span>
                                                                    @elseif ($c->status === 'approved')
                                                                        <button type="button" class="btn-primary py-1 text-xs"
                                                                            wire:click="payRow({{ $c->id }})"
                                                                            wire:loading.attr="disabled"
                                                                            wire:target="payRow({{ $c->id }})">
                                                                            <span wire:loading.remove wire:target="payRow({{ $c->id }})">Mark paid</span>
                                                                            <span wire:loading wire:target="payRow({{ $c->id }})">…</span>
                                                                        </button>
                                                                    @endif
                                                                    @include('livewire.partials.income-remove', ['c' => $c])
                                                                </div>
                                                                @include('livewire.partials.income-remove-confirm', ['c' => $c])
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endforeach

                                            {{-- Not part of any service group — the registrant's
                                                 own bonus, so it has no group-level approve. --}}
                                            @if ($block['direct_rows']->isNotEmpty())
                                                <div class="p-3">
                                                    <p class="mb-2 text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-400">
                                                        Direct Add Bonus · LKR {{ number_format($block['direct_rows']->sum('amount')) }}
                                                    </p>
                                                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                                                        @foreach ($block['direct_rows'] as $c)
                                                            <li wire:key="direct-{{ $c->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                                                <div class="min-w-0">
                                                                    <div class="truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ $c->student->name ?? 'Student' }}</div>
                                                                    <div class="truncate text-xs text-slate-500 dark:text-slate-400">{{ optional($c->incomeDate())->format('M d, Y') }}</div>
                                                                </div>
                                                                <div class="flex items-center gap-2">
                                                                    <span class="text-sm font-bold text-slate-900 dark:text-white">LKR {{ number_format($c->amount) }}</span>
                                                                    <x-commission-status :status="$c->display_status" />
                                                                    @if ($c->held_at)
                                                                        {{-- no action — resolves automatically once the quota is met --}}
                                                                    @elseif ($c->status === 'pending')
                                                                        {{-- ⚠️ NO APPROVE BUTTON (2026-08-25). Income is approved
                                                                             the moment its payment is verified, so a row that is
                                                                             still pending is being HELD — a restricted or
                                                                             suspended consultant. Offering Approve there was
                                                                             offering a button that refuses, and clicking it was
                                                                             what made the totals look duplicated. --}}
                                                                        <span class="text-[11px] font-semibold text-amber-600 dark:text-amber-400" title="Approved automatically at verification. Still pending means this consultant's income is currently held.">Held</span>
                                                                    @elseif ($c->status === 'approved')
                                                                        <button type="button" class="btn-primary py-1 text-xs"
                                                                            wire:click="payRow({{ $c->id }})"
                                                                            wire:loading.attr="disabled" wire:target="payRow({{ $c->id }})">
                                                                            <span wire:loading.remove wire:target="payRow({{ $c->id }})">Mark paid</span>
                                                                            <span wire:loading wire:target="payRow({{ $c->id }})">…</span>
                                                                        </button>
                                                                    @endif
                                                                    @include('livewire.partials.income-remove', ['c' => $c])
                                                                </div>
                                                                @include('livewire.partials.income-remove-confirm', ['c' => $c])
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
</div>
