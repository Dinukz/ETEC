<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Payment;
use App\Models\SystemRun;
use Illuminate\Support\Collection;

/**
 * READ-ONLY. Check every rupee the engine has recorded against the rules.
 *
 * Asked for 2026-08-29: "me wage data add krla check krgnn hariyt commission
 * (2500), direct bonus (4000) and upline (2500) ewa check krgnn kramyk naddd...
 * courses ekak buy krhm placement dala ewa wala 2500 udadt cal wenwada blann.
 * Ita passe system run eka yawala meka hariyt account walta stage balance wela
 * amount ynawada kiyla check krgnn manual krn nathuw kramyk nadda?"
 *
 * Until now the only way to answer that was to open one payment at a time. This
 * walks the whole table and reports what does not match, so a run can be checked
 * on one page instead of by hand.
 *
 * ⚠️ IT WRITES NOTHING. Every method here is a read. The one repair that came
 * out of it (stages) is a separate, explicit action on the controller — an audit
 * that silently fixes things cannot be trusted to report honestly.
 *
 * ⚠️ IT IS NOT THE ENGINE. Where it can, it checks a SHAPE rather than
 * recomputing what CommissionService would do — a checker that re-derives the
 * answer the same way is guaranteed to agree with a bug. The upline test looks
 * for a GAP in the chain of credited accounts, which is the signature all three
 * reported climb failures left behind.
 */
class CommissionRuleAudit
{
    /** Rules, in the order a reader wants to see them. */
    public const RULES = [
        'missing_direct' => 'Direct bonus was never recorded',
        'missing_slot' => 'Service group 2,500 was never recorded',
        'unexpected_slot_on_half' => 'A half payment earned a 2,500 (it should pay the direct bonus only)',
        'unexpected_direct_on_balance' => 'A balance payment paid the direct bonus a second time',
        'money_on_zero_payment' => 'A free / zero payment earned commission',
        'direct_on_self_purchase' => 'A consultant was paid the direct bonus for buying their own course',
        'duplicate_direct' => 'The direct bonus was recorded more than once',
        'duplicate_slot' => 'The 2,500 was recorded more than once on one account',
        'wrong_direct_amount' => 'The direct bonus is not the rate for its course',
        'wrong_slot_amount' => 'The slot income is not the configured rate',
        'upline_gap' => 'The 2,500 stopped climbing part-way up the account tree',
        'leg_not_recorded' => 'A 2,500 is not on Service Group 1 or 2 at all',
        'all_credits_on_one_leg' => 'Every 2,500 for this payment landed on the same side of every account',
    ];

    /**
     * ⚠️ RULES THAT ARE WORTH A LOOK, NOT DEFECTS (2026-09-02).
     *
     * These are reported separately from `violations` so that "0 problems"
     * keeps meaning "nothing is broken". A rule that cries wolf on correct data
     * makes the whole page unreadable — an accountant who learns to ignore one
     * row learns to ignore the list.
     *
     * `all_credits_on_one_leg` earned its way here on the day it shipped. It
     * looks for the signature of THE CROSS (2026-09-01): a payment whose credits
     * all landed on the same side of every account, which is what a climb that
     * discards the requested leg produces. But a climb through a genuinely
     * one-sided branch produces exactly the same shape and is completely
     * correct — the first legitimate scenario it was pointed at
     * (BuyingAnAccountPaysTheUplineTest) tripped it.
     *
     * It is kept because the shape is still the fastest way to spot a
     * re-introduced cross by eye. It is not a violation because, on its own, it
     * does not mean anything is wrong.
     */
    public const ADVISORY = [
        'all_credits_on_one_leg',
        'upline_missing_sponsor' => 'Nothing reached the sponsor network above this consultant',
    ];

    /** Cached placement maps, one per owning person. */
    private array $maps = [];

