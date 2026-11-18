<?php

namespace App\Services;

use App\Models\CommissionSetting;

/**
 * ⚠️⚠️ THE SYSTEM RUN RULE. ONE PLACE. EVERY CALLER READS IT FROM HERE.
 *
 * Asked for 2026-08-30, after the fifth round of stage-by-stage corrections:
 *
 *   "Aye mn stage wise kiynn oned? Logic eka kalin update krata meka wada
 *    krn nane, hariyt manual ekekin eka fix krn wenwa. Mt meke system run ekat
 *    withrk adala COMMON RULES tikak dala eka save krala, e widiyt system run
 *    eka yana widiyt hadann barid? Mokad issrahat consultant la 1000k withr
 *    enkota meka ekin eka balance wen nathi ewa hadann giyoth prashna godak
 *    athi wenwa. Common rule ekak hadala denn puluwnd, system run ekat adala,
 *    anith logic walata bada wen nathi wen widiyt."
 *
 * = stop fixing this case by case; put ONE set of rules that only the System
 *   Run uses, and make the run obey them — because at 1,000 consultants,
 *   correcting unbalanced accounts by hand is impossible.
 *
 * They are right, and the reason it kept needing hand-correction is that the
 * rule lived in FOUR places that drifted apart: flush(), state(), fullPlan()
 * and the run screen's wording. Every "fix" corrected one copy. This class is
 * now the only definition; the others delegate and hold no logic of their own.
 *
 * ----------------------------------------------------------------------------
 * THE RULE, in full. It is per SCN ACCOUNT and there are no special cases.
 *
 *   stage 1 (1-to-1): both sides need 2,500
 *   stage 2 (2-to-2): both sides need 5,000
 *   stage 3 (3-to-3): both sides need 7,500, and REPEATS forever
 *
 *   if SG1 >= threshold AND SG2 >= threshold:
 *        release exactly the threshold from EACH side
 *        stage 1 and 2 : whatever is left stays on the leg for next time
 *        stage 3       : whatever is left is closed off to company revenue
 *        the account moves up one stage
 *   otherwise:
 *        NOTHING happens. No release, no forfeit, no stage change, and the
 *        account keeps every rupee it holds.
 *
 * ⚠️ "OTHERWISE, NOTHING" IS THE HALF THAT KEEPS BREAKING. Reported four times
 * with real numbers: SCN-00101 at 27,500 / 2,500 lost 7,500 off SG1 while SG2
 * did not move; SCN-00102 at 0 / 25,000 released 7,500 with an empty side;
 * SCN-00104 at stage 1 gave up 2,500 from SG1 and nothing from SG2; SCN-00109
 * at 1-to-1 lost 5,000 a side instead of 2,500. Every one of those is an
 * account that this rule says must not have moved at all.
 *
 * ⚠️⚠️ A MAIN NEVER SETTLES ITS ROLLUP. THIS IS NO LONGER OPTIONAL (2026-09-01).
 *
 * A main's "Service Group 1" is not money of its own — it is the SUM of one
 * child account's two legs. So settling or forfeiting there spends that child's
 * rupees while the child is nowhere near balanced, which is how every one of
 * the cases above happened.
 *
 * "The main settles its Service Groups" and "no account moves unless its own
 * two sides balance" are THE SAME RUPEES COUNTED TWICE. Only one can hold.
 *
 * That rule was flipped SIX TIMES in eight days — 08-24 on, 08-27 off, 08-28
 * on, 08-30 off, 08-31 on, 08-31 off — and for a day it lived behind a saved
 * setting so it could be flipped without code. The owner then asked for the
 * per-account rule a sixth time, having seen the alternative run on live data
 * and reject it, and chose to have the option REMOVED so it cannot be switched
 * back on by accident. There is no `mainSettlesRollup()` any more and there
 * should not be one again.
 *
 * ⚠️ THIS RESTRICTS SETTLEMENT ONLY. The commission CLIMB is a different
 * mechanism and is untouched: a student added under a bought account still
 * credits every account above it, which is what "pahalin course buy kran ayagei
 * studentla add kran ayage upline 2500 add wenn one" asks for. Crediting and
 * settling are not the same thing — do not conflate them if this comes back.
 *
 * See ServiceGroupFlush::legPlan().
 */
