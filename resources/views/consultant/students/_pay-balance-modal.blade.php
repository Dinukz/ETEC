@props(['pay', 'balance'])

<div x-data="{ open: false }" class="inline-block">
    <button type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="open = true">Pay Balance</button>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div class="card w-full max-w-md p-6" @click.outside="open = false">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Pay Balance</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Balance due: <span class="font-bold text-slate-900 dark:text-white">LKR {{ number_format($balance, 2) }}</span>
            </p>

            <form method="POST" action="{{ route('consultant.payments.pay-balance', $pay) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf
                <div>
                    @include('consultant.students._payment-date-field')
                </div>
                <div>
                    <label class="label">Payment Reference <span class="font-normal text-slate-400">(optional)</span></label>
                    <input name="payment_reference_number" type="text" class="input" placeholder="Slip / transaction number">
                </div>
                <div>
                    <label class="label">Payment Receipt <span class="font-normal text-slate-400">(optional)</span></label>
                    <input name="payment_receipt" type="file" accept=".pdf,.png,.jpg,.jpeg" class="input">
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                    <button type="submit" class="btn-primary" @disabled(\App\Support\ConsultantPaymentDates::allowed() === [])>Record Balance Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
