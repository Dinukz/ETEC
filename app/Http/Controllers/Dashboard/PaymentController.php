<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Concerns\FiltersByDateRange;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    use ExportsCsv, FiltersByDateRange;

    public function __construct(private PaymentService $payments)
    {
    }

    public function index(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        // The cards follow the date filter as well — leaving them on all-time
        // totals next to a filtered table just reads as a contradiction.
        $inRange = fn () => $this->applyDateRange(Payment::query(), $request, 'paid_at');

        $stats = [
            'pending' => $inRange()->where('status', 'pending')->count(),
            'verified' => $inRange()->where('status', 'verified')->count(),
            'rejected' => $inRange()->where('status', 'rejected')->count(),
            // ⚠️ NULL FOR A GROUP SUPPORTER (2026-09-17): "Total Verified amount
            // eka ... hide krann one group supporter ta withrk". Not computed at
            // all rather than computed and hidden, so the figure never reaches
            // their page.
            'total_verified' => $request->user()?->hasRole('group_supporter')
                ? null
                : (float) $inRange()->where('status', 'verified')->sum('amount'),
        ];

        $query = Payment::query()
            // consultant.owner.user is needed too: a payment is often attributed
            // to a hidden placement account (no user of its own) — display_name
            // falls back to that account's real owner.
            ->with(['user', 'course', 'consultant.user', 'consultant.owner.user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Default view: payments awaiting verification. Excludes registrations
            // whose consultant hasn't confirmed a payment plan yet — those aren't
            // actionable here until confirmed (see Consultant "Pending Students").
            ->when(! $request->filled('status'), fn ($q) => $q->where('status', 'pending')->whereNotNull('payment_type'))
            // Name, email, code, SRN and SCN — the same search as the queue. See
            // Payment::scopeSearch(), which also groups it so it cannot escape
            // the status filter above (this one used to).
            ->search((string) $request->input('q', ''))
            ->latest('paid_at');

        // Filtered on the date the student paid, which is what the list is
        // sorted by and what "Paid At" shows.
        $this->applyDateRange($query, $request, 'paid_at');

        if ($request->input('export') === 'csv') {
            return $this->csvResponse(
                'payments-'.now()->format('Y-m-d').'.csv',
                ['Code', 'Student', 'Email', 'Course', 'Consultant', 'Amount', 'Method', 'Status', 'Paid At', 'Verified At'],
                $query->get()->map(fn ($p) => [
                    $p->payment_code, $p->user->name ?? '—', $p->user->email ?? '—',
                    $p->course->title ?? '—', $p->consultant?->display_name ?? '—',
                    $p->amount, $p->method, $p->status,
                    optional($p->paid_at)->toDateTimeString(), optional($p->verified_at)->toDateTimeString(),
                ]),
            );
        }

        // ⚠️ `payments` IS NO LONGER WHAT THE PAGE DRAWS, AND IT STILL HAS TO
        // BE HERE. Since 2026-09-10 the list and the review popup are rendered
        // by App\Livewire\PaymentQueue, which runs its own copy of this query.
        // This one is kept because four DateRangeFilterTest cases read it out
        // of the view data, and because it is the shape the CSV export above
        // already shares. One extra paginated 15-row query per page view,
        // knowingly paid rather than rewriting a passing test to suit new code.
        $payments = $query->paginate(15)->withQueryString();

        return view('dashboard.payments.index', compact('payments', 'stats', 'from', 'to'));
    }

    public function show(Payment $payment)
    {
        $payment->load(['user', 'course', 'consultant.user', 'consultant.owner.user', 'enrollment', 'verifications.verifier']);

        return view('dashboard.payments.show', compact('payment'));
    }

    /**
     * Stream the receipt straight from the storage disk instead of linking to
     * the public/storage symlink — shared hosts frequently lack (or break)
     * that symlink with no CLI access to recreate it, which was surfacing as
     * a 403 for accountants trying to view proof. This route also keeps the
     * file behind auth instead of a guessable public URL.
     */
    public function receipt(Payment $payment): StreamedResponse
    {
        abort_if(! $payment->receipt_path, 404);
        abort_unless(Storage::disk('public')->exists($payment->receipt_path), 404);

        return Storage::disk('public')->response($payment->receipt_path);
    }

    public function verify(Request $request, Payment $payment)
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);

        if ($payment->status !== 'pending') {
            return back()->with('error', 'This payment has already been processed.');
        }

        if (! $payment->payment_type) {
            return back()->with('error', 'The consultant hasn\'t confirmed a payment plan for this registration yet.');
        }

        $this->payments->verify($payment, $request->user(), $data['remarks'] ?? null);

        return back()->with('success', "Payment {$payment->payment_code} verified. Enrollment activated and commission recorded.");
    }

    public function reject(Request $request, Payment $payment)
    {
        $data = $request->validate(['remarks' => ['required', 'string', 'max:500']]);

        if ($payment->status !== 'pending') {
            return back()->with('error', 'This payment has already been processed.');
        }

        $this->payments->reject($payment, $request->user(), $data['remarks']);

        return back()->with('success', "Payment {$payment->payment_code} rejected.");
    }
}