class SystemRunRules
{
    /** Does the repeating stage close its surplus off to the company? */
    public const REPEAT_FORFEITS_KEY = 'system_run_repeating_forfeits_surplus';

    /**
     * Every rule the System Run obeys, in one list, for the screen that edits
     * them. `key` is the Setting key, `default` is what applies when unset.
     *
     * ⚠️ THIS LIST IS THE CONTRACT. The editor screen renders whatever is here,
     * so a new rule becomes editable by adding one entry — not by touching a
     * Blade file. Keep `effect` written for the owner, not for a developer.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function editable(): array
    {
        return [
            [
                'key' => 'stage_first_pairs',
                'type' => 'integer',
                'default' => ServiceGroupStaging::DEFAULT_FIRST_STAGE_PAIRS,
                'label' => 'Stage 1 (1-to-1) — pairs needed on each side',
                'effect' => 'Multiplied by the slot amount to get what each side must hold. 1 pair = LKR 2,500 a side, releasing LKR 5,000.',
            ],
            [
                'key' => 'stage_second_pairs',
                'type' => 'integer',
                'default' => ServiceGroupStaging::DEFAULT_SECOND_STAGE_PAIRS,
                'label' => 'Stage 2 (2-to-2) — pairs needed on each side',
                'effect' => '2 pairs = LKR 5,000 a side, releasing LKR 10,000. Used once per account.',
            ],
            [
                'key' => 'stage_repeat_pairs',
                'type' => 'integer',
                'default' => ServiceGroupStaging::DEFAULT_REPEAT_STAGE_PAIRS,
                'label' => 'Stage 3 (3-to-3) — pairs needed on each side, repeating',
                'effect' => '3 pairs = LKR 7,500 a side, releasing LKR 15,000. This stage repeats forever.',
            ],
            [
                'key' => self::REPEAT_FORFEITS_KEY,
                'type' => 'boolean',
                'default' => true,
                'label' => 'At stage 3, the surplus goes to the company',
                'effect' => 'ON: once an account settles a 3-to-3, everything above LKR 7,500 on each side is closed off to company revenue and cannot be recovered. OFF: it stays on the leg like the earlier stages.',
            ],
            [
                'key' => 'account_unlock_students',
                'type' => 'integer',
                'default' => ServiceGroupStaging::DEFAULT_UNLOCK_STUDENTS,
                'label' => 'Students needed on the previous account before the next one unlocks',
                'effect' => 'Gates an account\'s own payout only. Its income still rolls up and is still displayed.',
            ],
        ];
    }

    /** The current value of every editable rule, keyed by setting key. */
    public static function current(): array
    {
        $out = [];

        foreach (self::editable() as $rule) {
            $value = \App\Models\Setting::get($rule['key'], $rule['default']);

            $out[$rule['key']] = $rule['type'] === 'boolean' ? (bool) $value : (int) $value;
        }

        return $out;
    }

    /**
     * How many pairs this account must hold on EACH side, for its stage.
     *
     * Reads the same Settings-backed config as everything else, so changing
     * stage_first_pairs / stage_second_pairs / stage_repeat_pairs in the admin
     * moves the whole run — which is the "save the rules" half of the request.
     */
    public static function stepFor(int $stage): int
    {
        return ServiceGroupStaging::stepFor(max(0, $stage), ServiceGroupStaging::config());
    }

    /** The rupee amount each side must hold, for this account's stage. */
    public static function thresholdFor(int $stage, ?float $rate = null): float
    {
        $rate ??= (float) CommissionSetting::current()->commission_per_student;

        return self::stepFor($stage) * $rate;
    }

