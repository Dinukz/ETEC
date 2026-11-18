<x-layouts.app title="Accounts With No Money" header="Accounts With No Money">
    <div class="mb-6 sm:mb-8">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white sm:text-2xl">Which accounts could be removed?</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            The live site and your test accounts share one database, so nothing here guesses which is which.
            Every account is listed with <strong>what is attached to it</strong>. An account is only offered for
            deletion when it has no verified payment, no commission on any account it owns, and no student seated
            on its Service Groups. <strong>This page deletes nothing on its own.</strong>
        </p>
    </div>

    @if (session('success'))
        <div class="card mb-6 border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    {{-- The numbering, which is why anyone is on this page. --}}
    <div class="card mb-6 p-5">
        <h3 class="font-bold text-slate-900 dark:text-white">Numbering</h3>
        <div class="mt-3 grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Next number now</p>
                <p class="mt-1 font-mono text-xl font-extrabold text-slate-900 dark:text-white">
                    {{ \App\Services\NumberPool::format('SCN', $next_number) }}
                </p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">If everything below were deleted</p>
                <p class="mt-1 font-mono text-xl font-extrabold {{ $reclaimable < $next_number ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-900 dark:text-white' }}">
                    {{ \App\Services\NumberPool::format('SCN', $reclaimable) }}
                </p>
            </div>
            <div class="sm:text-right">
                <form method="POST" action="{{ route('users.recompute-numbering') }}"
                    onsubmit="return confirm('Recalculate the next number from the accounts that exist right now?');">
                    @csrf
                    <button type="submit" class="btn-secondary">Recalculate numbering</button>
                </form>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
            ⚠️ The counter only moves forward on its own, so deleting accounts does <strong>not</strong> lower it
            until you press Recalculate. Recalculating can never reuse a number that something still holds — it
            lands one above the highest that survives, which on a database with real consultants will not be 100.
        </p>
    </div>

    <div class="card mb-6 flex flex-wrap items-center justify-between gap-3 p-5">
        <div>
            <p class="font-bold text-slate-900 dark:text-white">
                {{ number_format($disposable->count()) }} with nothing attached
                <span class="font-normal text-slate-400">· {{ number_format($kept->count()) }} kept</span>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" id="bulk-clear" class="btn-secondary py-2 text-sm">Clear</button>
            <button type="submit" form="bulk-form" class="btn-primary bg-rose-600 py-2 text-sm hover:bg-rose-700">
                Delete selected (<span id="bulk-count">0</span>)
            </button>
        </div>
    </div>

    {{-- Posts to the ordinary bulk delete, which re-checks its own rules per
         user — you cannot remove yourself, only a super admin removes a super
         admin, the last super admin is protected, and a consultant's hidden
         placement accounts go with them. --}}
    <form id="bulk-form" method="POST" action="{{ route('users.bulk-destroy') }}"
        onsubmit="return confirm('Permanently delete ' + document.querySelectorAll('.row-check:checked').length + ' account(s)? This cannot be undone.');">
        @csrf @method('DELETE')
    </form>

    @if ($disposable->isEmpty())
        <div class="card mb-6 p-8 text-center text-sm text-slate-500 dark:text-slate-400">
            Every account has something attached to it. Nothing can be removed without losing real data.
        </div>
    @else
        <div class="card mb-6 overflow-hidden">
            <div class="border-b border-slate-100 p-5 dark:border-slate-800">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" id="select-all" class="h-4 w-4 rounded border-slate-300">
                    <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">Select all with nothing attached</span>
                </label>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="w-12 px-5 py-3"></th>
                            <th class="px-5 py-3 font-semibold">Account</th>
                            <th class="px-5 py-3 font-semibold">Role</th>
                            <th class="px-5 py-3 font-semibold">Number</th>
                            <th class="px-5 py-3 font-semibold">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($disposable as $row)
                            <tr>
                                <td class="px-5 py-3">
                                    <input type="checkbox" form="bulk-form" name="ids[]" value="{{ $row['id'] }}" class="row-check h-4 w-4 rounded border-slate-300">
                                </td>
                                <td class="px-5 py-3">
                                    <span class="block font-semibold text-slate-900 dark:text-white">{{ $row['name'] }}</span>
                                    <span class="block text-xs text-slate-400">{{ $row['email'] }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::headline($row['role'] ?? '—') }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $row['code'] ?? '—' }}</td>
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ $row['created_at']?->format('M d, Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ⚠️ The kept list is shown, not hidden. An account missing from the
         delete list should be visibly accounted for, or its absence reads as a
         bug and somebody goes looking for it in the database. --}}
    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Kept — and why</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                These cannot be offered for deletion. The reason is beside each one.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($kept as $row)
                        <tr>
                            <td class="px-5 py-3">
                                <span class="block font-semibold text-slate-900 dark:text-white">{{ $row['name'] }}</span>
                                <span class="block text-xs text-slate-400">{{ $row['email'] }}</span>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $row['code'] ?? '—' }}</td>
                            <td class="px-5 py-3 text-xs text-amber-600 dark:text-amber-400">{{ $row['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Same vanilla-JS selection as the user list; Alpine is not auto-injected
         in this build, so a page depending on it would do nothing live. --}}
    <script>
        (function () {
            var all = document.getElementById('select-all');
            var count = document.getElementById('bulk-count');
            var clear = document.getElementById('bulk-clear');

            function boxes() {
                return Array.prototype.slice.call(document.querySelectorAll('.row-check'));
            }

            function sync() {
                var checked = boxes().filter(function (b) { return b.checked; });

                count.textContent = checked.length;

                if (all) {
                    all.checked = checked.length > 0 && checked.length === boxes().length;
                    all.indeterminate = checked.length > 0 && checked.length < boxes().length;
                }
            }

            if (all) {
                all.addEventListener('change', function () {
                    boxes().forEach(function (b) { b.checked = all.checked; });
                    sync();
                });
            }

            if (clear) {
                clear.addEventListener('click', function () {
                    boxes().forEach(function (b) { b.checked = false; });
                    sync();
                });
            }

            document.addEventListener('change', function (e) {
                if (e.target && e.target.classList.contains('row-check')) {
                    sync();
                }
            });

            sync();
        })();
    </script>
</x-layouts.app>
