<div>
    {{-- ================= Filters ================= --}}
    {{-- These bind to #[Url] props, so they live in the query string. A verify
         is an AJAX round trip rather than a navigation, which is why the
         filters cannot be cleared by one. --}}
    <div class="card mt-6 mb-6 p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
            <div class="flex-1">
                <label for="pq-q" class="label">Search</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input id="pq-q" type="search" wire:model.live.debounce.400ms="q" placeholder="Code, name, email, SRN or SCN..." class="input pl-11">
                </div>
            </div>
            <div>
                <label for="pq-status" class="label">Status</label>
                <select id="pq-status" wire:model.live="status" class="input lg:w-40">
                    @foreach (['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected', '' => 'All'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            {{-- Both ends are inclusive, matched against the date paid. --}}
            <div>
                <label for="pq-from" class="label">From</label>
                <input id="pq-from" type="date" wire:model.live="from" max="{{ now()->toDateString() }}" class="input lg:w-44">
            </div>
            <div>
                <label for="pq-to" class="label">To</label>
                <input id="pq-to" type="date" wire:model.live="to" max="{{ now()->toDateString() }}" class="input lg:w-44">
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            @if ($q !== '' || $from !== '' || $to !== '' || $status !== 'pending')
                <button type="button" wire:click="resetFilters" class="btn-secondary">Reset</button>
            @endif
            {{-- Export stays a plain link to the controller: a CSV is a file
                 download, which a Livewire round trip cannot hand back. --}}
            <a href="{{ route('payments.index', array_filter(['q' => $q, 'status' => $status, 'from' => $from, 'to' => $to, 'export' => 'csv'])) }}" class="btn-secondary">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Export CSV
            </a>
            <span wire:loading class="text-xs text-slate-400">Loading…</span>
        </div>
    </div>

    {{-- ================= Table ================= --}}
    <div class="card hidden overflow-hidden lg:block">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                <tr>
                    <th class="px-6 py-4 font-semibold">Payment</th>
                    <th class="px-6 py-4 font-semibold">Student</th>
                    <th class="px-6 py-4 font-semibold">Course</th>
                    <th class="px-6 py-4 font-semibold">Amount</th>
                    <th class="px-6 py-4 font-semibold">Status</th>
                    <th class="px-6 py-4 text-right font-semibold">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($payments as $p)
                    <tr wire:key="pay-{{ $p->id }}" class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                        <td class="px-6 py-4">
                            <div class="font-mono text-xs font-semibold text-slate-900 dark:text-white">{{ $p->payment_code }}</div>
                            <div class="text-xs text-slate-400">{{ ucfirst($p->method) }}{{ $p->payment_type ? ' · '.\Illuminate\Support\Str::headline($p->payment_type) : '' }} · {{ optional($p->paid_at)->format('M d, Y') }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-semibold text-slate-900 dark:text-white">{{ $p->user->name ?? '—' }}</div>
                            @if ($p->user?->displayCode())<div class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $p->user->displayCode() }}</div>@endif
                            @if ($p->consultant)<div class="text-xs text-amber-600 dark:text-amber-400">via {{ $p->consultant->display_name }}@if ($p->requestedBy) · paid by {{ $p->requestedBy->scn_code ?: $p->requestedBy->consultant_code }}@endif</div>@endif
                        </td>
                        <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ \Illuminate\Support\Str::limit($p->course->title ?? '—', 24) }}</td>
                        <td class="px-6 py-4 font-bold text-slate-900 dark:text-white">LKR {{ number_format($p->amount) }}</td>
                        <td class="px-6 py-4"><x-payment-status :status="$p->status" /></td>
                        <td class="px-6 py-4 text-right">
                            {{-- Opens the modal below. The old page is still one
                                 click away from inside it, and still works on its
                                 own for anyone without JavaScript. --}}
                            <button type="button" wire:click="open({{ $p->id }})" wire:loading.attr="disabled" class="btn-secondary py-1.5 text-xs">Review</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">No payments found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Mobile cards --}}
    <div class="space-y-4 lg:hidden">
        @forelse ($payments as $p)
            <div wire:key="pay-m-{{ $p->id }}" class="card p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="font-mono text-xs font-semibold text-slate-900 dark:text-white">{{ $p->payment_code }}</div>
                        <div class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $p->user->name ?? '—' }}</div>
                        @if ($p->user?->displayCode())<div class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $p->user->displayCode() }}</div>@endif
                        <div class="text-xs text-slate-400">{{ \Illuminate\Support\Str::limit($p->course->title ?? '—', 30) }}</div>
                    </div>
                    <x-payment-status :status="$p->status" />
                </div>
                <div class="mt-4 flex items-center justify-between">
                    <span class="text-lg font-bold text-slate-900 dark:text-white">LKR {{ number_format($p->amount) }}</span>
                    <button type="button" wire:click="open({{ $p->id }})" class="btn-secondary py-2 text-sm">Review</button>
                </div>
            </div>
        @empty
            <div class="card p-12 text-center text-slate-500 dark:text-slate-400">No payments found.</div>
        @endforelse
    </div>

    <div class="mt-8">{{ $payments->links() }}</div>

    {{-- ================= The review modal ================= --}}
    @if ($selectedId !== null && $detail !== [])
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 sm:p-8">
            <div class="w-full max-w-4xl rounded-2xl bg-white shadow-2xl dark:bg-slate-900">

                <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5 dark:border-slate-800">
                    <div class="min-w-0">
                        <h3 class="font-mono text-lg font-extrabold text-slate-900 dark:text-white">{{ $detail['code'] }}</h3>
                        <p class="mt-0.5 text-xs text-slate-400">Submitted {{ $detail['paid_at'] ?? '—' }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <x-payment-status :status="$detail['status']" />
                        <button type="button" wire:click="close" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            <span class="sr-only">Close</span>
                        </button>
                    </div>
                </div>

                @if ($result)
                    {{-- ⚠️ SAYS WHAT IT CANNOT DO. The four stat cards above this
                         table are rendered by the controller and do not follow a
                         Livewire action, so the banner tells the truth rather
                         than leaving the accountant to notice the mismatch. --}}
                    <div class="border-b border-emerald-100 bg-emerald-50 px-5 py-3 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                        {{ $result }}
                        <span class="block text-xs opacity-80">The counts in the cards above update when you reload the page.</span>
                    </div>
                @endif

                @if ($problem)
                    <div class="border-b border-rose-100 bg-rose-50 px-5 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                        {{ $problem }}
                    </div>
                @endif

                <div class="grid gap-5 p-5 lg:grid-cols-2">

                    {{-- Details --}}
                    <div>
                        <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                            @foreach (array_merge([
                                'Student' => $detail['student'],
                                'Email' => $detail['email'],
                                'Course' => $detail['course'],
                                'Method' => $detail['method'],
                                'Payment type' => $detail['type'],
                                'Reference' => $detail['reference'],
                                'Referred by' => $detail['consultant'],
                            ], filled($detail['paid_by'] ?? null) ? ['Paid by' => $detail['paid_by']] : []) as $label => $value)
                                <div>
                                    <dt class="text-xs uppercase tracking-wide text-slate-400">{{ $label }}</dt>
                                    <dd class="mt-0.5 break-words font-medium text-slate-900 dark:text-white">{{ $value }}</dd>
                                </div>
                            @endforeach
                            <div class="sm:col-span-2">
                                <dt class="text-xs uppercase tracking-wide text-slate-400">Amount</dt>
                                <dd class="mt-0.5 text-2xl font-extrabold text-slate-900 dark:text-white">LKR {{ number_format($detail['amount'], 2) }}</dd>
                            </div>
                        </dl>

                        @if (! empty($detail['notes']))
                            <div class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
                                <span class="font-semibold">Student note:</span> {{ $detail['notes'] }}
                            </div>
                        @endif

                        @if (! empty($detail['history']))
                            <h4 class="mt-5 text-xs font-bold uppercase tracking-wide text-slate-400">Verification history</h4>
                            <ul class="mt-2 space-y-2">
                                @foreach ($detail['history'] as $entry)
                                    <li class="text-sm text-slate-600 dark:text-slate-300">
                                        <span class="font-medium">{{ $entry['action'] }}</span> by {{ $entry['by'] }}
                                        <span class="text-slate-400">· {{ $entry['when'] }}</span>
                                        @if ($entry['remarks'])<p class="text-xs text-slate-400">{{ $entry['remarks'] }}</p>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- Payslip --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-400">Payment proof</h4>
                            @if ($detail['receipt_url'])
                                <a href="{{ $detail['receipt_url'] }}" target="_blank" rel="noopener" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open in its own page</a>
                            @endif
                        </div>

                        @if (! $detail['receipt_url'])
                            <p class="mt-3 text-sm text-slate-400">No proof uploaded for this payment.</p>
                        @elseif ($detail['receipt_is_image'])
                            {{-- ⚠️ Zoom is a CSS transform on the image, not a
                                 bigger download. wire:ignore keeps Livewire from
                                 rebuilding this subtree on every round trip,
                                 which would throw away the zoom level mid-review. --}}
                            <div wire:ignore wire:key="proof-{{ $detail['id'] }}"
                                x-data="{ zoom: 1, x: 0, y: 0, dragging: false, sx: 0, sy: 0 }" class="mt-2">
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="zoom = Math.max(1, zoom - 0.25); if (zoom === 1) { x = 0; y = 0 }" class="btn-secondary px-2 py-1 text-xs">&minus;</button>
                                    <span class="w-12 text-center text-xs text-slate-400" x-text="Math.round(zoom * 100) + '%'"></span>
                                    <button type="button" @click="zoom = Math.min(6, zoom + 0.25)" class="btn-secondary px-2 py-1 text-xs">+</button>
                                    <button type="button" @click="zoom = 1; x = 0; y = 0" class="btn-secondary px-2 py-1 text-xs">Reset</button>
                                    <span class="text-[11px] text-slate-400">Scroll to zoom, drag to move</span>
                                </div>
                                <div class="mt-2 h-80 overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800"
                                    @wheel.prevent="zoom = Math.min(6, Math.max(1, zoom + ($event.deltaY < 0 ? 0.2 : -0.2))); if (zoom === 1) { x = 0; y = 0 }"
                                    @mousedown="dragging = true; sx = $event.clientX - x; sy = $event.clientY - y"
                                    @mousemove="if (dragging && zoom > 1) { x = $event.clientX - sx; y = $event.clientY - sy }"
                                    @mouseup.window="dragging = false"
                                    @mouseleave="dragging = false"
                                    :class="zoom > 1 ? 'cursor-move' : 'cursor-zoom-in'">
                                    <img src="{{ $detail['receipt_url'] }}" alt="Payment proof"
                                        class="h-full w-full select-none object-contain"
                                        draggable="false"
                                        :style="'transform: translate(' + x + 'px,' + y + 'px) scale(' + zoom + '); transition: ' + (dragging ? 'none' : 'transform .1s')">
                                </div>
                            </div>
                        @else
                            {{-- A payslip can be a PDF. Scaling a PDF means nothing,
                                 so the browser's own viewer handles it. --}}
                            <div class="mt-2 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                                <iframe src="{{ $detail['receipt_url'] }}" title="Payment proof" class="h-80 w-full bg-white"></iframe>
                            </div>
                            <p class="mt-2 text-xs text-slate-400">{{ $detail['receipt_kind'] }} file.</p>
                        @endif
                    </div>
                </div>

                {{-- Take action --}}
                <div class="border-t border-slate-100 p-5 dark:border-slate-800">
                    @if ($detail['status'] === 'pending' && ! $canAction)
                        {{-- A group supporter reviews payments but cannot action them. --}}
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-slate-500 dark:text-slate-400">Waiting for verification. You can view this payment, but not verify or reject it.</p>
                            <a href="{{ $detail['page_url'] }}" class="btn-secondary text-sm">Open full page</a>
                        </div>
                    @elseif ($detail['status'] === 'pending')
                        <label for="pq-remarks" class="label">Remarks</label>
                        <textarea id="pq-remarks" wire:model="remarks" rows="2" maxlength="500"
                            placeholder="Optional when verifying. Required when rejecting." class="input"></textarea>

                        <div class="mt-3 flex flex-wrap gap-3">
                            {{-- ⚠️ Verify is SLOW — a schema repair, a row-locked
                                 transaction holding the commission engine, and two
                                 synchronous emails. Disabled while it runs so it is
                                 not clicked twice. --}}
                            <button type="button" wire:click="verifyPayment"
                                wire:loading.attr="disabled" wire:target="verifyPayment,rejectPayment"
                                class="btn-primary">
                                <span wire:loading.remove wire:target="verifyPayment">Verify payment</span>
                                <span wire:loading wire:target="verifyPayment">Verifying, this takes a few seconds…</span>
                            </button>
                            <button type="button" wire:click="rejectPayment"
                                wire:loading.attr="disabled" wire:target="verifyPayment,rejectPayment"
                                class="btn-secondary !text-rose-600 dark:!text-rose-400">
                                <span wire:loading.remove wire:target="rejectPayment">Reject payment</span>
                                <span wire:loading wire:target="rejectPayment">Rejecting…</span>
                            </button>
                            <a href="{{ $detail['page_url'] }}" class="btn-secondary">Open full page</a>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-slate-500 dark:text-slate-400">This payment has already been processed.</p>
                            <a href="{{ $detail['page_url'] }}" class="btn-secondary text-sm">Open full page</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
