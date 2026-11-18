<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reproduces the exact production incident: `commission_settings` was missing
 * its `direct_commission` column entirely (not NULL — the column did not
 * exist), because the migration that adds it never actually ran on this
 * database. Two silent consequences, both fixed here:
 *
 *  1. Reading a non-existent column returns null, so the engine read the rate
 *     as 0 and skipped the bonus with no error anywhere — indistinguishable
 *     from a correctly configured zero.
 *  2. Saving the Settings form tried to INSERT a value for that column and hit
 *     a hard "Unknown column" 500 — the admin could not fix rule #1 through
 *     the UI at all.
 */
class CommissionSchemaRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** Simulates production: the column was never added. */
    private function dropDirectCommissionColumn(): void
    {
        Schema::table('commission_settings', function ($table) {
            $table->dropColumn('direct_commission');
        });
        cache()->forget('commission_settings.active');
    }

    /** Simulates production: this column was ALSO never added. */
    private function dropSlotsColumn(): void
    {
        Schema::table('commissions', function ($table) {
            $table->dropColumn('slots');
        });
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    /**
     * handleVerifiedPayment() now self-heals the schema as its very first step
     * (see CommissionService::handleVerifiedPayment), so verifying a payment no
     * longer silently awards nothing when the column is missing — it fixes the
     * column on the spot and awards correctly, in the same click.
     */
    public function test_a_verified_payment_self_heals_the_missing_column_and_awards_correctly(): void
    {
        $this->dropDirectCommissionColumn();

        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);
        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $hidden->id, 'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        // Must not throw, and must not silently skip the bonus either.
        app(PaymentService::class)->verify($payment, $accountant);

        $this->assertTrue(Schema::hasColumn('commission_settings', 'direct_commission'));
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'amount' => 3000]);
    }

    public function test_saving_commission_settings_used_to_500_and_now_self_heals(): void
    {
        $this->dropDirectCommissionColumn();

        $response = $this->actingAs($this->admin())->patch(route('settings.commission'), [
            'course_price' => 28000,
            'half_payment_amount' => 18000,
            'commission_per_student' => 2500,
            'direct_commission' => 4000,
            'max_commission' => 15000,
            'group_size' => 6,
            'first_tier_students' => 3,
            'first_tier_amount' => 7500,
            'second_tier_students' => 3,
            'second_tier_amount' => 7500,
            'finance_week_start_day' => 2,
            'finance_week_quota' => 2,
            'finance_grace_weeks' => 5,
        ]);

        // No 500, no "Unknown column" — the save succeeds and the value sticks.
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertTrue(Schema::hasColumn('commission_settings', 'direct_commission'), 'The column must exist after saving.');
        $this->assertSame(4000.0, (float) CommissionSetting::current()->direct_commission);
    }

    public function test_run_repair_fixes_the_column_and_then_a_new_registration_earns_the_bonus(): void
    {
        $this->dropDirectCommissionColumn();

        $this->actingAs($this->admin())->post(route('commission-health.repair'))->assertRedirect();

        $this->assertTrue(Schema::hasColumn('commission_settings', 'direct_commission'));
        $this->assertSame(3000.0, (float) CommissionSetting::current()->direct_commission, 'Repair fills the missing value with the documented default.');

        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);
        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $hidden->id, 'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);
        app(PaymentService::class)->verify($payment, $accountant);

        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'amount' => 3000]);
    }

    /**
     * "Old kattiya" — students whose payment was already sitting verified in
     * the database from BEFORE this self-heal existed, so nothing was ever
     * recorded for them and calling verify() again is not an option (it's
     * already verified). Repair must catch these up too.
     */
    public function test_repair_also_backfills_old_students_who_were_verified_before_the_self_heal_existed(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);
        Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $hidden->id, 'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'verified', 'paid_at' => now(), 'verified_at' => now(),
        ]);

        // Exactly historical production state: verified, nothing recorded.
        $this->assertSame(0, Commission::count());

        $this->actingAs($this->admin())->post(route('commission-health.repair'))->assertRedirect();

        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'amount' => 3000]);
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'bonus', 'amount' => 2500]);

        $this->approveAllCommissions();
        $this->assertSame(5500.0, (float) $hidden->fresh()->total_earned);
    }

    /**
     * The exact next incident: fixing direct_commission surfaced a SECOND
     * missing column the moment an accountant actually clicked "Verify" —
     * `commissions.slots`, hit by the very first Commission::create() call
     * (the direct bonus, not even the pair-settlement path). Verifying a
     * payment is the single most critical action in the app and must never
     * 500 for a schema gap that's cheap to detect and fix in place.
     */
    public function test_verifying_a_payment_self_heals_a_missing_slots_column_instead_of_crashing(): void
    {
        $this->dropSlotsColumn();

        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);
        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $hidden->id, 'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        // The real controller route an accountant actually clicks — not a
        // direct service call — so this proves the whole HTTP path survives.
        $response = $this->actingAs($accountant)->patch(route('payments.verify', $payment));

        $response->assertRedirect();
        $this->assertTrue(Schema::hasColumn('commissions', 'slots'), 'The column must now exist.');
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'amount' => 3000]);
    }

    /**
     * The follow-up incident, reported once the columns were already repaired:
     * a brand-new student's slot income (2,500) arrived but their direct bonus
     * (4,000) never did.
     *
     * Cause: CommissionSetting::current() caches the model for an hour, and an
     * instance cached while `direct_commission` did not exist as a column
     * carries no such attribute at all — so it reads back null, which the
     * engine treats as "the rate is 0" and silently skips the bonus. The
     * `commission_per_student` attribute on that very same stale object is
     * fine, which is why exactly one of the two kinds of income went missing.
     */
    public function test_settings_cached_before_the_column_existed_stop_zeroing_the_bonus(): void
    {
        $this->dropDirectCommissionColumn();

        // Warm the cache while the column is missing — the poisoned instance.
        $stale = CommissionSetting::current();
        $this->assertArrayNotHasKey('direct_commission', $stale->getAttributes(), 'Precondition: the cached model has no such attribute.');

        // Repairing the schema must also drop that cached instance.
        \App\Services\CommissionSchemaRepair::ensure();
        $this->assertGreaterThan(0.0, (float) CommissionSetting::current()->direct_commission, 'Repair must not leave a zero rate cached.');

        // And even if the poisoned instance is put back afterwards, reading the
        // settings has to notice the missing attribute and rebuild rather than
        // hand the engine a silent zero.
        cache()->put('commission_settings.active', $stale, 3600);
        $this->assertGreaterThan(0.0, (float) CommissionSetting::current()->direct_commission, 'A stale cached instance must self-heal on read.');
    }

    /** End to end: the bonus actually lands for a student verified in this state. */
    public function test_a_new_student_still_earns_the_bonus_when_the_cached_settings_are_stale(): void
    {
        $this->dropDirectCommissionColumn();
        $stale = CommissionSetting::current();
        \App\Services\CommissionSchemaRepair::ensure();
        cache()->put('commission_settings.active', $stale, 3600);

        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);
        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $hidden->id, 'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, $accountant);

        // Both kinds of income, not just the slot.
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'bonus', 'amount' => 2500]);
        $this->assertDatabaseHas('commissions', ['payment_id' => $payment->id, 'consultant_id' => $hidden->id, 'type' => 'referral']);
        $this->assertGreaterThan(
            0.0,
            (float) Commission::where('payment_id', $payment->id)->where('type', 'referral')->sum('amount'),
            'The direct-add bonus must be a real amount, not a silent zero.',
        );
    }

    /** Both columns missing at once — the actual reported production state. */
    public function test_verifying_survives_both_columns_missing_at_the_same_time(): void
    {
        $this->dropDirectCommissionColumn();
        $this->dropSlotsColumn();

        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);
        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $hidden->id, 'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        $this->actingAs($accountant)->patch(route('payments.verify', $payment))->assertRedirect();

        $this->assertTrue(Schema::hasColumn('commission_settings', 'direct_commission'));
        $this->assertTrue(Schema::hasColumn('commissions', 'slots'));
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral']);
    }
}
