<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deleting a user must fully purge them (and every dependent row) so the same
 * email can be reused immediately — this is what UserManagementController::
 * destroy() claims via forceDelete() + DB-level cascades. Verifying it
 * actually holds for a student AND a consultant with real dependent data
 * (payments, commissions, group memberships), since that's exactly the
 * scenario the user reported failing with a duplicate-email error.
 */
class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_deleting_a_student_with_payments_frees_the_email_for_reuse(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        $studentUser = User::factory()->create(['email' => 'reuse-me@example.com']);
        Student::create([
            'user_id' => $studentUser->id,
            'student_code' => 'SRN'.Str::random(6),
            'consultant_id' => $consultant->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $studentUser->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $consultant->id,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'verified',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)->delete(route('users.destroy', $studentUser))->assertRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'reuse-me@example.com']);
        $this->assertDatabaseMissing('students', ['user_id' => $studentUser->id]);
        $this->assertDatabaseMissing('payments', ['user_id' => $studentUser->id]);

        // The whole point: the email must be immediately reusable, not blocked
        // by a leftover row (soft-deleted or otherwise).
        $fresh = User::create([
            'name' => 'Reused Person',
            'email' => 'reuse-me@example.com',
            'password' => bcrypt('whatever'),
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('users', ['id' => $fresh->id, 'email' => 'reuse-me@example.com']);
    }

    public function test_deleting_a_consultant_with_commissions_and_groups_fully_cascades(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $consultantUser = User::factory()->create(['email' => 'consultant-to-delete@example.com']);
        $consultantUser->assignRole('consultant');
        $consultant = Consultant::create([
            'user_id' => $consultantUser->id,
            'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)),
            'status' => 'active',
        ]);
        $hiddenIds = $consultant->hiddenAccounts()->pluck('id');

        // Give them real commission/group data via a balanced pair.
        foreach ([1, 2] as $tier) {
            $studentUser = User::factory()->create();
            Student::create(['user_id' => $studentUser->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);
            $payment = Payment::create([
                'payment_code' => 'PYM'.Str::random(6),
                'user_id' => $studentUser->id,
                'course_id' => Course::first()->id,
                'consultant_id' => $consultant->id,
                'requested_tier' => $tier,
                'amount' => 28000,
                'currency' => 'LKR',
                'method' => 'bank_transfer',
                'payment_type' => 'full',
                'status' => 'pending',
                'paid_at' => now(),
            ]);
            app(\App\Services\CommissionService::class)->handleVerifiedPayment($payment);
        }

        $this->assertDatabaseHas('commissions', ['consultant_id' => $consultant->id]);
        $this->assertDatabaseHas('groups', ['consultant_id' => $consultant->id]);

        $this->actingAs($admin)->delete(route('users.destroy', $consultantUser))->assertRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'consultant-to-delete@example.com']);
        $this->assertDatabaseMissing('consultants', ['id' => $consultant->id]);
        foreach ($hiddenIds as $hiddenId) {
            // Hidden accounts have owner_consultant_id -> consultants.id; deleting
            // the owner must cascade to them too, not orphan them.
            $this->assertDatabaseMissing('consultants', ['id' => $hiddenId]);
        }
        $this->assertDatabaseMissing('commissions', ['consultant_id' => $consultant->id]);
        $this->assertDatabaseMissing('groups', ['consultant_id' => $consultant->id]);
    }
}
