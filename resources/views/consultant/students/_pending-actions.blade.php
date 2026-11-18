@props(['pay', 'studentName' => null])

{{--
    Chase-up actions for a registration that is still waiting to be paid.

    Both are gated on status === 'pending' by the controller as well; the guard
    here only keeps them off rows where they would be meaningless.
--}}
@php
    $name = $studentName ?: ($pay->payment_code ?? 'this student');
    $graceDays = \App\Http\Controllers\Consultant\StudentController::GRACE_DAYS;
    $daysLeft = $pay->created_at ? $graceDays - (int) $pay->created_at->diffInDays(now()) : null;
@endphp

<div class="inline-flex items-center gap-1" x-data="{ resend: false, remove: false }">
    {{-- Re-send the registration email --}}
    <button type="button" @click="resend = true" title="Re-send registration email"
        class="grid h-8 w-8 place-items-center rounded-lg text-brand-600 transition hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        <span class="sr-only">Re-send registration email</span>
    </button>

    {{-- Delete the unpaid registration --}}
    <button type="button" @click="remove = true" title="Delete this registration"
        class="grid h-8 w-8 place-items-center rounded-lg text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
        <span class="sr-only">Delete this registration</span>
    </button>

    {{-- Re-send confirmation --}}
    <div x-show="resend" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div class="card w-full max-w-md p-6 text-left" @click.outside="resend = false">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Re-send registration email?</h3>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                The registration details for <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $name }}</span>
                will be emailed again, along with the date the registration expires.
            </p>
            <p class="mt-2 text-xs text-slate-400">
                If the student has not logged in yet, a new temporary password is issued and any earlier one stops working.
            </p>
            <form method="POST" action="{{ route('consultant.payments.resend-email', $pay) }}" class="mt-5 flex justify-end gap-3">
                @csrf
                <button type="button" class="btn-secondary" @click="resend = false">Cancel</button>
                <button type="submit" class="btn-primary">Send email</button>
            </form>
        </div>
    </div>

    {{-- Delete confirmation --}}
    <div x-show="remove" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div class="card w-full max-w-md p-6 text-left" @click.outside="remove = false">
            <h3 class="text-lg font-bold text-rose-600 dark:text-rose-400">Delete this registration?</h3>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $name }}</span>
                ({{ $pay->payment_code }}) will be permanently removed — this cannot be undone.
            </p>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Their email address becomes free to register again immediately.
            </p>
            <form method="POST" action="{{ route('consultant.payments.destroy', $pay) }}" class="mt-5 flex justify-end gap-3">
                @csrf
                @method('DELETE')
                <button type="button" class="btn-secondary" @click="remove = false">Cancel</button>
                {{-- Plain `btn` + rose, not btn-primary with an override: every
                     class here already exists in the built CSS bundle, so this
                     ships without rebuilding and re-uploading public/build. --}}
                <button type="submit" class="btn bg-rose-600 text-white shadow-sm">Delete permanently</button>
            </form>
        </div>
    </div>
</div>

@if ($daysLeft !== null)
    <div class="mt-1 text-[11px] {{ $daysLeft <= 3 ? 'font-semibold text-rose-500' : 'text-slate-400' }}">
        {{ $daysLeft > 0 ? $daysLeft.' day'.($daysLeft === 1 ? '' : 's').' left before auto-delete' : 'Past the '.$graceDays.'-day window' }}
    </div>
@endif
