<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnforceConsultantFinanceStatus is deliberately a GLOBAL middleware (see its
 * own docblock) specifically because checkout.store sits in the bare `auth`
 * group with no role middleware at all — a route-scoped gate would leave a
 * suspended consultant able to keep enrolling students through it. This is
 * the single highest-value test for this whole feature: prove that leak is
 * actually closed.
 */
class ConsultantSuspensionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Checkout now requires a slip; keep it out of the real disk.
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->seed();
    }

    private function consultant(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function suspend(Consultant $consultant): void
    {
        $consultant->forceFill([
            'finance_status' => 'suspended',
            'finance_suspended_at' => now(),
        ])->save();
    }

    /**
     * checkout.store sits in the bare `auth` group with NO role middleware at
     * all (see the middleware's own docblock) — a consultant keeps their own
     * LMS access as a learner, so THEY can be the one submitting this route
     * directly, using their own account. This is the exact leak a
     * route-group-scoped gate would have missed.
     */
    public function test_a_suspended_consultant_cannot_check_out_their_own_enrollment(): void
    {
        $consultant = $this->consultant();
        $this->suspend($consultant);
        $course = Course::where('status', 'published')->first();

        $this->actingAs($consultant->user)->post(route('checkout.store', $course), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
        ])->assertRedirect(route('consultant.suspended'));

        $this->assertSame(0, Payment::count(), 'A suspended consultant must not be able to create a payment via checkout, which has no role middleware of its own.');
        $this->assertSame(0, Enrollment::count());
    }

    public function test_a_suspended_consultant_is_redirected_away_from_their_dashboard(): void
    {
        $consultant = $this->consultant();
        $this->suspend($consultant);

        $this->actingAs($consultant->user)
            ->get(route('consultant.dashboard'))
            ->assertRedirect(route('consultant.suspended'));
    }

    public function test_a_suspended_consultant_is_redirected_away_from_registering_a_student(): void
    {
        $consultant = $this->consultant();
        $this->suspend($consultant);

        $this->actingAs($consultant->user)
            ->get(route('consultant.students.create'))
            ->assertRedirect(route('consultant.suspended'));
    }

    public function test_a_suspended_consultant_cannot_submit_a_new_student_registration(): void
    {
        $consultant = $this->consultant();
        $this->suspend($consultant);
        $course = Course::first();

        $this->actingAs($consultant->user)->post(route('consultant.students.store'), [
            'name' => 'New Student',
            'email' => 'new-student-'.uniqid().'@example.com',
            'course_id' => $course->id,
            'placement_tier' => '1',
            'nic_owned_by_student' => '1',
        ])->assertRedirect(route('consultant.suspended'));

        $this->assertSame(0, \App\Models\Student::whereIn('consultant_id', $consultant->accountIds())->count());
    }

    public function test_the_suspended_page_itself_stays_reachable_no_redirect_loop(): void
    {
        $consultant = $this->consultant();
        $this->suspend($consultant);

        $this->actingAs($consultant->user)
            ->get(route('consultant.suspended'))
            ->assertOk();
    }

    public function test_a_suspended_consultant_can_still_see_their_own_finance_report(): void
    {
        $consultant = $this->consultant();
        $this->suspend($consultant);

        $this->actingAs($consultant->user)
            ->get(route('reports.finance'))
            ->assertOk();
    }

    public function test_a_json_request_from_a_suspended_consultant_gets_403_not_a_redirect(): void
    {
        $consultant = $this->consultant();
        $this->suspend($consultant);

        $this->actingAs($consultant->user)
            ->getJson(route('bookings.availability', ['date' => now()->toDateString()]))
            ->assertStatus(403);
    }

    public function test_a_restricted_but_not_suspended_consultant_is_never_touched_by_the_gate(): void
    {
        $consultant = $this->consultant();
        $consultant->forceFill([
            'finance_status' => 'restricted',
            'finance_restricted_at' => now(),
            'finance_restricted_period' => now()->toDateString(),
        ])->save();

        // Restricted consultants must be able to keep working — that is the
        // only way the hold on their income is ever released.
        $this->actingAs($consultant->user)
            ->get(route('consultant.dashboard'))
            ->assertOk();

        $this->actingAs($consultant->user)
            ->get(route('consultant.students.create'))
            ->assertOk();
    }

    public function test_non_consultant_roles_are_never_touched_by_the_gate(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_an_active_consultant_is_not_redirected(): void
    {
        $consultant = $this->consultant();
        $consultant->forceFill(['finance_status' => 'active'])->save();

        $this->actingAs($consultant->user)
            ->get(route('consultant.dashboard'))
            ->assertOk();
    }
}
