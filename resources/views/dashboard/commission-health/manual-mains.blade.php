<x-layouts.app title="Hand entries missing a main" header="Hand entries missing a main">
    <div class="mb-6">
        <a href="{{ route('commission-health.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to Commission Health
        </a>
    </div>

    <div class="mb-6">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Hand entries that stopped short of a main account</h2>
        <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
            Until 27 Sep, a hand-entered upline 2,500 skipped every main account on its way up — the
            person's own main and each sponsor's. It reached a Service Group account and went no further.
            New entries now reach the same accounts a verified payment would. Older entries are listed
            here with exactly the rows each one is missing. Nothing is added until you press the button.
        </p>
    </div>

    <x-flash />

    @php
        $rowCount = $entries->sum(fn ($e) => count($e['missing']));
        $total = $entries->sum(fn ($e) => count($e['missing']) * $e['amount']);
    @endphp

    @if ($entries->isEmpty())
        <div class="card p-8 text-center">
            <p class="font-semibold text-emerald-600 dark:text-emerald-400">Nothing is missing.</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Every hand-entered upline commission already reaches its main accounts.</p>
        </div>
    @else
        {{-- ⚠️ READ BEFORE PRESSING. If a missing main was already made up by
             hand as a separate entry, that entry cannot be connected to this
             one, and pressing would pay it a second time. --}}
        <div class="card mb-5 border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/10">
            <p class="font-semibold text-amber-900 dark:text-amber-200">
                {{ number_format($rowCount) }} {{ \Illuminate\Support\Str::plural('row', $rowCount) }} · LKR {{ number_format($total, 2) }} will be added
            </p>
            <p class="mt-1 text-sm text-amber-900 dark:text-amber-200">
                Check the accounts below first. If you already added any of these 2,500s by hand in a separate entry,
                remove that one first — it cannot be matched to this list, and pressing would pay it twice.
            </p>
            <form method="POST" action="{{ route('commission-health.repair-manual-mains') }}" class="mt-4"
                onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Adding…';">
                @csrf
                <button type="submit" class="btn-primary">Add these {{ number_format($rowCount) }} {{ \Illuminate\Support\Str::plural('row', $rowCount) }}</button>
            </form>
        </div>

        <div class="space-y-4">
            @foreach ($entries as $entry)
                <div class="card p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $entry['student'] }}</p>
                        <p class="text-xs text-slate-400">Entered {{ $entry['when'] }} on <span class="font-mono">{{ $entry['bottom'] }}</span> · LKR {{ number_format($entry['amount']) }} each</p>
                    </div>
                    <ul class="mt-3 space-y-1 text-sm">
                        @foreach ($entry['missing'] as $gap)
                            <li class="text-slate-600 dark:text-slate-300">
                                + <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $gap['code'] }}</span>
                                <span class="text-slate-400">· Service Group {{ $gap['leg'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Never touched — stated, so it cannot read as "checked and fine". --}}
    @if ($singles > 0)
        <p class="mt-6 text-xs text-slate-400">
            {{ number_format($singles) }} single-row {{ \Illuminate\Support\Str::plural('entry', $singles) }} left alone: an entry with one row may have been
            the "this account only" option on purpose, so this page never adds to it.
        </p>
    @endif
</x-layouts.app>
