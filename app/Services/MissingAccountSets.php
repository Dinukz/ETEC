<?php

namespace App\Services;

use App\Models\Consultant;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courses bought on a free card that never opened their set of SCN accounts.
 *
 * Until 2026-09-14 PaymentService::openAccountSetForBuyer() skipped every
 * zero-value payment, so a consultant who bought a course on a free card got
 * no new accounts at all — on the live site 23 such purchases, against 141 paid
 * purchases that all had theirs. Reported as "free card dammaham user
 * management eke e accounts penwan na". New purchases are fixed at the source;
 * this opens the sets the old rule skipped, through that same method, so it
 * can never open a set a purchase would not have opened.
 */
class MissingAccountSets
{
    /** @return Collection<int, Payment> */
    public function payments(): Collection
    {
        if (! Schema::hasColumn('consultants', 'opened_by_payment_id')) {
            return collect();
        }

        return Payment::query()
            ->with('user.consultant')
            ->where('status', 'verified')
            ->where('amount', '<=', 0)
            ->where(fn ($q) => $q->whereNull('payment_type')->orWhere('payment_type', '!=', 'balance'))
            // Bought by somebody who holds a real consultant account — a free
            // card on a plain student opens nothing, now or then.
            ->whereIn('user_id', Consultant::query()
                ->whereNotNull('user_id')
                ->where('is_hidden', false)
                ->select('user_id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('consultants')
                ->whereColumn('consultants.opened_by_payment_id', 'payments.id'))
            ->orderBy('id')
            ->get()
            // ⚠️ ONLY A BUYER WHO WAS ALREADY A CONSULTANT WHEN IT WAS VERIFIED —
            // the person openAccountSetForBuyer() would have seen at that moment.
            // Somebody promoted later received their opening set at promotion
            // and must not be handed a second one now.
            ->filter(function (Payment $payment) {
                $consultant = $payment->user?->consultant;

                return $consultant
                    && ! $consultant->is_hidden
                    && $consultant->created_at
                    && $payment->verified_at
                    && $consultant->created_at->lt($payment->verified_at);
            })
            ->values();
    }

    /** Open every missing set. @return int how many purchases received their accounts */
    public function open(): int
    {
        $opened = 0;

        foreach ($this->payments() as $payment) {
            app(PaymentService::class)->openAccountSetForBuyer($payment);
            $opened++;
        }

        return $opened;
    }
}
