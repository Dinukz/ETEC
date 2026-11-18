@props(['pay'])

@php
    // Why the accountant turned it down, so the consultant knows what to fix
    // before attaching a replacement. `verifications` is eager-loaded newest
    // first by StudentController::pending().
    $rejection = $pay->relationLoaded('verifications')
        ? $pay->verifications->firstWhere('action', 'rejected')
        : null;
@endphp

<div x-data="{ open: false }" class="inline-block">
    <button type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="open = true">Re-attach Slip</button>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div class="card max-h-[90vh] w-full max-w-md overflow-y-auto p-6" @click.outside="open = false">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Re-attach Payment Slip</h3>
            <p class="mt-1 font-mono text-xs text-slate-400">{{ $pay->payment_code }}</p>

            <div class="mt-3 rounded-xl bg-rose-50 px-4 py-3 text-sm dark:bg-rose-500/10">
                <p class="font-semibold text-rose-700 dark:text-rose-300">This payment was rejected.</p>
                @if ($rejection?->remarks)
                    <p class="mt-1 text-rose-600 dark:text-rose-400">Reason: {{ $rejection->remarks }}</p>
                @endif
                <p class="mt-1 text-xs text-rose-600/80 dark:text-rose-400/80">
                    Attach a corrected slip below and it goes straight back to the accountant. The student keeps their
                    registration and login — nothing needs to be created again.
                </p>
            </div>

            <form method="POST" action="{{ route('consultant.payments.resubmit', $pay) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf
                {{-- ⚠️ A REJECTED BALANCE IS STILL A BALANCE (2026-09-30). The
                     half was already paid, so the plan is not a choice here —
                     offering Full / Half / Free (with Full pre-selected, since no
                     option matched "balance") invited billing the whole course
                     a second time. The server keeps the type whatever is sent;
                     this only stops the question being asked. --}}
                @if ($pay->payment_type === 'balance')
                    <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800/50">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Payment Type</p>
                        <p class="mt-0.5 font-semibold text-slate-900 dark:text-white">Balance payment · LKR {{ number_format((float) $pay->amount, 2) }}</p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">The rest of a half payment that was already paid.</p>
                    </div>
                @else
                    <div>
                        <label class="label">Payment Type</label>
                        <select name="payment_type" required class="input">
                            @foreach (['full' => 'Full payment', 'half' => 'Half payment', 'free_card' => 'Free card'] as $value => $label)
                                <option value="{{ $value }}" @selected($pay->payment_type === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    {{-- Keeps the earlier date when it is still open (2026-09-22). --}}
                    @include('consultant.students._payment-date-field', ['current' => optional($pay->paid_at)->format('Y-m-d')])
                </div>
                <div>
                    {{-- Required for a balance, the same rule as the Pay Balance form. --}}
                    <label class="label">Payment Reference
                        @unless ($pay->payment_type === 'balance')<span class="font-normal text-slate-400">(optional)</span>@endunless
                    </label>
                    <input name="payment_reference_number" type="text" class="input"
                        value="{{ $pay->reference_number }}" placeholder="Slip / transaction number" @required($pay->payment_type === 'balance')>
                </div>
                <div>
                    {{-- Required, unlike the first confirmation: re-sending the
                         same evidence that was just rejected is never the intent. --}}
                    <label class="label">Corrected Slip</label>
                    <input name="payment_receipt" type="file" required accept=".pdf,.png,.jpg,.jpeg" class="input">
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                    <button type="submit" class="btn-primary" @disabled(\App\Support\ConsultantPaymentDates::allowed() === [])>Re-submit for Verification</button>
                </div>
            </form>
        </div>
    </div>
</div>