    /**
     * Is this the repeating stage — the one where the surplus is closed off?
     *
     * The ramp (1-to-1, 2-to-2) keeps its remainder on the leg; only 3-to-3
     * books the excess to the company. Owner, 2026-08-23: "wadi amount eka
     * company ekata add wen na, amount eka e widiytama thiyeno" for the ramp,
     * and 2026-08-30: "stage 3 nm... 7500ta wadiyen thiyeno nm eka 0 wela
     * company ekat yann one."
     */
    public static function isRepeating(int $stage): bool
    {
        $config = ServiceGroupStaging::config();

        return self::stepFor($stage) >= (int) ($config['repeat'] ?? self::stepFor($stage));
    }

    /**
     * Does the repeating stage actually close its surplus off to the company?
     *
     * Editable, because it is the one rule that DESTROYS money rather than
     * moving it — the owner should be able to switch it off without a code
     * change if a run is ever about to book more than they intended.
     */
    public static function repeatingForfeitsSurplus(): bool
    {
        return (bool) \App\Models\Setting::get(self::REPEAT_FORFEITS_KEY, true);
    }

    /**
     * ⚠️ THE DECISION. Every caller asks this and nobody re-derives it.
     *
     * @param  float  $leg1  what Service Group 1 holds, in scope for this run
     * @param  float  $leg2  what Service Group 2 holds, in scope for this run
     * @param  int  $stage  the ACCOUNT's own lifetime_pair_stage, never the person's
     * @return array{
     *   runs: bool, step: int, threshold: float, label: string,
     *   release_each_side: float, forfeits_surplus: bool,
     *   need_leg1: float, need_leg2: float, payout: float
     * }
     */
    public static function decide(float $leg1, float $leg2, int $stage, ?float $rate = null): array
    {
        $rate ??= (float) CommissionSetting::current()->commission_per_student;

        $step = self::stepFor($stage);
        $threshold = $step * $rate;

        // ⚠️ ONE expression, and it is the whole gate. Both sides, every stage.
        // There is deliberately no branch on stage here: 3-to-3 differs only in
        // what happens to the SURPLUS once the account has already qualified.
        $runs = $leg1 >= $threshold && $leg2 >= $threshold;

        return [
            'runs' => $runs,
            'step' => $step,
            'threshold' => $threshold,
            'label' => $step.'-to-'.$step,
            'release_each_side' => $runs ? $threshold : 0.0,
            'forfeits_surplus' => $runs && self::isRepeating($stage) && self::repeatingForfeitsSurplus(),
            'need_leg1' => max(0.0, $threshold - $leg1),
            'need_leg2' => max(0.0, $threshold - $leg2),
            'payout' => $runs ? $threshold * 2 : 0.0,
        ];
    }

    /**
     * Why an account holding money is not taking part, in the units the reader
     * acts in — students, not just rupees.
     *
     * Lives here rather than on the screen so the sentence can never describe a
     * different rule from the one that ran.
     */
    public static function explain(array $decision, float $rate): string
    {
        if ($decision['runs']) {
            return 'Both service groups hold LKR '.number_format($decision['threshold'])
                .', so this account settles '.$decision['label'].'.';
        }

        $rate = max(1.0, $rate);
        $short = [];

        foreach ([1, 2] as $leg) {
            $needed = $decision['need_leg'.$leg];

            if ($needed > 0) {
                $students = (int) ceil($needed / $rate);
                $short[] = $students.' more student'.($students === 1 ? '' : 's')
                    .' on Service Group '.$leg.' (LKR '.number_format($needed).')';
            }
        }

        return 'Needs '.implode(' and ', $short).' — this account settles at LKR '
            .number_format($decision['threshold']).' on each side ('.$decision['label'].'). '
            .'Until both sides reach it, nothing moves and it keeps everything it holds.';
    }
}
