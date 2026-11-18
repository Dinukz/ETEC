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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A MANUAL UNLOCK RESUMES FROM THE BALANCE — set 2026-08-27.
 *
 * Owner: "anith eka manual karoth unlock eka, e account eke kiyk thibbath eka 0
 * idn patan ganne, eka wardi — e welawe thiyen amount eken eke system run eka
 * yann one ekath hadann."
 * = if you manually unlock an account, whatever it was already holding, it
 *   starts from 0. That is wrong: at that moment the run must start from the
 *   amount that IS there.
 *
 * The release ladder is consultants.lifetime_pair_stage, and stepForAccount()
 * reads that column and nothing else. A locked account can never advance it —
 * flush() refuses on the isUnlocked() guard before reaching the stage bump — so
 * it was still at stage 0 on the day it was opened, and the next run valued a
 * full leg as a 1-to-1.
 *
 * That cost real money, not just time: one stage per run meant the backlog
 * trickled out over three runs, and by the third the account had reached the
 * repeating stage, where everything past 7,500 a side is forfeited to the
 * company.
 */
class UnlockResumesFromTheBalanceTest extends TestCase
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

    /** The locked second account, loaded up with a backlog nobody could release. */
    private function loadedLockedAccount(int $perLeg): Consultant
    {
        $second = $this->main->hiddenAccounts()->orderBy('id')->get()[1];

        for ($i = 0; $i < $perLeg; $i++) {
            $this->student($second, 1);
            $this->student($second, 2);
        }

        $fresh = Consultant::with('owner')->findOrFail($second->id);
        $this->assertFalse(
            app(\App\Services\ServiceGroupStaging::class)->isUnlocked($fresh),
            'guard: the account under test is chain-locked'
        );

        return $fresh;
    }

    private function forceOpen(Consultant $account): void
    {
        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->patch(route('users.account-lock', $account), ['state' => Consultant::LOCK_FORCED_OPEN])
            ->assertRedirect();
    }

    public function test_unlocking_resumes_the_ladder_at_the_stage_the_balance_earned(): void
    {
        $account = $this->loadedLockedAccount(8);   // 20,000 a side

        $this->assertSame(0, (int) $account->lifetime_pair_stage, 'guard: a locked account never advanced');

        $this->forceOpen($account);

        $this->assertSame(
            2,
            (int) $account->fresh()->lifetime_pair_stage,
            '20,000 a side has already earned its way past 1-to-1 and 2-to-2'
        );
    }

    /** The point of the fix: the first run after the unlock pays the real stage. */
    public function test_the_first_run_after_an_unlock_pays_the_full_stage(): void
    {
        $account = $this->loadedLockedAccount(8);
        $this->forceOpen($account);

        $result = app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame(
            3.0 * self::SLOT * 2,
            (float) $result['payout'],
            'a 3-to-3 pays 15,000 on the very next run — not 5,000 with the rest trickling out'
        );
    }

    /** A small balance still starts where it should — this is not a blanket promotion. */
    public function test_a_small_balance_still_starts_at_one_to_one(): void
    {
        $account = $this->loadedLockedAccount(1);   // 2,500 a side

        $this->forceOpen($account);

        $this->assertSame(0, (int) $account->fresh()->lifetime_pair_stage);
    }

    /**
     * Judged on the SMALLER leg — a stage is a pair, so a side with nothing
     * facing it earns nothing however much the other side holds.
     */
    public function test_a_one_sided_balance_earns_no_stage(): void
    {
        $second = $this->main->hiddenAccounts()->orderBy('id')->get()[1];
        for ($i = 0; $i < 8; $i++) {
            $this->student($second, 1);   // one side only
        }

        $account = Consultant::with('owner')->findOrFail($second->id);
        $this->forceOpen($account);

        $this->assertSame(0, (int) $account->fresh()->lifetime_pair_stage);
    }

    /** Forward only — an unlock must never hand back a stage already consumed. */
    public function test_unlocking_never_moves_the_ladder_backwards(): void
    {
        $account = $this->loadedLockedAccount(1);   // only 2,500 a side
        $account->forceFill(['lifetime_pair_stage' => 2])->save();

        $this->forceOpen($account);

        $this->assertSame(2, (int) $account->fresh()->lifetime_pair_stage);
    }
}
