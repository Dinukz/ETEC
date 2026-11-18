<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A consultant's reports must show THEIR figures only — never the company's.
 *
 * Reported 2026-08-14 from a consultant login (SCN-00109): the Payment Summary
 * Report's "Verified payments in range" card read LKR 1,288,000, which was the
 * whole company's course revenue, because that one total was the only query on
 * the page with no viewer scope on it. "Consultant ge payment detail withrk
 * awanm athi company eke detail one na methanata."
 *
 * That is a data leak as much as a wrong number: a consultant could read total
 * company payment volume off their own report page. The Admission Report leaked
 * the same way through its "Direct (no consultant)" count.
 */
class ReportsHideCompanyDataFromConsultantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function consultantUser(): User
    {
        $user = User::where('email', 'consultant@etec.lk')->firstOrFail();

        // Both reports are per-consultant grants since 2026-09-22 — granted here
        // so these tests keep checking what a consultant who HAS them can see.
        $this->grantConsultantPages($user, ['admissions', 'payment_summary']);

        return $user;
    }

    /** A verified payment on $account, or with no consultant at all when null. */
    private function verifiedPayment(?Consultant $account, float $amount): Payment
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN'.Str::random(6),
            'consultant_id' => $account?->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $account?->id,
            'requested_tier' => 1,
            'amount' => $amount,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    /** A second, unrelated consultant whose money must never appear on the first one's report. */
    private function otherConsultant(): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    // ----- Payment Summary Report ---------------------------------------------

    public function test_a_consultant_sees_only_their_own_students_payments(): void
    {
        $consultant = $this->consultantUser()->consultant;
        $mine = $consultant->hiddenAccounts()->first();

        $this->verifiedPayment($mine, 28000);
        // Someone else's money, and a direct signup with no consultant — both
        // are company data and must not reach this consultant's total.
        $this->verifiedPayment($this->otherConsultant()->hiddenAccounts()->first(), 50000);
        $this->verifiedPayment(null, 90000);

        $response = $this->actingAs($this->consultantUser())->get(route('reports.payments'));

        $response->assertOk();

        $this->assertSame(
            28000.0,
            (float) $response->viewData('totals')['payments'],
            "A consultant's payments total must cover their own accounts only — not the company's.",
        );
    }

    public function test_staff_still_see_the_company_wide_payments_total(): void
    {
        $consultant = $this->consultantUser()->consultant;

        $this->verifiedPayment($consultant->hiddenAccounts()->first(), 28000);
        $this->verifiedPayment($this->otherConsultant()->hiddenAccounts()->first(), 50000);
        $this->verifiedPayment(null, 90000);

        $response = $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->get(route('reports.payments'));

        $response->assertOk();

        $this->assertSame(
            168000.0,
            (float) $response->viewData('totals')['payments'],
            'Staff must keep the full company figure — the fix is scoping, not removal.',
        );
    }

    /** The leak was visible on screen, so assert the rendered figure too. */
    public function test_the_company_total_is_not_rendered_on_a_consultants_page(): void
    {
        $consultant = $this->consultantUser()->consultant;

        $this->verifiedPayment($consultant->hiddenAccounts()->first(), 28000);
        $this->verifiedPayment(null, 1260000);

        $response = $this->actingAs($this->consultantUser())->get(route('reports.payments'));

        $response->assertOk();
        $response->assertDontSee('1,288,000', false);
        $response->assertSee('Payments from your students', false);
    }

    // ----- Admission Report ----------------------------------------------------

    public function test_a_consultant_does_not_see_the_direct_signup_count(): void
    {
        $this->verifiedPayment(null, 28000);
        $this->verifiedPayment(null, 28000);

        $response = $this->actingAs($this->consultantUser())->get(route('reports.admissions'));

        $response->assertOk();
        $this->assertSame(0, $response->viewData('direct'), 'Company-wide direct signups are not a consultant figure.');
        $response->assertDontSee('Direct (no consultant)', false);
    }

    public function test_staff_still_see_the_direct_signup_count(): void
    {
        $this->verifiedPayment(null, 28000);
        $this->verifiedPayment(null, 28000);

        $response = $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->get(route('reports.admissions'));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('direct'));
        $response->assertSee('Direct (no consultant)', false);
    }
}
