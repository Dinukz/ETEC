{{-- ⚠️ A CERTAINLY-WRONG EMAIL (2026-11-10): mail to it only bounces, and the
     bounces got the site's mailbox suspended. Nothing is sent to it any more
     (TransactionalMail skips it) until it is corrected — see EmailAddressCheck. --}}
@php
    $emailProblem = \App\Support\EmailAddressCheck::problem($email);
    $emailSuggestion = $emailProblem ? \App\Support\EmailAddressCheck::suggestion($email) : null;
@endphp
@if ($emailProblem)
    <div class="truncate text-xs font-medium text-rose-600 dark:text-rose-400" title="{{ $emailProblem }}">
        {{ $emailSuggestion ? 'Mistyped — did you mean '.$emailSuggestion.'?' : 'Mistyped email — mail to it cannot arrive' }}
    </div>
@endif
