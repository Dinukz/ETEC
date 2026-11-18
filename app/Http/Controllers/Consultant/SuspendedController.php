<?php

namespace App\Http\Controllers\Consultant;

use App\Http\Controllers\Controller;
use App\Models\Setting;

/**
 * The locked-state page a suspended consultant is redirected to by
 * EnforceConsultantFinanceStatus. Reachable even while suspended — it is the
 * one route that middleware always allows, and it is where the consultant
 * finds out why and who to contact.
 */
class SuspendedController extends Controller
{
    public function __invoke()
    {
        $consultant = auth()->user()->consultant;

        abort_if(! $consultant, 403, 'No consultant profile found for your account.');

        // Not actually suspended (e.g. an admin already reactivated them but
        // this page is bookmarked) — send them back to work rather than
        // showing a locked page that no longer applies.
        if (! $consultant->isFinanceSuspended()) {
            return redirect()->route('consultant.dashboard');
        }

        // Cancelled excluded (2026-09-06) — a removed or forfeited row must not
        // be offered back to a suspended consultant as money still waiting.
        $heldTotal = (float) \App\Models\Commission::whereIn('consultant_id', $consultant->accountIds())
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('held_at')
            ->sum('amount');

        return view('consultant.suspended', [
            'consultant' => $consultant,
            'heldTotal' => $heldTotal,
            'contactEmail' => Setting::get('contact_email'),
        ]);
    }
}
