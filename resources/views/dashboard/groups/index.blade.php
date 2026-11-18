<x-layouts.app title="Service Groups" header="Service Group Management">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Service Groups</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Service Groups are auto-created and auto-filled as consultants refer students.</p>
    </div>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Total Service Groups" :value="number_format($stats['total'])" color="brand" icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z" />
        <x-stat-card label="Open" :value="number_format($stats['open'])" color="emerald" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Completed" :value="number_format($stats['completed'])" color="violet" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-stat-card label="Closed" :value="number_format($stats['closed'])" color="amber" icon="M5 13l4 4L19 7" />
    </div>

    {{-- Per-consultant Service Group (SCN) report --}}
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 p-6 dark:border-slate-800">
            <h3 class="font-bold text-slate-900 dark:text-white">Consultant Service Groups</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Each SCN account holds its own Service Group 1 and Service Group 2, each earning
                LKR {{ number_format($settings->commission_per_student) }} per student on its first
                {{ $settings->first_tier_students }} students. The Direct Add Bonus is listed apart — it is
                paid to whoever registered the student and is never service-group income.
            </p>

            {{-- ⚠️ SEARCH + PAGINATION (2026-09-15). This table used to render
                 every SCN account on the site at once, which is what made the
                 page crawl. Its keys are 'sq'/'sp' so they never collide with
                 the service-group list lower down, which owns 'q'/'page'. --}}
            <form method="GET" action="{{ route('groups.index') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="relative flex-1">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="search" name="sq" value="{{ $scnSearch }}" placeholder="Search by SCN code or consultant name..." class="input pl-11">
                </div>
                <button type="submit" class="btn-secondary">Search</button>
                @if ($scnSearch !== '')
                    <a href="{{ route('groups.index') }}" class="btn-secondary text-center">Clear</a>
                @endif
            </form>
            <p class="mt-2 text-xs text-slate-400">
                Showing {{ number_format($consultantTiers->count()) }} of {{ number_format($consultantTiers->total()) }} accounts.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-3 font-semibold">#</th>
                        <th class="px-6 py-3 font-semibold">Group Code</th>
                        <th class="px-6 py-3 font-semibold">Consultant</th>
                        <th class="px-6 py-3 font-semibold">Service Group 1</th>
                        <th class="px-6 py-3 font-semibold">Service Group 2</th>
                        <th class="px-6 py-3 font-semibold">Direct Add Bonus</th>
                        <th class="px-6 py-3 font-semibold">Total</th>
                        <th class="px-6 py-3 font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($consultantTiers as $i => $c)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-3 text-slate-500 dark:text-slate-400">{{ $consultantTiers->firstItem() + $i }}</td>
                            <td class="px-6 py-3 font-mono font-semibold text-slate-900 dark:text-white">{{ $c->scn_code }}</td>
                            <td class="px-6 py-3 text-slate-600 dark:text-slate-300">{{ $c->display_name }}</td>
                            <td class="px-6 py-3 font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($c->tier1_total ?? 0) }}</td>
                            <td class="px-6 py-3 font-semibold text-brand-600 dark:text-brand-400">{{ number_format($c->tier2_total ?? 0) }}</td>
                            <td class="px-6 py-3 font-semibold text-amber-600 dark:text-amber-400">{{ number_format($c->direct_total ?? 0) }}</td>
                            <td class="px-6 py-3 font-bold text-slate-900 dark:text-white">
                                {{ number_format(($c->tier1_total ?? 0) + ($c->tier2_total ?? 0) + ($c->direct_total ?? 0)) }}
                            </td>
                            <td class="px-6 py-3">
                                @unless ($c->is_hidden)
                                    {{-- Gives this consultant a fresh SCN with its own Service Group 1 / 2,
                                         i.e. another set of earning slots once these are used up. --}}
                                    <form method="POST" action="{{ route('groups.add-account', $c) }}"
                                        onsubmit="return confirm('Open a new earning account for {{ $c->display_name }}?')">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">+ New account</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-slate-500 dark:text-slate-400">
                            {{ $scnSearch !== '' ? 'No account matches that search.' : 'No consultant has a Service Group Code yet.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($consultantTiers->hasPages())
            <div class="border-t border-slate-100 p-4 dark:border-slate-800">{{ $consultantTiers->links() }}</div>
        @endif
    </div>

    <div class="mt-6 grid gap-5 sm:grid-cols-2">
        <div class="card p-6">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Group 01</p>
            <p class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">{{ number_format($tierMemberTotals[1]) }} Members</p>
        </div>
        <div class="card p-6">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Group 02</p>
            <p class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">{{ number_format($tierMemberTotals[2]) }} Members</p>
        </div>
    </div>

    {{-- ⚠️ HAND-ENTERED COMMISSION (2026-08-27). Super-admin only — this creates
         money. Asked for before go-live so a missing 2,500 / 4,000 can be put
         in without waiting for a code fix. The duplicate guard lives in
         ManualCommissionEntry: an identical row refuses the whole entry. --}}
    @if (auth()->user()?->hasRole('super_admin'))
        <div class="card mt-6 overflow-hidden border-amber-200 dark:border-amber-900/60">
            <div class="border-b border-slate-100 p-6 dark:border-slate-800">
                <h3 class="font-bold text-slate-900 dark:text-white">Add a commission by hand</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    For a student whose income did not calculate. It is recorded exactly like an automatic
                    one: a Service Group amount waits on the leg for the next System Run, and the direct
                    bonus is payable straight away. If the same commission already exists for that student,
                    nothing is added.
                </p>
            </div>

            {{-- ⚠️ REWORKED 2026-09-15. The two dropdowns here held 1,551 and
                 2,764 unsearchable options, and the tool had never once been
                 used successfully on live. The account picker is now searched
                 in the browser (the same filter the consultant Service Groups
                 picker uses), and the Student field is gone — the commission is
                 recorded against the account's own consultant instead. The
                 button disables itself on submit, which is what now guards the
                 double-click the duplicate rule used to catch. --}}
            <form method="POST" action="{{ route('groups.manual-commission') }}" class="grid gap-4 p-6 lg:grid-cols-5"
                onsubmit="if (! this.consultant_id.value) { alert('Choose an SCN account from the list first.'); return false; } this.querySelector('button[type=submit]').disabled = true;">
                @csrf

                <div class="lg:col-span-3" x-data="{
                        open: false,
                        search: '',
                        chosenId: '',
                        chosenUser: null,
                        options: {{ \Illuminate\Support\Js::from($accountOptions) }},
                        get filtered() {
                            const q = this.search.trim().toLowerCase();
                            const list = q ? this.options.filter(o => o.label.toLowerCase().includes(q)) : this.options;
                            return list.slice(0, 50);
                        },
                        choose(option) {
                            this.chosenId = option.id;
                            this.chosenUser = option.user_id;
                            this.search = option.label;
                            this.open = false;
                        },
                    }">
                    <label for="mc-consultant" class="label">SCN account</label>
                    <input type="hidden" name="consultant_id" :value="chosenId">
                    <div class="relative">
                        <input id="mc-consultant" type="text" autocomplete="off" required
                            x-model="search"
                            @focus="open = true" @click="open = true" @keydown.escape="open = false"
                            placeholder="Type an SCN code or a name..." class="input">
                        {{-- ⚠️ style="display:none" is the REAL mechanism, not x-cloak:
                             there is no [x-cloak] rule in the built CSS, so without it
                             this panel flashes open before Alpine boots. Alpine clears
                             the inline style itself once `open` turns true. Adding a CSS
                             rule instead would mean a fresh npm run build. --}}
                        <div x-show="open" x-cloak style="display: none" @click.outside="open = false"
                            class="absolute left-0 right-0 z-30 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                            <template x-for="option in filtered" :key="option.id">
                                <button type="button" @click="choose(option)"
                                    class="block w-full border-b border-slate-100 px-4 py-2.5 text-left text-sm text-slate-700 last:border-b-0 hover:bg-brand-50 dark:border-slate-800 dark:text-slate-200 dark:hover:bg-brand-500/10"
                                    x-text="option.label"></button>
                            </template>
                            <p x-show="filtered.length === 0" class="px-4 py-3 text-sm text-slate-400">Nothing matches that search.</p>
                        </div>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-3">
                        <p class="text-xs text-slate-400">Every SCN account, placement accounts included. The commission is recorded against this account's own consultant.</p>
                        <button type="button" x-show="chosenUser" x-cloak style="display: none"
                            @click="Livewire.dispatch('show-user-summary', { userId: chosenUser })"
                            class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Info / upline
                        </button>
                    </div>
                </div>

                <div>
                    <label for="mc-type" class="label">Amount</label>
                    <select id="mc-type" name="type" required class="input">
                        <option value="slot" @selected(old('type') === 'slot')>Service Group — LKR {{ number_format($settings->commission_per_student) }}</option>
                        <option value="direct" @selected(old('type') === 'direct')>Direct bonus — LKR {{ number_format($settings->direct_commission) }}</option>
                    </select>
                </div>

                {{-- A course with its own direct amount (2026-10-22, Elliott Wave
                     5,000). Only changes a Direct bonus entry — a 2,500 is the
                     same for every course. Title AND price, so two courses with
                     similar names cannot be mixed up. --}}
                @if (($courseDirectRates ?? collect())->isNotEmpty())
                    <div>
                        <label for="mc-course" class="label">Course <span class="font-normal text-slate-400">(direct bonus only)</span></label>
                        <select id="mc-course" name="course_id" class="input">
                            <option value="">Any other course — LKR {{ number_format($settings->direct_commission) }}</option>
                            @foreach ($courseDirectRates as $courseId => $courseRate)
                                <option value="{{ $courseId }}" @selected((string) old('course_id') === (string) $courseId)>{{ $courseRate['title'] }} (LKR {{ number_format($courseRate['price']) }}) — LKR {{ number_format($courseRate['direct']) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- ⚠️ THE DATE THIS INCOME BELONGS TO (2026-09-15). A hand entry
                     has no payment to date it by, so without this there was no
                     way to say which System Run should settle it. --}}
                <div>
                    <label for="mc-date" class="label">Date</label>
                    <input id="mc-date" name="earned_on" type="date" required class="input"
                        max="{{ \Illuminate\Support\Carbon::now('Asia/Colombo')->toDateString() }}"
                        value="{{ old('earned_on', \Illuminate\Support\Carbon::now('Asia/Colombo')->toDateString()) }}">
                    <p class="mt-1 text-xs text-slate-400">A System Run settling up to this date will pick it up.</p>
                </div>

                <div class="lg:col-span-2">
                    <label for="mc-tier" class="label">Service Group</label>
                    <select id="mc-tier" name="tier" required class="input">
                        <option value="1" @selected(old('tier') == 1)>Service Group 1</option>
                        <option value="2" @selected(old('tier') == 2)>Service Group 2</option>
                    </select>
                </div>

                <div class="lg:col-span-2">
                    <label for="mc-scope" class="label">Who receives it</label>
                    <select id="mc-scope" name="scope" required class="input">
                        <option value="account" @selected(old('scope') === 'account')>This SCN account only</option>
                        <option value="upline" @selected(old('scope') === 'upline')>This account and its whole upline</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">
                        The upline option walks the same chain a verified payment does. The direct bonus never
                        climbs, so it always lands on the one account.
                    </p>
                </div>

                <div class="flex items-end lg:col-span-1">
                    <button type="submit" class="btn-primary w-full">Add commission</button>
                </div>
            </form>
        </div>
    @endif

    <form method="GET" action="{{ route('groups.index') }}" class="card mt-6 mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search by code, name, or consultant..." class="input pl-11">
        </div>
        <select name="status" class="input lg:w-40" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['open', 'completed', 'closed'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Search</button>
    </form>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($groups as $group)
            @php $pct = $group->capacity > 0 ? min(100, ($group->members_count / $group->capacity) * 100) : 0; @endphp
            <a href="{{ route('groups.show', $group) }}" class="card group p-5 transition hover:shadow-md">
                <div class="flex items-center justify-between">
                    {{-- The SCN account, not the GRP-XXXXXX key (2026-09-22): the
                         group code is an internal id nobody outside the database can
                         place. Kept in the tooltip for anyone who needs it. --}}
                    <span class="font-mono text-xs font-semibold text-slate-400" title="{{ $group->group_code }}">{{ $group->consultant?->scn_code ?: $group->group_code }}</span>
                    @php $gs = ['open' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'completed' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400', 'closed' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$group->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                    <span class="badge {{ $gs }}">{{ ucfirst($group->status) }}</span>
                </div>
                <h3 class="mt-3 font-bold text-slate-900 dark:text-white">{{ $group->name ?? 'Service Group' }}</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $group->consultant?->display_name ?? 'Consultant' }}</p>

                <div class="mt-4">
                    <div class="mb-1.5 flex items-center justify-between text-xs">
                        <span class="font-medium text-slate-500 dark:text-slate-400">{{ $group->members_count }} / {{ $group->capacity }} students</span>
                        <span class="font-bold text-slate-700 dark:text-slate-200">{{ round($pct) }}%</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full bg-brand-500" style="width: {{ $pct }}%"></div>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                    <span class="text-slate-400">Service Group Income</span>
                    <span class="font-bold text-slate-900 dark:text-white">LKR {{ number_format($group->total_commission_earned) }}</span>
                </div>
            </a>
        @empty
            <div class="card col-span-full p-12 text-center text-slate-500 dark:text-slate-400">No service groups found.</div>
        @endforelse
    </div>

    <div class="mt-8">{{ $groups->links() }}</div>

    {{-- The Info popup the hand-entry picker opens: SCN accounts, service
         groups, origin and UPLINE. One component for the page, exactly like
         User Management — nothing loads until a button is pressed. --}}
    @if (auth()->user()?->hasRole('super_admin'))
        @livewire('user-account-summary')
    @endif
</x-layouts.app>
