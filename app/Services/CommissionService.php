<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The referral / service-group / commission engine.
 *
 * All amounts come from the active CommissionSetting row — nothing is hardcoded.
 *
 * ----- Structure ----------------------------------------------------------
 * A consultant owns several ACCOUNTS (SCN numbers): their own plus the hidden
 * placement accounts created with them (SCN-00100 / 00101 / 00102). Each
 * account has ONE service group holding two legs — Service Group 1 and
 * Service Group 2 — with `first_tier_students` / `second_tier_students`
 * earning slots each (3 + 3 by default).
 *
 * ----- Who earns ----------------------------------------------------------
 * A verified student fills a slot on one leg of the consultant who registered
 * them, AND one leg of every consultant above them in the sponsor chain (which
 * leg depends on the branch the student came through). Every filled slot is
 * worth `commission_per_student` to that account's owner. On top of that, the
 * consultant who PERSONALLY registered the student also earns
 * `direct_commission` — uplines never get that part.
 *
 * ----- When it is paid (REWRITTEN 2026-08-09) ------------------------------
 * The two income kinds now have DIFFERENT timing, and this is the whole point
 * of the current design:
 *
 *  - DIRECT-ADD BONUS (`direct_commission`, 4,000): paid the instant the
 *    payment is verified, per student, UNCAPPED and forever. It follows the
 *    PERSON who registered the student, on whichever of their accounts, and
 *    is never affected by leg balance or by an account being locked.
 *
 *  - SLOT / GROUP INCOME (`commission_per_student`, 2,500): released only when
 *    a whole STAGE of matched pairs completes — 1-to-1 (5,000, once per
 *    consultant lifetime), then 2-to-2 (10,000, once per lifetime), then
 *    3-to-3 (15,000) repeating forever. See ServiceGroupStaging, which owns
 *    all of that logic.
 *
 * Pair-matching was previously removed on 2026-08-02 and is deliberately BACK
 * as of 2026-08-09, confirmed by the owner with explicit numbers. This rule
 * has now flipped three times — read commission-rules-2026-08.md before
 * touching it again.
 *
 * ----- No more earning cap -------------------------------------------------
 * The old "only the first 3 + 3 students on an account earn" cap is GONE. An
 * account accepts unlimited students, and 3 is now merely the repeating STAGE
 * STEP rather than a ceiling. What limits earning instead is the ACCOUNT
 * UNLOCK rule: a consultant's second account releases no group income until
 * their first has 12 students (ServiceGroupStaging::isUnlocked()).
 *
 * ----- Half payments and their balance -------------------------------------
 * ⚠️ REVERSED 2026-08-23. From 2026-08-04 a half payment earned exactly like a
 * full one and the balance earned nothing. It is now split by what each part
 * pays for:
 *
 *   half payment    -> the 4,000 DIRECT bonus only. The student really was
 *                      brought in, so that is earned. No slot income for the
 *                      registrant and none for any ancestor.
 *   balance payment -> the 2,500 SLOT income, for the registrant and up the
 *                      whole account tree. No direct bonus (already paid).
 *
 * A seat on a service group is only worth paying for once the course behind it
 * has actually been paid for: "half payment kranwanm eyta 2500 add wen na,
 * 4000 withri add wenne... full ekak dammoth withrk ita passe 2500 add wenwa".
 *
 * The balance row is its own Payment (payment_type = 'balance', created via
 * Consultant\StudentController::payBalance()), carrying the same consultant_id
 * and requested_tier, so it knows exactly where to place the money.
 */
class CommissionService
{
    /** Safety stop for the sponsor-chain walk (also guards against data cycles). */
    // Public since 2026-08-27 so ManualCommissionEntry walks the SAME bound
    // rather than keeping its own copy — a hand entry must reach exactly the
    // accounts an automatic one would.
    public const MAX_UPLINE_DEPTH = 50;

    /**
     * Commission::type used for slot income (service group) vs the direct-add
     * bonus. Public because ServiceGroupStaging reads TYPE_SLOT to decide
     * whether a seat has already been settled — the two classes must agree on
     * this value or staging would re-pay every student on every run.
     */
    public const TYPE_SLOT = 'bonus';

    public const TYPE_DIRECT = 'referral';

    public function __construct(protected ServiceGroupStaging $staging)
    {
    }

