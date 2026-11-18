{{-- The Payment Date box on a consultant's payment forms (2026-09-22).
     Only yesterday, today and tomorrow, and never a day a System Run already
     settled — ConsultantPaymentDates. The server checks the same list; this box
     only mirrors it. $current: an existing date to keep when it is still allowed. --}}
@php
    $payDates = \App\Support\ConsultantPaymentDates::allowed();
    $today = \Illuminate\Support\Carbon::now('Asia/Colombo')->toDateString();
    $payDefault = in_array($current ?? null, $payDates, true)
        ? $current
        : (in_array($today, $payDates, true) ? $today : ($payDates[0] ?? ''));
@endphp
<label class="label">Payment Date</label>
@if ($payDates)
    <input name="payment_date" type="date" required value="{{ $payDefault }}"
        min="{{ end($payDates) }}" max="{{ $payDates[0] }}" class="input">
    <p class="mt-1 text-xs text-slate-400">Yesterday, today or tomorrow. Days already settled by a System Run are closed.</p>
@else
    <input name="payment_date" type="date" disabled class="input">
    <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ \App\Support\ConsultantPaymentDates::message() }}</p>
@endif
