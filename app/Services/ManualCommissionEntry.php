<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ HAND-ENTERED COMMISSION — THE FALLBACK WHEN THE ENGINE MISSES ONE.
 *
 * Asked for 2026-08-27, before going live: "student and consultant 10000k wadi
 * pramanay add wenwa, ethkot calculate wen nathi prshn awoth loku prshnyk athi
 * wenwa — eka nisa manual 2500 add wen widiytath, Service Group Management
 * ekat manual 2500 saha direct 4000, anith eka upline 2500 add wen widiyt dala
 * denn. Option dekak one: SCN eka group ehekt withrk ynn, saha upline group
 * load krla e okkotam add krnn puluwn widiyt."
 *
 * So two scopes:
 *   - ACCOUNT : one row, on the account named.
 *   - UPLINE  : that row PLUS the climb — every account above it, exactly the
 *               chain CommissionService walks when a payment is verified.
 *
 * ⚠️ THIS MINTS MONEY. Three guards, none of them optional:
 *   1. A STUDENT MUST BE NAMED. commissions.user_id is NOT NULL, and that is a
 *      good constraint here rather than an obstacle — a hand-made commission
 *      with nobody attached is unauditable, and the whole point of this tool is
 *      to repair a specific student's missing income.
 *   2. DUPLICATES ARE REFUSED, not silently created. The dangerous failure mode
 *      is clicking twice; an identical row (same account, student, type) stops
 *      the whole entry so nobody is paid the same 2,500 twice.
 *   3. It is written in ONE transaction, so a refusal partway up the climb
 *      leaves nothing behind.
 *
 * What it deliberately does NOT do: release. A hand-entered slot row lands on
 * the leg like any other and waits for a System Run, so the staged rules still
 * govern when it is paid. Only the direct bonus is payable immediately, because
 * it never goes on a leg at all.
 */
class ManualCommissionEntry
{
    public const SCOPE_ACCOUNT = 'account';

    public const SCOPE_UPLINE = 'upline';

    public const TYPE_SLOT = 'slot';

    public const TYPE_DIRECT = 'direct';

