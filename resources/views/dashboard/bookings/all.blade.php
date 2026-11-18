<x-layouts.app title="All Bookings" header="Active Bookings">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4 sm:mb-8">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Active Bookings</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">All active hall bookings — pick a hall and month, then tap a day to see its bookings.</p>
        </div>
        <a href="{{ route('bookings.create') }}" class="btn-primary">Book a Hall</a>
    </div>

    <form method="GET" action="{{ route('bookings.all') }}" class="card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
        <select name="hall_id" class="input sm:w-64" onchange="this.form.submit()">
            @foreach ($halls as $hall)
                <option value="{{ $hall->id }}" @selected($hallId == $hall->id)>{{ $hall->name }}{{ $hall->location ? ' - '.$hall->location : '' }}</option>
            @endforeach
        </select>
        <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="input sm:w-48" onchange="this.form.submit()">
        <button type="submit" class="btn-secondary">View</button>
    </form>

    @php
        $start = $month->copy()->startOfMonth();
        $gridStart = $start->copy()->startOfWeek(\Carbon\Carbon::SUNDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SATURDAY);
        $days = [];
        for ($d = $gridStart->copy(); $d->lte($gridEnd); $d->addDay()) { $days[] = $d->copy(); }

        $isAdmin = auth()->user()->isAdmin();
        $myId = auth()->id();

        // Every booking in the month, keyed by date, with everything the
        // click-to-view modal needs — including whether THIS viewer may cancel it.
        $byDay = collect($days)->mapWithKeys(function ($day) use ($bookings, $isAdmin, $myId) {
            $key = $day->format('Y-m-d');
            $dayBookings = ($bookings[$key] ?? collect())->map(fn ($b) => [
                'id' => $b->id,
                'time' => substr($b->start_time, 0, 5).' – '.substr($b->end_time, 0, 5),
                'hall' => $b->hall->name ?? '—',
                'reason' => $b->reason ?: '—',
                'by' => $b->user->name ?? 'Unknown',
                'can_cancel' => $isAdmin || $b->user_id === $myId,
                'cancel_url' => route('bookings.cancel', $b->id),
            ])->values();

            return [$key => $dayBookings];
        });
    @endphp

    {{-- The calendar and its day modal must share ONE Alpine scope. The modal is
         a full-screen fixed overlay, so if `open` is out of scope its x-show can
         never resolve — Alpine still strips x-cloak, leaving the overlay stuck on
         top of the page swallowing every click. --}}
    <div x-data="{
        open: false,
        day: null,
        bookings: {},
        list() { return this.bookings[this.day] ?? []; },
        show(day) { this.day = day; this.open = true; },
    }" x-init="bookings = @js($byDay)">
    <div class="card overflow-hidden">
        <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
            @foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $dow)
                <div class="px-1 py-2 sm:px-2 sm:py-3">{{ $dow }}</div>
            @endforeach
        </div>
        <div class="grid grid-cols-7">
            @foreach ($days as $day)
                @php
                    $inMonth = $day->month === $month->month;
                    $dayKey = $day->format('Y-m-d');
                    $dayBookings = $bookings[$dayKey] ?? collect();
                @endphp
                <button type="button" @click="show('{{ $dayKey }}')"
                    class="min-h-20 border-b border-r border-slate-100 p-1 text-left transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40 sm:min-h-24 sm:p-1.5 {{ $inMonth ? '' : 'bg-slate-50/60 dark:bg-slate-900/40' }} {{ $day->isToday() ? 'bg-brand-50/60 dark:bg-brand-500/10' : '' }}">
                    <div class="text-right text-xs font-semibold {{ $inMonth ? ($day->isToday() ? 'text-brand-600 dark:text-brand-400' : 'text-slate-600 dark:text-slate-300') : 'text-slate-300 dark:text-slate-600' }}">{{ $day->day }}</div>
                    <div class="mt-1 space-y-1">
                        @foreach ($dayBookings->take(2) as $b)
                            <div class="flex items-start gap-1 truncate rounded px-1 py-0.5 text-[10px] leading-tight text-slate-600 dark:text-slate-300">
                                <span class="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500"></span>
                                <span class="truncate">{{ substr($b->start_time, 0, 5) }} {{ $b->reason ?: ($b->user->name ?? 'Booked') }}</span>
                            </div>
                        @endforeach
                        @if ($dayBookings->count() > 2)
                            <div class="px-1 text-[10px] font-medium text-slate-400">+{{ $dayBookings->count() - 2 }} more</div>
                        @endif
                    </div>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Day detail modal --}}
    <div x-show="open" x-cloak
        class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4"
        @click.self="open = false" @keydown.escape.window="open = false">
        <div class="max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white" x-text="day ? new Date(day + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) : ''"></h3>
                <button type="button" @click="open = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <template x-if="list().length === 0">
                <p class="mt-6 text-center text-sm text-slate-400">No bookings on this day.</p>
            </template>

            <div class="mt-4 space-y-3">
                <template x-for="b in list()" :key="b.id">
                    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                        <div class="flex items-center justify-between">
                            <span class="font-mono text-sm font-semibold text-slate-900 dark:text-white" x-text="b.time"></span>
                            <span class="text-xs text-slate-400" x-text="b.hall"></span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300" x-text="b.reason"></p>
                        <p class="mt-1 text-xs text-slate-400">Booked by <span x-text="b.by"></span></p>
                        <template x-if="b.can_cancel">
                            <form :action="b.cancel_url" method="POST" class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800" @submit="if (!confirm('Cancel this booking?')) $event.preventDefault()">
                                @csrf @method('PATCH')
                                <button type="submit" class="text-xs font-semibold text-rose-600 hover:underline dark:text-rose-400">Cancel booking</button>
                            </form>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>
    </div>
</x-layouts.app>
