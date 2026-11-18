<?php

namespace App\Livewire;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The payment verification queue, actioned without leaving the page.
 *
 * Asked for 2026-09-10: *"mta awshyai review denkot eka wen page ehekt ywan
 * nathiw browes popup ehekin load wenn hadann... popup ek verify kraham
 * livewire walin page ekama refresh kran nathiw payment verify wenn hadal denn
 * filters dala thiyenwa nm eka refresh wen nathiw thiyenn one."*
 *
 * Reported again after the first upload: Review still navigated to
 * /payments/{id}. That was because this component did not exist yet.
 *
 * ⚠️ WHAT THIS DELIBERATELY DOES NOT OWN.
 * The four stat cards above the table stay in PaymentController. That was the
 * owner's choice, and it has a consequence that must be told rather than
 * hidden: after a verify here, those counts are stale until the page is
 * reloaded. The result banner says so in words. Do NOT "fix" it by dispatching
 * a browser event at them — that is the exact 2026-09-06 Service Income bug,
 * where an event was dispatched that nothing listened for and the screen
 * silently disagreed with the database.
 *
 * ⚠️ THE ROW LIST IS RENDERED TWICE ON FIRST LOAD.
 * PaymentController still computes and passes `payments`, because four
 * DateRangeFilterTest cases read it out of the view data and because it is the
 * no-JavaScript fallback. That is one extra paginated 15-row query per page
 * view, knowingly paid to keep the old path intact on a live screen with 730
 * pending payments.
 */
class PaymentQueue extends Component
{
    use WithPagination;

    /**
     * ⚠️ #[Url] IS THE WHOLE ANSWER TO "filters must not reset".
     *
     * These live in the query string, so a verify — which is an AJAX round
     * trip, not a navigation — cannot clear them, the Back button still works,
     * and a filtered queue is still a shareable link.
     */
    #[Url(except: '')]
    public string $q = '';

    /** Default 'pending': the queue exists to be worked through. */
    #[Url(except: 'pending')]
    public string $status = 'pending';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** The payment open in the modal, or null. */
    public ?int $selectedId = null;

    /** Detail for the open payment. Scalars only — a public prop is serialised into the page. */
    public array $detail = [];

    public string $remarks = '';

    /** What just happened, shown as a banner inside the modal. */
    public ?string $result = null;

    public ?string $problem = null;

