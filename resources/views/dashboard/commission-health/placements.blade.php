<x-layouts.app title="Account Placements" header="Account Placements">
    <div class="mb-6 sm:mb-8">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Where each bought account sits</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            A set opened by a course purchase sits under whichever account was chosen at checkout. When nothing was
            chosen, it has no account above it — so the upline LKR 2,500 has nowhere to climb and never reaches the
            account it should. This page finds those and lets you point them at the right one.
        </p>
    </div>

    @if (session('success'))
        <div class="card mb-6 border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="card mb-6 border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
            {{ session('error') }}
        </div>
    @endif

    @php $broken = collect($rows)->where('broken', true); @endphp

    <div class="card mb-6 p-5 {{ $broken->isEmpty() ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10' : 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' }}">
        @if ($broken->isEmpty())
            <h3 class="font-bold text-emerald-700 dark:text-emerald-400">Every bought account has an account above it</h3>
            <p class="mt-1 text-sm text-emerald-700/90 dark:text-emerald-300/90">
                Nothing here is stopping an upline 2,500 from climbing.
            </p>
        @else
            <h3 class="font-bold text-amber-700 dark:text-amber-400">
                {{ $broken->count() }} account(s) have nowhere for their upline 2,500 to climb
            </h3>
            <p class="mt-1 text-sm text-amber-700/90 dark:text-amber-300/90">
                Each one is listed below with the accounts it could sit under. Pick the one the course was really
                bought against.
            </p>
        @endif
    </div>

    @if (! empty($rows))
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Account</th>
                            <th class="px-5 py-3 font-semibold">Consultant</th>
                            <th class="px-5 py-3 font-semibold">Sits under</th>
                            <th class="px-5 py-3 font-semibold">Move it</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-5 py-3">
                                    <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $row['code'] }}</span>
                                    @if ($row['corrected'])
                                        <span class="block text-xs text-blue-600 dark:text-blue-400">placement corrected by hand</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                    {{ $row['owner'] }}
                                    <span class="block font-mono text-xs text-slate-400">{{ $row['owner_code'] }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    @if ($row['broken'])
                                        <span class="font-semibold text-amber-600 dark:text-amber-400">
                                            {{ $row['kind'] === 'unknown' ? 'nothing recorded' : ($row['under_code'] ?? 'the main account') }}
                                        </span>
                                        <span class="block text-xs text-slate-400">the climb stops here</span>
                                    @else
                                        <span class="font-mono text-slate-600 dark:text-slate-300">{{ $row['under_code'] }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <form method="POST" action="{{ route('commission-health.repoint') }}" class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="consultant_id" value="{{ $row['id'] }}">
                                        <select name="under_id" class="input w-36 text-xs">
                                            @foreach ($row['options'] as $id => $code)
                                                <option value="{{ $id }}">{{ $code }}</option>
                                            @endforeach
                                        </select>
                                        <select name="tier" class="input w-28 text-xs">
                                            <option value="1">Group 1</option>
                                            <option value="2">Group 2</option>
                                        </select>
                                        <button type="submit" class="btn-secondary text-xs">Move</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 p-5 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                Moving an account changes only where it sits. It does not touch the payment, who earned it, or any
                money already settled — after moving, use <strong>Replay missing income</strong> on Commission Health
                so the 2,500s that never climbed are recorded.
            </div>
        </div>
    @endif
</x-layouts.app>
