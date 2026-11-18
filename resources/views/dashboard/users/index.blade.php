<x-layouts.app title="User Management" header="User Management">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Users</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Manage accounts, roles, and access across the platform.</p>
        </div>
        @role('super_admin')
            <a href="{{ route('users.cleanup') }}" class="btn-secondary">Accounts with no money</a>
        @endrole
        {{-- ⚠️ 2026-11-10: mail to these can only bounce, and the bounces got the
             site's mailbox suspended. Correct each one, then re-send. --}}
        @if ($mistypedEmails > 0)
            <a href="{{ route('users.index', ['email' => 'mistyped']) }}" class="btn-secondary !text-rose-600 dark:!text-rose-400">Mistyped emails ({{ number_format($mistypedEmails) }})</a>
        @endif
        <a href="{{ route('users.create') }}" class="btn-primary">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            New User
        </a>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('users.index') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search by name, email, SCN or SRN..." class="input pl-11">
        </div>
        <select name="role" class="input lg:w-44" onchange="this.form.submit()">
            <option value="">All roles</option>
            @foreach ($roles as $r)
                <option value="{{ $r }}" @selected(request('role') === $r)>{{ \Illuminate\Support\Str::headline($r) }}</option>
            @endforeach
        </select>
        <select name="status" class="input lg:w-40" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['active', 'inactive', 'suspended'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <select name="email" class="input lg:w-44" onchange="this.form.submit()">
            <option value="">All emails</option>
            <option value="mistyped" @selected(request('email') === 'mistyped')>Mistyped emails</option>
        </select>
        {{-- Kept inside the filter form so changing the page size does not
             throw away the search and role filters already applied. --}}
        <select name="per_page" class="input lg:w-36" onchange="this.form.submit()" title="Rows per page">
            @foreach ($perPageOptions as $option)
                <option value="{{ $option }}" @selected($perPage === $option)>Show {{ number_format($option) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    {{-- ⚠️ THE FORM IS CLOSED IMMEDIATELY and the checkboxes join it by
         form="bulk-form" instead of by being inside it. Wrapping the table
         would nest each row's own delete form inside this one, which the HTML
         parser drops — the per-row Delete buttons would silently stop working.
         The bar appears only once something is selected; a permanently visible
         "Delete selected" beside a select-all is too easy to hit by accident. --}}
    <form id="bulk-form" method="POST" action="{{ route('users.bulk-destroy') }}"
        onsubmit="return confirm('Permanently delete ' + document.querySelectorAll('.row-check:checked').length + ' user(s)? This cannot be undone.');">
        @csrf @method('DELETE')
    </form>

    <div id="bulk-bar" hidden class="card mb-4 flex flex-wrap items-center justify-between gap-3 border-rose-200 bg-rose-50 p-4 dark:border-rose-500/30 dark:bg-rose-500/10">
        <p class="text-sm font-semibold text-rose-700 dark:text-rose-300">
            <span id="bulk-count">0</span> selected
        </p>
        <div class="flex items-center gap-2">
            <button type="button" id="bulk-clear" class="btn-secondary py-2 text-sm">Clear</button>
            <button type="submit" form="bulk-form" class="btn-primary bg-rose-600 py-2 text-sm hover:bg-rose-700">Delete selected</button>
        </div>
    </div>

    {{-- Table (desktop) --}}
    <div class="card hidden overflow-hidden lg:block">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                <tr>
                    <th class="w-12 px-6 py-4">
                        <input type="checkbox" id="select-all" class="h-4 w-4 rounded border-slate-300" title="Select all on this page">
                    </th>
                    <th class="px-6 py-4 font-semibold">User</th>
                    <th class="px-6 py-4 font-semibold">SCN / SRN</th>
                    <th class="px-6 py-4 font-semibold">Role</th>
                    <th class="px-6 py-4 font-semibold">Status</th>
                    <th class="px-6 py-4 font-semibold">Joined</th>
                    <th class="px-6 py-4 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($users as $u)
                    <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                        <td class="px-6 py-4">
                            @if ($u->id !== auth()->id())
                                <input type="checkbox" form="bulk-form" name="ids[]" value="{{ $u->id }}" class="row-check h-4 w-4 rounded border-slate-300">
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                {{-- ⚠️ THE PHOTO OPENS WHERE IT IS SHOWN (2026-09-23).
                                     *"userge athulat yann nathuw eliye idnm profile photo
                                     eka click krla view krnn puluwn widiyt hadnn"* — until
                                     now the only way to see somebody's photo full size was
                                     to open their page. The button is rendered only when
                                     there IS a photo; an initial circle has nothing to
                                     open, and a dead-looking button would read as broken. --}}
                                @if ($u->avatar)
                                    <button type="button" class="shrink-0 cursor-zoom-in rounded-full"
                                        data-photo="{{ route('media.show', $u->avatar) }}" data-photo-name="{{ $u->name }}"
                                        title="View {{ $u->name }}'s photo">
                                        <x-user-avatar :user="$u" size="h-10 w-10" text="text-sm" />
                                    </button>
                                @else
                                    <x-user-avatar :user="$u" size="h-10 w-10" text="text-sm" />
                                @endif
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-slate-900 dark:text-white">{{ $u->name }}</div>
                                    <div class="truncate text-xs text-slate-400">{{ $u->email }}</div>
                                    @include('dashboard.users.partials.mistyped-email', ['email' => $u->email])
                                </div>
                            </div>
                        </td>
                        {{-- Follows the CURRENT role, so it changes with the role picker. --}}
                        <td class="px-6 py-4 font-mono text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $u->roleCode() ?? '—' }}</td>
                        <td class="px-6 py-4">
                            @include('dashboard.users.partials.role-form', ['u' => $u, 'assignableRoles' => $assignableRoles])
                        </td>
                        <td class="px-6 py-4">
                            <x-user-status :status="$u->status" />
                        </td>
                        <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ $u->created_at->format('M d, Y') }}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-end gap-2">
                                {{-- Asked for 2026-09-14: the Direct Registrations Info popup,
                                     so a user can be checked without opening their page. Only
                                     an id is dispatched; the one UserAccountSummary mounted at
                                     the bottom of this page does the loading. --}}
                                <button type="button" onclick="Livewire.dispatch('show-user-summary', { userId: {{ $u->id }} })"
                                    class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" title="Info">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </button>
                                <a href="{{ route('users.edit', $u) }}" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" title="Edit">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>
                                @if ($u->id !== auth()->id())
                                    <form method="POST" action="{{ route('users.destroy', $u) }}"
                                        onsubmit="if (!confirm('Delete {{ $u->name }}? This cannot be undone.')) return false; this.querySelector('button').disabled = true;">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="grid h-9 w-9 place-items-center rounded-lg border border-rose-200 text-rose-500 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-500/30 dark:hover:bg-rose-500/10" title="Delete">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">No users match your filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Cards (mobile) --}}
    <div class="space-y-4 lg:hidden">
        @forelse ($users as $u)
            <div class="card p-5">
                <div class="flex items-center gap-3">
                    @if ($u->id !== auth()->id())
                        <input type="checkbox" form="bulk-form" name="ids[]" value="{{ $u->id }}" class="row-check h-4 w-4 shrink-0 rounded border-slate-300">
                    @endif
                    {{-- Same as the table above — see the note there. --}}
                    @if ($u->avatar)
                        <button type="button" class="shrink-0 cursor-zoom-in rounded-full"
                            data-photo="{{ route('media.show', $u->avatar) }}" data-photo-name="{{ $u->name }}">
                            <x-user-avatar :user="$u" size="h-11 w-11" text="text-base" />
                        </button>
                    @else
                        <x-user-avatar :user="$u" size="h-11 w-11" text="text-base" />
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-semibold text-slate-900 dark:text-white">{{ $u->name }}</div>
                        <div class="truncate text-xs text-slate-400">{{ $u->email }}</div>
                        @include('dashboard.users.partials.mistyped-email', ['email' => $u->email])
                        @if ($u->roleCode())
                            <div class="font-mono text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $u->roleCode() }}</div>
                        @endif
                    </div>
                    <x-user-status :status="$u->status" />
                </div>
                <div class="mt-4">
                    @include('dashboard.users.partials.role-form', ['u' => $u, 'assignableRoles' => $assignableRoles])
                </div>
                <div class="mt-4 flex gap-2">
                    <button type="button" onclick="Livewire.dispatch('show-user-summary', { userId: {{ $u->id }} })" class="btn-secondary flex-1 py-2 text-sm">Info</button>
                    <a href="{{ route('users.edit', $u) }}" class="btn-secondary flex-1 py-2 text-sm">Edit</a>
                    @if ($u->id !== auth()->id())
                        <form method="POST" action="{{ route('users.destroy', $u) }}"
                            onsubmit="if (!confirm('Delete {{ $u->name }}?')) return false; this.querySelector('button').disabled = true;" class="flex-1">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-secondary w-full py-2 text-sm !text-rose-600 disabled:cursor-not-allowed disabled:opacity-50 dark:!text-rose-400">Delete</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <div class="card p-12 text-center text-slate-500 dark:text-slate-400">No users match your filters.</div>
        @endforelse
    </div>

    <div class="mt-8">{{ $users->links() }}</div>

    @livewire('user-account-summary')

    {{-- The photo viewer. One overlay for the whole page, filled by whichever
         avatar was pressed — not one per row, which is what made the other
         popups on this project expensive. --}}
    <div id="photo-viewer" hidden class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-4 shadow-2xl dark:bg-slate-900">
            <div class="mb-3 flex items-center justify-between gap-4">
                <p id="photo-viewer-name" class="truncate font-semibold text-slate-900 dark:text-white"></p>
                <button type="button" id="photo-viewer-close" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    <span class="sr-only">Close</span>
                </button>
            </div>
            <img id="photo-viewer-image" src="" alt="" class="max-h-[80vh] w-full rounded-xl object-contain">
        </div>
    </div>

    {{-- Select-all + the count. Vanilla JS on purpose: Alpine is not
         auto-injected in this build (Livewire v4 is bundled manually), so a
         page that quietly depends on it would work locally and do nothing on
         the live site. --}}
    <script>
        (function () {
            var all = document.getElementById('select-all');
            var bar = document.getElementById('bulk-bar');
            var count = document.getElementById('bulk-count');
            var clear = document.getElementById('bulk-clear');

            function boxes() {
                return Array.prototype.slice.call(document.querySelectorAll('.row-check'));
            }

            function sync() {
                var checked = boxes().filter(function (b) { return b.checked; });

                count.textContent = checked.length;
                bar.hidden = checked.length === 0;

                if (all) {
                    all.checked = checked.length > 0 && checked.length === boxes().length;
                    // Some selected but not all — the third state, so the header
                    // box never claims the whole page is ticked when it is not.
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

        // The profile-photo viewer. Delegated from the document, so it keeps
        // working after Livewire swaps part of the page in — and vanilla for
        // the same reason as the block above: Alpine is not auto-injected here.
        (function () {
            var box = document.getElementById('photo-viewer');
            var img = document.getElementById('photo-viewer-image');
            var name = document.getElementById('photo-viewer-name');

            if (!box) {
                return;
            }

            function close() {
                box.hidden = true;
                // Cleared on close so the previous person's face never flashes
                // up for a moment behind the next one while it loads.
                img.src = '';
            }

            document.addEventListener('click', function (e) {
                var trigger = e.target.closest ? e.target.closest('[data-photo]') : null;

                if (trigger) {
                    img.src = trigger.getAttribute('data-photo');
                    img.alt = trigger.getAttribute('data-photo-name') || '';
                    name.textContent = trigger.getAttribute('data-photo-name') || '';
                    box.hidden = false;

                    return;
                }

                // Anywhere outside the card closes it, including the X button.
                if (!box.hidden && (e.target === box || (e.target.closest && e.target.closest('#photo-viewer-close')))) {
                    close();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    close();
                }
            });
        })();
    </script>
</x-layouts.app>
