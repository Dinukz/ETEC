<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * ⚠️ A REPEAT PURCHASE EARNS ITS OWN 2,500 ON THE ACCOUNT IT IS PLACED ON
 * (2026-10-22).
 *
 * Owner: "habi student register weddi hariyt enwa 2500 eth inn student or
 * consultant kenk buy kraddi 2500 calculate wen na meka fix krla denn" — a new
 * student's 2,500 arrives, but when an EXISTING student or consultant buys
 * another course, the account it is placed on gets nothing. Reported on
 * PYM-07242: Dinuka bought MASTER IN ELLIOTT WAVE placed on SCN-00297; the
 * upline (SCN-00295, Service Group 2) was paid, SCN-00297 itself was not.
 *
 * The cause was CommissionService::placeInto()'s REGISTRANT path paying once
 * per SEAT. The buyer already sat on that account from an earlier purchase, so
 * the seat read as "already paid" — even by a 2,500 that had been removed by
 * hand — and the new purchase earned that account nothing. That rule was never
 * the owner's: it was written into the 2026-08-14 crash fix ("one seat is one
 * 2,500"). The UPLINE path has paid per purchase since 2026-08-29, and the
 * Rule Audit has always expected a 2,500 on the placement account for every
 * full payment.
 *
 * Only the MONEY changes. One person still holds one seat per account, so the
 * 12-student unlock count, the seat lists and the "members" figures do not
 * move; the new 2,500 lands on the Service Group the buyer chose and settles in
 * the System Run like any other.
 *
 * ⚠️ FROM 00:00 ON 22 OCTOBER 2026, SRI LANKA TIME — NEVER BEFORE. Commission
 * Health → Repair replays every verified payment, and a hand entry carries no
 * payment_id for the engine to see: applied to history, this rule could pay a
 * repeat purchase the owner already made good by hand a second time. Today is
 * included on purpose, so Repair completes the purchase that was reported and
 * any other made today. Earlier purchases keep exactly what they were paid.
 */
class RepeatPurchaseRules
{
    /** Midnight at the start of 22 October 2026 in Sri Lanka — the day it was reported. */
    public const SINCE = '2026-10-22 00:00:00';

    public static function since(): Carbon
    {
        return Carbon::parse(self::SINCE, 'Asia/Colombo')->utc();
    }

    /** Does this payment earn per purchase on the account it is placed on? */
    public static function appliesTo(Payment $payment): bool
    {
        return ($payment->verified_at ?? now())->gte(self::since());
    }
}
