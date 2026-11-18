<x-layouts.app title="System Run Rules" header="System Run Rules">
    <div class="mb-6 sm:mb-8">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">The rules the System Run obeys</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            These are the <strong>only</strong> rules a run follows. Change them here rather than asking for a code
            change — nothing else needs editing, and the run reads these values every time it is performed.
        </p>
    </div>

    @if (session('success'))
        <div class="card mb-6 border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    {{-- The ladder as it stands, computed from the saved values rather than
         written out — so this box can never describe a rule that is not in
         force, which is exactly how the run screen and the engine drifted. --}}
    <div class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">What each stage asks for right now</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                At LKR {{ number_format($rate) }} per student. An account settles <strong>one stage per run</strong>,
                then moves up.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[36rem] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-3 font-semibold">Stage</th>
                        <th class="px-5 py-3 font-semibold">Needed on EACH side</th>
                        <th class="px-5 py-3 font-semibold">Releases</th>
                        <th class="px-5 py-3 font-semibold">Surplus</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($ladder as $row)
                        <tr>
                            <td class="px-5 py-3 font-semibold text-slate-900 dark:text-white">{{ $row['label'] }}</td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">LKR {{ number_format($row['each_side']) }}</td>
                            <td class="px-5 py-3 text-emerald-600 dark:text-emerald-400">LKR {{ number_format($row['releases']) }}</td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                @if ($row['repeating'] && $values[\App\Services\SystemRunRules::REPEAT_FORFEITS_KEY])
                                    <span class="text-rose-600 dark:text-rose-400">goes to the company</span>
                                @else
                                    stays on the leg
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 p-5 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">
            <strong>Both sides must reach the amount.</strong> If either side is short, the account does not take part
            in the run at all and keeps everything it holds — no release, no surplus to the company, and its stage
            does not move.
        </div>
    </div>

    <form method="POST" action="{{ route('system-run.rules.update') }}" class="card mb-6 overflow-hidden">
        @csrf
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Change a rule</h3>
        </div>

        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($rules as $rule)
                <div class="p-5 sm:flex sm:items-start sm:gap-6">
                    <div class="sm:w-2/3">
                        <label for="{{ $rule['key'] }}" class="block font-semibold text-slate-900 dark:text-white">
                            {{ $rule['label'] }}
                        </label>
                        <p class="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{{ $rule['effect'] }}</p>
                    </div>
                    <div class="mt-3 sm:mt-0 sm:w-1/3">
                        @if ($rule['type'] === 'boolean')
                            {{-- The hidden field is what makes an unchecked box
                                 save as OFF rather than simply not being sent. --}}
                            <input type="hidden" name="{{ $rule['key'] }}" value="0">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" id="{{ $rule['key'] }}" name="{{ $rule['key'] }}" value="1"
                                    @checked($values[$rule['key']])
                                    class="h-4 w-4 rounded border-slate-300 text-blue-600">
                                <span class="text-sm text-slate-700 dark:text-slate-200">
                                    {{ $values[$rule['key']] ? 'On' : 'Off' }}
                                </span>
                            </label>
                        @else
                            <input type="number" min="1" step="1" id="{{ $rule['key'] }}" name="{{ $rule['key'] }}"
                                value="{{ $values[$rule['key']] }}" class="input w-full sm:w-32">
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="border-t border-slate-100 p-5 dark:border-slate-800">
            <button type="submit" class="btn-primary">Save the rules</button>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                Saving changes nothing that has already been settled. It only decides what the <strong>next</strong>
                run does.
            </p>
        </div>
    </form>

    {{-- ⚠️ The point of the page: see the effect on the REAL network before
         running anything. preview() writes nothing. --}}
    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">What a run would do right now, under these rules</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                A dry run against every live account. Nothing is settled and nothing is written — change a rule above,
                save, and this recalculates.
            </p>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Accounts that would settle</p>
                <p class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-white">{{ number_format($preview['count']) }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Released to consultants</p>
                <p class="mt-1 text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($preview['payout']) }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Goes to the company</p>
                <p class="mt-1 text-2xl font-extrabold text-rose-600 dark:text-rose-400">LKR {{ number_format($preview['forfeit']) }}</p>
                <p class="text-xs text-slate-400">cannot be recovered</p>
            </div>
        </div>

        @if (! empty($preview['accounts']))
            <div class="overflow-x-auto border-t border-slate-100 dark:border-slate-800">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Account</th>
                            <th class="px-5 py-3 font-semibold">Service Group 1</th>
                            <th class="px-5 py-3 font-semibold">Service Group 2</th>
                            <th class="px-5 py-3 font-semibold">Releases</th>
                            <th class="px-5 py-3 font-semibold">To company</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach (array_slice($preview['accounts'], 0, 50) as $a)
                            <tr>
                                <td class="px-5 py-3">
                                    <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $a['code'] }}</span>
                                    <span class="block text-xs text-slate-400">{{ $a['name'] }} · {{ $a['label'] }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">LKR {{ number_format($a['leg1']) }}</td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">LKR {{ number_format($a['leg2']) }}</td>
                                <td class="px-5 py-3 font-semibold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($a['payout']) }}</td>
                                <td class="px-5 py-3 {{ $a['forfeit'] > 0 ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-slate-400' }}">
                                    LKR {{ number_format($a['forfeit']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="border-t border-slate-100 p-5 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                No account is balanced on both sides right now, so a run would settle nothing.
            </div>
        @endif

        <div class="border-t border-slate-100 p-5 dark:border-slate-800">
            <a href="{{ route('system-run.index') }}" class="btn-secondary">Go to the System Run</a>
        </div>
    </div>
</x-layouts.app>
