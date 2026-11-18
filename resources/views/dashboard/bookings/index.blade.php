<x-layouts.app title="My Bookings" header="My Bookings">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">My Bookings</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Book a hall for lectures, meetings, or conferences.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('bookings.all') }}" class="btn-secondary">All Bookings</a>
            <a href="{{ route('bookings.create') }}" class="btn-primary">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Book a Hall
            </a>
        </div>
    </div>

    <form method="GET" action="{{ route('bookings.index') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
        <select name="hall_id" class="input lg:w-56" onchange="this.form.submit()">
            <option value="">All halls</option>
            @foreach ($halls as $hall)
                <option value="{{ $hall->id }}" @selected(request('hall_id') == $hall->id)>{{ $hall->name }}</option>
            @endforeach
        </select>
        <input type="date" name="date" value="{{ request('date') }}" class="input lg:w-44" onchange="this.form.submit()">
        <select name="status" class="input lg:w-40" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['approved', 'cancelled'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    @if ($bookings->isEmpty())
        <div class="card p-12 text-center">
            <h3 class="font-bold text-slate-900 dark:text-white">No bookings yet</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Book a hall to see it listed here.</p>
            <a href="{{ route('bookings.create') }}" class="btn-primary mt-6">Book a Hall</a>
        </div>
    @else
        {{-- Table (desktop) --}}
        <div class="card hidden overflow-hidden lg:block">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Hall</th>
                        <th class="px-6 py-4 font-semibold">Date</th>
                        <th class="px-6 py-4 font-semibold">Time</th>
                        <th class="px-6 py-4 font-semibold">Reason</th>
                        <th class="px-6 py-4 font-semibold">Status</th>
                        <th class="px-6 py-4 text-right font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($bookings as $b)
                        <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-4 font-semibold text-slate-900 dark:text-white">{{ $b->hall->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ $b->booking_date->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ substr($b->start_time, 0, 5) }} – {{ substr($b->end_time, 0, 5) }}</td>
                            <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($b->reason, 40) ?: '—' }}</td>
                            <td class="px-6 py-4">
                                @php $bs = ['approved' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'pending' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'cancelled' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$b->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                                <span class="badge {{ $bs }}">{{ ucfirst($b->status) }}</span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                @if ($b->status !== 'cancelled' && $b->booking_date->gte(today()))
                                    <form method="POST" action="{{ route('bookings.cancel', $b) }}" onsubmit="return confirm('Cancel this booking?')">
                                        @csrf @method('PATCH')
                                        <button class="badge bg-rose-50 text-rose-600 transition hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-400">Cancel</button>
                                    </form>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Cards (mobile) --}}
        <div class="space-y-4 lg:hidden">
            @foreach ($bookings as $b)
                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <span class="font-semibold text-slate-900 dark:text-white">{{ $b->hall->name ?? '—' }}</span>
                        @php $bs = ['approved' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'pending' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'cancelled' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$b->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                        <span class="badge {{ $bs }}">{{ ucfirst($b->status) }}</span>
                    </div>
                    <div class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $b->booking_date->format('M d, Y') }} · {{ substr($b->start_time, 0, 5) }} – {{ substr($b->end_time, 0, 5) }}</div>
                    @if ($b->reason)<div class="mt-1 text-xs text-slate-400">{{ $b->reason }}</div>@endif
                    @if ($b->status !== 'cancelled' && $b->booking_date->gte(today()))
                        <form method="POST" action="{{ route('bookings.cancel', $b) }}" class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800" onsubmit="return confirm('Cancel this booking?')">
                            @csrf @method('PATCH')
                            <button class="text-sm font-medium text-rose-600 dark:text-rose-400">Cancel booking</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-8">{{ $bookings->links() }}</div>
    @endif
</x-layouts.app>