    /**
     * Called when a payment is verified for a student referred by a consultant.
     * Places the student, settles whatever that completes for the registering
     * consultant, then rolls the same student up the sponsor chain.
     *
     * Returns the registering consultant's slot commission, when one settled.
     *
     * Everything recorded here is PENDING: it is what the consultant has earned,
     * but it only counts towards their income once an accountant or admin
     * approves it (CommissionController::approve). Nothing is notified from
     * here — the consultant hears about it at approval, not before.
     *
     * Every step is idempotent, so this is safe to re-run over historical
     * payments to record rows that were missed.
     */
    public function handleVerifiedPayment(Payment $payment): ?Commission
    {
        // No consultant → no commission (direct signup). Company keeps full revenue.
        if (! $payment->consultant_id) {
            return null;
        }

        // ⚠️ THE BALANCE IS WHAT EARNS THE SLOT NOW (reversed 2026-08-23).
        //
        // From 2026-08-04 a half payment earned exactly like a full one, and
        // the balance earned nothing. The owner has reversed that: a half payer
        // earns the registrant their 4,000 only, and the 2,500 slot income —
        // theirs and every ancestor's — waits until the course is actually paid
        // for in full. "student kenk add kraddi half payment kranwanm eyta 2500
        // add wen na, 4000 withri add wenne... full ekak dammoth withrk ita
        // passe 2500 add wenwa, upline eke 2500th release wenawa."
        //
        // So this row is no longer a no-op: it settles the slot and walks the
        // upline, and pays NO direct bonus (that was earned at half time).
        //
        // Company revenue needs no special handling — PaymentService::verify()
        // books `amount - commissions written against this payment_id`, so the
        // half payment now keeps more and the balance keeps less, automatically.
        if ($payment->payment_type === 'balance') {
            return $this->settleBalance($payment);
        }

        // ⚠️⚠️ "EARNS NOTHING" USED TO ALSO MEAN "IS SEATED NOWHERE" — SPLIT
        // 2026-09-06, BECAUSE THOSE ARE TWO DIFFERENT QUESTIONS.
        //
        // The 2026-08-19 rule (owner-confirmed) is about MONEY: "free card
        // kenagen add kraham eyalage commission en na, mokad eyalage amount ekak
        // en na ne company ekata" — a free card is recorded as a verified
        // payment of 0.00 (EnrollmentService::amountForType), and it must mint
        // no commission because it brought the company nothing.
        //
        // The fix for that RETURNED HERE, before placeInto() — the one call
        // that also creates the GroupMember seat and rewrites
        // students.consultant_id/group_id to the chosen placement account. So a
        // free-card student was never seated at all: reported 2026-09-06 as
        // "free student kenek add kaloth eya All Students ekata add wenne na,
        // eya add kara SCN account ekatath add wenne na" — the Direct
        // Registrations page showed their payment as verified ("Free") while
        // the Placement column still read "Pending verification" forever,
        // because no GroupMember row was ever going to exist to end that state.
        //
        // The seat is a PLACEMENT fact — which account this real person's
        // record sits under — not an earning. It must be created the same way
        // for every verified payment; only the MONEY differs for a free card.
        // $isWorthless below now gates ONLY the commission calls (awardSlot
        // inside placeInto, awardDirectBonus, creditUpline), so a free-card
        // student is seated exactly like any other and still earns nobody a
        // rupee — both halves of "eyalage commission en na" but not "eya kisi
        // account ekakwath thiyenne na" hold at once.
        //
        // ⚠️ THE ANCESTOR CLIMB IS DELIBERATELY STILL SKIPPED ENTIRELY, not
        // just its commission. creditUpline() both seats AND pays every
        // ancestor, and only the registrant's own seat was reported missing —
        // widening this to also seat ancestors on a free card is a bigger,
        // untested structural change nobody asked for. "logic kisi deyk wens
        // krnn epa" (don't change other logic): this touches only the one seat
        // that was reported missing.
        //
        // Keyed on the AMOUNT rather than on payment_type === 'free_card',
        // unchanged from the original rule — it also covers any other
        // zero-value payment however it arises.
        // The schema self-heal used to live here, but this method is ALWAYS
        // called from inside PaymentService::verify()'s own outer transaction —
        // so "before this method's local transaction" was still mid-transaction
        // as far as MySQL is concerned. An ALTER TABLE run there silently
        // auto-commits that outer transaction underneath Laravel, which then
        // throws "There is no active transaction" the moment it tries to
        // commit/rollback its own bookkeeping — exactly what happened in
        // production. Moved to PaymentService::verify(), before ANY transaction
        // opens, which is the only place this is provably safe.
        return DB::transaction(function () use ($payment) {
            $settings = CommissionSetting::current();
            // 'owner' is eager-loaded because heldAtFor() resolves a hidden
            // placement account up to its real owner — Model::preventLazyLoading()
            // is active outside production and would throw on that access
            // otherwise (and it would silently N+1 in production).
            $consultant = Consultant::with('owner')->lockForUpdate()->find($payment->consultant_id);

            if (! $consultant) {
                return null;
            }

            // First verified referral → issue this account's SCN number.
            //
            // ⚠️ THROUGH THE SAME BLOCK ALLOCATION ensureFor() USES (2026-09-01).
            //
            // This took a bare NumberPool::take(1) and never asked
            // numberForPromotedStudent(). A consultant whose first verified
            // referral landed BEFORE HiddenAccountService::ensureFor() had given
            // them a code therefore got a number off the head of the pool — and
            // permanently lost the SRN → SCN identity the pool exists to
            // guarantee. Worse, ensureFor() then sees a code already present,
            // skips its own block allocation, and their two hidden accounts
            // scatter as well.
            //
            // Their own SRN number when it is still free, a fresh block of three
            // otherwise — identical to numberBlockFor().
            if (! $consultant->scn_code) {
                $number = NumberPool::numberForPromotedStudent($consultant->owningPerson()?->user_id)
                    ?? NumberPool::take(NumberPool::BLOCK);

                $consultant->update(['scn_code' => NumberPool::format('SCN', $number)]);
            }

            $requestedTier = in_array((int) $payment->requested_tier, [1, 2], true) ? (int) $payment->requested_tier : null;

            // ⚠️ SELF-PURCHASE: the buyer IS the consultant on this payment.
            //
            // Registering income is for BRINGING SOMEONE IN. Paying it to the
            // person who bought their own course is paying them to be their own
            // customer. Nothing compared payment.user_id against the
            // consultant's owner before 2026-08-19, and the hole was live and
            // reachable with no special access: /register captures any ?ref=
            // into the session (RegisterController), the session survives
            // login, and CheckoutController::referralConsultant() resolves it
            // without ever checking it is not the buyer's own account. A
            // consultant opening their OWN referral link and buying any course
            // took 2,500 (slot) + 4,000 (direct bonus) from the company, and
            // placeInto() additionally rewrote their own students.consultant_id
            // to themselves.
            //
            // The UPLINE is still credited below — that is the owner's stated
            // rule for a consultant's own purchase (2026-08-19: "consultant
            // kenk widiyta cource buy krata... meyage upline eke kawru hari
            // inwanm eyalatath 2500 gane ynwa"). Only the buyer's own two
            // earnings are withheld.
            $selfPurchase = $this->isSelfPurchase($consultant, $payment);

            // ⚠️ A SELF-PURCHASE STILL FILLS THE SLOT IT WAS PLACED IN (2026-08-22).
            //
            // From 2026-08-19 a self-purchase earned nothing at all. The owner
            // has since been explicit that the slot income is still due: "eke
            // course ekak buy krahama thamanma consultant 2500 add wenn one,
            // eka add wela na kohe wath" — buying the 4th account credited
            // nowhere, and the 2,500 should have landed on the account the
            // purchase was placed under.
            //
            // The two earnings answer different questions, which is why only
            // one of them is withheld:
            //   - the 2,500 SLOT income is for FILLING A SEAT on a service
            //     group. The buyer really does occupy that seat, it really does
            //     count toward that leg's threshold, and it still has to pair
            //     with the opposite leg before a single rupee is released.
            //   - the 4,000 DIRECT bonus is for BRINGING SOMEONE IN. Nobody was
            //     brought in, so it stays withheld — that is the part of the
            //     2026-08-19 hole that was actually free money.
            //
            // $isDirect is false: the buyer is not their own registrant, so the
            // students.consultant_id rewrite that flag drives must not fire —
            // EnrollmentService already points that row at the chosen placement.
            // ⚠️ A HALF PAYMENT EARNS NO SLOT INCOME YET (2026-08-23).
            //
            // The 2,500 is what a filled seat on a service group is worth, and
            // the course has only been half paid for. It is earned when the
            // balance is verified — see settleBalance(), which creates the seat
            // and the slot then. The 4,000 direct bonus below is unaffected:
            // the student was genuinely brought in.
            $isHalf = $payment->payment_type === 'half';

            // ⚠️ SEATED REGARDLESS; PAID ONLY IF THE PAYMENT IS WORTH ANYTHING
            // (2026-09-06). See the note above handleVerifiedPayment() for the
            // full report. placeInto() itself now withholds the slot commission
            // when $isWorthless — it still creates the seat and rewrites
            // students.consultant_id/group_id every time, which is the part
            // that was missing for a free card.
            $isWorthless = (float) $payment->amount <= 0;

            $direct = $isHalf
                ? null
                : $this->placeInto($consultant, $payment, $requestedTier, $settings, ! $selfPurchase);

            // ⚠️ A REGISTRATION MADE BY SOMEONE ABOVE THE ACCOUNT'S HOLDER
            // (2026-11-16). SCN-112 registered on SCN-132 (downline SCN-130's
            // account): the direct bonus is 112's — "direct bonus eka … Consultant
            // (Requested By) add wena widiytma" — paid AFTER the climb below, see
            // there. Null for every other payment, and those take the line below
            // byte for byte as before.
            $payerEarns = RegistrationPayer::directEarner($payment, $consultant);

            // The direct-add bonus is earned for bringing the student in, so it
            // is recorded the moment the payment is verified — it never waits
            // for a pair, and a half payment earns it too.
            //
            // ⚠️ UNCHANGED FOR A FREE CARD: still withheld, exactly as before
            // 2026-09-06 — that half of "eyalage commission en na" was already
            // correct and is not touched here, only made explicit.
            if (! $payerEarns && ! $selfPurchase && ! $isWorthless) {
                $this->awardDirectBonus($consultant, $payment, $requestedTier, $settings);
            }

            // Same student now counts into every ancestor's legs — but only once
            // the course is fully paid for. On a half payment the ancestors'
            // 2,500 waits with the registrant's own: "upline eka 2500 hold wenn
            // one". settleBalance() walks the chain when the balance lands.
            //
            // ⚠️ AND STILL SKIPPED ENTIRELY FOR A FREE CARD, on purpose — this
            // call both SEATS and PAYS every ancestor, and only the registrant's
            // own seat was reported missing. Widening this too would seat a
            // free-card student's whole upline, which is a bigger, untested
            // change nobody asked for; see the note above.
            if (! $isHalf && ! $isWorthless) {
                $this->creditUpline($consultant, $payment, $requestedTier, $settings);
            }

            // ⚠️ THE PAYER'S DIRECT BONUS (2026-11-16) — see $payerEarns above.
            //
            // Only WHO receives it changes: same amount (directRateFor, so a
            // course's own 5,000 too), same moment, a half payment earns it and
            // its balance does not, nothing on a free card or for someone buying
            // their own course. The climb above ran exactly as for any other
            // payment — 132 -> 130 -> 112's account -> 112 -> above — which also
            // seats the student on 112's main, so the bonus is filed on that
            // seat's Service Group instead of an arbitrary one.
            //
            // oncePerPayment: ONE direct per payment, whoever already holds it,
            // so Commission Health -> Repair replaying a payment whose bonus was
            // paid to the placement under the 2026-11-03 rule never adds a
            // second one here.
            if ($payerEarns && ! $selfPurchase && ! $isWorthless && ! $this->isSelfPurchase($payerEarns, $payment)) {
                $this->awardDirectBonus($payerEarns, $payment, $requestedTier, $settings, oncePerPayment: true);
            }

            return $direct;
        });
    }

    /**
     * A half payer settles the rest of their course fee.
     *
     * This is where the slot income for that student is finally earned — both
     * the registrant's own 2,500 and every ancestor's — because a seat on a
     * service group is only worth paying for once the course behind it is.
     * Reversed on 2026-08-23; see handleVerifiedPayment()'s note.
     *
     * No direct-add bonus: that was earned when the student was brought in, at
     * half-payment time, and paying it again here would double it.
     *
     * ⚠️ SAFE ON HISTORICAL DATA. Students who half-paid under the OLD rule
     * already have a seat and a slot commission from that payment. placeInto()
     * reuses an existing seat and its $alreadySettled check then awards no
     * second 2,500, so settling their balance now tops nobody up twice.
     * (Still true under the per-purchase guard of 22 Oct 2026: a half and its
     * balance share an enrollment, which that guard matches on.)
     */
    protected function settleBalance(Payment $payment): ?Commission
    {
        return DB::transaction(function () use ($payment) {
            $settings = CommissionSetting::current();
            $consultant = Consultant::with('owner')->lockForUpdate()->find($payment->consultant_id);

            if (! $consultant) {
                return null;
            }

            $requestedTier = in_array((int) $payment->requested_tier, [1, 2], true) ? (int) $payment->requested_tier : null;

            // $isDirect false: the students.consultant_id rewrite it drives
            // already happened when the original half payment was verified.
            $slot = $this->placeInto($consultant, $payment, $requestedTier, $settings, false);

            $this->creditUpline($consultant, $payment, $requestedTier, $settings);

            return $slot;
        });
    }

