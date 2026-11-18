@if ($students->isEmpty())
    <div class="card p-12 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
        </span>
        <h3 class="mt-4 font-bold text-slate-900 dark:text-white">{{ $emptyTitle ?? 'No students yet' }}</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $emptyMessage ?? "Add your first student to start building your service group and earning service income." }}</p>
        <a href="{{ route('consultant.students.create') }}" class="btn-primary mt-6">Add your first student</a>
    </div>
@else
    {{-- Table (desktop) --}}
    <div class="card hidden overflow-hidden lg:block">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[76rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-4 py-4 font-semibold">#</th>
                        <th class="px-4 py-4 font-semibold">Full Name</th>
                        <th class="px-4 py-4 font-semibold">Student Code</th>
                        <th class="px-4 py-4 font-semibold">Consultant Code</th>
                        <th class="px-4 py-4 font-semibold">Contact</th>
                        <th class="px-4 py-4 font-semibold">Email</th>
                        <th class="px-4 py-4 font-semibold">Gender</th>
                        <th class="px-4 py-4 font-semibold">Register Date</th>
                        <th class="px-4 py-4 font-semibold">SRN Active Date</th>
                        <th class="px-4 py-4 font-semibold">Placement</th>
                        <th class="px-4 py-4 font-semibold">Course</th>
                        <th class="px-4 py-4 font-semibold">Payment Status</th>
                        <th class="px-4 py-4 font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($students as $i => $student)
                        @php
                            $pay = ($payments[$student->user_id] ?? collect())->first();
                            $tier = ($tiers ?? collect())[$student->user_id] ?? null;
                            // No seat yet (payment still unverified) → show the
                            // service group the consultant chose at registration.
                            $wantedTier = $tier ?: (($requestedTiers ?? collect())[$student->user_id] ?? null);
                            // WHICH account the student actually sits in. Showing
                            // only "GROUP 02" left it impossible to tell whose
                            // service group that was — a consultant owns several
                            // accounts and can register on any of them.
                            // students.consultant_id is the placement account
                            // itself (set at enrolment, re-stamped on
                            // verification by CommissionService::placeInto), so
                            // it is per-row correct; the payment is the fallback
                            // for rows that predate that write.
                            $placedOn = $student->consultant?->scn_code
                                ?? $student->consultant?->consultant_code
                                ?? $pay?->consultant?->scn_code
                                ?? $pay?->consultant?->consultant_code;
                            // ⚠️ PAID BY SOMEBODY ELSE (2026-11-03): the holder sees the row
                            // (the student IS on their account) but view only. payerOf() is
                            // the definition the action guards use, so shown == allowed.
                            $payer = $pay ? \App\Services\RegistrationPayer::payerOf($pay) : null;
                            $payerIsViewer = $payer !== null && isset($consultant) && (int) $payer->id === (int) $consultant->id;
                            $viewOnly = $payer !== null && ! $payerIsViewer;
                            $payerCode = $payer ? ($payer->scn_code ?: $payer->consultant_code) : null;
                        @endphp
                        <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-4 py-4 text-slate-500 dark:text-slate-400">{{ $students->firstItem() + $i }}</td>
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <x-user-avatar :user="$student->user" size="h-8 w-8" text="text-xs" />
                                    <span class="truncate font-semibold text-slate-900 dark:text-white">{{ $student->user->name ?? 'Unknown' }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-4 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $student->displayCode() }}</td>
                            <td class="px-4 py-4 font-mono text-xs text-slate-500 dark:text-slate-400">
                                {{ ($consultant ?? $student->consultant)?->scn_code ?? '—' }}
                                @if ($student->partnerConsultant)
                                    <div class="text-slate-400">Partner: {{ $student->partnerConsultant->scn_code ?? $student->partnerConsultant->consultant_code }}</div>
                                @endif
                                @if ($payer)
                                    <div class="text-amber-600 dark:text-amber-400">Paid by {{ $payerIsViewer ? 'you' : $payerCode }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-slate-500 dark:text-slate-400">{{ $student->user->phone ?? '—' }}</td>
                            <td class="px-4 py-4 truncate text-slate-500 dark:text-slate-400">{{ $student->user->email ?? '—' }}</td>
                            <td class="px-4 py-4 text-slate-500 dark:text-slate-400">{{ $student->gender ? ucfirst($student->gender) : '—' }}</td>
                            <td class="px-4 py-4 text-slate-500 dark:text-slate-400">{{ optional($student->created_at)->format('Y-m-d') }}</td>
                            <td class="px-4 py-4 text-slate-500 dark:text-slate-400">{{ optional($pay?->verified_at)->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-4 py-4">
                                @if ($tier)
                                    <span class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">GROUP 0{{ $tier }}</span>
                                @elseif ($wantedTier)
                                    {{-- Chosen, but the seat is only created once the payment is verified. --}}
                                    <span class="badge bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">GROUP 0{{ $wantedTier }}</span>
                                    <span class="mt-0.5 block text-[11px] text-slate-400">Pending verification</span>
                                @else
                                    <span class="text-xs text-slate-400">Not assigned</span>
                                @endif
                                @if ($placedOn)
                                    <span class="mt-0.5 block font-mono text-[11px] text-slate-400">CON: {{ $placedOn }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-slate-500 dark:text-slate-400">{{ $pay?->course?->title ?? '—' }}</td>
                            <td class="px-4 py-4">@if ($pay)<x-payment-status :status="$pay->status" :type="$pay->payment_type" />@else<span class="text-xs text-slate-400">—</span>@endif</td>
                            <td class="px-4 py-4">
                                {{-- Asked for 2026-09-10: an info button on every row opening a
                                     summary of that person's SCN accounts. It is the only
                                     UNCONDITIONAL thing in this cell — every other control here
                                     depends on the payment state, so a fully verified row used to
                                     have an empty action column.

                                     ⚠️ This only dispatches an id. The summary itself lives in the
                                     one ScnAccountSummary component mounted at the bottom of
                                     index.blade.php, because rendering it per row would cost ~30
                                     queries × 12 rows on every page view. --}}
                                <button type="button"
                                    onclick="Livewire.dispatch('show-scn-summary', { studentId: {{ $student->id }} })"
                                    class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Info
                                </button>

                                @if ($viewOnly)
                                    {{-- Somebody above registered this student on this account and
                                         pays for it, so no payment controls here. The server refuses
                                         them too (RegistrationPayer::canHandle). The SCN only, no
                                         name: the payer is above this viewer, outside their network. --}}
                                    <p class="text-xs text-slate-500 dark:text-slate-400">View only · Paid by <span class="font-mono">{{ $payerCode }}</span></p>
                                @else
                                    {{-- A rejection is recoverable: the consultant attaches a
                                         corrected slip and the SAME payment goes back to the
                                         accountant. Checked before the payment_type branch,
                                         since a rejected payment already has one. --}}
                                    @if ($pay && $pay->status === 'rejected')
                                        @include('consultant.students._resubmit-payment-modal', ['pay' => $pay])
                                    @elseif ($pay && ! $pay->payment_type)
                                        @include('consultant.students._confirm-payment-modal', ['pay' => $pay])
                                    @elseif ($pay && $pay->payment_type === 'half' && $pay->status === 'verified')
                                        @include('consultant.students._pay-balance-modal', ['pay' => $pay, 'balance' => \App\Services\EnrollmentService::remainingBalance($pay->course, $student->user_id)])
                                    @endif

                                    {{-- Still unpaid: chase the student up, or drop
                                         the registration entirely. --}}
                                    @if ($pay && $pay->status === 'pending')
                                        <div class="mt-1">
                                            @include('consultant.students._pending-actions', ['pay' => $pay, 'studentName' => $student->user->name ?? null])
                                        </div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Cards (mobile) --}}
    <div class="space-y-4 lg:hidden">
        @foreach ($students as $student)
            @php
                $pay = ($payments[$student->user_id] ?? collect())->first();
                $tier = ($tiers ?? collect())[$student->user_id] ?? null;
                $wantedTier = $tier ?: (($requestedTiers ?? collect())[$student->user_id] ?? null);
                $placedOn = $student->consultant?->scn_code
                    ?? $student->consultant?->consultant_code
                    ?? $pay?->consultant?->scn_code
                    ?? $pay?->consultant?->consultant_code;
                // Paid by somebody else — see the desktop row above (2026-11-03).
                $payer = $pay ? \App\Services\RegistrationPayer::payerOf($pay) : null;
                $payerIsViewer = $payer !== null && isset($consultant) && (int) $payer->id === (int) $consultant->id;
                $viewOnly = $payer !== null && ! $payerIsViewer;
                $payerCode = $payer ? ($payer->scn_code ?: $payer->consultant_code) : null;
            @endphp
            <div class="card p-5">
                <div class="flex items-center gap-3">
                    <x-user-avatar :user="$student->user" size="h-11 w-11" text="text-base" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-semibold text-slate-900 dark:text-white">{{ $student->user->name ?? 'Unknown' }}</div>
                        {{-- ⚠️ `user?->` — a Student can outlive its User (2026-08-23).
                             Every other line here already guarded with `?? `, which
                             suppresses the null read; this @if did not, so one
                             orphaned row 500'd the whole Direct Registrations page:
                             "Attempt to read property phone on null". Orphans exist
                             because an unpaid registration is cleaned up by
                             force-deleting the user, which can leave the student row
                             behind. The row still renders, as "Unknown", so it stays
                             visible to be cleaned up rather than silently hidden. --}}
                        <div class="truncate text-xs text-slate-400">{{ $student->user->email ?? '' }} @if ($student->user?->phone) · {{ $student->user->phone }} @endif</div>
                    </div>
                    @if ($pay)<x-payment-status :status="$pay->status" :type="$pay->payment_type" />@endif
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
                    <div><dt class="text-slate-400">Code</dt><dd class="font-mono text-slate-600 dark:text-slate-300">{{ $student->displayCode() }}</dd></div>
                    <div><dt class="text-slate-400">Consultant Code</dt><dd class="font-mono text-slate-600 dark:text-slate-300">{{ ($consultant ?? $student->consultant)?->scn_code ?? '—' }}@if ($payer)<span class="block text-amber-600 dark:text-amber-400">Paid by {{ $payerIsViewer ? 'you' : $payerCode }}</span>@endif</dd></div>
                    <div><dt class="text-slate-400">Gender</dt><dd class="text-slate-600 dark:text-slate-300">{{ $student->gender ? ucfirst($student->gender) : '—' }}</dd></div>
                    <div><dt class="text-slate-400">Course</dt><dd class="truncate text-slate-600 dark:text-slate-300">{{ $pay?->course?->title ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400">Register Date</dt><dd class="text-slate-600 dark:text-slate-300">{{ optional($student->created_at)->format('Y-m-d') }}</dd></div>
                    <div><dt class="text-slate-400">SRN Active Date</dt><dd class="text-slate-600 dark:text-slate-300">{{ optional($pay?->verified_at)->format('Y-m-d') ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-slate-400">Placement</dt>
                        <dd class="text-slate-600 dark:text-slate-300">
                            @if ($tier)
                                GROUP 0{{ $tier }}
                            @elseif ($wantedTier)
                                GROUP 0{{ $wantedTier }} <span class="text-amber-600 dark:text-amber-400">(pending verification)</span>
                            @else
                                Not assigned
                            @endif
                            @if ($placedOn) · CON: {{ $placedOn }} @endif
                            @if ($student->partnerConsultant) · Partner: {{ $student->partnerConsultant->scn_code ?? $student->partnerConsultant->consultant_code }} @endif
                        </dd>
                    </div>
                </dl>
                <button type="button"
                    onclick="Livewire.dispatch('show-scn-summary', { studentId: {{ $student->id }} })"
                    class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    SCN account info
                </button>
                @if ($viewOnly)
                    <div class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        View only · Paid by <span class="font-mono">{{ $payerCode }}</span>
                    </div>
                @else
                    @if ($pay && $pay->status === 'rejected')
                        <div class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                            @include('consultant.students._resubmit-payment-modal', ['pay' => $pay])
                        </div>
                    @elseif ($pay && ! $pay->payment_type)
                        <div class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                            @include('consultant.students._confirm-payment-modal', ['pay' => $pay])
                        </div>
                    @elseif ($pay && $pay->payment_type === 'half' && $pay->status === 'verified')
                        <div class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                            @include('consultant.students._pay-balance-modal', ['pay' => $pay, 'balance' => \App\Services\EnrollmentService::remainingBalance($pay->course, $student->user_id)])
                        </div>
                    @endif

                    @if ($pay && $pay->status === 'pending')
                        <div class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                            @include('consultant.students._pending-actions', ['pay' => $pay, 'studentName' => $student->user->name ?? null])
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-8">{{ $students->links() }}</div>
@endif
