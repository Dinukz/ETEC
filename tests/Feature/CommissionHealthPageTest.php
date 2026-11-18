<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The in-app replacement for the one-off diagnostic scripts (which 404'd on
 * this host). An admin has no CLI, so they must be able to see WHY a
 * commission is missing and repair it from the browser.
 */
class CommissionHealthPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    /** A payment already sitting verified in the database, with no commission. */
    private function legacyVerifiedPayment(int $consultantId, int $tier): Payment
    {
        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $consultantId,
            'requested_tier' => $tier,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'verified',
            'paid_at' => now(),
            'verified_at' => now(),
        ]);
    }

    public function test_a_consultant_cannot_reach_the_page(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail();

        $this->actingAs($consultant)->get(route('commission-health.index'))->assertForbidden();
        $this->actingAs($consultant)->post(route('commission-health.repair'))->assertForbidden();
    }

    public function test_it_reports_a_zero_direct_bonus_as_the_reason_nothing_is_paid(): void
    {
        CommissionSetting::where('is_active', true)->update(['direct_commission' => 0]);
        cache()->forget('commission_settings.active');

        $response = $this->actingAs($this->admin())->get(route('commission-health.index'));

        $response->assertOk();
        $response->assertSee('The Direct Add Bonus is LKR 0.');
        $response->assertSee('no bonus can ever be paid', false);
    }

    public function test_it_refuses_to_repair_while_the_bonus_is_zero(): void
    {
        CommissionSetting::where('is_active', true)->update(['direct_commission' => 0]);
        cache()->forget('commission_settings.active');

        $this->actingAs($this->admin())
            ->post(route('commission-health.repair'))
            ->assertRedirect();

        $this->assertSame(0, Commission::count(), 'Repairing against a zero rate would record nothing and look like another failure.');
    }

    public function test_repair_records_the_income_a_verified_payment_missed(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        $this->legacyVerifiedPayment($hidden->id, 1);
        $this->assertSame(0, Commission::count());

        // The page should call this out before anything is repaired.
        $this->actingAs($this->admin())->get(route('commission-health.index'))
            ->assertOk()
            ->assertSee('have no direct bonus recorded', false);

        $this->actingAs($this->admin())->post(route('commission-health.repair'))->assertRedirect();

        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'amount' => 3000]);
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'bonus', 'amount' => 2500]);
        $this->approveAllCommissions();

        $this->assertSame(5500.0, (float) $hidden->fresh()->total_earned, 'A lone student backfills both the slot and the bonus.');
    }

    public function test_repair_is_safe_to_run_twice(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        $this->legacyVerifiedPayment($hidden->id, 1);
        $this->legacyVerifiedPayment($hidden->id, 2);

        $this->actingAs($this->admin())->post(route('commission-health.repair'));
        $total = (float) Commission::sum('amount');

        $this->actingAs($this->admin())->post(route('commission-health.repair'));

        $this->assertSame($total, (float) Commission::sum('amount'), 'A second repair must not pay again.');
        $this->approveAllCommissions();

        $this->assertSame(11000.0, (float) $hidden->fresh()->total_earned);
    }

    /**
     * Production had tables that exist without a row in the `migrations`
     * ledger, so a blanket `migrate` aborted with "table already exists" and
     * Repair 500'd. Repair must no longer depend on migrations at all.
     */
    public function test_repair_survives_a_migration_ledger_that_is_out_of_sync(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();
        $this->legacyVerifiedPayment($hidden->id, 1);

        // Exactly the broken state: the table is there, the ledger row is not.
        \Illuminate\Support\Facades\DB::table('migrations')->where('migration', 'like', '%personal_access_tokens%')->delete();

        $this->actingAs($this->admin())
            ->post(route('commission-health.repair'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->approveAllCommissions();

        $this->assertSame(5500.0, (float) $hidden->fresh()->total_earned, 'Repair must still record the slot + the bonus.');
    }

    public function test_it_offers_to_reconcile_a_migration_recorded_nowhere(): void
    {
        \Illuminate\Support\Facades\DB::table('migrations')->where('migration', 'like', '%personal_access_tokens%')->delete();

        $this->actingAs($this->admin())->get(route('commission-health.index'))
            ->assertOk()
            ->assertSee('never recorded', false)
            ->assertSee('Fix Migration Records', false);

        $this->actingAs($this->admin())
            ->post(route('commission-health.reconcile'))
            ->assertRedirect()
            ->assertSessionHas('success');

        // Recorded now, and the page stops complaining.
        $this->assertDatabaseHas('migrations', ['migration' => '2026_07_01_165645_create_personal_access_tokens_table']);
        $this->actingAs($this->admin())->get(route('commission-health.index'))
            ->assertOk()
            ->assertDontSee('Fix Migration Records', false);
    }

    public function test_it_shows_a_clean_bill_of_health_when_nothing_is_wrong(): void
    {
        $response = $this->actingAs($this->admin())->get(route('commission-health.index'));

        $response->assertOk();
        $response->assertSee('No problems found.');
        $response->assertSee('Live');
    }
}