    /**
     * Is this payment the consultant buying their OWN course?
     *
     * Resolved through owningPerson() so it still catches the case where the
     * buyer is seated on one of their own HIDDEN placement accounts — that
     * account has no user_id of its own, and comparing it directly would miss
     * every self-purchase routed through SCN-00101/00102.
     *
     * A hidden account whose owner has somehow been detached reads as user_id
     * null; that is treated as NOT a self-purchase, which is the conservative
     * direction here (it withholds nothing that was genuinely earned).
     */
    /** Did an admin remove this payment's slot income on this account? */
    protected function removedByHand(Consultant $account, Payment $payment): bool
    {
        return \App\Services\FinanceSchemaRepair::removalReady()
            && Commission::where('payment_id', $payment->id)
                ->where('consultant_id', $account->id)
                ->where('type', self::TYPE_SLOT)
                ->whereNotNull('removed_at')
                ->exists();
    }

    protected function isSelfPurchase(Consultant $consultant, Payment $payment): bool
    {
        $ownerUserId = $consultant->owningPerson()->user_id;

        return $ownerUserId !== null
            && (int) $ownerUserId === (int) $payment->user_id;
    }

    /**
     * Walk the sponsor chain upward, filling one leg of each ancestor.
     *
     * The leg an ancestor is credited on is decided by network position, not by
     * the student's own group tag: it is the side the node below the ancestor
     * sits on within that ancestor's tree.
     *
     * @return array<Commission>
     */
    /**
     * Credit every account of the OWNER'S OWN tree that sits above this one.
     *
     * The structure comes from AccountPlacementMap, the same source the Service
     * Groups page draws "Placed under SCN-00143 · Service Group 1" from — so
     * what the consultant is shown and where the money goes cannot drift apart.
     * Each hop credits the parent on the leg this account occupies:
     *
     *   student in SCN-00145 → SCN-00143 leg 1 (145 was placed there)
     *                        → SCN-00142 leg 1 (143 IS the main's leg 1)
     *                        → then the external sponsor chain takes over
     *
     * ⚠️ The registrant guard in creditUpline() deliberately does NOT apply
     * here. It exists to stop a cyclic parent_consultant_id paying one person a
     * second time for the same seat; this walk is the owner's own placement
     * tree, where crediting each level is the whole point of the pyramid. It is
     * kept safe structurally instead: strictly ascending, every account visited
     * at most once, and it never leaves the owner's accounts.
     *
     * placeInto() is idempotent per (account group, student) — it reuses an
     * existing seat and awards no second slot — so a re-verified payment cannot
     * pay any of these twice.
     *
     * @return array<int, Commission>
     */
    protected function creditOwnAccountAncestors(Consultant $placedUnder, Payment $payment, CommissionSetting $settings): array
    {
        $owner = $placedUnder->owningPerson();
        // A set bought on a downline's account hangs inside the owner's tree only
        // for payments verified under the new rules — history was added by hand.
        // See ClimbRules.
        $placements = app(\App\Services\AccountPlacementMap::class)
            ->for($owner, placeDownlineSets: ClimbRules::appliesTo($payment));

        $earned = [];
        $seen = [$placedUnder->id => true];
        $node = $placedUnder;

        for ($depth = 0; $depth < self::MAX_UPLINE_DEPTH; $depth++) {
            // ⚠️ REMOVED BY HAND STAYS REMOVED ON THE WAY UP (2026-09-16).
            //
            // When an admin removed this payment's 2,500 on this account, the
            // accounts above it in the SAME person's tree must not mint a fresh
            // one when history is replayed. This only mattered once a foreign set
            // bought on a downline's account started hanging inside the owner's
            // tree (AccountPlacementMap::ownAccountAbove): its old payments gained
            // a route to accounts holding no row for them yet, so Run Repair would
            // have re-paid PYM-00381 — every one of its 12 rows removed on 8 Sep —
            // onto SCN-00230 and SCN-00229. On a first verification nothing has
            // been removed, so a live payment climbs exactly as before.
            if ($this->removedByHand($node, $payment)) {
                break;
            }

            $entry = $placements[$node->id] ?? null;
            $parentId = $entry['under_id'] ?? null;

            // Reached the person's main account, or an account whose placement
            // was never recorded — either way there is nothing above it here.
            if (! $parentId || isset($seen[$parentId])) {
                break;
            }

            // ⚠️⚠️ THE MAIN EARNS ITS OWN ROWS (2026-09-02) — THIS REVERSES THE
            // 2026-08-21 "STOP AT THE MAIN, IT ROLLS UP" RULE.
            //
            // The climb used to break here, so the main was the ONE account in
            // the whole model that earned nothing. Its Service Group 1 / 2
            // figures were a ROLLUP: the sum of the accounts hanging directly
            // off it. That single exception is what made the owner's two
            // requirements look mutually exclusive for eight straight reports:
            //
            //   - let the main settle its rollup  -> it spends a child's rupees
            //     while that child is still unbalanced ("2nd ekai 3rd ekai
            //     stage balance nathiw flush out wenwa")
            //   - stop the main settling          -> it shows a balance it can
            //     never move ("main account eke amount eka yanne na")
            //
            // Both are true of a rollup, and neither is true of an account that
            // holds its own money. One boolean was flipped six times choosing
            // which half to break. The owner picked the structural answer on
            // 2026-09-02 when shown the two costs side by side: the main is now
            // an ordinary settlement unit like every other account — it earns
            // its own rows, holds its own two legs, and settles when those two
            // legs balance, touching nothing that belongs to a child.
            //
            // ⚠️ THE HEADLINE MUST STOP ROLLING UP IN THE SAME BREATH, or the
            // main's own row and the child's row are the same student counted
            // twice. That is ServiceGroupFlush::legScopeQuery(), where the
            // rollup branch was deleted in this same change. The two are one
            // decision; do not restore either half on its own.
            //
            // ⚠️⚠️ A FORCE-SHUT ACCOUNT NO LONGER BLOCKS THE WAY UP (2026-09-25).
            //
            // It did, from 2026-09-02: an account an admin deliberately closed
            // was treated as money that "must not reach a payout by ANY route",
            // so the climb stopped dead at it and the owner's MAIN never earned
            // its own row for that student.
            //
            // The owner reported the result on his own screen — SCN-00748
            // reading "SG1 LKR 7,500 · SG2 LKR 0 · LKR 2,500 held on SCN-00750
            // — closed by an administrator". With one side at zero that account
            // can never balance, so the 7,500 on the other side is frozen too:
            // *"lock nisa point cal wen nathiw hira wela, eka wardi … point eka
            // cal wenn one."*
            //
            // Measured on the 21 Sep data: 70 accounts are force-shut and this
            // break had cost 9 main accounts LKR 42,500 in rows that were never
            // written — and far more than that in money frozen behind an
            // unbalanced leg.
            //
            // ⚠️ THE LOCK STILL MEANS SOMETHING, AND THAT PART IS UNTOUCHED: a
            // shut account cannot flush its OWN money (ServiceGroupStaging::
            // isUnlocked() gates the flush, and ServiceGroupFlush refuses the
            // account outright). Closing an account still stops that account
            // earning a payout. What it no longer does is punish everybody
            // above it for a decision taken about one leg.
            //
            // This is the same shape as the 12-student chain lock, which has
            // never blocked the climb: "e account walin hamben ewa mekat add
            // krnn... main eka flush out wenn one system run ekedi" (2026-08-27).
            //
            // ⚠️ NOT GATED BY ClimbRules. The other two climb corrections are
            // forward-only because the owner had already entered that history by
            // hand, so a replay would pay it twice. Nothing was hand-entered for
            // these: the rows are simply missing. Commission Health → Repair
            // replays each payment through this engine and writes only rows that
            // do not exist, so it recovers exactly the 42,500 and nothing else.

            $parent = Consultant::with('owner')->find($parentId);

            // A purchase attributed to somebody else's account is not part of
            // this tree (see AccountPlacementMap's 'bought-foreign'), and the
            // external sponsor chain is the right place for that money.
            if (! $parent || (int) $parent->owningPerson()->id !== (int) $owner->id) {
                break;
            }

            $seen[$parent->id] = true;

            // ⚠️ A SET-MAIN EARNS LIKE ANY OTHER ACCOUNT IN THE CLIMB.
            //
            // It was skipped for a few hours on 2026-08-25, on the theory that
            // it was the source of an "extra 2,500". That was the wrong
            // culprit: the real duplication was the MAIN's headline summing the
            // whole subtree, so a deep student was counted once per level they
            // climbed through. That is fixed where it belongs, in the rollup —
            // GroupController now counts only accounts hanging directly off the
            // main, and ServiceGroupFlush::accountsInOwnerGroup() matches it.
            //
            // Skipping it here starved the account instead: SCN-00106 read LKR
            // 0 across every column while the two accounts beneath it earned
            // normally — "SCN 106 amount tika watila na, eka calculate wela na".
            // Nothing can be PLACED into a set-main, so the climb is the only
            // income it will ever have; without it, it can never pair, never
            // flush, and never advance a stage.
            //
            // Crediting it cannot double the headline any more, because it is
            // not a direct child of the main and so is not counted there.
            // isUplineHop: an ancestor is paid because a course was bought
            // BELOW it, so a seat it already holds for this person must not
            // suppress the new earning. See the guard in placeInto().
            $commission = $this->placeInto($parent, $payment, $entry['tier'] ?? null, $settings, false, isUplineHop: true);

            if ($commission) {
                // ⚠️ AUTO-APPROVED, LIKE EVERY OTHER UPLINE 2,500 (2026-08-23).
                //
                // These rows were left pending while the external sponsor chain
                // below auto-approved its own, so the SAME kind of income
                // arrived in two different states on one screen — some rows
                // reading "Approved · Manual · Mark paid", others "Pending ·
                // Stage not balanced · Approve". The accountant then clicked
                // Approve on the pending ones, which is the step the owner
                // wants gone: "upline eyana ewata approve kran nathuw kelinm
                // mark paid button eka withrk denn".
                //
                // Same rule and same reason as the external chain (08-19): one
                // registration credits EVERY ancestor, so the click count grows
                // with depth x volume and does not survive real student
                // numbers. force: true skips ONLY the release gate — held and
                // finance-suspended income still stops dead.
                app(CommissionApproval::class)->approve($commission, force: true, actorId: null);

                $earned[] = $commission;
            }

            $node = $parent;
        }

        return $earned;
    }

