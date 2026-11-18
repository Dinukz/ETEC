<x-layouts.app title="Service Income Summary" header="Service Income Summary">
    <div class="mb-6">
        <a href="{{ route('commissions.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to service income
        </a>
    </div>

    <div class="mb-6">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">All Service Income</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ number_format($totals['count']) }} record(s) · LKR {{ number_format($totals['amount']) }}
            @if ($from || $to)
                recorded
                @if ($from && $to)
                    between {{ $from->format('M d, Y') }} and {{ $to->format('M d, Y') }}
                @elseif ($from)
                    on or after {{ $from->format('M d, Y') }}
                @else
                    on or before {{ $to->format('M d, Y') }}
                @endif
            @endif
        </p>
    </div>

    <form method="GET" action="{{ route('commissions.history') }}" class="card mb-6 p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
            <div>
                <label for="status" class="label">Status</label>
                <select id="status" name="status" class="input lg:w-40">
                    <option value="">All statuses</option>
                    @foreach (['pending' => 'Pending', 'held' => 'On Hold', 'approved' => 'Approved', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type" class="label">Type</label>
                <select id="type" name="type" class="input lg:w-44">
                    <option value="">All types</option>
                    @foreach (['bonus' => 'Service Group Income', 'referral' => 'Direct Add Bonus'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            {{-- Both ends inclusive, matched against the Date column. --}}
            <div>
                <label for="from" class="label">From</label>
                <input id="from" type="date" name="from" value="{{ optional($from)->toDateString() }}" max="{{ now()->toDateString() }}" class="input lg:w-44">
            </div>
            <div>
                <label for="to" class="label">To</label>
                <input id="to" type="date" name="to" value="{{ optional($to)->toDateString() }}" max="{{ now()->toDateString() }}" class="input lg:w-44">
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="submit" class="btn-primary">Filter</button>
            @if (request()->hasAny(['status', 'type', 'from', 'to']))
                <a href="{{ route('commissions.history') }}" class="btn-secondary">Reset</a>
            @endif
            <a href="{{ route('commissions.history', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn-secondary">Export CSV</a>
        </div>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Consultant</th>
                        <th class="px-6 py-4 font-semibold">Student</th>
                        <th class="px-6 py-4 font-semibold">Type</th>
                        <th class="px-6 py-4 font-semibold">Amount</th>
                        <th class="px-6 py-4 font-semibold">Status</th>
                        <th class="px-6 py-4 font-semibold">Date</th>
                        <th class="px-6 py-4 text-right font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($commissions as $c)
                        <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-4 font-semibold text-slate-900 dark:text-white">
                                {{ $c->consultant?->display_name ?? '—' }}
                                {{-- Who this income is actually FROM: for an upline
                                     credit, the downline consultant whose own
                                     registration climbed the sponsor chain to earn
                                     this row. Nothing shown for a consultant's own
                                     placement — there is no separate "source" to
                                     name, they earned it themselves. --}}
                                @if ($source = $c->sourceConsultant())
                                    <div class="mt-0.5 text-xs font-normal text-slate-500 dark:text-slate-400">
                                        from downline: <span class="font-mono">{{ $source->scn_code ?? $source->consultant_code }}</span> — {{ $source->display_name }}
                                    </div>
                                @endif
                                {{-- Income from the consultant buying a course through
                                     their own account, rather than from registering
                                     anybody. Same label as the Service Income board. --}}
                                @if ($c->isCoursePurchase())
                                    <div class="mt-0.5 text-xs font-normal text-sky-600 dark:text-sky-400">
                                        Opened by a course purchase on this account
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ $c->student->name ?? '—' }}</td>
                            <td class="px-6 py-4"><span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ ucfirst($c->type) }}</span></td>
                            <td class="px-6 py-4 font-bold text-slate-900 dark:text-white">LKR {{ number_format($c->amount) }}</td>
                            <td class="px-6 py-4">
                                <x-commission-status :status="$c->display_status" />
                                @if ($c->wasManuallyReleased())
                                    <span class="mt-0.5 block text-[11px] font-semibold text-amber-600 dark:text-amber-400" title="Released by hand with the Approve button, not by a System Run">Manual release</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ optional($c->incomeDate())->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-right">
                                @if ($c->held_at)
                                    <span class="text-xs text-slate-400">Held — resolves automatically</span>
                                @elseif ($c->status === 'pending')
                                    {{-- Always approvable by hand — see the matching block in
                                         dashboard/commissions/index.blade.php for why the old
                                         "Not yet balanced" dead end was removed. --}}
                                    @php $releasedHere = $c->type === 'referral' || ($c->is_released ?? false); @endphp
                                    @unless ($releasedHere)
                                        <span class="mr-2 text-[11px] font-semibold text-amber-600 dark:text-amber-400" title="Its service group has not balanced a stage yet (1-to-1 / 2-to-2 / 3-to-3)">Stage not balanced</span>
                                    @endunless
                                    <form method="POST" action="{{ route('commissions.approve', $c) }}" class="inline-block"
                                        @unless ($releasedHere) onsubmit="return confirm('A System Run has NOT released this yet.\n\nApproving LKR {{ number_format($c->amount) }} by hand skips the flush, so the service group balance is not reduced and this row stays marked Manual.\n\nApprove anyway?');" @endunless>
                                        @csrf @method('PATCH')<button class="btn-secondary py-1.5 text-xs">Approve</button>
                                    </form>
                                @elseif ($c->status === 'approved')
                                    <form method="POST" action="{{ route('commissions.paid', $c) }}">@csrf @method('PATCH')<button class="btn-primary py-1.5 text-xs">Mark paid</button></form>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">No service income found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-8">{{ $commissions->links() }}</div>
</x-layouts.app>
