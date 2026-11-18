@props(['pay'])

<div x-data="{ open: false }" class="inline-block">
    <button type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="open = true">Confirm Payment</button>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div class="card w-full max-w-md p-6" @click.outside="open = false">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Confirm Payment</h3>
            <p class="mt-1 font-mono text-xs text-slate-400">{{ $pay->payment_code }}</p>

            <form method="POST" action="{{ route('consultant.payments.confirm', $pay) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf
                @method('PATCH')
                <div>
                    {{-- Each plan's amount for THIS student's course (2026-10-22):
                         a course may have its own first instalment (Elliott Wave:
                         20,000 now, 19,000 later). A course removed since shows
                         the plain labels. --}}
                    @php
                        $planCourse = $pay->course;
                        $fullDue = $planCourse ? \App\Services\EnrollmentService::amountForType($planCourse, 'full') : null;
                        $halfDue = $planCourse ? \App\Services\EnrollmentService::amountForType($planCourse, 'half') : null;
                    @endphp
                    <label class="label">Payment Type</label>
                    <select name="payment_type" required class="input">
                        <option value="">Select payment type</option>
                        <option value="full">Full payment{{ $fullDue !== null ? ' — LKR '.number_format($fullDue) : '' }}</option>
                        <option value="half">Half payment{{ $halfDue !== null ? ' — LKR '.number_format($halfDue).' now, LKR '.number_format(max(0, $fullDue - $halfDue)).' later' : '' }}</option>
                        <option value="free_card">Free card</option>
                    </select>
                </div>
                <div>
                    {{-- The date on the slip, not the moment this form happens to be
                         submitted — defaults to today. See the partial for the
                         yesterday / today / tomorrow rule (2026-09-22). --}}
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
                    <button type="submit" class="btn-primary" @disabled(\App\Support\ConsultantPaymentDates::allowed() === [])>Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>