    protected function creditUpline(Consultant $placedUnder, Payment $payment, ?int $requestedTier, CommissionSetting $settings): array
    {
        // ⚠️ FIRST CLIMB THE OWNER'S OWN ACCOUNT TREE (2026-08-22).
        //
        // A person's accounts form a tree of their own: the opening pair sit
        // under their main, and every bought set sits under whichever account
        // was picked at checkout. A student seated deep in that tree fills the
        // leg of every account above them, exactly as they fill a downline's
        // ancestors — that is what makes the upper accounts pairable at all.
        //
        // This was missing entirely. A bought set records its placement on the
        // PAYMENT, never as a parent_consultant_id, so the sponsor walk below
        // resolved straight to the owning person and jumped to their EXTERNAL
        // sponsor — skipping every account in between. Reported 2026-08-22: a
        // student placed in the bought SCN-00145 earned its own 2,500 + 4,000
        // but "upline eken yana 2500 calculate un na... 143 account ekatat saha
        // 142 main account ekatath calculate wen one".
        $earned = $this->creditOwnAccountAncestors($placedUnder, $payment, $settings);

        // Hidden accounts are placement buckets, not people — the sponsor chain
        // starts at the real consultant that owns them.
        $node = $placedUnder->is_hidden ? $placedUnder->owner : $placedUnder;

        // The PERSON who registered this student. They already earned the slot
        // + direct bonus as the registrant, so reaching them again anywhere up
        // the chain would pay one person twice for one student — see the guard
        // inside the loop.
        $registrantPersonId = (int) $placedUnder->owningPerson()->id;

        $seen = [];
        $depth = 0;

        while ($node && $node->parent_consultant_id && $depth < self::MAX_UPLINE_DEPTH) {
            if (isset($seen[$node->id])) {
                break; // cyclic parent data — stop rather than loop forever.
            }
            $seen[$node->id] = true;

            // Which branch of the parent's tree this node sits on.
            $side = $this->sideUnderParent($node) ?? $requestedTier;

            // The account the student was originally placed under when this
            // sponsor relationship was recorded. It may be one of the owner's
            // hidden placement accounts.
            // 'owner' eager-loaded for the same reason as in
            // handleVerifiedPayment() above — it may itself be hidden.
            $sponsorAccount = Consultant::with('owner')->lockForUpdate()->find($node->parent_consultant_id);

            if (! $sponsorAccount) {
                break;
            }

            // ⚠️ REVERSED 2026-08-12, at the owner's explicit request.
            //
            // Until now the money stayed on $sponsorAccount — the SPECIFIC
            // (often hidden) account the downline was sponsored through — so
            // that each of a person's accounts earned independently, with its
            // own Service Group 1/2 row. That was deliberate (2026-08-05) and
            // was pinned by a test.
            //
            // ⚠️⚠️ REVERSED AGAIN 2026-08-21 — BACK ONTO THE SPECIFIC ACCOUNT.
            //
            // THIRD setting of this rule: the specific placement account
            // (2026-08-05) -> the owner's MAIN account (2026-08-12) -> the
            // specific account again, now.
            //
            // The owner restated it four ways in one message: "sub account
            // walat ekathu wen na... SCN 102 S1 ekat ekathu wen na eth eka
            // kelinm gihilla SCN 100 eke S2 ekata add wenwa, eka wardi... 4th
            // account eken ena upline 2500 ya 2nd or 3rd account walata ekathu
            // wen nathiw kelin main account ekata yanwa... pahala student add
            // krahama upline eken yana 2500 sub ekat add wen nathiw kelinm
            // main account ekat yanne, ekath waradi."
            //
            // ⚠️ THIS DOES NOT LOSE THE 2026-08-20 REQUIREMENT that the money
            // be visible under the OWNER'S correct Service Group. It now gets
            // there a different way: the row sits on the placement account, and
            // the Service Groups rollup attributes that whole account to the
            // owner's leg it represents (Consultant::serviceGroupLeg()). So a
            // downline under SCN-00101 credits SCN-00101, and SCN-00101 IS the
            // owner's Service Group 1 — which is exactly what the owner asked
            // to see, and it now shows on BOTH rows ("dekem penwnn one")
            // instead of only on the main.
            //
            // The SEAT follows the money, as before — placeInto() creates the
            // GroupMember on whichever account is passed.
            $recipient = $sponsorAccount;

            // ⚠️ Never credit the BUYER, and never credit the REGISTRANT, as
            // their own upline.
            //
            // The $seen guard above cannot catch either: it is evaluated at the
            // top of the loop against $node, while the money below goes to
            // $recipient, which only becomes $node on the NEXT iteration. So
            // the first hop always paid out before any cycle was detected.
            //
            // A consultant whose parent_consultant_id points at their own
            // account (or at one of their own hidden accounts, which resolves
            // to them here) therefore took a full extra 2,500 on top of the
            // 2,500 + 4,000 they already earned as the registrant — and it was
            // not even a seat-reuse no-op, because a registration placed on
            // their hidden account leaves their MAIN group without a seat for
            // that student, so placeInto() created a brand new one. That is
            // +2,500 on every student they ever register, forever.
            //
            // Checked per hop rather than once, so a longer cycle that loops
            // back to either party is caught too.
            //
            // ⚠️⚠️ THE BUYER IS NO LONGER A STOP (2026-09-15) — ONLY THE REGISTRANT IS.
            //
            // This also broke on isSelfPurchase($recipient, $payment). A
            // consultant who buys a course placed on their OWN DOWNLINE'S
            // account always climbs back through themselves, so that `break`
            // cut off their own accounts AND every account above them. Reported
            // on SCN-00229: PYM-00079 was placed on SCN-00432 (chandima, who sits
            // inside SCN-00229's own SCN-00230) and credited SCN-00432 and
            // SCN-00430 — then nothing, not SCN-00230, not SCN-00229, not the six
            // accounts above. On the live dump that day 7 of 218 full payments
            // stopped this way, every one exactly at an account the buyer owns.
            // Owner: "e palcement krapu account eken point eka meyata saha
            // meyagen udat calculate wenn one".
            //
            // Crediting the buyer's account here is the owner's own 2026-08-22
            // rule for a self-purchase ("thamanma consultant 2500 add wenn one"):
            // the SLOT is due, only the 4,000 is withheld — and uplines never
            // receive a 4,000 (isDirect false below), so nothing is loosened.
            //
            // The double-pay case the buyer check once covered is still closed:
            //   - bought on their OWN account -> buyer IS the registrant, so the
            //     registrant check below still stops the climb back into them;
            //   - a cycle through the buyer    -> placeInto() is idempotent per
            //     earning event, and $seen ends the walk on the next hop.
            //
            // ⚠️ ONLY FOR PAYMENTS VERIFIED UNDER THE NEW RULES (2026-09-17). The
            // owner added the history by hand, so an older payment replayed by
            // Run Repair must stop at the buyer exactly as it did — see ClimbRules.
            if ((! ClimbRules::appliesTo($payment) && $this->isSelfPurchase($recipient, $payment))
                || (int) $recipient->owningPerson()->id === $registrantPersonId) {
                break;
            }

            // isDirect = false: uplines never earn the direct-add bonus.
            // isUplineHop: THE reported bug. The buyer already holds a seat in
            // this sponsor's group — that seat is how they became a downline —
            // and the per-seat guard was suppressing every later purchase.
            $commission = $this->placeInto($recipient, $payment, $side, $settings, false, isUplineHop: true);

            if ($commission) {
                $earned[] = $commission;

                // ⚠️⚠️ AUTO-APPROVED AGAIN (owner-confirmed 2026-08-19).
                //
                // THIRD flip of this rule: auto-approved 08-12, made to wait
                // for a System Run 08-14, auto again now. The owner's reason is
                // operational scale, not policy — approving upline rows by hand
                // does not survive the student count: "manual danakot meke
                // student la 200 ita wadi wen kota godka manual krnn one, eka
                // nisa eka auto dann upline 2500". One registration credits
                // EVERY ancestor, so the click count grows with depth x volume.
                //
                // Normal slot/upline 2,500 rows are approved automatically at
                // verification now. They still sit on their service-group leg
                // until System Run stamps released_at; approval and release
                // are deliberately separate states.
                //
                // force: true skips ONLY the release gate (gate 3). The
                // hold / finance-status gate is NOT overridable by anything,
                // so a restricted or suspended consultant's income still stops
                // here exactly as before — see CommissionApproval's docblock.
                //
                // actorId: null deliberately — an automatic approval must not
                // stamp whichever accountant happened to be verifying the
                // payment, which would read in the audit trail as a human
                // decision nobody made.
                app(CommissionApproval::class)->approve($commission, force: true, actorId: null);

                // ⚠️ AND THEN CLIMB THE RECIPIENT'S OWN TREE (2026-08-26).
                //
                // The hop above lands the 2,500 on the SPECIFIC account the
                // downline was sponsored through (08-21 rule, above). When that
                // account is a DEEP one — a leg inside a bought set — the money
                // stopped dead there and never rose to the account that
                // represents the owner's Service Group.
                //
                // That was invisible until the rollup was narrowed on 08-25 to
                // count only accounts hanging DIRECTLY off the main (the fix for
                // the duplicated 2,500). Before then the main summed the whole
                // subtree, so a deep row still showed up — by accident. After
                // it, a downline sponsored through SCN-00105 credited SCN-00105
                // and the owner's headline read 0: "consultant lagen yana upline
                // 2500 dn add wen na... consultant yatathe inn aya student la
                // add krahama 2500 udata calculate wenn one auto".
                //
                // So the same climb the registrant's own placement gets is
                // applied here: 00105 -> 00103 (set-main) -> 00101, stopping at
                // the main, which rolls up. Each level is a real row that can
                // pair and flush, and the headline still counts it exactly once
                // because only 00101 is a direct child.
                //
                // A recipient that is ALREADY a direct child returns straight
                // away — its parent is the main, which is the loop's stop
                // condition — so the common shallow case is untouched.
            }

            // ⚠️ CLIMBS EVEN WHEN THIS HOP PAID NOTHING (2026-08-29).
            //
            // This used to sit INSIDE `if ($commission)`, so a hop that placeInto()
            // suppressed silently cancelled the climb above it as well — one
            // no-op at the bottom and the entire rest of the branch earned
            // nothing. That doubled the blast radius of the seat-reuse bug.
            //
            // The accounts above $recipient are different accounts with their
            // own seats and their own guards, so whether THIS one had anything
            // left to pay says nothing about them. placeInto() is idempotent, so
            // calling it here unconditionally can only ever add money that was
            // genuinely owed — never a duplicate.
            $earned = array_merge(
                $earned,
                $this->creditOwnAccountAncestors($recipient, $payment, $settings),
            );

            // Climbing further up needs a REAL consultant — a hidden account
            // has no parent_consultant_id of its own to walk. $recipient is
            // already resolved above, so this is now a no-op in practice; kept
            // explicit so the walk stays correct if the resolution above is
            // ever changed back.
            $node = $recipient->is_hidden ? $recipient->owner : $recipient;
            $depth++;
        }

        return $earned;
    }

