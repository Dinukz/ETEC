<x-layouts.app title="SCN / SRN Numbers" header="SCN / SRN Numbers">
    <div class="mb-6 sm:mb-8">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Can each SCN number match its SRN number?</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            This page only counts. It changes nothing, and it is safe to open at any time.
        </p>
    </div>

    {{-- Why the answer is not simply "yes". Stated once, at the top, because
         every number below only makes sense against it. --}}
    <div class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Why this needs checking first</h3>
        </div>
        <div class="space-y-2 p-5 text-sm text-slate-600 dark:text-slate-300">
            <p>
                Hidden placement accounts take their numbers from the <strong>same pool</strong> as real
                consultants. One consultant who buys one course already uses SCN-00100 to SCN-00105 —
                and five of those six are hidden accounts.
            </p>
            <p>
                So a promoted student's SRN number is often already held by something else. The table
                below is the real count on this database, not an estimate.
            </p>
        </div>
    </div>

    @php
        $cards = [
            ['label' => 'Already match', 'value' => $totals['already_match'], 'note' => 'nothing to do', 'tone' => 'text-emerald-600 dark:text-emerald-400'],
            ['label' => 'Number is free', 'value' => $totals['free'], 'note' => 'could be changed safely', 'tone' => 'text-sky-600 dark:text-sky-400'],
            ['label' => 'Held by a hidden account', 'value' => $totals['taken_by_hidden'], 'note' => 'needs that account renumbered', 'tone' => 'text-amber-600 dark:text-amber-400'],
            ['label' => 'Held by another consultant', 'value' => $totals['taken_by_real'], 'note' => 'cannot be resolved by renumbering', 'tone' => 'text-rose-600 dark:text-rose-400'],
            ['label' => 'Never was a student', 'value' => $totals['no_srn'], 'note' => 'no SRN to match', 'tone' => 'text-slate-500 dark:text-slate-400'],
        ];
    @endphp

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
        @foreach ($cards as $card)
            <div class="card p-4">
                <p class="text-xs uppercase tracking-wide text-slate-400">{{ $card['label'] }}</p>
                <p class="mt-1 text-2xl font-extrabold {{ $card['tone'] }}">{{ number_format($card['value']) }}</p>
                <p class="mt-0.5 text-[11px] text-slate-400">{{ $card['note'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">
                Every consultant ({{ number_format($totals['total']) }})
            </h3>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Consultant</th>
                        <th class="px-6 py-4 font-semibold">Has SCN</th>
                        <th class="px-6 py-4 font-semibold">Student number</th>
                        <th class="px-6 py-4 font-semibold">Would want</th>
                        <th class="px-6 py-4 font-semibold">Result</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-6 py-3 text-slate-700 dark:text-slate-200">{{ $row['name'] }}</td>
                            <td class="px-6 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $row['scn'] ?? '—' }}</td>
                            <td class="px-6 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $row['srn'] ?? '—' }}</td>
                            <td class="px-6 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $row['wanted'] ?? '—' }}</td>
                            <td class="px-6 py-3">
                                @switch($row['status'])
                                    @case('already-match')
                                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Already matches</span>
                                        @break
                                    @case('free')
                                        <span class="text-xs font-medium text-sky-600 dark:text-sky-400">Free — could be changed</span>
                                        @break
                                    @case('taken-by-hidden')
                                        <span class="text-xs font-medium text-amber-600 dark:text-amber-400">Taken by {{ $row['held_by'] }}</span>
                                        @break
                                    @case('taken-by-real')
                                        <span class="text-xs font-medium text-rose-600 dark:text-rose-400">Taken by {{ $row['held_by'] }}</span>
                                        @break
                                    @default
                                        <span class="text-xs text-slate-400">Never was a student</span>
                                @endswitch
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-slate-400">No consultants yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">
        <a href="{{ route('commission-health.index') }}" class="text-sm font-medium text-sky-600 hover:underline dark:text-sky-400">
            &larr; Back to Commission Health
        </a>
    </div>
</x-layouts.app>