    /**
     * @return array{
     *   checked: int, skipped_removed: int, violations: array<int, array<string, mixed>>,
     *   summary: array<string, int>, rates: array<string, float>,
     *   stage_issues: array<int, array<string, mixed>>, stage_checkable: bool
     * }
     */
    public function run(int $limit = 0): array
    {
        $settings = CommissionSetting::current();
        $slotRate = (float) $settings->commission_per_student;
        $directRate = (float) ($settings->direct_commission ?? 0);

        // ⚠️ A COURSE MAY PAY ITS OWN DIRECT (2026-10-22) — the Elliott Wave
        // course pays 5,000 where the standard is 4,000. Read here straight off
        // the courses (deleted ones too) rather than through the engine's own
        // helper, so the audit still checks the engine instead of agreeing with
        // it. [course_id => amount], only for courses that set one.
        $courseDirect = \App\Models\Course::withTrashed()->get()
            ->mapWithKeys(fn ($c) => [(int) $c->id => (float) ($c->getAttribute('direct_commission') ?? 0)])
            ->filter(fn (float $amount) => $amount > 0)
            ->all();

        $violations = [];
        $checked = 0;
        $skippedRemoved = 0;

        $query = Payment::query()
            ->where('status', 'verified')
            ->whereNotNull('consultant_id');

        if ($limit > 0) {
            $query->whereIn('id', Payment::where('status', 'verified')
                ->whereNotNull('consultant_id')
                ->orderByDesc('id')
                ->limit($limit)
                ->pluck('id'));
        }

        // Chunked so a network with 10,000+ payments never loads the table into
        // memory. Commissions for the whole chunk come back in ONE query.
        $query->chunkById(200, function (Collection $payments) use (&$violations, &$checked, &$skippedRemoved, $slotRate, $directRate, $courseDirect) {
            // ⚠️ A HAND-REMOVED PAYMENT CANNOT BE AUDITED, AND MUST NOT BE
            // REPORTED AS BROKEN (2026-09-06).
            //
            // Removing a wrong 2,500 leaves the seat in place with no live slot
            // row, which is precisely the shape of `missing_slot`. Without this
            // the audit would report every deliberate correction as a permanent
            // defect — and a page that reports correct data as a problem stops
            // being read (the same reasoning behind self::ADVISORY). Counted and
            // named instead, so the skip is visible rather than silent.
            $removedPaymentIds = \App\Services\FinanceSchemaRepair::removalReady()
                ? Commission::whereIn('payment_id', $payments->pluck('id'))
                    ->whereNotNull('removed_at')
                    ->pluck('payment_id')
                    ->unique()
                    ->flip()
                : collect();

            $rows = Commission::whereIn('payment_id', $payments->pluck('id'))
                ->where('status', '!=', 'cancelled')
                // `tier` is selected for checkLegs() — without it every row
                // reads as legless and the audit reports the whole table.
                ->get(['id', 'payment_id', 'consultant_id', 'type', 'amount', 'tier'])
                ->groupBy('payment_id');

            $accounts = Consultant::whereIn('id', $payments->pluck('consultant_id')->unique())
                ->with('owner')
                ->get()
                ->keyBy('id');

            foreach ($payments as $payment) {
                if ($removedPaymentIds->has($payment->id)) {
                    $skippedRemoved++;

                    continue;
                }

                $checked++;

                $account = $accounts[$payment->consultant_id] ?? null;

                if (! $account) {
                    continue;
                }

                $expectedDirect = $courseDirect[(int) $payment->course_id] ?? $directRate;

                foreach ($this->checkPayment($payment, $account, $rows[$payment->id] ?? collect(), $slotRate, $expectedDirect) as $v) {
                    $violations[] = $v;
                }
            }
        }, 'id');

        // ⚠️ ADVISORIES ARE SPLIT OUT (2026-09-02). See self::ADVISORY.
        $notices = array_values(array_filter($violations, fn ($v) => in_array($v['rule'], self::ADVISORY, true)));
        $violations = array_values(array_filter($violations, fn ($v) => ! in_array($v['rule'], self::ADVISORY, true)));

        $summary = [];

        foreach ($violations as $v) {
            $summary[$v['rule']] = ($summary[$v['rule']] ?? 0) + 1;
        }

        $noticeSummary = [];

        foreach ($notices as $v) {
            $noticeSummary[$v['rule']] = ($noticeSummary[$v['rule']] ?? 0) + 1;
        }

        [$stageIssues, $stageCheckable] = $this->auditStages();

        return [
            'checked' => $checked,
            'skipped_removed' => $skippedRemoved,
            'violations' => $violations,
            'summary' => $summary,
            'notices' => $notices,
            'notice_summary' => $noticeSummary,
            'rates' => ['slot' => $slotRate, 'direct' => $directRate],
            'stage_issues' => $stageIssues,
            'stage_checkable' => $stageCheckable,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function checkPayment(Payment $payment, Consultant $account, Collection $rows, float $slotRate, float $directRate): array
    {
        $out = [];
        $note = fn (string $rule, string $detail) => [
            'rule' => $rule,
            'payment' => $payment->payment_code,
            'payment_id' => $payment->id,
            'account' => $account->scn_code ?? $account->consultant_code,
            'plan' => $payment->payment_type ?? 'full',
            'detail' => $detail,
        ];

        $amount = (float) $payment->amount;
        $type = $payment->payment_type ?? 'full';

        $direct = $rows->where('type', CommissionService::TYPE_DIRECT);
        $slots = $rows->where('type', CommissionService::TYPE_SLOT);
        $ownSlot = $slots->where('consultant_id', $account->id);

        // ---- A payment worth nothing earns nothing -------------------------
        if ($amount <= 0) {
            if ($rows->isNotEmpty()) {
                $out[] = $note('money_on_zero_payment', 'LKR '.number_format($rows->sum('amount')).' recorded against a zero-value payment.');
            }

            return $out;
        }

        // ⚠️ A SELF-PURCHASE IS NOT A BLANKET EXCLUSION (rule set 2026-08-22).
        //
        // My first version of this check flagged ANY commission on a consultant
        // buying their own course, and it immediately fired on healthy data.
        // The engine is right and the check was wrong: the two earnings answer
        // different questions.
        //
        //   the 2,500 SLOT   is for FILLING A SEAT. The buyer really does
        //                    occupy it and it still has to pair. Owner:
        //                    "eke course ekak buy krahama thamanma consultant
        //                     2500 add wenn one, eka add wela na kohe wath."
        //   the 4,000 DIRECT is for BRINGING SOMEONE IN. Nobody was, so it is
        //                    withheld — that was the 08-19 free-money hole.
        //
        // The upline is credited either way. So only the direct bonus is a
        // violation here.
        $selfPurchase = $this->isSelfPurchase($account, $payment);

        $expectsDirect = $type !== 'balance' && ! $selfPurchase;
        $expectsSlot = $type !== 'half';

        // ---- The 4,000 -----------------------------------------------------
        if ($expectsDirect && $direct->isEmpty()) {
            $out[] = $note('missing_direct', 'No direct bonus for a '.$type.' payment.');
        }

        if (! $expectsDirect && $direct->isNotEmpty()) {
            $out[] = $selfPurchase
                ? $note('direct_on_self_purchase', 'Nobody was brought in, so no direct bonus is due.')
                : $note('unexpected_direct_on_balance', 'The direct bonus was already earned on the half payment.');
        }

        if ($direct->count() > 1) {
            $out[] = $note('duplicate_direct', $direct->count().' direct bonus rows for one payment.');
        }

        foreach ($direct as $row) {
            if ($directRate > 0 && abs((float) $row->amount - $directRate) > 0.01) {
                $out[] = $note('wrong_direct_amount', 'LKR '.number_format((float) $row->amount).' recorded, the rate for this course is LKR '.number_format($directRate).'.');
                break;
            }
        }

        // ---- The 2,500 on the account the student was placed under ---------
        if ($expectsSlot && $ownSlot->isEmpty()) {
            $out[] = $note('missing_slot', 'No service-group income on the placement account.');
        }

        if (! $expectsSlot && $ownSlot->isNotEmpty()) {
            $out[] = $note('unexpected_slot_on_half', 'A half payment must pay the direct bonus only.');
        }

        if ($ownSlot->count() > 1) {
            $out[] = $note('duplicate_slot', $ownSlot->count().' slot rows on the same account for one payment.');
        }

        foreach ($ownSlot as $row) {
            if (abs((float) $row->amount - $slotRate) > 0.01) {
                $out[] = $note('wrong_slot_amount', 'LKR '.number_format((float) $row->amount).' recorded, the rate is LKR '.number_format($slotRate).'.');
                break;
            }
        }

        // ---- ...and that it climbed ----------------------------------------
        if ($expectsSlot && $ownSlot->isNotEmpty()) {
            foreach ($this->checkClimb($account, $slots) as $problem) {
                $out[] = $note($problem['rule'], $problem['detail']);
            }

            // ⚠️ THE LEG, WHICH THIS AUDIT COULD NOT SEE UNTIL 2026-09-01.
            //
            // Twelve rules and not one of them looked at `tier`. So the page
            // that reported "1 problem across 19 payments" was structurally
            // blind to a credit landing on the wrong SIDE of the right account
            // — the exact fault reported eight times, with the right amount on
            // the right account every time.
            foreach ($this->checkLegs($slots) as $problem) {
                $out[] = $note($problem['rule'], $problem['detail']);
            }
        }

        return $out;
    }

    /**
     * Did the 2,500 keep climbing, or stop part-way?
     *
     * ⚠️ A GAP IS THE SIGNATURE. The climb failing has been reported three times
     * and every time it left the same trace: the account the student sits on has
     * its row, one or more accounts ABOVE it have theirs, and somewhere in
     * between an account has none. So rather than recompute the chain the engine
     * should have walked — a check that re-derives the answer the same way would
     * simply agree with the bug — this walks the RECORDED placement upward and
     * reports the first level that is empty.
     *
     * Stopping below the owning person MAIN is correct and is not a gap: income
     * never lands on a main, it rolls up. See CommissionService.
     *
     * @return array<int, array{rule: string, detail: string}>
     */
    /**
     * Is every 2,500 for this payment on a real leg, and did the climb spread
     * across both sides where it should have?
     *
     * ⚠️ WHAT THIS CAN AND CANNOT SAY. It does NOT recompute which leg each
     * credit belongs on — a checker that re-derives the engine's answer the
     * same way would simply agree with the engine, which is how this fault
     * survived eight rounds. It reports two things a reader can act on:
     *
     *   - a credit carrying no leg at all, which can never pair and so can
     *     never be paid; and
     *   - a payment whose credits ALL landed on one side across three or more
     *     accounts, which is the signature the cross leaves. A legitimate
     *     climb through a one-sided branch looks the same, so this is worded as
     *     something to look at, not as a defect.
     *
     * @return array<int, array{rule: string, detail: string}>
     */
    protected function checkLegs(Collection $slots): array
    {
        $out = [];

        $legless = $slots->reject(fn ($row) => in_array((int) $row->tier, [1, 2], true));

        if ($legless->isNotEmpty()) {
            $out[] = [
                'rule' => 'leg_not_recorded',
                'detail' => $legless->count().' credit(s) carry no Service Group, so they can never pair.',
            ];
        }

        $placed = $slots->filter(fn ($row) => in_array((int) $row->tier, [1, 2], true));

        if ($placed->count() >= 3 && $placed->pluck('tier')->unique()->count() === 1) {
            $out[] = [
                'rule' => 'all_credits_on_one_leg',
                'detail' => 'All '.$placed->count().' credits landed on Service Group '
                    .(int) $placed->first()->tier.'. Worth checking — an account that only ever '
                    .'receives on one side can never balance, and so never settles.',
            ];
        }

        return $out;
    }

    protected function checkClimb(Consultant $account, Collection $slots): array
    {
        $owner = $account->owningPerson();

        if (! $owner) {
            return [];
        }

        $credited = $slots->pluck('consultant_id')->map(fn ($id) => (int) $id)->all();
        $map = $this->mapFor($owner);

        $node = (int) $account->id;
        $out = [];
        $seen = [];

        for ($depth = 0; $depth < CommissionService::MAX_UPLINE_DEPTH; $depth++) {
            $parent = (int) ($map[$node]['under_id'] ?? 0);

            // Top of this person tree, or the main itself: the climb is over.
            if ($parent === 0 || $parent === (int) $owner->id || isset($seen[$parent])) {
                break;
            }

            $seen[$parent] = true;

            if (! in_array($parent, $credited, true)) {
                $code = $map[$node]['under_code'] ?? null;
                $out[] = [
                    'rule' => 'upline_gap',
                    'detail' => 'Nothing reached '.($code ?: 'account #'.$parent).', which sits directly above it.',
                ];
                break;
            }

            $node = $parent;
        }

        // And the hop across to whoever sponsored this person.
        if ($owner->parent_consultant_id) {
            $sponsor = Consultant::find($owner->parent_consultant_id);
            $sponsorOwnerId = $sponsor?->owningPerson()?->id;

            if ($sponsorOwnerId) {
                $sponsorAccountIds = Consultant::where('id', $sponsorOwnerId)
                    ->orWhere('owner_consultant_id', $sponsorOwnerId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (array_intersect($credited, $sponsorAccountIds) === []) {
                    $out[] = [
                        'rule' => 'upline_missing_sponsor',
                        'detail' => 'No 2,500 reached the sponsor network above this consultant.',
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Is any account claiming a stage it never actually paired for?
     *
     * ⚠️ THIS IS THE 2026-08-29 DAMAGE REPORT. runAll() used to advance an
     * account stage whenever somebody ELSE flush released its rows, so accounts
     * drained by their main climbed 1-to-1 to 2-to-2 to 3-to-3 without ever
     * balancing their own two sides. They then needed 7,500 a side and quietly
     * stopped running. That bump is gone, but the stages it already wrote are
     * still sitting on the rows and no run will correct them.
     *
     * A real pairing is recorded in system_runs.accounts. Anything above that
     * count was not earned.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    protected function auditStages(): array
    {
        if (! FinanceSchemaRepair::stagingReady()) {
            return [[], false];
        }

        $pairings = [];
        $sawIdentifiers = false;

        foreach (SystemRun::orderBy('id')->get(['id', 'accounts']) as $run) {
            foreach ($run->settledAccounts() as $entry) {
                $rounds = max(1, (int) ($entry['rounds'] ?? 1));
                $id = $entry['account_id'] ?? null;
                $code = $entry['code'] ?? null;

                if ($id !== null) {
                    $sawIdentifiers = true;
                    $pairings['id:'.(int) $id] = ($pairings['id:'.(int) $id] ?? 0) + $rounds;
                } elseif ($code !== null) {
                    $sawIdentifiers = true;
                    $pairings['code:'.$code] = ($pairings['code:'.$code] ?? 0) + $rounds;
                }
            }
        }

        // No run has ever recorded which accounts it settled, so a stage cannot
        // be judged against anything. Say so rather than reporting "all clear" —
        // a checker that cannot see is not a checker that found nothing.
        if (! $sawIdentifiers) {
            return [[], false];
        }

        $cap = ServiceGroupStaging::ONE_TIME_STAGES;
        $config = ServiceGroupStaging::config();
        $issues = [];

        Consultant::whereNotNull('scn_code')
            ->where('lifetime_pair_stage', '>', 0)
            ->chunkById(500, function (Collection $accounts) use ($pairings, $cap, $config, &$issues) {
                foreach ($accounts as $account) {
                    $paired = max(
                        $pairings['id:'.$account->id] ?? 0,
                        $pairings['code:'.$account->scn_code] ?? 0,
                    );

                    $earned = min($cap, $paired);
                    $claimed = (int) $account->lifetime_pair_stage;

                    if ($claimed > $earned) {
                        $step = ServiceGroupStaging::stepFor($claimed, $config);
                        $should = ServiceGroupStaging::stepFor($earned, $config);

                        $issues[] = [
                            'id' => $account->id,
                            'code' => $account->scn_code,
                            'claimed' => $claimed,
                            'earned' => $earned,
                            'paired' => $paired,
                            'claimed_label' => $step.'-to-'.$step,
                            'should_label' => $should.'-to-'.$should,
                        ];
                    }
                }
            });

        return [$issues, true];
    }

    /** @return array<int, array<string, mixed>> */
    protected function mapFor(Consultant $owner): array
    {
        return $this->maps[$owner->id] ??= app(AccountPlacementMap::class)->for($owner);
    }

    protected function isSelfPurchase(Consultant $account, Payment $payment): bool
    {
        $ownerUserId = $account->owningPerson()?->user_id;

        return $ownerUserId !== null && (int) $ownerUserId === (int) $payment->user_id;
    }
}