    /**
     * The leg this consultant occupies inside their sponsor's tree — taken from
     * where they were originally placed as a student under that sponsor.
     *
     * ⚠️ PUBLIC SINCE 2026-09-27, AND ONLY SO THAT IT IS NOT COPIED. The manual
     * commission tool must put each upline 2,500 on the same leg this engine
     * would have (ManualCommissionEntry::chainFrom). This method reads seats
     * and carries three owner rulings; a second copy of it would drift the
     * first time one of them changes. It is a pure read — making it callable
     * changes nothing about how the engine uses it.
     */
    public function sideUnderParent(Consultant $node): ?int
    {
        if (! $node->user_id || ! $node->parent_consultant_id) {
            return null;
        }

        $parent = Consultant::find($node->parent_consultant_id);

        if (! $parent) {
            return null;
        }

        // ⚠️ THE PLACEMENT ACCOUNT ITSELF NAMES THE LEG (owner-confirmed 2026-08-18).
        //
        // A person's FIRST placement account IS their main account's Service
        // Group 1, and their SECOND IS Service Group 2 — that is what those
        // accounts mean, not merely a bucket a student happened to land in.
        // So a downline sponsored through SCN-00102 credits the ancestor's
        // leg 2, whatever tier that downline's own seat occupies inside
        // SCN-00102.
        //
        // This REPLACES a seat-tier lookup (kept below as the fallback) that
        // read the downline's own tier within the parent's groups. The two
        // disagree whenever a downline was seated on, say, SCN-00102's leg 1:
        // the ancestor was then credited on leg 1 while the account the
        // downline was sponsored through means leg 2. Reported 2026-08-18 as
        // "pathi deka maru wela" — the two sides coming out swapped.
        //
        // Only the first two placement accounts map this way. A third or
        // later account (admin-created via HiddenAccountService::
        // addExtraAccount) has no third leg to name, so it keeps behaving as
        // its own separate account and falls through — owner's explicit
        // instruction 2026-08-18: "eka danata wenam account ekak widiyta yana
        // widiyt dann, passe mokatad yanne one kiyla mn pahadili krannm."
        // ⚠️ NO ORDINAL RULE HERE ANY MORE (2026-08-21).
        //
        // The ordinal answers "which leg of the OWNER does this account
        // represent". That was the right question while upline money landed on
        // the owner's MAIN account. Since it now lands on the PLACEMENT ACCOUNT
        // itself (see creditUpline), the question changed to "which leg INSIDE
        // that account does this downline sit on" — and that is the seat
        // lookup below.
        //
        // The owner's requirement that it appear under their correct Service
        // Group is still met, by the rollup rather than by this value:
        // Consultant::serviceGroupLeg() attributes the whole account to the
        // owner's leg it represents. Applying the ordinal HERE as well would
        // apply the same mapping twice — once to pick a leg inside the account,
        // then again to place the account — and land the money on the wrong
        // side of its own account.

        // ⚠️ THE EXPLICIT CHOICE IS ONLY FOR A MAIN-ACCOUNT PARENT.
        //
        // It is read HERE, after the ordinal — not before it, which is the bug
        // this ordering fixes (introduced 2026-08-19, reported 2026-08-20).
        //
        // `placement_tier` is populated from payments.requested_tier, and those
        // two are DIFFERENT QUANTITIES that only look alike:
        //
        //   requested_tier  = which leg the student sat on INSIDE SCN-00101
        //   ordinal         = which leg SCN-00101 OCCUPIES INSIDE SCN-00100
        //
        // Owner's worked example: student B is seated on SCN-00101's Service
        // Group 2, so requested_tier = 2. B later becomes a consultant and
        // registers their own students. Reading the explicit value first
        // credited SCN-00100's leg 2 — but SCN-00101 IS SCN-00100's leg 1, so
        // every one of those 2,500s landed on the wrong side of the ancestor:
        // "SCN 100 account eke service group 1 ekata thama athi wenne 101 eka,
        // upline eka yann one SCN 100 service group 1 ekata."
        //
        // A MAIN account has no ordinal — it is not the "1st" or "2nd" anything
        // — so there the recorded choice is the only thing that can name the
        // leg, and it is still authoritative.
        if (! $parent->is_hidden && \App\Services\FinanceSchemaRepair::placementTierReady()) {
            $chosen = (int) ($node->placement_tier ?? 0);

            if (in_array($chosen, [1, 2], true)) {
                return $chosen;
            }
        }

        // Fallback: the sponsor's own account, or a third-and-later placement
        // account. The sponsor may have placed them in any of its accounts.
        $parentAccountIds = $parent->is_hidden ? [$parent->id] : $parent->accountIds();

        $tier = GroupMember::where('user_id', $node->user_id)
            ->whereIn('group_id', Group::whereIn('consultant_id', $parentAccountIds)->select('id'))
            ->value('tier');

        return in_array((int) $tier, [1, 2], true) ? (int) $tier : null;
    }

