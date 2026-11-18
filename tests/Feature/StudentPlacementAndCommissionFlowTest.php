<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduces the exact reported scenario: a consultant registers ONE student
 * through a Partner Consultant (one of their own hidden SCN accounts),
 * confirms the plan, the accountant verifies it as "Full Paid" — and the
 * Direct Registrations page showed "Placement: Not assigned" with LKR 0
 * everywhere on Service Groups.
 *
 * "Not assigned" for PLACEMENT was a real bug: the badge was sourced from
 * Commission (money already recorded) instead of GroupMember (where the
 * student was actually seated), so a correctly-placed student looked
 * unplaced. That is fixed regardless of payout timing.
 *
 * The LKR 0 the user originally saw was, at the time, "correct" under a
 * pairing rule that has since been removed (2026-08-02, at the owner's
 * explicit request) — a single student now earns their consultant the slot
 * AND the direct bonus immediately, per student, with no opposite-leg wait.
 * This test proves both: placement shows immediately, and a LONE student
 * already earns 2,500 + 3,000 = 5,500 once approved.
 */
class StudentPlacementAndCommissionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function registerConfirmAndVerify(User $consultantUser, string $email, int $tier, ?int $partnerId, User $accountant): Payment
    {
        $course = Course::where('status', 'published')->firstOrFail();

        $payload = [
            'name' => 'Student '.$email,
            'email' => $email,
            'course_id' => $course->id,
            'placement_tier' => $tier,
        ];

        if ($partnerId) {
            $payload['partner_consultant_id'] = $partnerId;
        }

        $this->actingAs($consultantUser)->post(route('consultant.students.store'), $payload)->assertRedirect();

        $payment = Payment::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();

        $this->actingAs($consultantUser)->patch(route('consultant.payments.confirm', $payment), [
            'payment_type' => 'full',
            'payment_date' => now()->toDateString(),
        ])->assertRedirect();

        app(PaymentService::class)->verify($payment->fresh(), $accountant);

        return $payment->fresh();
    }

    public function test_a_lone_student_shows_their_placement_and_already_earns_the_slot_and_bonus(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultant->hiddenAccounts()->first();

        $this->registerConfirmAndVerify($consultantUser, 'mohan-lone@example.com', 1, $hidden->id, $accountant);

        // Both the slot and the direct bonus are recorded immediately — a lone
        // student on one leg is not gated on anything else happening.
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'bonus', 'tier' => 1, 'amount' => 2500]);
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'amount' => 3000]);
        $this->approveAllCommissions();

        $this->assertSame(5500.0, (float) $hidden->fresh()->total_earned, 'A lone student already earns 2,500 + 3,000.');

        // Placement: must NOT say "Not assigned" — they ARE seated in Service Group 1.
        $response = $this->actingAs($consultantUser)->get(route('consultant.students.index'));
        $response->assertOk();
        $response->assertSee('GROUP 01');
        $response->assertDontSee('Not assigned');
    }

    public function test_a_second_student_on_the_opposite_leg_just_adds_its_own_slot_and_bonus(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultant->hiddenAccounts()->first();

        $this->registerConfirmAndVerify($consultantUser, 'mohan-a@example.com', 1, $hidden->id, $accountant);
        $this->approveAllCommissions();

        $this->assertSame(5500.0, (float) $hidden->fresh()->total_earned, 'First side already pays its own slot + bonus.');

        $this->registerConfirmAndVerify($consultantUser, 'mohan-b@example.com', 2, $hidden->id, $accountant);
        $this->approveAllCommissions();

        // The money is registered on the PARTNER account chosen at placement
        // (SCN-00166), not the consultant's own main account — the "Service
        // Groups" page then sums it across all of the consultant's accounts.
        $this->assertSame(11000.0, (float) $hidden->fresh()->total_earned, 'Two independent students: 5,500 + 5,500.');

        // Each leg carries its own 2,500 slot + 3,000 direct — never dumped
        // onto a single Service Group column.
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'bonus', 'tier' => 1, 'amount' => 2500]);
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'bonus', 'tier' => 2, 'amount' => 2500]);
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'tier' => 1, 'amount' => 3000]);
        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'tier' => 2, 'amount' => 3000]);

        // The Service Groups screen keeps the two income kinds apart: each leg
        // shows only its own 2,500 slot income, and the 6,000 of direct-add
        // bonus is reported as its own separate figure — never folded into a
        // Service Group column.
        $response = $this->actingAs($consultantUser)->get(route('consultant.groups.index'));
        $response->assertOk();

        $tierTotals = $response->viewData('tierTotals');

        $this->assertSame(2500.0, (float) $tierTotals[1]->total, 'Service Group 1 carries slot income only.');
        $this->assertSame(2500.0, (float) $tierTotals[2]->total, 'Service Group 2 carries slot income only.');
        $this->assertSame(6000.0, (float) $response->viewData('directIncome'), 'Both direct bonuses, reported separately.');

        $listResponse = $this->actingAs($consultantUser)->get(route('consultant.students.index'));
        $listResponse->assertOk();
        $listResponse->assertSee('GROUP 01');
        $listResponse->assertSee('GROUP 02');
        $listResponse->assertDontSee('Not assigned');
    }
}
