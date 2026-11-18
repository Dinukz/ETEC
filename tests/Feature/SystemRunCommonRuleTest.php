<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use App\Services\SystemRunRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️⚠️ THE ONE SYSTEM RUN RULE — the five cases reported 2026-08-30.
 *
 * The owner stopped listing symptoms and asked for the rule itself:
 *
 *   "Aye mn stage wise kiynn oned? ... Mt meke system run ekat withrk adala
 *    COMMON RULES tikak dala eka save krala, e widiyt system run eka yana widiyt
 *    hadann barid? Mokad issrahat consultant la 1000k withr enkota meka ekin eka
 *    balance wen nathi ewa hadann giyoth prashna godak athi wenwa."
 *
 * So this file tests the RULE, not five bugs. Every case below is the same
 * sentence applied to different numbers:
 *
 *   SG1 >= threshold AND SG2 >= threshold  ->  release the threshold from BOTH,
 *                                              forfeit the rest only at 3-to-3
 *   anything else                          ->  NOTHING happens at all
 *
 * Each test carries the account code and the figures exactly as reported, so a
 * failure here names the case the owner would recognise.
 */
class SystemRunCommonRuleTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private Consultant $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');


        $this->main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function student(Consultant $account, int $leg): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now()->subDays(10),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now()->subDays(10),
        ]);

        $payment->forceFill(['created_at' => now()->subDays(10)])->save();

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    /** Seed an account to exactly $leg1 / $leg2 rupees, at $stage. */
    private function seedAccount(Consultant $account, float $leg1, float $leg2, int $stage): Consultant
    {
        for ($i = 0; $i < (int) ($leg1 / self::SLOT); $i++) {
            $this->student($account, 1);
        }
        for ($i = 0; $i < (int) ($leg2 / self::SLOT); $i++) {
            $this->student($account, 2);
        }

        $account->forceFill(['lifetime_pair_stage' => $stage])->save();

        return $account->fresh();
    }

    /** @return array{0: float, 1: float} the two leg balances, as the screen shows them */
    private function legs(Consultant $account): array
    {
        $flush = app(ServiceGroupFlush::class);
        $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();

        return [
            $flush->legBalance($account->fresh(), $group, 1),
            $flush->legBalance($account->fresh(), $group, 2),
        ];
    }

    /**
     * At least $n placement accounts. A consultant is created with two, and the
     * reported sheet needs four — opened the same way an admin opens one.
     */
    private function accounts(int $n = 2): array
    {
        $service = app(\App\Services\HiddenAccountService::class);

        while ($this->main->fresh()->hiddenAccounts()->count() < $n) {
            $service->addExtraAccount($this->main->fresh());
        }

        return $this->main->fresh()->hiddenAccounts()->orderBy('id')->get()->all();
    }

    private function systemRun(): array
    {
        return app(ServiceGroupFlush::class)->runAll(now()->addDay());
    }

    /**
     * ⚠️ FORFEITS ONLY, AND source_type CANNOT TELL THEM APART.
     *
     * PaymentService books the course fee left over after commissions as
     * source_type 'group_overflow' too (PaymentService.php:97) whenever the
     * payment has a consultant — so that column mixes ordinary course revenue
     * with System Run forfeits. A flush row is the one with no payment_id.
     */
    private function companyRevenue(): float
    {
        return (float) \App\Models\CompanyRevenue::where('source_type', 'group_overflow')
            ->whereNull('payment_id')
            ->sum('amount');
    }

    // ---- The rule itself, with no database at all ---------------------------

    public function test_the_rule_is_one_sentence_at_every_stage(): void
    {
        foreach ([0 => 2500.0, 1 => 5000.0, 2 => 7500.0] as $stage => $threshold) {
            $this->assertSame(
                $threshold,
                SystemRunRules::thresholdFor($stage, self::SLOT),
                'stage '.$stage.' asks for '.$threshold.' a side',
            );

            $this->assertFalse(
                SystemRunRules::decide($threshold - 1, $threshold * 10, $stage, self::SLOT)['runs'],
                'one rupee short on either side means the account does not take part',
            );
            $this->assertFalse(
                SystemRunRules::decide($threshold * 10, $threshold - 1, $stage, self::SLOT)['runs'],
                'and it does not matter which side is short',
            );
            $this->assertTrue(
                SystemRunRules::decide($threshold, $threshold, $stage, self::SLOT)['runs'],
                'exactly the threshold on both sides qualifies',
            );
            $this->assertSame(
                $threshold,
                SystemRunRules::decide($threshold * 4, $threshold * 9, $stage, self::SLOT)['release_each_side'],
                'and it never releases more than one stage, however much is held',
            );
        }
    }

    public function test_only_the_repeating_stage_closes_the_surplus_off(): void
    {
        $this->assertFalse(SystemRunRules::decide(99999, 99999, 0, self::SLOT)['forfeits_surplus'], '1-to-1 keeps the rest');
        $this->assertFalse(SystemRunRules::decide(99999, 99999, 1, self::SLOT)['forfeits_surplus'], '2-to-2 keeps the rest');
        $this->assertTrue(SystemRunRules::decide(99999, 99999, 2, self::SLOT)['forfeits_surplus'], '3-to-3 gives it to the company');
    }

    // ---- SCN-00101 : 27,500 / 2,500 at 3-to-3 -------------------------------

    /**
     * "SG1 and SG2 balance na, ethkot meke flush out wenn ba. Habai meke SG1
     *  eka 27500 thibb eke 7500k adu wela, SG2 eke 2500k thiyeo eka adu wela na."
     */
    public function test_scn101_unbalanced_at_3_to_3_loses_nothing(): void
    {
        [$a101] = $this->accounts();
        $this->seedAccount($a101, 27500, 2500, 2);

        $this->assertSame([27500.0, 2500.0], $this->legs($a101), 'guard: the reported figures');

        $this->systemRun();

        $this->assertSame(
            [27500.0, 2500.0],
            $this->legs($a101),
            'Not balanced, so not one rupee moves — not off SG1, and nothing to the company.',
        );
        $this->assertSame(0.0, $this->companyRevenue(), 'and nothing is booked as revenue either');
    }

    /**
     * ⚠️ THE 2026-08-31 EVENING REPORT, to the rupee.
     *
     * "SCN 101 SG1 70000k thibba SG2 2500i. Meke depathth 7500k gane thibila
     *  balance unoth withri meke system run ekedi flush out wenn one, eth meka
     *  flush out wela thiyeno — eka wardi. Anith eka 3rd account eka SCN 102,
     *  meke SG1 eke 2500i SG2 eke 75000, eketh e wagem flush out wela thiyeno."
     *
     * Both are at 3-to-3 and neither is balanced, so a run must leave both of
     * them exactly as they are.
     */
    public function test_the_two_reported_accounts_are_left_untouched(): void
    {
        [$a101, $a102] = $this->accounts();

        $this->seedAccount($a101, 70000, 2500, 2);
        $this->seedAccount($a102, 2500, 75000, 2);

        $this->assertSame([70000.0, 2500.0], $this->legs($a101), 'guard: SCN-00101 as reported');
        $this->assertSame([2500.0, 75000.0], $this->legs($a102), 'guard: SCN-00102 as reported');

        $result = $this->systemRun();

        $this->assertSame([70000.0, 2500.0], $this->legs($a101), 'SCN-00101 is not balanced, so nothing moves');
        $this->assertSame([2500.0, 75000.0], $this->legs($a102), 'nor is SCN-00102');
        $this->assertSame(0.0, (float) $result['forfeit'], 'and neither is emptied to the company');
    }

    // ---- SCN-00102 : 0 / 25,000 at 3-to-3 -----------------------------------

    /** "SG1 eke 0, SG2 eke 25000k... eth system run ekedi 7500k out wela thiyeno." */
    public function test_scn102_with_an_empty_side_releases_nothing(): void
    {
        [, $a102] = $this->accounts();
        $this->seedAccount($a102, 0, 25000, 2);

        $this->systemRun();

        $this->assertSame(
            [0.0, 25000.0],
            $this->legs($a102),
            'An empty side can never pair, so 7,500 must not leave the other one.',
        );
    }

    // ---- SCN-00104 : stage 1, 2,500 / 15,000 --------------------------------

    /**
     * "Stage 1 eke SG1 eken 2500k adu wela thiyeno, eth SG2 eke 15000n 2500k out
     *  wela na — same amount ekam thiyeno."
     */
    public function test_scn104_at_1_to_1_gives_up_2500_from_both_sides(): void
    {
        [$a104] = $this->accounts();
        $this->seedAccount($a104, 2500, 15000, 0);

        $this->systemRun();

        $this->assertSame(
            [0.0, 12500.0],
            $this->legs($a104),
            'Both sides give up 2,500 — never one side only.',
        );
    }

    /** On the ramp the surplus STAYS. Only 3-to-3 books it to the company. */
    public function test_the_ramp_keeps_its_surplus(): void
    {
        [$a104] = $this->accounts();
        $this->seedAccount($a104, 2500, 15000, 0);

        $this->systemRun();

        $this->assertSame(0.0, $this->companyRevenue(), 'a 1-to-1 gives the company nothing');
    }

    // ---- SCN-00109 : stage 1, 5,000 / 7,500 ---------------------------------

    /**
     * "Stage 1to1 thiyenne, ethkota SG1 and SG2 2500 gane eka paththakin adu
     *  wenn one. SG1 eke 5000i SG2 eke 7500 thibila, meke wela thiyenne SG1 eke
     *  0 wela SG2 eke 2500k ithiri wela — sampuran wardi. 2500 gane adu wela
     *  SG1 eke 2500i SG2 eke 5000i penann one."
     */
    public function test_scn109_at_1_to_1_takes_exactly_2500_from_each_side(): void
    {
        [$a109] = $this->accounts();
        $this->seedAccount($a109, 5000, 7500, 0);

        $this->systemRun();

        $this->assertSame(
            [2500.0, 5000.0],
            $this->legs($a109),
            'exactly what the owner wrote: SG1 2,500 and SG2 5,000',
        );
    }

    // ---- 3-to-3 : the surplus really does go to the company -----------------

    /**
     * "Stage 3 nm... SG1 eke 7500ta wadiyen thiyeno nm withrk adu wenn, e wage
     *  SG2 eketh 7500ta wadiyen thiyeno nm eka 0 wela company ekat yann one."
     */
    public function test_a_balanced_3_to_3_empties_both_sides_and_pays_the_company(): void
    {
        [$a101] = $this->accounts();
        $this->seedAccount($a101, 30000, 25000, 2);

        $result = $this->systemRun();

        $this->assertSame([0.0, 0.0], $this->legs($a101), 'both sides go to zero at 3-to-3');
        $this->assertSame(15000.0, (float) $result['payout'], '7,500 released from each side');
        $this->assertSame(
            (30000.0 - 7500.0) + (25000.0 - 7500.0),
            (float) $result['forfeit'],
            'and every rupee above 7,500 a side is closed off to the company',
        );
        $this->assertSame(40000.0, $this->companyRevenue(), 'booked as real revenue, not just cancelled');
    }

    // ---- The whole sheet at once --------------------------------------------

    /**
     * ⚠️ THE WHOLE NETWORK AT ONCE — and asserted as a PROPERTY, not as fixed
     * figures. This is the "1000 consultants" half of the request: the rule has
     * to hold for every account in a single run, whatever it happens to hold.
     *
     * Fixed expected numbers were the wrong tool here and briefly failed for a
     * reason worth recording: seeding a CHILD account also credits its
     * ancestors, because slot income climbs the owner's own account tree. So
     * "put 2,500 on SCN-00103" quietly also moves SCN-00102. Asserting the RULE
     * instead of the arithmetic is immune to that, and is what actually has to
     * be true at scale.
     */
    public function test_every_account_in_one_run_obeys_the_rule(): void
    {
        $accounts = $this->accounts(4);

        $this->seedAccount($accounts[0], 27500, 2500, 2);   // unbalanced 3-to-3
        $this->seedAccount($accounts[1], 0, 25000, 2);      // empty side
        $this->seedAccount($accounts[2], 2500, 15000, 0);   // balanced 1-to-1
        $this->seedAccount($accounts[3], 5000, 7500, 0);    // balanced 1-to-1

        // What every account holds, and what the rule says will happen to it.
        $before = [];

        foreach ($this->main->fresh()->hiddenAccounts as $account) {
            [$leg1, $leg2] = $this->legs($account);
            $stage = (int) $account->lifetime_pair_stage;

            $before[$account->id] = [
                'code' => $account->scn_code,
                'leg1' => $leg1,
                'leg2' => $leg2,
                'decision' => SystemRunRules::decide($leg1, $leg2, $stage, self::SLOT),
            ];
        }

        $this->assertNotEmpty($before, 'guard: there are accounts to judge');

        $this->systemRun();

        foreach ($before as $id => $was) {
            $account = Consultant::findOrFail($id);
            [$now1, $now2] = $this->legs($account);
            $d = $was['decision'];

            if (! $d['runs']) {
                $this->assertSame(
                    [$was['leg1'], $was['leg2']],
                    [$now1, $now2],
                    $was['code'].' was not balanced, so not one rupee may move',
                );

                continue;
            }

            if ($d['forfeits_surplus']) {
                $this->assertSame(
                    [0.0, 0.0],
                    [$now1, $now2],
                    $was['code'].' settled a 3-to-3, so both sides empty to the company',
                );

                continue;
            }

            $this->assertSame(
                [$was['leg1'] - $d['threshold'], $was['leg2'] - $d['threshold']],
                [$now1, $now2],
                $was['code'].' must give up exactly LKR '.number_format($d['threshold']).' from EACH side',
            );
        }
    }

    /** And a stage only advances for an account that actually settled. */
    public function test_an_account_that_did_not_settle_keeps_its_stage(): void
    {
        [$a101, $a104] = $this->accounts();

        $this->seedAccount($a101, 27500, 2500, 2);
        $this->seedAccount($a104, 2500, 15000, 0);

        $this->systemRun();

        $this->assertSame(2, (int) $a101->fresh()->lifetime_pair_stage, 'it did not pair, so it does not move');
        $this->assertSame(1, (int) $a104->fresh()->lifetime_pair_stage, 'it paired a 1-to-1, so it advances one');
    }
}