    /**
     * Which placement account this is, in the owner's own creation order:
     * 1 for their first hidden account, 2 for the second, and so on.
     *
     * Ordered by id, the same sequence HiddenAccountService creates them in
     * and the same one Consultant::orderedAccountIds() / the account-unlock
     * chain walk — so "first placement account" means the same thing
     * everywhere in the app.
     */
    /**
     * Which of the OWNER'S legs this placement account represents, or null
     * when it represents none.
     *
     * Accounts arrive in SETS. The opening set is the person's own main
     * account plus two hidden accounts, so hidden #1 IS Service Group 1 and
     * hidden #2 IS Service Group 2 — the owner's model, stated 2026-08-20:
     * "SCN 100 ... eke thiyena service group 1 eka athule thama SCN 101
     * thiyenne ... anith paththe thiyen service group 2 eke thama SCN 102".
     *
     * Every LATER set (opened by a consultant buying another course, see
     * HiddenAccountService::addAccountSet) is a triple of the same shape: a
     * set-main plus its own two legs. So from hidden #3 onward the pattern
     * repeats every three — {set-main, Service Group 1, Service Group 2} —
     * which is what "plaweniyta hambena account tika wagem" means, and matches
     * "Placement kraddi 4th eke main account eka hide krann, mokada ethanat
     * enn one 5th and 6th account": the set-main is never a placement target,
     * only its two legs are.
     *
     *   hidden #1 -> leg 1        #4 -> leg 1        #7 -> leg 1
     *   hidden #2 -> leg 2        #5 -> leg 2        #8 -> leg 2
     *                             #3 -> set-main     #6 -> set-main
     *
     * ⚠️ WHY THIS MATTERS BEYOND TIDINESS. Before this, only #1 and #2 named a
     * leg and everything from #3 fell through to a seat-tier lookup — the very
     * rule the 2026-08-20 fix declares illegal, because a seat tier INSIDE one
     * account cannot name a leg OF ANOTHER. That was survivable while third
     * accounts were a rare admin action; the moment a consultant's own course
     * purchase started minting sets automatically it became the common case.
     *
     * Returns null for a set-main, which has no leg of its own to name.
     */
    protected function placementLeg(Consultant $account): ?int
    {
        // Single source of truth — see Consultant::serviceGroupLeg(). Kept as a
        // thin wrapper so the call sites below read in this class's own terms.
        return $account->serviceGroupLeg();
    }

    protected function placementOrdinal(Consultant $account): ?int
    {
        $ids = Consultant::where('owner_consultant_id', $account->owner_consultant_id)
            ->where('is_hidden', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $position = array_search($account->id, $ids, true);

        return $position === false ? null : $position + 1;
    }

    /**
     * Add the student to one leg of a single account and settle whatever that
     * completes. Used for the registering consultant and for every ancestor.
     *
     * $isDirect marks the registering consultant: only they get the direct-add
     * bonus, and only they own the student record.
     */
    /**
     * Does this placement pay once per PURCHASE (rather than once per seat)?
     *
     * Always on an upline hop (2026-08-29). On the account the purchase is
     * placed on, for payments verified from 22 Oct 2026 — RepeatPurchaseRules.
     * Public so Commission Health can tell the live server runs this code.
     */
    public function paysPerPurchase(Payment $payment, bool $isUplineHop): bool
    {
        return $isUplineHop || RepeatPurchaseRules::appliesTo($payment);
    }

    protected function placeInto(Consultant $consultant, Payment $payment, ?int $tier, CommissionSetting $settings, bool $isDirect, bool $isUplineHop = false): ?Commission
    {
        $accountGroupIds = Group::where('consultant_id', $consultant->id)->pluck('id');

        // A seat for this (consultant, payment) pair may already exist — either
        // from earlier in this same run, or from being placed under an older
        // rule version (e.g. before slot income paid per-student). Re-use it
        // rather than re-placing, but still fall through below so a member
        // placed under the old rule can pick up the slot income it never got.
        $member = GroupMember::where('payment_id', $payment->id)
            ->whereIn('group_id', $accountGroupIds)
            ->first();

        // ...and even when THIS payment has no seat, the STUDENT may already
        // occupy one in this account's group from an EARLIER payment — a second
        // course, or a re-registration.
        //
        // group_members carries `unique (group_id, user_id)`, so inserting a
        // second seat for the same student throws
        // UniqueConstraintViolationException and takes the whole payment
        // verification down with a 500. That is a real production incident
        // (2026-08-14, "Duplicate entry '74-134' for key 'uniq_group_user'"
        // while verifying PYM-00054): the guard above only matched on
        // payment_id, so a returning student was invisible to it.
        //
        // Re-using the seat is also the correct MONEY answer, not just a crash
        // fix: one seat per student per service group is exactly what that
        // unique key encodes, and $alreadySettled below then finds the seat's
        // existing slot commission and awards no second 2,500. The direct-add
        // bonus is keyed on payment_id instead, so it is unaffected and still
        // pays per registration.
        //
        // ⚠️ THE SEAT IS STILL REUSED, BUT SINCE 22 OCT 2026 THE MONEY IS NOT
        // "ONE PER SEAT" — a new purchase on the same account earns its own
        // 2,500. See RepeatPurchaseRules and the guard below.
        $member ??= GroupMember::where('user_id', $payment->user_id)
            ->whereIn('group_id', $accountGroupIds)
            ->orderBy('id')
            ->first();

        if ($member) {
            $group = Group::findOrFail($member->group_id);
        } else {
            $group = $this->activeGroupFor($consultant, $settings);

            // No explicit leg (legacy / self-checkout) → fill leg 1 first, then leg 2.
            $leg = $tier ?? (((int) $group->tier1_filled) < (int) $settings->first_tier_students ? 1 : 2);

            $member = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $payment->user_id,
                'enrollment_id' => $payment->enrollment_id,
                'payment_id' => $payment->id,
                'position' => (int) $group->filled_slots + 1,
                'tier' => $leg,
                'commission_amount' => 0,
                'joined_at' => now(),
            ]);

            if ($isDirect) {
                // Only the registering consultant owns the student record.
                Student::where('user_id', $payment->user_id)->update([
                    'consultant_id' => $consultant->id,
                    'group_id' => $group->id,
                ]);
            }

            $group->increment('filled_slots');
            $group->increment($leg === 1 ? 'tier1_filled' : 'tier2_filled');
            $group->refresh();
        }

