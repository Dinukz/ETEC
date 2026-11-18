<?php

namespace Tests\Feature;

use App\Livewire\ServiceIncomeBoard;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ⚠️ WHY A 2,500 APPEARED, when nobody registered a student.
 *
 * Asked for 2026-09-06: a consultant buying a course through one of their own
 * SCN accounts mints a slot commission — correctly — but the row on the Service
 * Income board looked no different from any other, so there was nothing on
 * screen saying where the money came from. "SCN account ekin course ekak buy
 * kranam, course ekak buy kra kiyla methan penwann — e buy krla thama methant
 * 2500 add une kiyla danagann."
 *
 * The test that matters is the NEGATIVE one: a label that appears on every row
 * says nothing at all.
 */
class CoursePurchaseIncomeLabelTest extends TestCase
{
    use RefreshDatabase;

    private const LABEL = 'Opened by a course purchase';

    private Consultant $consultant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => 2500,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');

        $this->consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function nextCourse(): Course
    {
        $template = Course::where('status', 'published')->firstOrFail();
        $n = Course::count() + 1;

        return Course::create([
            'title' => 'Label Course '.$n,
            'slug' => 'label-course-'.$n.'-'.Str::random(6),
            'description' => 'test',
            'price' => $template->price,
            'currency' => $template->currency,
            'status' => 'published',
            'lecturer_id' => $template->lecturer_id,
            'published_at' => now(),
        ]);
    }

    private function verify(User $buyer, Consultant $account): Payment
    {
        $course = $this->nextCourse();

        $enrollment = Enrollment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $account->id, 'requested_tier' => 1,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    private function someoneElse(): User
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function slotFor(Payment $payment): Commission
    {
        return Commission::with(['payment', 'consultant.owner'])
            ->where('payment_id', $payment->id)
            ->where('type', 'bonus')
            ->firstOrFail();
    }

    /** The consultant bought the course themselves, through their own account. */
    public function test_a_self_purchase_row_knows_it_came_from_a_course_purchase(): void
    {
        $account = $this->consultant->hiddenAccounts()->orderBy('id')->first();

        $payment = $this->verify($this->consultant->user, $account);

        $this->assertTrue($this->slotFor($payment)->isCoursePurchase());
    }

    /**
     * ⚠️ THE ONE THAT GIVES THE LABEL ITS MEANING. Ordinary income — somebody
     * else's registration — must NOT carry it.
     */
    public function test_registering_somebody_else_is_not_a_course_purchase(): void
    {
        $account = $this->consultant->hiddenAccounts()->orderBy('id')->first();

        $payment = $this->verify($this->someoneElse(), $account);

        $this->assertFalse($this->slotFor($payment)->isCoursePurchase());
    }

    /**
     * A purchase routed through a HIDDEN placement account still counts. The
     * account has no user_id of its own, so the question is only answerable
     * through the owner — which is why isCoursePurchase() resolves
     * owningPerson() rather than reading consultant->user_id.
     */
    public function test_it_sees_through_a_hidden_placement_account(): void
    {
        $account = $this->consultant->hiddenAccounts()->orderBy('id')->first();

        $this->assertTrue($account->is_hidden, 'guard: this really is a placement account');

        $payment = $this->verify($this->consultant->user, $account);
        $row = $this->slotFor($payment);

        $this->assertTrue($row->consultant->is_hidden);
        $this->assertTrue($row->isCoursePurchase());
    }

    /** It reaches the screen the owner was actually looking at. */
    public function test_the_board_says_so(): void
    {
        $account = $this->consultant->hiddenAccounts()->orderBy('id')->first();
        $this->verify($this->consultant->user, $account);

        Livewire::actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->test(ServiceIncomeBoard::class)
            ->assertSee(self::LABEL);
    }

    public function test_the_board_stays_quiet_for_an_ordinary_registration(): void
    {
        $account = $this->consultant->hiddenAccounts()->orderBy('id')->first();
        $this->verify($this->someoneElse(), $account);

        Livewire::actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->test(ServiceIncomeBoard::class)
            ->assertDontSee(self::LABEL);
    }

    /** And on the history page, so the two screens agree. */
    public function test_the_history_page_says_so_too(): void
    {
        $account = $this->consultant->hiddenAccounts()->orderBy('id')->first();
        $this->verify($this->consultant->user, $account);

        $this->actingAs(User::where('email', 'superadmin@etec.lk')->firstOrFail())
            ->get(route('commissions.history'))
            ->assertOk()
            ->assertSee(self::LABEL);
    }
}
