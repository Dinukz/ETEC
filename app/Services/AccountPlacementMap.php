<?php

namespace App\Services;

use App\Models\Consultant;
use App\Models\Payment;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE EACH OF A CONSULTANT'S SCN ACCOUNTS HANGS — the provenance of an account.
 *
 * Requested 2026-08-22: "SCN account mokatad aithi wenne kiyala placement krla
 * thiyenne mokind kiyla... SCN 133 aithi wenne placement krla thiyenne SCN 123
 * ta, e wage 4th and 7th and issrahat buy krana cource aithi wenne mokatd
 * kiyla penwann one."
 *
 * The accounts list showed nine bare SCN codes with nothing saying how they
 * relate, so once a consultant owned more than the opening three there was no
 * way to tell from the screen which purchase produced which accounts or which
 * account a bought set was placed under. Every fact needed was already stored;
 * none of it was surfaced.
 *
 * Accounts arrive in sets of three and the answer differs by position:
 *
 *   #1, #2       the OPENING pair, created at promotion. They sit directly
 *                under the person's own main account, as its leg 1 and leg 2.
 *   #3, #6, #9…  a SET-MAIN. This is the one the buyer PLACED — its position is
 *                whatever they chose at checkout, recorded on the payment that
 *                opened the set.
 *   #4, #5, …    the two legs of the set above them. They sit inside their own
 *                set-main, as its leg 1 and leg 2.
 *
 * So only the set-main needs looking up; the rest is structural. That lookup
 * goes through `consultants.opened_by_payment_id` (written when a consultant's
 * course purchase is verified) to `payments.consultant_id` + `requested_tier` —
 * the account and Service Group picked on the checkout form.
 *
 * ⚠️ Batched deliberately. Sets continue indefinitely — 7/8/9, 10/11/12 and on —
 * so a per-row lookup would grow with every course a consultant ever buys. This
 * resolves any number of accounts in four queries.
 *
 * @see Consultant::serviceGroupLeg() for the matching leg mapping — the two
 *      derive position the same way and must stay in step.
 */