    /**
     * @return array{created: array<int, array{code: string, amount: float, type: string}>, skipped: array<int, string>}
     *
     * @throws \RuntimeException when the entry would duplicate money already recorded
     */
    public function add(
        Consultant $account,
        ?User $student,
        string $type,
        int $leg,
        string $scope,
        ?int $actorId = null,
        // ⚠️ LAST, so the existing positional callers keep working. The day this
        // income belongs to: a System Run settling up to this date picks it up.
        // Null keeps the old behaviour (dated by when it was written).
        ?\Illuminate\Support\Carbon $earnedOn = null,
        // The course the student registered on (2026-10-22). Only changes a
        // DIRECT entry, and only for a course with its own amount (the Elliott
        // Wave course pays 5,000); null = the standard. A 2,500 is the same for
        // every course.
        ?int $courseId = null,
    ): array {
        $settings = CommissionSetting::current();

        // ⚠️ THE STUDENT IS OPTIONAL SINCE 2026-09-15.
        //
        // Owner: "methan student kiyla ekak thiyeno eka awshya wen na". The
        // form's 2,764-long student list was one of the two reasons this tool
        // was unusable, and on live it had never once been used successfully.
        //
        // commissions.user_id is still NOT NULL, so when no student is named
        // the row is attributed to the account's OWN person instead. Placement
        // accounts carry no user of their own (all 1,176 on live have a NULL
        // user_id), so they fall back to the person who owns them — which is
        // also who the money ultimately belongs to.
        $studentId = $student?->id
            ?? $account->user_id
            ?? $account->owningPerson()?->user_id;

        if (! $studentId) {
            throw new \RuntimeException(
                'That account has no owner on record, so there is nobody to record the commission against.'
            );
        }

        $amount = $type === self::TYPE_DIRECT
            ? \App\Support\CourseMoney::directCommission($courseId, $settings)
            : (float) $settings->commission_per_student;

        if ($amount <= 0) {
            throw new \RuntimeException('The commission rate is not configured — set it in Commission Settings first.');
        }

        // The direct bonus follows the PERSON who registered, never the climb,
        // so an "upline" scope makes no sense for it. Same rule as the engine:
        // "Uplines NEVER get this."
        //
        // Each target carries its OWN leg (see chainFrom) — only the account
        // named in the form takes the form's leg.
        $targets = $type === self::TYPE_DIRECT || $scope === self::SCOPE_ACCOUNT
            ? collect([['account' => $account, 'leg' => $leg]])
            : $this->chainFrom($account, $leg);

        // Named the same way CommissionService::earnedAtFor() does it, and for
        // the same reason: on a database where the column has not been added yet
        // (FinanceSchemaRepair adds it lazily), naming `earned_at` in an INSERT
        // throws "unknown column" and takes the whole entry down.
        $earnedAt = $earnedOn !== null && \App\Services\FinanceSchemaRepair::earnedAtReady()
            ? ['earned_at' => $earnedOn]
            : [];

        return DB::transaction(function () use ($targets, $student, $studentId, $type, $amount, $actorId, $earnedAt) {
            $created = [];
            $skipped = [];

            foreach ($targets as ['account' => $target, 'leg' => $targetLeg]) {
                $rowType = $type === self::TYPE_DIRECT
                    ? CommissionService::TYPE_DIRECT
                    : CommissionService::TYPE_SLOT;

                // ⚠️ THE DUPLICATE GUARD ONLY APPLIES TO A NAMED STUDENT.
                //
                // It keys on (account, student, type). With no student named
                // the row is attributed to the account's own person, so this
                // would refuse every entry after the first one on that account
                // — which is the opposite of what the tool is for. Repeats are
                // legitimate there, and double-clicking is guarded on the form
                // instead (the button disables itself on submit).
                if ($student !== null) {
                    $existing = Commission::where('consultant_id', $target->id)
                        ->where('user_id', $student->id)
                        ->where('type', $rowType)
                        ->where('status', '!=', 'cancelled')
                        ->exists();

                    if ($existing) {
                        // Refuse the WHOLE entry rather than part of it — a partial
                        // climb is harder to reason about afterwards than none.
                        throw new \RuntimeException(sprintf(
                            '%s already has a %s commission recorded for this student. Nothing was added — check the Service Income list before entering it by hand.',
                            $target->scn_code ?? $target->consultant_code,
                            $type === self::TYPE_DIRECT ? 'direct bonus' : 'service group',
                        ));
                    }
                }

                $this->writeRow($target, $studentId, $rowType, $targetLeg, $amount, $actorId, $earnedAt);

                $created[] = [
                    'code' => $target->scn_code ?? $target->consultant_code,
                    'amount' => $amount,
                    'type' => $rowType,
                    // Which Service Group it landed on — for the admin alert email (2026-10-29).
                    'leg' => $targetLeg,
                ];
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * Write ONE hand-entered row, exactly as every hand entry is written.
     *
     * Shared with ManualEntryMainRepair so a row added afterwards to complete an
     * old entry is indistinguishable from one the entry would have written
     * itself — same columns, same approval path.
     *
     * @param  array<string, mixed>  $extra  e.g. ['earned_at' => …]
     */
    public function writeRow(Consultant $target, int $studentId, string $rowType, int $leg, float $amount, ?int $actorId, array $extra = []): Commission
    {
        $group = Group::where('consultant_id', $target->id)->orderBy('id')->first();

        $commission = Commission::create($extra + [
            'consultant_id' => $target->id,
            'user_id' => $studentId,
            'group_id' => $group?->id,
            'type' => $rowType,
            'tier' => $leg,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        // Approved the same way the engine approves this kind of row, so a
        // hand-entered one behaves identically from here on. force: skips only
        // the release gate; a held or finance-suspended consultant's income
        // still stops dead.
        app(CommissionApproval::class)->approve($commission, force: true, actorId: $actorId);

        return $commission;
    }

    /**
     * Every account a hand entry reaches, each with the LEG it lands on — the
     * same accounts, in the same order, on the same legs as
     * CommissionService::creditUpline() when a payment is verified.
     *
     * ⚠️⚠️ REWRITTEN 2026-09-27 — THE MAIN ACCOUNTS WERE BEING SKIPPED.
     *
     * Reported by the owner with Udara Dilshantha's own screen: a manual 2,500
     * for Lakshan Maduranga (in Udara's downline) reached SCN-00113 — SG1 went
     * 32,500 → 35,000 — while Udara's MAIN, SCN-00112, did not move at all.
     * *"second ekat add wela eth main ekat add wela na … anith ayageth mehem
     * aththe."*
     *
     * This walk was written before 2026-09-02, when a main account did not earn
     * rows of its own — it rolled its children up. So it carried two rules that
     * the engine dropped that day and this file never did:
     *
     *   1. On the registrant's own tree it stopped one hop SHORT of the main
     *      ("the main rolls up, it never earns a row of its own").
     *   2. On the way up the sponsor chain it credited each sponsor ACCOUNT and
     *      then jumped straight to that person's own sponsor — never walking
     *      the sponsor's own tree up to THEIR main. That is exactly Udara's
     *      case: SCN-00113 credited, SCN-00112 skipped.
     *
     * Since 2026-09-02 the engine credits every account on both walks, main
     * included (creditOwnAccountAncestors after every hop). This is now the
     * same shape, hop for hop.
     *
     * ⚠️ THE LEG IS CHOSEN PER HOP, NOT ONCE FOR THE WHOLE ENTRY. It used to
     * stamp the form's leg on every row up the chain. The engine asks, at each
     * hop, which side of that account the money arrived through — the placement
     * map's tier on the owner's own tree, and sideUnderParent() between people.
     * Only the first row (the account the admin named) takes the form's leg.
     *
     * @return \Illuminate\Support\Collection<int, array{account: Consultant, leg: int}>
     */
    public function chainFrom(Consultant $account, int $leg = 1): \Illuminate\Support\Collection
    {
        $chain = collect([['account' => $account, 'leg' => $leg]]);
        $seen = [$account->id => true];

        // 1. The registrant's OWN tree, from this account up to and including
        //    their main — creditOwnAccountAncestors() in the engine.
        $this->climbOwnTree($account, $leg, $chain, $seen);

        // 2. Then the sponsor chain, person by person — creditUpline() — and
        //    after every sponsor account, that sponsor's own tree up to THEIR
        //    main, which is the step that was missing.
        $engine = app(CommissionService::class);
        $registrantId = (int) $account->owningPerson()->id;
        $node = $account->is_hidden ? $account->owner : $account;
        $depth = 0;

        while ($node && $node->parent_consultant_id && $depth++ < CommissionService::MAX_UPLINE_DEPTH) {
            $sponsor = Consultant::with('owner')->find($node->parent_consultant_id);

            if (! $sponsor || isset($seen[$sponsor->id])) {
                break;
            }

            // The engine's stop: a climb that comes back round to the person it
            // started from is not an upline.
            if ((int) $sponsor->owningPerson()->id === $registrantId) {
                break;
            }

            $side = $engine->sideUnderParent($node) ?? $leg;

            $seen[$sponsor->id] = true;
            $chain->push(['account' => $sponsor, 'leg' => $side]);

            $this->climbOwnTree($sponsor, $side, $chain, $seen);

            $node = $sponsor->is_hidden ? $sponsor->owner : $sponsor;
        }

        return $chain;
    }

    /**
     * From $from up its owner's own tree to and including their main, each
     * parent on the side the child hangs on.
     *
     * @param  \Illuminate\Support\Collection<int, array{account: Consultant, leg: int}>  $chain
     * @param  array<int, bool>  $seen
     */
    protected function climbOwnTree(Consultant $from, int $fallbackLeg, \Illuminate\Support\Collection $chain, array &$seen): void
    {
        $owner = $from->owningPerson();

        // placeDownlineSets on: a hand entry is written today, so it follows the
        // rules a payment verified today would (ClimbRules::appliesTo is true for
        // anything after ClimbRules::since()).
        $placements = app(AccountPlacementMap::class)->for($owner);
        $node = $from;

        for ($depth = 0; $depth < CommissionService::MAX_UPLINE_DEPTH; $depth++) {
            $entry = $placements[$node->id] ?? null;
            $parentId = $entry['under_id'] ?? null;

            if (! $parentId || isset($seen[$parentId])) {
                break;
            }

            $parent = Consultant::with('owner')->find($parentId);

            if (! $parent || (int) $parent->owningPerson()->id !== (int) $owner->id) {
                break;
            }

            $seen[$parent->id] = true;

            // The side of $parent that $node hangs on. A placement the map could
            // not resolve keeps the leg it arrived on rather than landing on no
            // leg at all — a NULL tier is invisible to every leg query.
            $tier = in_array((int) ($entry['tier'] ?? 0), [1, 2], true) ? (int) $entry['tier'] : $fallbackLeg;

            $chain->push(['account' => $parent, 'leg' => $tier]);
            $node = $parent;
        }
    }
}
