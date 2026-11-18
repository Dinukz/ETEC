<x-layouts.app title="Broken uplines" header="Broken uplines">
    <div class="mb-6">
        <a href="{{ route('commission-health.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to Commission Health
        </a>
    </div>

    <div class="mb-6">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Consultants with no upline</h2>
        <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
            Deleting a consultant used to empty the upline of everyone directly below them, with no
            error shown anywhere. Their whole branch then stopped appearing in the sponsor's network,
            and no income could climb past the gap. New deletions now move the branch up automatically.
            Anything already broken is listed here.
        </p>
    </div>

    <x-flash />

    @if ($orphans->where('lost_an_upline', true)->isEmpty())
        <div class="card p-8 text-center">
            <p class="font-semibold text-emerald-600 dark:text-emerald-400">No broken branches.</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Every consultant below the top of the network has an upline.</p>
        </div>
    @endif

    <div class="space-y-5">
        @foreach ($orphans as $orphan)
            {{-- The network's own root legitimately has no sponsor, so it is
                 shown for completeness but never offered a Reconnect button. --}}
            @php $isRoot = ! $orphan['lost_an_upline']; @endphp

            <div class="card p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-lg font-bold text-slate-900 dark:text-white">{{ $orphan['code'] }}</span>
                            @if ($isRoot)
                                <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Top of the network</span>
                            @else
                                <span class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Upline lost</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $orphan['name'] }}</p>
                        @if ($orphan['email'])
                            <p class="text-xs text-slate-400">{{ $orphan['email'] }}</p>
                        @endif
                    </div>

                    <div class="text-right text-sm">
                        <p class="text-slate-500 dark:text-slate-400">People below them</p>
                        <p class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ number_format($orphan['downline_size']) }}</p>
                    </div>
                </div>

                @unless ($isRoot)
                    {{-- What the audit log remembers. The rows themselves are
                         gone, but their creation record kept the links the
                         foreign key erased, so the original chain is readable. --}}
                    @if ($orphan['path'])
                        <div class="mt-5 rounded-xl bg-slate-50 p-4 dark:bg-slate-800/50">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">What the history shows</p>
                            <ol class="mt-2 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                                @foreach ($orphan['path'] as $step)
                                    <li class="flex gap-2">
                                        <span class="text-slate-400">{{ $loop->iteration }}.</span>
                                        <span>{{ $step }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('commission-health.reconnect-upline') }}" class="mt-5 flex flex-wrap items-end gap-3">
                        @csrf
                        <input type="hidden" name="consultant_id" value="{{ $orphan['id'] }}">

                        <div>
                            <label for="sponsor-{{ $orphan['id'] }}" class="label">Put them under</label>
                            <input id="sponsor-{{ $orphan['id'] }}" name="sponsor_id" type="number" required
                                value="{{ $orphan['suggested_id'] }}"
                                class="input w-44" placeholder="Account id">
                        </div>

                        <button type="submit" class="btn-primary">Reconnect</button>

                        @if ($orphan['suggested_code'])
                            <p class="text-sm text-emerald-700 dark:text-emerald-400">
                                Suggested: <span class="font-mono font-semibold">{{ $orphan['suggested_code'] }}</span>
                                — the nearest account above them that still exists.
                            </p>
                        @else
                            <p class="text-sm text-amber-700 dark:text-amber-400">
                                No suggestion. Everything above them was deleted too, so somebody has to decide where this branch belongs.
                            </p>
                        @endif
                    </form>

                    {{-- ⚠️ Says the quiet part. Reconnecting really does move no money by
                         itself, but the upline walk reads the tree live, so the next
                         Repair credits everything this branch could not reach while it
                         was cut. Told here rather than discovered later. --}}
                    <p class="mt-3 text-xs text-slate-400">
                        Reconnecting changes who sits under whom. It does not create, delete or recalculate any
                        income by itself. Afterwards, Commission Health then Run Repair will credit the uplines
                        this branch never reached while it was cut — that is real money, paid for the first time,
                        not a second payment. Free-card registrations pay nobody and are unaffected.
                    </p>
                @endunless
            </div>
        @endforeach
    </div>
</x-layouts.app>
