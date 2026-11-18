<x-layouts.app title="Service Group Details" header="Service Group Details">
    <div class="mb-6">
        <a href="{{ route('groups.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to service groups
        </a>
    </div>

    @php
        $pct = $group->capacity > 0 ? min(100, ($group->members->count() / $group->capacity) * 100) : 0;
        $commPct = $slotMax > 0 ? min(100, ($slotEarned / $slotMax) * 100) : 0;
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Summary --}}
        <div class="lg:col-span-1 space-y-6">
            <div class="card p-6">
                <div class="flex items-center justify-between">
                    {{-- SCN account, not the internal GRP key — see groups/index. --}}
                    <span class="font-mono text-xs font-semibold text-slate-400" title="{{ $group->group_code }}">{{ $group->consultant?->scn_code ?: $group->group_code }}</span>
                    @php $gs = ['open' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'completed' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400', 'closed' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$group->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                    <span class="badge {{ $gs }}">{{ ucfirst($group->status) }}</span>
                </div>
                <h2 class="mt-3 text-xl font-extrabold text-slate-900 dark:text-white">{{ $group->name ?? 'Service Group' }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">Consultant: {{ $group->consultant?->display_name ?? '—' }}</p>
                <p class="mt-1 font-mono text-xs font-semibold text-brand-600 dark:text-brand-400">
                    {{ $group->consultant?->scn_code ?? $group->consultant?->consultant_code ?? 'No SCN yet' }}
                </p>

                {{-- Group progress --}}
                <div class="mt-6">
                    <div class="mb-1.5 flex items-center justify-between text-sm">
                        <span class="font-medium text-slate-500 dark:text-slate-400">Service Group Progress</span>
                        <span class="font-bold text-slate-900 dark:text-white">{{ $group->members->count() }} / {{ $group->capacity }} Students</span>
                    </div>
                    <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ $pct }}%"></div>
                    </div>
                </div>

                {{-- Service group income. Slot income ONLY — the direct-add
                     bonus is a separate figure below and is deliberately not
                     added in here, since this bar measures the group cap. --}}
                <div class="mt-5">
                    <div class="mb-1.5 flex items-center justify-between text-sm">
                        <span class="font-medium text-slate-500 dark:text-slate-400">Service Group Income</span>
                        <span class="font-bold text-slate-900 dark:text-white">LKR {{ number_format($slotEarned) }} / {{ number_format($slotMax) }}</span>
                    </div>
                    <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full bg-accent-500 transition-all" style="width: {{ $commPct }}%"></div>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-400">
                        LKR {{ number_format($rate) }} per student, on the first
                        {{ $legs[1]['capacity'] }} + {{ $legs[2]['capacity'] }} students.
                    </p>
                </div>

                {{-- Each leg on its own, so it is clear which of the two
                     service groups has filled and what it paid. --}}
                <div class="mt-5 space-y-3">
                    @foreach ([1 => 'Service Group 1', 2 => 'Service Group 2'] as $leg => $legLabel)
                        @php
                            $l = $legs[$leg];
                            $legPct = $l['max'] > 0 ? min(100, ($l['earned'] / $l['max']) * 100) : 0;
                            $full = $l['members'] >= $l['capacity'];
                        @endphp
                        <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-semibold text-slate-600 dark:text-slate-300">
                                    {{ $legLabel }}
                                    @if ($full)
                                        <span class="badge ml-1 bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400">Complete</span>
                                    @endif
                                </span>
                                <span class="font-medium text-slate-500 dark:text-slate-400">{{ $l['members'] }} / {{ $l['capacity'] }} students</span>
                            </div>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full {{ $leg === 1 ? 'bg-emerald-500' : 'bg-brand-500' }}" style="width: {{ $legPct }}%"></div>
                            </div>
                            <p class="mt-1.5 text-xs font-bold {{ $leg === 1 ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-600 dark:text-brand-400' }}">
                                LKR {{ number_format($l['earned']) }} / {{ number_format($l['max']) }}
                            </p>
                        </div>
                    @endforeach
                </div>

                {{-- Kept completely apart from the group income above. --}}
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">Direct Add Bonus</p>
                    <p class="mt-1 text-lg font-extrabold text-amber-600 dark:text-amber-400">LKR {{ number_format($directEarned) }}</p>
                    <p class="mt-1 text-xs text-amber-700/80 dark:text-amber-400/80">
                        Paid to whoever personally registered each student. Not service-group income, and it never climbs to an upline.
                    </p>
                </div>

                <dl class="mt-6 space-y-2 border-t border-slate-100 pt-5 text-sm dark:border-slate-800">
                    <div class="flex justify-between"><dt class="text-slate-400">Remaining slots</dt><dd class="font-medium text-slate-700 dark:text-slate-200">{{ max(0, $group->capacity - $group->members->count()) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Opened</dt><dd class="font-medium text-slate-700 dark:text-slate-200">{{ optional($group->opened_at)->format('M d, Y') ?? '—' }}</dd></div>
                    @if ($group->completed_at)
                        <div class="flex justify-between"><dt class="text-slate-400">Completed</dt><dd class="font-medium text-slate-700 dark:text-slate-200">{{ $group->completed_at->format('M d, Y') }}</dd></div>
                    @endif
                </dl>
            </div>
        </div>

        {{-- Members --}}
        <div class="lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="border-b border-slate-100 p-5 dark:border-slate-800"><h3 class="font-bold text-slate-900 dark:text-white">Service Group members</h3></div>
                @if ($group->members->isEmpty())
                    <p class="p-10 text-center text-sm text-slate-500 dark:text-slate-400">No members have joined this service group yet.</p>
                @else
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($group->members->sortBy('position') as $member)
                            @php
                                // Read the real commission row rather than the
                                // seat's own column, which older placements
                                // left at 0 even after they had earned.
                                $slot = (float) ($memberSlots[$member->id] ?? 0);
                            @endphp
                            <li class="flex items-center gap-3 p-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-bold text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">{{ $member->position }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $member->user->name ?? 'Student' }}</div>
                                    <div class="truncate text-xs text-slate-400">
                                        Service Group {{ $member->tier }} · Joined {{ optional($member->joined_at)->format('M d, Y') }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <span class="text-sm font-bold {{ $slot > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400' }}">
                                        LKR {{ number_format($slot) }}
                                    </span>
                                    @if ($slot <= 0)
                                        <p class="text-[11px] text-slate-400">Beyond the paid slots</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