    /**
     * ⚠️ AUTHORISE INSIDE EVERY ACTION — the route middleware does NOT run here.
     *
     * /payments sits behind a role check, but a Livewire action arrives on
     * Livewire's own update endpoint carrying only the framework's middleware.
     * Without this, any logged-in user could post a payment id and verify it.
     * Same warning, same reason, as App\Livewire\ServiceIncomeBoard.
     */
    protected function authorizeStaff(): void
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && $user->canActionPayments(),
            403,
            'You are not allowed to action payments.',
        );
    }

    /**
     * Opening a payment to LOOK at it. Wider than authorizeStaff() by exactly
     * one role: a group supporter (2026-09-17) may review every payment but
     * never verify or reject one.
     */
    protected function authorizeViewer(): void
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && ($user->canActionPayments() || $user->hasRole('group_supporter')),
            403,
            'You are not allowed to view payments.',
        );
    }

    // ----- filters -----------------------------------------------------------

    /** Any filter change starts again at page one, or the list looks empty. */
    public function updated($property): void
    {
        if (in_array($property, ['q', 'status', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->q = '';
        $this->status = 'pending';
        $this->from = '';
        $this->to = '';
        $this->resetPage();
    }

    /** A filter date, or null when absent or unparseable — never an error. */
    protected function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The queue, filtered.
     *
     * ⚠️ Kept identical to PaymentController::index(), including the default
     * whereNotNull('payment_type') — without it the queue fills with
     * registrations whose consultant has not yet confirmed a plan, which are
     * not actionable here and which a test pins.
     */
    protected function rows()
    {
        [$from, $to] = [$this->parseDate($this->from), $this->parseDate($this->to)];

        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $term = trim($this->q);

        return Payment::query()
            // consultant.owner.user is needed too: a payment is often attributed
            // to a hidden placement account with no user of its own, and
            // display_name falls back to that account's real owner.
            // user.student / user.consultant: the SRN / SCN shown under the name.
            // requestedBy: who paid, when not the account's holder (2026-11-03,
            // RegistrationPayer) — no key and no query on every other row.
            ->with(['user.student', 'user.consultant', 'course', 'consultant.user', 'consultant.owner.user', 'requestedBy'])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->status === 'pending', fn ($query) => $query->whereNotNull('payment_type'))
            // Name, email, code, SRN and SCN — see Payment::scopeSearch().
            ->search($term)
            ->when($from, fn ($query) => $query->whereDate('paid_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('paid_at', '<=', $to))
            ->latest('paid_at')
            ->paginate(15);
    }

    // ----- the modal ---------------------------------------------------------

    public function open(int $paymentId): void
    {
        $this->authorizeViewer();
        $this->reset(['detail', 'remarks', 'result', 'problem']);

        $this->selectedId = $paymentId;
        $this->detail = $this->detailFor($paymentId);
    }

    public function close(): void
    {
        $this->reset(['selectedId', 'detail', 'remarks', 'result', 'problem']);
    }

    /** @return array<string, mixed> */
    protected function detailFor(int $paymentId): array
    {
        $payment = Payment::with([
            'user', 'course', 'consultant.user', 'consultant.owner.user', 'verifications.verifier', 'requestedBy.user',
        ])->find($paymentId);

        if (! $payment) {
            return [];
        }

        $receipt = $payment->receipt_path;
        $extension = $receipt ? strtolower(pathinfo($receipt, PATHINFO_EXTENSION)) : null;

        return [
            'id' => $payment->id,
            'code' => $payment->payment_code,
            'student' => $payment->user->name ?? '—',
            'email' => $payment->user->email ?? '—',
            'course' => $payment->course->title ?? '—',
            'method' => ucfirst((string) $payment->method),
            'type' => $payment->payment_type ? \Illuminate\Support\Str::headline($payment->payment_type) : '—',
            'reference' => $payment->reference_number ?? '—',
            // ⚠️ NOT "Direct signup". ConsultantNameDisplayTest asserts the
            // payments INDEX never contains that string, and this modal now
            // renders on the index.
            'consultant' => $payment->consultant?->display_name ?? '—',
            // The consultant who registered it on that account and paid, when not
            // its holder (2026-11-03) — see RegistrationPayer. Null otherwise.
            'paid_by' => $payment->requestedBy
                ? ($payment->requestedBy->scn_code ?: $payment->requestedBy->consultant_code)
                    .($payment->requestedBy->user?->name ? ' — '.$payment->requestedBy->user->name : '')
                : null,
            'amount' => (float) $payment->amount,
            'status' => $payment->status,
            'notes' => $payment->notes,
            'paid_at' => optional($payment->paid_at)->format('F d, Y \a\t g:i A'),
            'receipt_url' => $receipt ? route('payments.receipt', $payment) : null,
            // ⚠️ A payslip can be a PDF — uploads accept pdf,png,jpg,jpeg.
            // `transform: scale` means nothing for a PDF, so the view branches
            // on this rather than putting everything in an <img>.
            'receipt_is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true),
            'receipt_kind' => $extension ? strtoupper($extension) : null,
            'page_url' => route('payments.show', $payment),
            'history' => $payment->verifications->map(fn ($v) => [
                'action' => ucfirst((string) $v->action),
                'by' => $v->verifier->name ?? 'Unknown',
                'when' => optional($v->verified_at)->diffForHumans(),
                'remarks' => $v->remarks,
            ])->all(),
        ];
    }

    // ----- verify and reject -------------------------------------------------

    /**
     * ⚠️ DELEGATES TO PaymentService — never reimplements.
     *
     * verify() runs a schema repair, then a row-locked transaction holding the
     * whole commission engine, then possibly opens a new SCN account set, then
     * sends two emails synchronously. It takes seconds. The button is disabled
     * while it runs, and the service's lockForUpdate makes a second click
     * block rather than double-pay.
     */
    public function verifyPayment(): void
    {
        $this->authorizeStaff();

        $payment = $this->pendingPayment();

        if (! $payment) {
            return;
        }

        app(PaymentService::class)->verify($payment, auth()->user(), $this->remarks ?: null);

        $this->afterAction($payment->payment_code.' verified. Enrollment activated and commission recorded.');
    }

    public function rejectPayment(): void
    {
        $this->authorizeStaff();

        if (trim($this->remarks) === '') {
            $this->problem = 'A reason is required to reject a payment.';

            return;
        }

        $payment = $this->pendingPayment();

        if (! $payment) {
            return;
        }

        app(PaymentService::class)->reject($payment, auth()->user(), trim($this->remarks));

        $this->afterAction($payment->payment_code.' rejected. The consultant can attach a corrected slip.');
    }

    /** The open payment if it is still actionable; otherwise sets $problem and returns null. */
    protected function pendingPayment(): ?Payment
    {
        $payment = $this->selectedId ? Payment::find($this->selectedId) : null;

        if (! $payment) {
            $this->problem = 'That payment no longer exists.';

            return null;
        }

        // Both guards the controller has, kept word for word.
        if ($payment->status !== 'pending') {
            $this->problem = 'This payment has already been processed.';

            return null;
        }

        if (! $payment->payment_type) {
            $this->problem = 'The consultant hasn\'t confirmed a payment plan for this registration yet.';

            return null;
        }

        return $payment;
    }

    protected function afterAction(string $message): void
    {
        $this->remarks = '';
        $this->problem = null;
        $this->result = $message;

        // Re-read so the modal and the row behind it both show the new status.
        // The stat cards cannot follow — see the class docblock.
        $this->detail = $this->detailFor((int) $this->selectedId);
    }

    public function render()
    {
        return view('livewire.payment-queue', [
            'payments' => $this->rows(),
            // Hides Verify / Reject for a view-only group supporter. The buttons
            // are only the visible half — the actions refuse them regardless.
            'canAction' => (bool) auth()->user()?->canActionPayments(),
        ]);
    }
}