class AccountPlacementMap
{
    /**
     * @return array<int, array{under_id: ?int, under_code: ?string, tier: ?int, kind: string}>
     *
     * `kind` is one of:
     *   main    — the person's own account; the root, placed under nobody here
     *   opening — one of the two accounts created with them
     *   set-leg — a leg inside a bought set's own set-main
     *   bought  — a set-main, placed under one of the buyer's OWN accounts
     *   bought-foreign — a set-main whose purchase was attributed to an account
     *             the buyer does not hold. Not a parent: a warning.
     *   unknown — a set-main with no payment on record (pre-dates the marker,
     *             or was opened one-at-a-time by an admin)
     */
    public function for(Consultant $consultant, bool $placeDownlineSets = true): array
    {
        $owner = $consultant->owningPerson();

        $columns = ['id', 'scn_code', 'consultant_code', 'owner_consultant_id', 'opened_by_payment_id'];

        // ⚠️ SELECTED CONDITIONALLY. These two carry an admin's explicit
        // placement correction (see overrideFor), and they are added at runtime
        // by FinanceSchemaRepair rather than by a migration — so on a database
        // that has not healed yet, naming them here would throw "no such
        // column" and take out every Service Groups page. Absent simply means
        // no correction exists, which is the correct reading.
        foreach (['placed_under_consultant_id', 'placement_tier'] as $optional) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('consultants', $optional)) {
                $columns[] = $optional;
            }
        }

        $hidden = Consultant::where('owner_consultant_id', $owner->id)
            ->where('is_hidden', true)
            ->orderBy('id')
            ->get($columns)
            ->values();

        $map = [
            $owner->id => [
                'under_id' => null,
                'under_code' => null,
                'tier' => null,
                'kind' => 'main',
            ],
        ];

        [$payments, $targets] = $this->boughtPlacements($hidden);

        foreach ($hidden as $index => $account) {
            $ordinal = $index + 1;

            // The opening pair are the person's own two legs.
            if ($ordinal <= 2) {
                $map[$account->id] = [
                    'under_id' => $owner->id,
                    'under_code' => $this->codeOf($owner),
                    'tier' => $ordinal,
                    'kind' => 'opening',
                ];

                continue;
            }

            $inSet = ($ordinal - 3) % 3;

            // A leg of the set above it — structural, no lookup needed.
            if ($inSet !== 0) {
                $setMain = $hidden[$index - $inSet] ?? null;

                $map[$account->id] = [
                    'under_id' => $setMain?->id,
                    'under_code' => $this->codeOf($setMain),
                    'tier' => $inSet,
                    'kind' => 'set-leg',
                ];

                continue;
            }

            // A set-main: the only account whose position was CHOSEN.
            //
            // ⚠️ AN EXPLICIT OVERRIDE WINS (2026-08-31). When a purchase was
            // made with no placement picked, the derived position below lands
            // on the main or on nothing, and the climb dies there — the
            // SCN-00122 report. An admin can re-point such an account, and that
            // correction is stored on the account rather than by rewriting who
            // earned the payment. See FinanceSchemaRepair.
            $override = $this->overrideFor($account, $hidden, $owner);

            if ($override) {
                $map[$account->id] = $override;

                continue;
            }

            $payment = $payments[$account->opened_by_payment_id ?? 0] ?? null;
            $target = $payment ? ($targets[$payment->consultant_id] ?? null) : null;

            if (! $target) {
                $map[$account->id] = [
                    'under_id' => null,
                    'under_code' => null,
                    'tier' => null,
                    'kind' => 'unknown',
                ];

                continue;
            }

            // ⚠️⚠️ A FOREIGN ACCOUNT INSIDE THE BUYER'S OWN DOWNLINE (2026-09-16).
            //
            // SCN-00229 bought SCN-00442's set on SCN-00432 — chandima's
            // account, and chandima sits inside SCN-00230, SCN-00229's own
            // Service Group 1. Labelled foreign below, the set had no parent in
            // the owner's tree, so every student placed in it climbed to the
            // set-main and then skipped the owner's main entirely (the sponsor
            // walk starts ABOVE the main): "4th account eken add krapu student
            // denekge 2500k ... 5000k add wela na main ekat".
            //
            // Such a set is hung under the buyer's OWN account that downline is
            // inside, on the side the downline occupies — where the owner
            // understands it was bought ("4th eka buy krla thiyenne 2nd account
            // eken"). The downline itself earns nothing from the set's students.
            // A set bought on an UPLINE's or a stranger's account never reaches
            // the buyer's tree on this climb, so it stays foreign exactly as
            // before. On the live dump that day all 7 foreign sets were of the
            // downline kind, and none of the other kind existed.
            //
            // $placeDownlineSets is false only when the commission engine is
            // replaying a payment verified BEFORE this rule — the owner added
            // that history by hand, so it must climb as it always did. See
            // ClimbRules. Every screen keeps the default.
            if ($placeDownlineSets && ! $this->belongsTo($target, $owner)) {
                $inOwnTree = $this->ownAccountAbove($target, $owner);

                if ($inOwnTree) {
                    $map[$account->id] = [
                        'under_id' => $inOwnTree['account']->id,
                        'under_code' => $this->codeOf($inOwnTree['account']),
                        'tier' => $inOwnTree['leg'],
                        'kind' => 'bought',
                        // Where the purchase itself was credited, for display.
                        'bought_on_code' => $this->codeOf($target),
                    ];

                    continue;
                }
            }

            $map[$account->id] = [
                'under_id' => $target->id,
                'under_code' => $this->codeOf($target),
                // requested_tier is the leg INSIDE the chosen account. It can
                // legitimately be absent on an older placement, so the view
                // must cope with a named account and no group.
                'tier' => $payment->requested_tier ? (int) $payment->requested_tier : null,
                // ⚠️ ONLY THE OWNER'S OWN ACCOUNT IS A PLACEMENT (2026-08-22).
                //
                // payments.consultant_id answers "which account earns from this
                // purchase", which is the SAME account as the set's position
                // only when the buyer picked one of their own. Reported the day
                // this shipped: SCN-00142 read "Placed under SCN-00124" — an
                // account the owner does not hold — "124 aththe na... mn course
                // eka buy kre me account, ethkot 124 enn widiyk na."
                //
                // A foreign account there is not a parent to display, it is a
                // sign the purchase was attributed away from the buyer. The view
                // says so plainly instead of drawing it as a parent.
                'kind' => $this->belongsTo($target, $owner) ? 'bought' : 'bought-foreign',
            ];
        }

        return $this->withOwnerGroups($map, $owner);
    }

    /**
     * WHICH OF THE OWNER'S TWO SERVICE GROUPS each account ultimately sits in.
     *
     * ⚠️ THIS IS NOT `Consultant::serviceGroupLeg()`, AND THE DIFFERENCE IS THE
     * CROSS REPORTED ON 2026-08-23.
     *
     * serviceGroupLeg() answers "which leg is this account within its OWN set",
     * derived from its ordinal: #4 -> 1, #5 -> 2, #7 -> 1, #8 -> 2. That is the
     * right answer for the opening pair, which do sit directly under the main,
     * and it is what tells a set-main apart (null). But for a BOUGHT set it
     * says nothing about where the owner actually put that set.
     *
     * A set bought into Service Group 1 belongs to Service Group 1 — all three
     * of its accounts, whichever internal leg a student lands on. The ordinal
     * rule instead reported the set's second leg as the owner's Service Group
     * 2, so half of every bought set's income was attributed to the opposite
     * side of the owner's headline. Buying into Service Group 2 mirrored the
     * same error, which is why it read as the two sides swapping on the way up:
     *
     *   "6th account ekata api student kenk add karoth S1 eke 2500 ... 151 ta
     *    giyama S2 ekata thama add wenne, eka waradi. 4th eka thiyenne 151 eke
     *    Service group 1 eka athule thama, ethkota eke dana ewa okkom Service
     *    group 1 ekata enn one, ohom cross wenn ba."
     *
     * So it is resolved by CLIMBING, not by position: an account's owner-group
     * is the group of whatever it hangs under, and the recursion ends at the
     * hop sitting directly beneath the main — that hop's own tier is the answer
     * for everything below it.
     *
     * @param  array<int, array<string, mixed>>  $map
     * @return array<int, array<string, mixed>>
     */
    protected function withOwnerGroups(array $map, Consultant $owner): array
    {
        foreach (array_keys($map) as $id) {
            $map[$id]['owner_group'] = $this->resolveOwnerGroup($map, $owner, $id);
        }

        return $map;
    }

    /** @param  array<int, array<string, mixed>>  $map */
    protected function resolveOwnerGroup(array $map, Consultant $owner, int $id): ?int
    {
        // The main is not inside either of its own groups — it IS the root.
        if ($id === $owner->id) {
            return null;
        }

        $seen = [];
        $node = $id;

        // Bounded by the number of accounts; $seen also stops a corrupted
        // placement row from turning the climb into a hang.
        while (isset($map[$node]) && ! isset($seen[$node])) {
            $seen[$node] = true;

            $entry = $map[$node];
            $parent = $entry['under_id'] ?? null;

            // Hanging directly off the main: this hop's own tier is the group
            // that every account below it belongs to.
            if ($parent === null || $parent === $owner->id) {
                return in_array($entry['tier'] ?? null, [1, 2], true) ? (int) $entry['tier'] : null;
            }

            $node = $parent;
        }

        // A set whose placement was never recorded, or one placed under an
        // account belonging to someone else — neither sits in one of THIS
        // person's groups, so it stays honestly unknown rather than guessed.
        return null;
    }

    /**
     * The payments that opened bought sets, and the accounts they were placed
     * under — resolved in two queries however many sets exist.
     *
     * @param  \Illuminate\Support\Collection<int, Consultant>  $hidden
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    protected function boughtPlacements($hidden): array
    {
        // The marker column is added by FinanceSchemaRepair rather than a
        // migration (the host has no CLI), so on a database that has not been
        // repaired yet this page must still render — just without provenance.
        if (! Schema::hasColumn('consultants', 'opened_by_payment_id')) {
            return [collect(), collect()];
        }

        $paymentIds = Consultant::whereIn('id', $hidden->pluck('id'))
            ->whereNotNull('opened_by_payment_id')
            ->pluck('opened_by_payment_id', 'id');

        // Carry the marker back onto the models so the caller can read it.
        $hidden->each(function (Consultant $account) use ($paymentIds) {
            $account->opened_by_payment_id = $paymentIds[$account->id] ?? null;
        });

        if ($paymentIds->isEmpty()) {
            return [collect(), collect()];
        }

        $payments = Payment::whereIn('id', $paymentIds->values()->unique())
            ->get(['id', 'consultant_id', 'requested_tier'])
            ->keyBy('id');

        // owner_consultant_id comes back too, so the caller can tell an account
        // the buyer holds from one belonging to somebody else.
        $targets = Consultant::whereIn('id', $payments->pluck('consultant_id')->filter()->unique())
            ->get(['id', 'scn_code', 'consultant_code', 'owner_consultant_id'])
            ->keyBy('id');

        return [$payments, $targets];
    }

    /** Is this account the person themselves, or one of the accounts they hold? */
    /**
     * An admin's explicit correction of where a bought set sits.
     *
     * Only honoured when it points at an account the SAME PERSON holds — a
     * placement outside the owner's own tree is not a parent the climb could
     * walk anyway, and allowing it would let a typo route somebody else's
     * income. Silently ignored (falls back to the derived placement) when the
     * column does not exist yet, so a half-applied schema behaves like the old
     * build rather than losing every placement.
     *
     * @param  \Illuminate\Support\Collection<int, Consultant>  $hidden
     * @return array<string, mixed>|null
     */
    protected function overrideFor(Consultant $account, $hidden, Consultant $owner): ?array
    {
        $targetId = (int) ($account->placed_under_consultant_id ?? 0);

        if ($targetId === 0) {
            return null;
        }

        $target = $targetId === (int) $owner->id
            ? $owner
            : $hidden->firstWhere('id', $targetId);

        if (! $target) {
            return null;
        }

        return [
            'under_id' => $target->id,
            'under_code' => $this->codeOf($target),
            'tier' => $account->placement_tier ? (int) $account->placement_tier : null,
            'kind' => 'bought',
        ];
    }

    /**
     * The first account the OWNER holds above a foreign account, climbing that
     * account's sponsor chain — and the leg of it the branch occupies.
     *
     * The leg is read the same way CommissionService::sideUnderParent() reads
     * it when the branch's own purchases climb, so the set and that branch's
     * money land on the same side: a main's recorded placement_tier first, else
     * the seat the branch's person holds in that account.
     *
     * Null when the chain never reaches the owner (an upline or a stranger), or
     * when the leg cannot be read — an unknown side is left foreign rather than
     * guessed, because a guessed side puts real money on the wrong leg.
     *
     * @return array{account: Consultant, leg: int}|null
     */
    protected function ownAccountAbove(Consultant $foreign, Consultant $owner): ?array
    {
        $child = Consultant::with('owner')->find($foreign->id)?->owningPerson();
        $seen = [];

        for ($depth = 0; $child && $child->parent_consultant_id && $depth < CommissionService::MAX_UPLINE_DEPTH; $depth++) {
            if (isset($seen[$child->id])) {
                return null;
            }
            $seen[$child->id] = true;

            $parent = Consultant::with('owner')->find($child->parent_consultant_id);

            if (! $parent) {
                return null;
            }

            $parentPerson = $parent->owningPerson();

            if ((int) $parentPerson->id === (int) $owner->id) {
                $leg = $this->sideOf($child, $parent);

                return $leg === null ? null : ['account' => $parent, 'leg' => $leg];
            }

            $child = $parentPerson;
        }

        return null;
    }

    /** CommissionService::sideUnderParent(), for a known parent account. */
    protected function sideOf(Consultant $child, Consultant $parent): ?int
    {
        if (! $child->user_id) {
            return null;
        }

        if (! $parent->is_hidden && FinanceSchemaRepair::placementTierReady()) {
            $chosen = (int) ($child->placement_tier ?? 0);

            if (in_array($chosen, [1, 2], true)) {
                return $chosen;
            }
        }

        $accountIds = $parent->is_hidden ? [$parent->id] : $parent->accountIds();

        $tier = \App\Models\GroupMember::where('user_id', $child->user_id)
            ->whereIn('group_id', \App\Models\Group::whereIn('consultant_id', $accountIds)->select('id'))
            ->value('tier');

        return in_array((int) $tier, [1, 2], true) ? (int) $tier : null;
    }

    protected function belongsTo(Consultant $account, Consultant $owner): bool
    {
        return $account->id === $owner->id
            || (int) $account->owner_consultant_id === $owner->id;
    }

    protected function codeOf(?Consultant $account): ?string
    {
        return $account?->scn_code ?: $account?->consultant_code;
    }
}