        // ⚠️⚠️ PER EARNING EVENT, NOT PER SEAT (2026-08-29).
        //
        // THIS IS THE MISSING UPLINE 2,500 ON A COURSE PURCHASE. Reported four
        // times: "4th account eka buy kraddi 2500 upline ekata calculate wen na
        // — thamange account ekat cal wenwa eth upline eken calculate wen na."
        //
        // The guard used to be per SEAT: "does this group_member already have a
        // slot row?" That is right for the REGISTRANT, and it is what the 08-14
        // crash fix relies on. It is wrong for an UPLINE hop, because of how a
        // downline comes to exist: they became this sponsor's downline by being
        // registered as a STUDENT under that sponsor, which seated them in the
        // sponsor's group and paid that seat its 2,500. So when that same person
        // later BUYS a course of their own, placeInto() reuses that years-old
        // seat (the fallback above), finds its long-settled 2,500, and returns
        // NULL — the sponsor earns nothing on the new purchase.
        //
        // Proven by isolating the single variable: a downline hung under the
        // sponsor who had NEVER been seated in their group DID credit the
        // sponsor on a 4th-account purchase; the identical downline WITH a seat
        // credited nobody. Depth is irrelevant — the 4th, 7th and 10th purchases
        // fail the same way.
        //
        // ⚠️ AND THE NULL IS WHY IT IS INVISIBLE: creditUpline() wraps the
        // auto-approve AND the ancestor climb in `if ($commission)`, so a
        // suppressed row silently cancels the rest of that hop too. Nothing is
        // misplaced — no row is created at all, which is why every "follow the
        // money" hunt for it came up empty.
        //
        // So the question becomes "has THIS EARNING EVENT already paid this
        // seat?", which still refuses every kind of double payment:
        //   - re-verifying the same payment      -> same payment_id
        //   - half then balance                  -> they SHARE enrollment_id
        //                                           (payBalance copies it), and
        //                                           that is what keeps the
        //                                           2026-08-23 rule intact
        //   - the backfill replaying history     -> both of the above
        // while a genuinely NEW course purchase is a new enrollment, and earns.
        // ⚠️ ONLY THE UPLINE PATH WAS LOOSENED HERE (until 22 Oct 2026 — see the
        // next note). The REGISTRANT path kept the strict per-seat rule, because
        // that was then read as a decision in its own right: a returning student
        // buying a SECOND course from the same consultant fills no new seat and
        // earned that consultant no second 2,500 ("one seat means one 2,500" —
        // RepeatStudentSeatReuseTest, which now pins that only for payments
        // verified before 22 Oct 2026). The direct bonus paid twice there.
        //
        // My first cut of this fix loosened BOTH paths and broke that rule. The
        // two are genuinely different events: the sponsor is being paid because
        // a NEW COURSE WAS BOUGHT beneath them, which the owner has stated
        // directly — "consultant kenk widiyta course buy krata... meyage upline
        // eke kawru hari inwanm eyalatath 2500 gane ynwa" (2026-08-19).
        //
        // ⚠️⚠️ AND THE REGISTRANT PATH TOO, FROM 22 OCTOBER 2026.
        //
        // Owner: "inn student or consultant kenk buy kraddi 2500 calculate wen
        // na meka fix krla denn". "One seat means one 2,500" was never the
        // owner's rule — it came from the 08-14 crash fix — and it withheld the
        // 2,500 from the account every repeat purchase was placed on (PYM-07242,
        // SCN-00297, blocked by a 2,500 removed by hand a month earlier). Each
        // purchase is now its own earning event here as well, with the same
        // guard, so a re-verify, a half and its balance, and Repair still pay
        // once. Payments verified before that day keep the per-seat answer they
        // were given — see RepeatPurchaseRules for why history is not replayed.
        $settledQuery = Commission::where('group_member_id', $member->id)
            ->where('type', self::TYPE_SLOT);

        $perPurchase = $this->paysPerPurchase($payment, $isUplineHop);

        if ($perPurchase) {
            // Narrow "has this seat been paid" down to "has THIS EARNING EVENT
            // paid this seat".
            $settledQuery->where(function ($q) use ($payment) {
                $q->where('payment_id', $payment->id);

                if ($payment->enrollment_id) {
                    // A half and its balance SHARE an enrollment (payBalance()
                    // copies it), which is what keeps the 2026-08-23 rule. Rows
                    // written before 2026-08-29 carry the SEAT's payment id
                    // rather than the earning one, so they are matched here by
                    // enrollment rather than by id.
                    $q->orWhereIn('payment_id', Payment::where('enrollment_id', $payment->enrollment_id)->select('id'));
                } else {
                    // Pre-enrollment data has nothing to pair on. Fall back to
                    // the same student and the same course — the conservative
                    // direction, since it can only ever WITHHOLD a second
                    // payment, never create one.
                    $q->orWhereIn('payment_id', Payment::where('user_id', $payment->user_id)
                        ->where('course_id', $payment->course_id)
                        ->whereNull('enrollment_id')
                        ->select('id'));
                }
            });
        }

        $alreadySettled = $settledQuery->exists();

        // ⚠️ A WORTHLESS PAYMENT FILLS THE SEAT BUT EARNS NOTHING (2026-09-06).
        //
        // Everything ABOVE this line — the seat lookup/creation, the
        // students.consultant_id/group_id rewrite, the group's filled_slots and
        // tier counters — runs the same for a free card as for a real payment,
        // because occupying a seat is a PLACEMENT fact, not an earning. Only
        // the commission decision reads the amount, which is the single change
        // that fixed a free-card student never being seated at all (see the
        // note above handleVerifiedPayment()). Read from $payment directly
        // rather than threaded in as a parameter, so this holds wherever
        // placeInto() is called from, present or future — not just the one
        // call site that was reported broken.
        $isWorthless = (float) $payment->amount <= 0;

        // The 2,500 is RECORDED for every student the instant their payment is
        // verified, so the consultant can SEE it straight away — the owner was
        // explicit about that ("meke 2500 penwann one"). Whether it may then be
        // APPROVED and paid out is a separate question, decided by
        // ServiceGroupStaging on the approval path once the legs balance.
        // ⚠️ THE REQUESTED LEG IS CARRIED THROUGH on an upline hop — see the
        // note in awardSlot(). On the registrant path under the old per-seat
        // rule it stays null, because there the seat IS the placement and its
        // tier is the right answer.
        //
        // A repeat purchase (22 Oct 2026 on) reuses a seat taken on an EARLIER
        // purchase, whose tier is the side chosen THEN. The new 2,500 belongs
        // on the side chosen NOW — the same "cross" the 2026-09-01 note in
        // awardSlot() describes. For a seat just created by this payment the
        // two are the same, so nothing else moves.
        $creditLeg = $perPurchase && in_array((int) $tier, [1, 2], true) ? (int) $tier : null;

        $commission = ($alreadySettled || $isWorthless)
            ? null
            : $this->awardSlot($consultant, $group, $member, $settings, $payment, $creditLeg);

        // Move this account's released depth forward if the placement just
        // balanced a whole stage. Idempotent and monotonic, so a re-run or a
        // concurrent verification can never double-advance it.
        $this->staging->advance($consultant, $group);

