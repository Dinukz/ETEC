<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * WHEN THE SEPTEMBER 2026 CLIMB RULES START — so history is never paid twice.
 *
 * Two rules changed together (2026-09-15/16), both on a consultant who buys a
 * course placed on their OWN DOWNLINE'S account:
 *   1. the upline climb no longer stops at the buyer's accounts
 *      (CommissionService::creditUpline), and
 *   2. the set that purchase opens hangs inside the buyer's own tree, so its
 *      students reach the buyer's main (AccountPlacementMap::ownAccountAbove).
 *
 * ⚠️ THE OWNER ADDED THE MISSING HISTORY BY HAND (2026-09-17): "okkom commission
 * tika manual add kre". A hand entry carries no payment_id, so the engine's
 * per-payment guards cannot see it. Applied to old payments, the new rules would
 * make the next Run Repair mint every one of those rows a SECOND time.
 *
 * So each rule applies only to a payment verified from the moment this build
 * first ran. That moment is recorded once, the first time it is asked for, and
 * never moves — a payment verified before it keeps exactly the climb it got.
 */
class ClimbRules
{
    public const KEY = 'climb_rules_2026_09_since';

    /** The moment the new rules began. Recorded on first use. */
    public static function since(): Carbon
    {
        try {
            $stored = Setting::get(self::KEY);

            if (! $stored) {
                // A minute early, so the very verification that records this
                // moment — its verified_at is stamped an instant before the
                // engine runs — is not itself mistaken for history.
                $stored = now()->subMinute()->toDateTimeString();
                Setting::set(self::KEY, $stored, 'string', 'internal');
            }

            return Carbon::parse($stored);
        } catch (\Throwable $e) {
            report($e);

            // Unreadable settings: treat everything before now as history. The
            // cost is a new payment climbing the old way; the alternative is
            // paying the owner's hand entries twice.
            return now()->subMinute();
        }
    }

    /** Does this payment climb by the new rules? */
    public static function appliesTo(Payment $payment): bool
    {
        return ($payment->verified_at ?? now())->gte(self::since());
    }
}
