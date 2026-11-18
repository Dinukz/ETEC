<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️⚠️ BOTH SIDES AT THE STAGE AMOUNT, AT EVERY STAGE — set 2026-08-27.
 *
 * THIRD setting of this rule. It required both sides until 08-26, when the owner
 * asked for a side to settle alone at 3-to-3 ("7500 samani ho adui nm 0 wenwa
 * depaththenm"). Shown what that did on a real run — SCN-00103 holding 37,500
 * against 0 released 7,500 and closed 30,000 off to the company — they reversed
 * it and restated the whole ladder, stage by stage:
 *
 *   "Stage 1 eke depaththem 2500k aniwaren thiyenn one... naththn run wen na,
 *    account eka load wenn wath one na... habai wadi amount tika company ekt
 *    yn na stage 1 nisa."
 *   "Stage 2 eke depaththem 5000k aniwaren thiyenn one..."
 *   "Stage 3... eke depaththem 7500k aniwaren thiyenn one... naththn run wen
 *    na... habai wadi amount tika company ekat yanwa meka stage 3 nisa...
 *    meke paththaka hari 7500ta wada adui nam run wen na."
 *
 * So ONE gate for all three stages: both sides at the stage amount, or the
 * account does not take part at all. What still differs at 3-to-3 is only what
 * happens to the SURPLUS once it qualifies.
 *
 * Do not flip this a fourth time without the same explicit, numbers-based
 * confirmation.
 */
class BothSidesRequiredAtEveryStageTest extends TestCase
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
            'status' => 'active', 'enrolled_at' => now()->subDays(3),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now()->subDays(3),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    /** An account seeded with $n1 students on leg 1 and $n2 on leg 2, at $stage. */
    private function account(int $n1, int $n2, int $stage = 0): Consultant
    {
        $account = $this->main->hiddenAccounts()->orderBy('id')->first();

        for ($i = 0; $i < $n1; $i++) {
            $this->student($account, 1);
        }
        for ($i = 0; $i < $n2; $i++) {
            $this->student($account, 2);
        }

        $account->forceFill(['lifetime_pair_stage' => $stage])->save();

        return $account->fresh();
    }

    /** @return array{payout: float, forfeit: float, flushed: bool} */
    private function flush(Consultant $account): array
    {
        $result = app(ServiceGroupFlush::class)->flush(
            $account->fresh(),
            Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail(),
            now()->addDay(),
        );

        return [
            'payout' => (float) $result['payout'],
            'forfeit' => (float) $result['forfeit'],
            'flushed' => (bool) $result['flushed'],
        ];
    }

    // ----- Stage 1 -----------------------------------------------------------

    public function test_stage_1_pays_5000_when_both_sides_have_2500(): void
    {
        $result = $this->flush($this->account(1, 1, 0));

        $this->assertTrue($result['flushed']);
        $this->assertSame(5000.0, $result['payout']);
        $this->assertSame(0.0, $result['forfeit'], 'stage 1 keeps the surplus on the leg');
    }

    public function test_stage_1_does_not_run_when_one_side_is_short(): void
    {
        $result = $this->flush($this->account(5, 0, 0));

        $this->assertFalse($result['flushed'], 'one side is empty — the account does not take part');
        $this->assertSame(0.0, $result['payout']);
        $this->assertSame(0.0, $result['forfeit'], 'and nothing is closed off to the company');
    }

    public function test_stage_1_keeps_the_surplus_on_the_leg(): void
    {
        $result = $this->flush($this->account(4, 4, 0));

        $this->assertSame(5000.0, $result['payout'], 'only the stage amount, 2,500 a side');
        $this->assertSame(0.0, $result['forfeit'], 'the other 15,000 waits for the next stage');
    }

    // ----- Stage 2 -----------------------------------------------------------

    public function test_stage_2_pays_10000_when_both_sides_have_5000(): void
    {
        $result = $this->flush($this->account(2, 2, 1));

        $this->assertSame(10000.0, $result['payout']);
        $this->assertSame(0.0, $result['forfeit'], 'stage 2 keeps the surplus too');
    }

    public function test_stage_2_does_not_run_when_one_side_is_short(): void
    {
        // 12,500 against 2,500 — plenty on one side, under 5,000 on the other.
        $result = $this->flush($this->account(5, 1, 1));

        $this->assertFalse($result['flushed']);
        $this->assertSame(0.0, $result['payout']);
        $this->assertSame(0.0, $result['forfeit']);
    }

    // ----- Stage 3 (the repeating stage) -------------------------------------

    public function test_stage_3_pays_15000_and_closes_the_surplus_off(): void
    {
        // 20,000 against 7,500 — SCN-00109's shape, which was already correct.
        $result = $this->flush($this->account(8, 3, 2));

        $this->assertSame(15000.0, $result['payout'], '7,500 a side');
        $this->assertSame(12500.0, $result['forfeit'], 'and the 12,500 surplus goes to the company');
    }

    /**
     * ⚠️ THE REPORTED CASE. SCN-00103: 37,500 on one side, nothing on the other.
     * It released 7,500 and forfeited 30,000. It must do neither.
     */
    public function test_stage_3_does_not_run_when_one_side_is_empty(): void
    {
        $account = $this->account(15, 0, 2);

        $result = $this->flush($account);

        $this->assertFalse($result['flushed'], 'SCN-00103 must not take part at all');
        $this->assertSame(0.0, $result['payout'], 'nothing is released');
        $this->assertSame(0.0, $result['forfeit'], 'and nothing is lost to the company');

        $this->assertSame(
            37500.0,
            (float) Commission::where('consultant_id', $account->id)
                ->where('type', 'bonus')->whereNull('released_at')
                ->where('status', '!=', 'cancelled')->sum('amount'),
            'every rupee is still sitting on the leg, waiting for the other side'
        );
    }

    public function test_stage_3_does_not_run_when_one_side_is_merely_short(): void
    {
        // 37,500 against 5,000 — the other side exists but is under 7,500.
        $result = $this->flush($this->account(15, 2, 2));

        $this->assertFalse($result['flushed'], '"paththaka hari 7500ta wada adui nam run wen na"');
        $this->assertSame(0.0, $result['forfeit']);
    }

    /** Exactly on the line counts as reaching it. */
    public function test_exactly_the_stage_amount_on_both_sides_qualifies(): void
    {
        $result = $this->flush($this->account(3, 3, 2));

        $this->assertSame(15000.0, $result['payout']);
        $this->assertSame(0.0, $result['forfeit'], 'nothing above 7,500 a side, so nothing to close off');
    }

    /** A whole run must not offer such an account either — "load wenn wath one na". */
    public function test_a_blocked_account_is_not_settled_by_a_whole_run(): void
    {
        $account = $this->account(15, 0, 2);

        $before = (float) Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')->whereNull('released_at')->sum('amount');

        $result = app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame(0.0, (float) $result['payout']);
        $this->assertSame(0.0, (float) $result['forfeit']);
        $this->assertSame(
            $before,
            (float) Commission::where('consultant_id', $account->id)
                ->where('type', 'bonus')->whereNull('released_at')->sum('amount'),
        );
    }

    /** And the preview must agree with the run, as always. */
    public function test_the_preview_offers_nothing_either(): void
    {
        $this->account(15, 0, 2);

        $preview = app(ServiceGroupFlush::class)->preview(now()->addDay());

        $this->assertSame(0.0, (float) $preview['payout']);
        $this->assertSame(0.0, (float) $preview['forfeit']);
    }
}
