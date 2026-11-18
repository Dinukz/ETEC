<?php

namespace App\Services;

use App\Models\Consultant;
use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠️ WHO PAYS FOR A REGISTRATION ON SOMEBODY ELSE'S ACCOUNT (2026-11-03).
 *
 * The owner's example: SCN-112 registers a student on SCN-132, an account of
 * SCN-130 who hangs in 112's group. "Consultant (Requested By)" must STAY 112 —
 * the form used to flip it to 130 the moment 132 was picked, "eka wardi" — and
 * 112 PAYS: confirms the plan, attaches and re-attaches the slip, pays the
 * balance. 130 still sees the student (it is on his account and counts for his
 * weekly quota, the owner's answer) but view only, marked "Paid by SCN-112".
 *
 * ⚠️ THE 2,500 DOES NOT MOVE. The climb, the weekly quota, promotion and the
 * System Run all read the PLACEMENT (payments.consultant_id /
 * students.consultant_id): the 2,500 climbs 132 -> 130 -> the 112 account 130
 * hangs under -> 112 -> above, exactly as if 130 had registered the student.
 * Never make the climb read this column: its "registrant" is the placement's
 * holder, and starting it from the payer would stop the climb at 113.
 *
 * ⚠️ THE DIRECT BONUS GOES TO THE PAYER (owner, 2026-11-16): "direct bonus eka
 * … Consultant (Requested By) add wena widiytma". The ONE place the engine reads
 * this column is directEarner() — the 4,000 (or the course's own amount) lands
 * on 112's main instead of on 132. Nothing else about the money changes.
 *
 * ⚠️ STORED ONLY WHEN THE PAYER IS NOT THE ACCOUNT'S OWN HOLDER. Every payment
 * before this date, and every registration since on one's own account, keeps
 * NULL and behaves byte for byte as before — the column is not even created
 * until the first registration that needs it.
 *
 * Same self-heal pattern as CourseSchemaRepair: production has no CLI, and no
 * migration file (Commission Health reports a migration the live ledger never
 * ran as "not applied", forever). ⚠️ NEVER CALL heal()/ensure() INSIDE AN OPEN
 * TRANSACTION — ALTER auto-commits under MySQL (see PaymentService::verify()).
 *
 * ⚠️ NOT MASS-ASSIGNABLE ON PURPOSE. The column is written only with
 * forceFill() (StudentController::store() and payBalance(), EnrollmentService),
 * so an older Payment.php left on the server can never silently drop the
 * payer, and the audit log of every other payment stays exactly as it was.
 */
class RegistrationPayer
{
    public const COLUMN = 'requested_by_consultant_id';

    private const CACHE_KEY = 'payments.payer.ready';

    private static ?bool $memo = null;

    public static function forgetMemo(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    public static function ready(): bool
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            return self::$memo = (bool) Cache::remember(
                self::CACHE_KEY,
                now()->addMinutes(10),
                fn () => Schema::hasColumn('payments', self::COLUMN),
            );
        } catch (\Throwable) {
            return self::$memo = false;
        }
    }

    /** The cheap call: repair only when not known to be ready. */
    public static function heal(): void
    {
        if (! self::ready()) {
            self::ensure();
        }
    }

    /**
     * Always looks at the real column (never trusts the memo), then resets it.
     *
     * @return array<int, string> one line per change
     */
    public static function ensure(): array
    {
        $log = [];

        try {
            if (Schema::hasTable('payments') && ! Schema::hasColumn('payments', self::COLUMN)) {
                Schema::table('payments', function (Blueprint $table) {
                    // A PERSON — the payer's main consultant id. No foreign key on
                    // purpose: a deleted payer leaves an id that payerOf() reads as
                    // "nobody", rather than taking the payment with it.
                    $table->unsignedBigInteger(self::COLUMN)->nullable();
                    $table->index(self::COLUMN);
                });

                $log[] = 'payments: added '.self::COLUMN.'.';
            }
        } catch (\Throwable $e) {
            report($e);
            $log[] = 'payment payer column: could not be repaired — '.$e->getMessage();
        }

        self::forgetMemo();

        return $log;
    }

    /**
     * The consultant paying for this registration, or null when its holder
     * pays — which is every payment with nothing recorded, AND one whose payer
     * can no longer act at all, so a registration is never stranded with
     * nobody allowed to pay: the row gone, a hidden account, the login deleted,
     * finance-suspended (StudentController::consultant() refuses every payment
     * action) or no longer a consultant (role:consultant). The holder's own
     * rule takes over, and hands back if the payer can act again.
     *
     * ⚠️ ONE DEFINITION for the action guards and for the Pending Students
     * table, so the buttons shown always match what the server allows.
     */
    public static function payerOf(Payment $payment): ?Consultant
    {
        // An absent column is an absent attribute: null, and no query.
        $id = (int) ($payment->getAttribute(self::COLUMN) ?? 0);

        if ($id === 0) {
            return null;
        }

        $payer = $payment->relationLoaded('requestedBy')
            ? $payment->getRelation('requestedBy')
            : Consultant::with('user.roles')->find($id);

        if (! $payer instanceof Consultant || $payer->is_hidden) {
            return null;
        }

        // Explicit, so it is never a lazy load inside a list.
        $payer->loadMissing('user.roles');

        if ($payer->user === null || $payer->isFinanceSuspended() || ! $payer->user->hasRole('consultant')) {
            return null;
        }

        return $payer;
    }

    /**
     * ⚠️ WHO EARNS THE DIRECT BONUS ON THIS PAYMENT, WHEN IT IS NOT THE
     * PLACEMENT (2026-11-16).
     *
     * The owner: "direct bonus eka haduwe Partner Consultant waten widiyt ned eka
     * aye hadnn weno Consultant (Requested By) add wena widiytma" — SCN-112
     * registers on SCN-132 (an account of downline SCN-130): the direct bonus is
     * 112's, while the 2,500 still climbs from 132 through 130 and 112 ("2500
     * dentama cal weno").
     *
     * Returns the Requested By consultant recorded on the payment, or null when
     * the old rule applies — nothing recorded (every payment before this feature
     * and every registration on one's own account), the column not there yet,
     * the payer's row gone, their login deleted, no longer a consultant, or a
     * payer who resolves to the placement's own person. Null = CommissionService
     * pays the placement, exactly as before.
     *
     * ⚠️ NOT payerOf(). That one answers "who may press the buttons" and also
     * hands them to the holder while the payer is finance-SUSPENDED, so a
     * registration is never stranded. A suspension does not move money: a
     * suspended payer's direct is still theirs, exactly as a suspended holder's
     * is today (approval and payout check the live status on their own).
     */
    public static function directEarner(Payment $payment, Consultant $placement): ?Consultant
    {
        // An absent column is an absent attribute: null, and no query.
        $id = (int) ($payment->getAttribute(self::COLUMN) ?? 0);

        if ($id === 0) {
            return null;
        }

        $payer = Consultant::with(['owner', 'user.roles'])->find($id);

        if (! $payer instanceof Consultant || $payer->is_hidden) {
            return null;
        }

        // A deleted login reads as no user (soft deletes); a person who is no
        // longer a consultant earns no consultant income.
        if ($payer->user === null || ! $payer->user->hasRole('consultant')) {
            return null;
        }

        if ((int) $payer->owningPerson()->id === (int) $placement->owningPerson()->id) {
            return null;
        }

        return $payer;
    }

    /**
     * May $actor confirm / re-attach / pay the balance / re-send / delete?
     *
     * ⚠️ TODAY'S RULE STILL APPLIES FIRST, IT IS NOT REPLACED. A recorded payer
     * only NARROWS it — to the payer and the people above them, the only ones
     * whose reach contains the payer — so this column can never let anyone act
     * on a payment they could not act on before 2026-11-03.
     */
    public static function canHandle(Consultant $actor, Payment $payment): bool
    {
        if ($payment->consultant_id === null) {
            return false;
        }

        $reach = array_map('intval', $actor->reachableAccountIds());

        if (! in_array((int) $payment->consultant_id, $reach, true)) {
            return false;
        }

        $payer = self::payerOf($payment);

        return $payer === null || in_array((int) $payer->id, $reach, true);
    }

    /**
     * Every payment $payer pays for on somebody else's account. Empty, and no
     * query, until the column exists — nobody can be paying for anything before.
     *
     * @return Collection<int, object{id: int, user_id: int, consultant_id: int, status: string}>
     */
    public static function paidBy(Consultant $payer): Collection
    {
        if (! self::ready()) {
            return collect();
        }

        try {
            return Payment::query()
                ->where(self::COLUMN, $payer->id)
                ->whereNotNull('consultant_id')
                ->toBase()
                ->get(['id', 'user_id', 'consultant_id', 'status']);
        } catch (\Throwable $e) {
            // A cached "ready" outliving the column — a database restored from
            // an older backup. Forget it and list nothing: this runs on every
            // consultant's Students and Pending pages, which must never 500.
            report($e);
            self::forgetMemo();

            return collect();
        }
    }
}
