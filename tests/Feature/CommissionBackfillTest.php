<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The reported "commission eka add wela na" that no code fix could ever solve:
 * the student's payment was verified BEFORE the rule existed, and
 * PaymentService::verify() returns early for an already-verified payment — so
 * the engine never looks at them again. `commissions:backfill` replays that
 * history. Simulated here by writing a verified payment straight to the
 * database (exactly the state an old payment is in) and never calling verify().
 */
class CommissionBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** A payment already sitting in the database as verified, with no commission. */
    private function legacyVerifiedPayment(int $consultantId, int $tier, string $plan = 'full'): Payment
    {
        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $consultantId,
            'requested_tier' => $tier,
            'amount' => $plan === 'half' ? 18000 : 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => $plan,
            'status' => 'verified',
            'paid_at' => now(),
            'verified_at' => now(),
        ]);
    }

    public function test_a_student_verified_before_the_rules_existed_gets_their_bonus_backfilled(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        $this->legacyVerifiedPayment($hidden->id, 1);

        // Exactly the reported symptom: verified, but not a rupee recorded.
        $this->assertSame(0, Commission::count());
        $this->assertSame(0.0, (float) $hidden->fresh()->total_earned);

        $this->artisan('commissions:backfill')->assertSuccessful();

        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'amount' => 3000]);
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'bonus', 'amount' => 2500]);
        $this->approveAllCommissions();

        $this->assertSame(5500.0, (float) $hidden->fresh()->total_earned, 'A lone student backfills both the slot and the bonus.');
    }

    public function test_the_backfill_settles_a_pair_that_should_already_have_paid(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        $this->legacyVerifiedPayment($hidden->id, 1);
        $this->legacyVerifiedPayment($hidden->id, 2);

        $this->artisan('commissions:backfill')->assertSuccessful();

        // Both bonuses (6,000) plus the settled pair (2 slots x 2500 = 5,000).
        $this->approveAllCommissions();

        $this->assertSame(11000.0, (float) $hidden->fresh()->total_earned);

        // ⚠️ 5,000 -> 10,000 on 2026-09-02. This sum is UNSCOPED, and the climb
        // now credits the consultant's main as well as the placement account —
        // two slots per registration across the person, not one. The account
        // under test still holds exactly its own 11,000, which the line above
        // pins, so the backfill's own arithmetic is unchanged.
        $this->assertSame(10000.0, (float) Commission::where('type', 'bonus')->sum('amount'));
    }

    public function test_running_the_backfill_twice_never_pays_twice(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        $this->legacyVerifiedPayment($hidden->id, 1);
        $this->legacyVerifiedPayment($hidden->id, 2);

        $this->artisan('commissions:backfill')->assertSuccessful();
        $firstRun = (float) Commission::sum('amount');
        $rows = Commission::count();

        $this->artisan('commissions:backfill')->assertSuccessful();

        $this->assertSame($firstRun, (float) Commission::sum('amount'), 'A second backfill must add no money.');
        $this->assertSame($rows, Commission::count(), 'A second backfill must add no rows.');
        $this->approveAllCommissions();

        $this->assertSame(11000.0, (float) $hidden->fresh()->total_earned, 'And must not inflate the running total.');
    }

    public function test_the_backfill_pays_a_half_payment_exactly_like_a_full_one(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        $this->legacyVerifiedPayment($hidden->id, 1, 'full');
        $this->legacyVerifiedPayment($hidden->id, 2, 'half');

        $this->artisan('commissions:backfill')->assertSuccessful();

        // Two students, each earning their own slot + bonus — the instalment
        // plan changes nothing about what is backfilled.
        $this->approveAllCommissions();

        $this->assertSame(11000.0, (float) $hidden->fresh()->total_earned);
        $this->assertSame(5000.0, (float) Commission::where('type', 'bonus')->sum('amount'));
    }

    public function test_a_dry_run_reports_but_changes_nothing(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        $this->legacyVerifiedPayment($hidden->id, 1);

        $this->artisan('commissions:backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Commission::count(), 'A dry run must roll everything back.');
        $this->assertSame(0.0, (float) $hidden->fresh()->total_earned);
    }
}
