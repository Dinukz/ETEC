<x-layouts.app title="Service Group Details" header="Service Group Details">
    <div class="mb-6">
        <a href="{{ route('consultant.groups.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to service groups
        </a>
    </div>

    @php
        $settings = \App\Models\CommissionSetting::current();
        $pct = $group->capacity > 0 ? min(100, ($group->members->count() / $group->capacity) * 100) : 0;
        $commPct = $settings->max_commission > 0 ? min(100, ($group->total_commission_earned / $settings->max_commission) * 100) : 0;
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-1">
            <div class="card p-6">
                <div class="flex items-center justify-between">
                    {{-- SCN account, not the internal GRP key — see groups/index.
                         `consultant` is eager-loaded by the controller for this. --}}
                    <span class="font-mono text-xs font-semibold text-slate-400" title="{{ $group->group_code }}">{{ $group->consultant?->scn_code ?: $group->group_code }}</span>
                    @php $gs = ['open' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'completed' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400', 'closed' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$group->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                    <span class="badge {{ $gs }}">{{ ucfirst($group->status) }}</span>
                </div>
                <h2 class="mt-3 text-xl font-extrabold text-slate-900 dark:text-white">{{ $group->name ?? 'Service Group' }}</h2>

                <div class="mt-6">
                    <div class="mb-1.5 flex items-center justify-between text-sm">
                        <span class="font-medium text-slate-500 dark:text-slate-400">Service Group Progress</span>
                        <span class="font-bold text-slate-900 dark:text-white">{{ $group->members->count() }} / {{ $group->capacity }} Students</span>
                    </div>
                    <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ $pct }}%"></div>
                    </div>
                </div>

                {{-- Slot income only. The direct-add bonus is deliberately not
                     included: this bar measures the service-group cap. --}}
                <div class="mt-5">
                    <div class="mb-1.5 flex items-center justify-between text-sm">
                        <span class="font-medium text-slate-500 dark:text-slate-400">Service Group Income</span>
                        <span class="font-bold text-slate-900 dark:text-white">LKR {{ number_format($group->total_commission_earned) }} / {{ number_format($settings->max_commission) }}</span>
                    </div>
                    <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full bg-accent-500 transition-all" style="width: {{ $commPct }}%"></div>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-400">
                        LKR {{ number_format($settings->commission_per_student) }} per student, up to
                        LKR {{ number_format($settings->max_commission) }} per account. Your Direct Add Bonus is separate.
                    </p>
                </div>

                <dl class="mt-6 space-y-2 border-t border-slate-100 pt-5 text-sm dark:border-slate-800">
                    <div class="flex justify-between"><dt class="text-slate-400">Remaining slots</dt><dd class="font-medium text-slate-700 dark:text-slate-200">{{ max(0, $group->capacity - $group->members->count()) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Opened</dt><dd class="font-medium text-slate-700 dark:text-slate-200">{{ optional($group->opened_at)->format('M d, Y') ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="border-b border-slate-100 p-5 dark:border-slate-800"><h3 class="font-bold text-slate-900 dark:text-white">Service Group members</h3></div>
                @if ($group->members->isEmpty())
                    <p class="p-10 text-center text-sm text-slate-500 dark:text-slate-400">No students in this service group yet.</p>
                @else
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($group->members->sortBy('position') as $member)
                            <li class="flex items-center gap-3 p-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-bold text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">{{ $member->position }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $member->user->name ?? 'Student' }}</div>
                                    <div class="truncate text-xs text-slate-400">Joined {{ optional($member->joined_at)->format('M d, Y') }}</div>
                                </div>
                                <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($member->commission_amount) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