        return $commission;
    }

    /**
     * ⚠️ THE DIRECT AMOUNT CAN BELONG TO THE COURSE (2026-10-22).
     *
     * Owner: the new "MASTER IN ELLIOTT WAVE" course pays 5,000 direct where
     * every other course pays 4,000 — "kalin labune 4000 i meke eka wadi wela
     * thiyeno 5000k widiyta". A course with its own amount pays that; every
     * other course pays the standard (Commission Settings), exactly as before.
     *
     * Only the AMOUNT moves. Who earns it, when, and the once-per-payment guard
     * are untouched, and the 2,500, the climb, the Service Groups and the System
     * Run never read a course at all. Public so Commission Health can tell the
     * live server is running this code.
     */
    public function directRateFor(Payment $payment, CommissionSetting $settings): float
    {
        return \App\Support\CourseMoney::directCommission($payment->course_id ? (int) $payment->course_id : null, $settings);
    }

    /**
     * The direct-add bonus for the consultant who personally registered this
     * student. Paid on verification regardless of the payment plan — bringing
     * the student in is what earns it.
     *
     * UNCAPPED as of 2026-08-09, at the owner's explicit request: every
     * student a consultant registers earns this, forever, on whichever of
     * their accounts, with no ceiling and regardless of whether that account
     * is unlocked for group income yet. The old "only the first 3 + 3 seats
     * earn" gate (`seatIsWithinCap()`, now deleted) applied to this too and
     * has been removed entirely.
     *
     * Uplines never get this part; it is only ever called for the registrant —
     * or, since 2026-11-16, for the Requested By consultant who registered the
     * student on a downline's account (RegistrationPayer::directEarner()).
     *
     * $oncePerPayment (that payer case only): the guard looks for a direct row
     * for this payment on ANY account, not just this one. Every other call keeps
     * the original per-account guard unchanged.
     */
    protected function awardDirectBonus(Consultant $consultant, Payment $payment, ?int $tier, CommissionSetting $settings, bool $oncePerPayment = false): ?Commission
    {
        $rate = $this->directRateFor($payment, $settings);

        if ($rate <= 0) {
            return null;
        }

        // One bonus per registration, however many times a verify is retried.
        $alreadyPaid = Commission::where('payment_id', $payment->id)
            ->when(! $oncePerPayment, fn ($q) => $q->where('consultant_id', $consultant->id))
            ->where('type', self::TYPE_DIRECT)
            ->exists();

        if ($alreadyPaid) {
            return null;
        }

        // The seat placeInto() just created (or created on an earlier run).
        $member = GroupMember::where('payment_id', $payment->id)
            ->whereIn('group_id', Group::where('consultant_id', $consultant->id)->select('id'))
            ->first();

        $group = $member ? Group::find($member->group_id) : $this->activeGroupFor($consultant, $settings);

        $commission = Commission::create($this->earnedAtFor($payment) + [
            'consultant_id' => $consultant->id,
            'group_id' => $group->id,
            'group_member_id' => $member?->id,
            'user_id' => $payment->user_id,
            'payment_id' => $payment->id,
            'tier' => $member?->tier ?? $tier,
            'amount' => round($rate, 2),
            'slots' => 1,
            'type' => self::TYPE_DIRECT,
            // Recorded pending, then approved immediately below — see
            // autoApprove(). It stays pending only when the consultant's income
            // is held, which is the one gate nothing may override.
            'status' => 'pending',
            'held_at' => $this->heldAtFor($consultant),
        ]);

        $this->autoApprove($commission);

        return $commission;
    }

    /**
     * Record the service-group slot income for the ONE member just placed.
     * Always `pending` — being recorded is not the same as being payable; see
     * ServiceGroupStaging for the balance rule that gates approval. The
     * direct-add bonus is NOT handled here — awardDirectBonus() does that.
     */
    protected function awardSlot(Consultant $consultant, Group $group, GroupMember $member, CommissionSetting $settings, Payment $payment, ?int $leg = null): Commission
    {
        $amount = round((float) $settings->commission_per_student, 2);

        $commission = Commission::create($this->earnedAtFor($payment) + [
            'consultant_id' => $consultant->id,
            'group_id' => $group->id,
            'group_member_id' => $member->id,
            'user_id' => $member->user_id,
            // ⚠️ THE EARNING PAYMENT, NOT THE SEAT'S (2026-08-29).
            //
            // This was $member->payment_id. The two are the same for every
            // ordinary registration and diverge ONLY when a seat is reused —
            // which is exactly the case the guard above now lets through. Left
            // as the seat's, the new row would be stamped with the buyer's
            // ORIGINAL registration payment, so:
            //   - the guard above could never see it and would award again on
            //     every re-verification, and
            //   - CommissionRuleAudit would still report "no 2,500 reached the
            //     sponsor network" for the purchase, because no row carries it.
            'payment_id' => $payment->id,
            // ⚠️⚠️ THE LEG THIS CREDIT WAS ASKED FOR, NOT THE SEAT'S (2026-09-01).
            //
            // This was `$member->tier`, unconditionally — and that is THE CROSS
            // reported eight times: "eke amount eka cross wela yanwa."
            //
            // placeInto() reuses an existing seat when the student already sits
            // in this account's group, and on that path it DISCARDS the leg the
            // caller asked for. So a climb crediting leg 2 of an account where
            // that person was seated on leg 1 years ago wrote the 2,500 on
            // LEG 1. The row was real and the amount right; only the side was
            // wrong.
            //
            // ⚠️ AND IT IS SELF-PERPETUATING, which is why the SETTLEMENT rule
            // was blamed for eight rounds: every upline credit piling onto one
            // side means the account is never balanced, so it never settles,
            // so the main's rolled-up total never falls — which reads exactly
            // like "main account eke amount eka yanne na".
            //
            // The SEAT is not moved: it records where the student physically
            // sits, and moving it would rewrite the original registration. Only
            // the money is placed on the leg this credit belongs to. $leg is
            // null on the registrant path, where the seat IS the placement.
            'tier' => $leg ?? $member->tier,
            'amount' => $amount,
            'slots' => 1,
            'type' => self::TYPE_SLOT,
            // Pending until an accountant or admin approves it.
            'status' => 'pending',
            'held_at' => $this->heldAtFor($consultant),
        ]);

        $member->update(['commission_amount' => $amount]);

        // ⚠️ AUTO-APPROVED AT VERIFICATION (2026-08-25). The Approve button is gone.
        //
        // Reversed from 08-24, where the slot waited for the run to approve it.
        // The owner's instruction after seeing the queue double-count:
        // "Approve button eka ain kranm hari, okkom auto calculate wen widiyt
        // thiyann... sampuran meke 2500 and 4000 dekama auto yana widiyta
        // hadann, payment eka verify krhama ena widiyta."
        //
        // So approval is no longer a step at ALL — recording and approving are
        // one event, and the only human action left on income is Mark paid.
        //
        // ⚠️ Two consequences that are deliberate, not oversights:
        //  - A System Run still RELEASES slot rows from the leg by stamping
        //    released_at. It does not approve them, because normal rows are
        //    already approved before they ever get there. Its job is clearing
        //    legs, advancing the stage, and forfeiting the excess past the
        //    repeating stage.
        //  - Forfeiting therefore has to reach APPROVED rows, or the
        //    excess-to-company rule would silently stop working the day this
        //    shipped. See ServiceGroupFlush::flush().
        $this->autoApprove($commission);

        return $commission;
    }

    /**
     * Approve a freshly recorded commission without anyone clicking anything.
     *
     * ⚠️ APPROVAL IS NO LONGER A HUMAN STEP (2026-08-24). Only PAYING is.
     *
     * The owner's instruction: "Main or sub SCN account okkogem 1to1, 2to2,
     * 3to3 thiyeno nm aniwaren flush wenn one auto, e tika approve wela ynn
     * one, mark paid withri manual denn one — anith 2500 ha 4000 auto approve
     * wenn one." So every slot and every direct bonus is approved as it is
     * recorded, and the accountant's queue is now purely "Mark paid".
     *
     * `force: true` skips ONLY the staged-release gate — the point of the
     * change. The hold / finance-status gate is NOT overridable by anything, so
     * a restricted or suspended consultant's income still stops dead here
     * exactly as before; that row stays pending and simply cannot be paid.
     *
     * actorId is null: an automatic approval must not stamp whichever
     * accountant happened to be verifying the payment, which would read in the
     * audit trail as a human decision nobody made.
     */
    protected function autoApprove(?Commission $commission): void
    {
        if ($commission) {
            app(CommissionApproval::class)->approve($commission, force: true, actorId: null);
        }
    }

    /**
     * Stamp new income as held from the moment it is recorded, so the display
     * is correct on first render rather than waiting for the next evaluation
     * pass to catch up. This is a convenience only — the AUTHORITATIVE gate
     * that actually stops money moving is the live finance-status check in
     * CommissionController::creditApproved(), so a wrong or missing stamp
     * here can never leak a payout, only show a stale badge briefly.
     *
     * Note: BackfillCommissions replays historical payments through this same
     * path, which would stamp TODAY's hold state onto old rows. That is
     * accepted rather than special-cased — the very next finance evaluation
     * (which runs on nearly every consultant request) corrects it either way.
     */
    protected function heldAtFor(Consultant $consultant): ?\Illuminate\Support\Carbon
    {
        // ⚠️ NEW INCOME IS NEVER BORN HELD (2026-09-15).
        //
        // This returned now() while the owner was restricted or suspended. A
        // held row is invisible on the Service Group legs (legScopeQuery
        // excludes it), so a restricted consultant's brand-new 2,500s also
        // arrived reading zero — the same "0 wen nathi wenn" report that
        // stopped the weekly quota from holding at all. See
        // ConsultantFinanceService::applySideEffects() for why no payout can
        // leak from this: approval and payout each check the live finance
        // status on their own, not this stamp.
        return null;
    }

    /**
     * The `earned_at` fragment to merge into a Commission::create() array.
     *
     * ⚠️ RETURNS AN EMPTY ARRAY WHEN THE COLUMN IS ABSENT. Naming a column
     * that has not been added yet throws "unknown column" and would take out
     * payment verification itself — the one thing on this path that must never
     * fail. FinanceSchemaRepair::ensure() adds it, but a half-deployed server
     * is exactly the case this guard exists for.
     *
     * The value is the PAYMENT DATE, not now(): the row is inserted at
     * verification, which at 1000+ registrations a day is routinely a week
     * after the payslip went in, so stamping it now() put every commission
     * and every finance report on the wrong day.
     *
     * ⚠️ `paid_at` — the "Payment Date" the consultant fills in on the Confirm
     * Payment dialog — OVERRIDES the earlier choice of created_at (2026-09-10),
     * at the owner's direction on 2026-09-11: created_at is when the
     * registration row was written, which is not the date the money moved.
     * The earlier worry was a hand-typed date being wrong; entry validation is
     * `before_or_equal:today`, so it can never run forward, only back.
     *
     * Falls back to created_at because paid_at is nullable on older rows.
     *
     * @return array<string, \Illuminate\Support\Carbon|null>
     */
    protected function earnedAtFor(Payment $payment): array
    {
        if (! \App\Services\FinanceSchemaRepair::earnedAtReady()) {
            return [];
        }

        return ['earned_at' => $payment->paid_at ?? $payment->created_at];
    }

    /**
     * The account's service group. Groups no longer close or roll over — each
     * account keeps a single group that accepts students indefinitely, with
     * only its first slots earning.
     */
    protected function activeGroupFor(Consultant $consultant, CommissionSetting $settings): Group
    {
        $group = Group::where('consultant_id', $consultant->id)->orderBy('id')->first();

        if ($group) {
            return $group;
        }

        $group = Group::create([
            'group_code' => 'GRP-'.strtoupper(Str::random(6)),
            'consultant_id' => $consultant->id,
            'name' => 'Service Group',
            'capacity' => (int) $settings->group_size,
            'filled_slots' => 0,
            'tier1_filled' => 0,
            'tier2_filled' => 0,
            'status' => 'open',
            'total_commission_earned' => 0,
            'opened_at' => now(),
        ]);

        $consultant->update(['active_group_id' => $group->id]);

        return $group;
    }
}
