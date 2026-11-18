<x-layouts.app title="Payment Details" header="Payment Details">
    <div class="mb-6">
        <a href="{{ route('payments.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to payments
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Details --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="card p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-mono text-xl font-extrabold text-slate-900 dark:text-white">{{ $payment->payment_code }}</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Submitted {{ optional($payment->paid_at)->format('F d, Y \a\t g:i A') ?? '—' }}</p>
                    </div>
                    <x-payment-status :status="$payment->status" />
                </div>

                <dl class="mt-6 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    @foreach ([
                        'Student' => $payment->user->name ?? '—',
                        'Email' => $payment->user->email ?? '—',
                        'Course' => $payment->course->title ?? '—',
                        'Method' => ucfirst($payment->method),
                        'Payment type' => $payment->payment_type ? \Illuminate\Support\Str::headline($payment->payment_type) : '—',
                        'Reference' => $payment->reference_number ?? '—',
                        'Referred by' => $payment->consultant?->display_name ?? 'Direct signup',
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">{{ $label }}</dt>
                            <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Amount</dt>
                        <dd class="mt-0.5 text-2xl font-extrabold text-slate-900 dark:text-white">LKR {{ number_format($payment->amount, 2) }}</dd>
                    </div>
                </dl>

                @if ($payment->notes)
                    <div class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
                        <span class="font-semibold">Student note:</span> {{ $payment->notes }}
                    </div>
                @endif
            </div>

            {{-- Payment proof --}}
            <div class="card p-6">
                <h3 class="font-bold text-slate-900 dark:text-white">Payment proof</h3>
                @if ($payment->receipt_path)
                    @php $ext = strtolower(pathinfo($payment->receipt_path, PATHINFO_EXTENSION)); @endphp
                    @if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']))
                        <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="mt-4 block overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
                            <img src="{{ route('payments.receipt', $payment) }}" alt="Payment proof" class="max-h-96 w-full object-contain bg-slate-50 dark:bg-slate-800">
                        </a>
                    @else
                        <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="btn-secondary mt-4">View / download proof ({{ strtoupper($ext) }})</a>
                    @endif
                @else
                    <p class="mt-3 text-sm text-slate-400">No proof uploaded for this payment.</p>
                @endif
            </div>

            {{-- Verification history --}}
            @if ($payment->verifications->isNotEmpty())
                <div class="card p-6">
                    <h3 class="font-bold text-slate-900 dark:text-white">Verification history</h3>
                    <ul class="mt-4 space-y-3">
                        @foreach ($payment->verifications as $v)
                            <li class="flex items-start gap-3 text-sm">
                                <span class="mt-1 h-2 w-2 shrink-0 rounded-full {{ $v->action === 'verified' ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                <div>
                                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ ucfirst($v->action) }}</span>
                                    by {{ $v->verifier->name ?? 'Unknown' }}
                                    <span class="text-slate-400">· {{ optional($v->verified_at)->diffForHumans() }}</span>
                                    @if ($v->remarks)<p class="text-xs text-slate-500 dark:text-slate-400">{{ $v->remarks }}</p>@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- Actions --}}
        <div class="lg:col-span-1">
            <div class="lg:sticky lg:top-24">
                @if ($payment->status === 'pending' && ! auth()->user()->canActionPayments())
                    {{-- A group supporter reviews payments but cannot action them. --}}
                    <div class="card p-6">
                        <h3 class="font-bold text-slate-900 dark:text-white">Waiting for verification</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">You can view this payment, but only an accountant or admin can verify or reject it.</p>
                    </div>
                @elseif ($payment->status === 'pending')
                    <div class="card p-6" x-data="{ mode: null }">
                        <h3 class="font-bold text-slate-900 dark:text-white">Take action</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Manual verification is required for all payments.</p>

                        <div class="mt-5 space-y-3" x-show="mode === null">
                            <button @click="mode = 'verify'" class="btn-primary w-full py-3">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                Verify payment
                            </button>
                            <button @click="mode = 'reject'" class="btn-secondary w-full py-3 !text-rose-600 dark:!text-rose-400">Reject payment</button>
                        </div>

                        {{-- Verify form --}}
                        <form x-show="mode === 'verify'" x-cloak method="POST" action="{{ route('payments.verify', $payment) }}" class="mt-5 space-y-3">
                            @csrf @method('PATCH')
                            <label class="label">Remarks (optional)</label>
                            <textarea name="remarks" rows="3" class="input" placeholder="e.g. Verified against bank statement"></textarea>
                            <div class="flex gap-2">
                                <button type="button" @click="mode = null" class="btn-secondary flex-1">Cancel</button>
                                <button type="submit" class="btn-primary flex-1">Confirm verify</button>
                            </div>
                        </form>

                        {{-- Reject form --}}
                        <form x-show="mode === 'reject'" x-cloak method="POST" action="{{ route('payments.reject', $payment) }}" class="mt-5 space-y-3">
                            @csrf @method('PATCH')
                            <label class="label">Reason for rejection <span class="text-rose-500">*</span></label>
                            <textarea name="remarks" rows="3" required class="input" placeholder="Explain why this payment is rejected"></textarea>
                            <div class="flex gap-2">
                                <button type="button" @click="mode = null" class="btn-secondary flex-1">Cancel</button>
                                <button type="submit" class="btn-primary flex-1 !bg-rose-600 hover:!bg-rose-700">Confirm reject</button>
                            </div>
                        </form>
                    </div>
                @else
                    <div class="card p-6 text-center">
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl {{ $payment->status === 'verified' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' }}">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                @if ($payment->status === 'verified')<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>@else<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>@endif
                            </svg>
                        </span>
                        <p class="mt-4 font-bold text-slate-900 dark:text-white">Payment {{ $payment->status }}</p>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Processed {{ optional($payment->verified_at)->diffForHumans() }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
