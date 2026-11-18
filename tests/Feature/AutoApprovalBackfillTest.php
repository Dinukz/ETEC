<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\AutoApprovalBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Applying the current auto-approval rules to income recorded before them.
 *
 * Auto-approval runs at CREATION time, so it does nothing for rows already in
 * the database — and no other path will ever approve them, because the queue's
 * bulk buttons are unforced and correctly refuse an unbalanced slot. Reported
 * 2026-08-24 as a Service Groups page reading "LKR 0 · + LKR 52,000 awaiting
 * approval" beside a System Run that had nothing left to flush.
 */
class AutoApprovalBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function owner(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /**
     * A commission as it would have been written BEFORE auto-approval existed:
     * recorded, pending, with nobody coming to approve it.
     */
    private function legacyRow(Consultant $account, string $type, float $amount, ?Consultant $registeredBy = null): Commission
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            // payment.consultant_id is who REGISTERED the student. When it
            // differs from the row's own account, the row is an upline credit.
            'consultant_id' => ($registeredBy ?? $account)->id,
            'requested_tier' => 1,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'verified',
        ]);

        return Commission::create([
            'consultant_id' => $account->id,
            'user_id' => $student->id,
            'payment_id' => $payment->id,
            'type' => $type,
            'tier' => 1,
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }

    public function test_it_approves_a_historical_direct_bonus(): void
    {
        $account = $this->owner()->hiddenAccounts()->first();
        $bonus = $this->legacyRow($account, 'referral', 4000);

        $result = app(AutoApprovalBackfill::class)->run();

        $this->assertSame('approved', $bonus->fresh()->status);
        $this->assertSame(1, $result['direct']);
        $this->assertSame(4000.0, $result['amount']);
    }

    public function test_it_approves_a_historical_upline_credit(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        // Registered on the SECOND account, credited to the FIRST — an upline row.
        $upline = $this->legacyRow($accounts[0], 'bonus', 2500, $accounts[1]);

        $result = app(AutoApprovalBackfill::class)->run();

        $this->assertSame('approved', $upline->fresh()->status);
        $this->assertSame(1, $result['upline']);
    }

    /**
     * ⚠️ THE LINE IT MUST NOT CROSS. The registrant's own slot income is
     * released by a System Run — approving it here would empty every future run
     * and hand out staged money early.
     */
    public function test_it_never_approves_the_registrants_own_slot_income(): void
    {
        $account = $this->owner()->hiddenAccounts()->first();
        $own = $this->legacyRow($account, 'bonus', 2500);

        $result = app(AutoApprovalBackfill::class)->run();

        $this->assertSame('pending', $own->fresh()->status, 'Still the flush job.');
        $this->assertSame(0, $result['approved']);
    }

    /** ⚠️ Held income is never released by a backfill either. */
    public function test_it_skips_held_income(): void
    {
        $account = $this->owner()->hiddenAccounts()->first();

        $bonus = $this->legacyRow($account, 'referral', 4000);
        $bonus->forceFill(['held_at' => now()])->save();

        $result = app(AutoApprovalBackfill::class)->run();

        $this->assertSame('pending', $bonus->fresh()->status);
        $this->assertSame(0, $result['approved']);
    }

    /** A restricted consultant's income is reported as skipped, not approved. */
    public function test_a_restricted_consultants_income_is_reported_as_skipped(): void
    {
        $owner = $this->owner();
        $account = $owner->hiddenAccounts()->first();

        $this->legacyRow($account, 'referral', 4000);
        $owner->update(['finance_status' => 'restricted']);

        $result = app(AutoApprovalBackfill::class)->run();

        $this->assertSame(0, $result['approved']);
        $this->assertSame(1, $result['skipped_held'], 'Counted honestly rather than claimed as approved.');
    }

    /** ⚠️ The count shown on the button must equal what the button then does. */
    public function test_the_preview_count_matches_what_the_run_approves(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        $this->legacyRow($accounts[0], 'referral', 4000);
        $this->legacyRow($accounts[1], 'referral', 4000);
        $this->legacyRow($accounts[0], 'bonus', 2500, $accounts[1]);
        $this->legacyRow($accounts[0], 'bonus', 2500);          // own slot — excluded

        $preview = app(AutoApprovalBackfill::class)->pending();
        $result = app(AutoApprovalBackfill::class)->run();

        $this->assertSame(3, $preview['count']);
        $this->assertSame($preview['count'], $result['approved']);
        $this->assertSame($preview['amount'], $result['amount']);
    }

    /** Running it twice approves nothing extra. */
    public function test_it_is_idempotent(): void
    {
        $account = $this->owner()->hiddenAccounts()->first();
        $this->legacyRow($account, 'referral', 4000);

        app(AutoApprovalBackfill::class)->run();
        $second = app(AutoApprovalBackfill::class)->run();

        $this->assertSame(0, $second['approved']);
    }

    /** Reachable from Commission Health, and only by staff. */
    public function test_the_action_is_reachable_and_guarded(): void
    {
        $account = $this->owner()->hiddenAccounts()->first();
        $bonus = $this->legacyRow($account, 'referral', 4000);

        $this->actingAs($this->owner()->user)
            ->post(route('commission-health.approve-historic'))
            ->assertForbidden();

        $this->assertSame('pending', $bonus->fresh()->status, 'A consultant cannot approve their own income.');

        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->post(route('commission-health.approve-historic'))
            ->assertRedirect();

        $this->assertSame('approved', $bonus->fresh()->status);
    }
}
