<x-layouts.app title="Book a Hall" header="Book a Hall">
    <div class="mb-6">
        <a href="{{ route('bookings.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to my bookings
        </a>
    </div>

    <div class="mx-auto max-w-2xl" x-data="hallAvailability()">
        <div class="card p-6 lg:p-8">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Book a hall</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Pick a date and time, check which halls are free, then confirm your booking.</p>

            @if ($errors->any())
                <div class="mt-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
                    <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('bookings.store') }}" class="mt-6 space-y-5">
                @csrf
                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <label for="booking_date" class="label">Date</label>
                        <input id="booking_date" name="booking_date" type="date" x-model="date" min="{{ today()->toDateString() }}" value="{{ old('booking_date') }}" required class="input">
                    </div>
                    <div>
                        <label for="start_time" class="label">Start time</label>
                        <input id="start_time" name="start_time" type="time" x-model="start" value="{{ old('start_time', '08:30') }}" required class="input">
                    </div>
                    <div>
                        <label for="end_time" class="label">End time</label>
                        <input id="end_time" name="end_time" type="time" x-model="end" value="{{ old('end_time', '16:30') }}" required class="input">
                    </div>
                </div>

                <div>
                    <button type="button" @click="check()" class="btn-secondary" :disabled="checking">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <span x-text="checking ? 'Checking…' : 'Check Available Halls'"></span>
                    </button>
                    <template x-if="checked && available.length === 0">
                        <p class="mt-2 text-sm font-medium text-rose-600 dark:text-rose-400">No halls are free for that time range.</p>
                    </template>
                    <template x-if="checked && available.length > 0">
                        <p class="mt-2 text-sm font-medium text-emerald-600 dark:text-emerald-400"><span x-text="available.length"></span> hall(s) available — highlighted below.</p>
                    </template>
                </div>

                <div>
                    <label for="hall_id" class="label">Hall</label>
                    <select id="hall_id" name="hall_id" required class="input">
                        <option value="">Select a hall</option>
                        @foreach ($halls as $hall)
                            <option value="{{ $hall->id }}" @selected(old('hall_id') == $hall->id)
                                :class="checked && !available.some(h => h.id === {{ $hall->id }}) ? 'text-slate-400' : ''">
                                {{ $hall->name }}{{ $hall->location ? ' - '.$hall->location : '' }}{{ $hall->capacity ? ' ('.$hall->capacity.' seats)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="reason" class="label">Reason <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="reason" name="reason" type="text" value="{{ old('reason') }}" maxlength="190" class="input" placeholder="e.g. All consultant meeting">
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <a href="{{ route('bookings.index') }}" class="btn-secondary">Cancel</a>
                    <button type="submit" class="btn-primary">Confirm Booking</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function hallAvailability() {
            return {
                date: @json(old('booking_date', today()->toDateString())),
                start: @json(old('start_time', '08:30')),
                end: @json(old('end_time', '16:30')),
                available: [],
                checked: false,
                checking: false,
                async check() {
                    if (!this.date || !this.start || !this.end) return;
                    this.checking = true;
                    try {
                        const params = new URLSearchParams({ booking_date: this.date, start_time: this.start, end_time: this.end });
                        const res = await fetch(`{{ route('bookings.availability') }}?${params}`, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        this.available = data.halls ?? [];
                        this.checked = true;
                    } finally {
                        this.checking = false;
                    }
                },
            };
        }
    </script>
</x-layouts.app>
