<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\CommissionRuleAudit;
use App\Services\CommissionService;
use App\Services\CourseSchemaRepair;
use App\Services\EnrollmentService;
use App\Services\ManualCommissionEntry;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A COURSE WITH ITS OWN HALF PAYMENT AND DIRECT SERVICE INCOME (2026-10-22).
 *
 * Owner: "Mta aluth course ekak add kragnn one ... kalin hambuna direct service
 * bonus eka kalin labune 4000 i meke eka wadi wela thiyeno 5000k widiyta
 * service income eka 2500i eka paran eka wagem normal 2500 add weno."
 *
 *   MASTER IN ELLIOTT WAVE — price 39,000, half 20,000, balance 19,000,
 *   direct service income 5,000, and the 2,500 service income as normal.
 *
 * And the condition they set on the plan: "Meke parana courses tika kalin giya
 * wagem system run e tika yann one. 2500 and 4000 normal parana widiytm
 * calculation tika wenn one stage wise parana widiytama. ekath ekkm thama me
 * aluth courses ekath enn one." — the old courses exactly as before, and the
 * new course inside the SAME System Run, stages and Service Groups.
 *
 * See App\Support\CourseMoney.
 */
class CourseOwnPaymentPlanTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const STANDARD_DIRECT = 4000;

    private Course $elliott;

    private Course $old;

    private User $consultantUser;

    private Consultant $main;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
        CourseSchemaRepair::ensure();
        Cache::forever('settings.mail.grouped', collect());
        Storage::fake('public');

        // The live rates.
        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::STANDARD_DIRECT,
        ]);
        Cache::forget('commission_settings.active');

        // The seeded 28,000 course stands for every existing course.
        $this->old = Course::where('status', 'published')->firstOrFail();

        $this->elliott = Course::create([
            'title' => 'MASTER IN ELLIOTT WAVE',
            'slug' => 'master-in-elliott-wave',
            'price' => 39000,
            'half_payment_amount' => 20000,
            'direct_commission' => 5000,
            'currency' => 'LKR',
            'level' => 'advanced',
            'lecturer_id' => User::where('email', 'lecturer@etec.lk')->value('id'),
            'status' => 'published',
            'published_at' => now(),
            'default_language' => 'Sinhala',
            'default_class_type' => 'Online',
            'default_course_type' => 'Group',
        ]);

        $this->consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->main = $this->consultantUser->consultant;
        $this->accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    // ----- helpers -------------------------------------------------------------

    /** Register a student on $course through the consultant's first placement account. */
    private function register(Course $course, string $email): Payment
    {
        static $n = 0;
        $n++;

        $this->actingAs($this->consultantUser)->post(route('consultant.students.store'), [
            'name' => 'Student '.$email,
            'email' => $email,
            'phone' => '07713'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'date_of_birth' => '2000-01-01',
            'gender' => 'male',
            'address' => '12 Galle Road',
            'city' => 'Colombo',
            'district' => 'Colombo',
            'nic' => '20002'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'nic_owned_by_student' => 1,
            'course_id' => $course->id,
            'language' => 'Sinhala',
            'class_type' => 'Online',
            'course_type' => 'Diploma',
            'placement_tier' => 1,
            'partner_consultant_id' => $this->main->hiddenAccounts()->orderBy('id')->firstOrFail()->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Payment::whereHas('user', fn ($q) => $q->where('email', $email))->latest('id')->firstOrFail();
    }

    /** The consultant's "Confirm Payment" box, on $plan. */
    private function confirm(Payment $payment, string $plan): Payment
    {
        $this->actingAs($this->consultantUser)
            ->patch(route('consultant.payments.confirm', $payment), [
                'payment_type' => $plan,
                'payment_date' => now()->toDateString(),
                'payment_reference_number' => 'REF-'.$payment->id,
                'payment_receipt' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error')
            ->assertRedirect();

        return $payment->fresh();
    }

    private function verify(Payment $payment): Payment
    {
        return app(PaymentService::class)->verify($payment->fresh(), $this->accountant);
    }

    /** A pending FULL payment on $course, placed on $account's $leg — the System Run fixture. */
    private function paymentOn(Consultant $account, int $leg, Course $course, ?User $buyer = null): Payment
    {
        $buyer ??= User::factory()->create();

        if (! Student::where('user_id', $buyer->id)->exists()) {
            Student::create([
                'user_id' => $buyer->id, 'student_code' => 'SRN'.Str::random(8),
                'status' => 'active', 'enrolled_at' => now()->subDays(3),
            ]);
        }

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => $course->effective_price, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now()->subDays(3),
        ]);
    }

    private function seat(Consultant $account, int $leg, Course $course): Payment
    {
        return $this->verify($this->paymentOn($account, $leg, $course));
    }

    /** The live direct service income recorded for one payment. */
    private function direct(Payment $payment): float
    {
        return (float) Commission::where('payment_id', $payment->id)
            ->where('type', CommissionService::TYPE_DIRECT)
            ->where('status', '!=', 'cancelled')
            ->sum('amount');
    }

    private function directRows(Payment $payment): int
    {
        return Commission::where('payment_id', $payment->id)->where('type', CommissionService::TYPE_DIRECT)->count();
    }

    /** @return array<int, float> the distinct slot amounts recorded for one payment */
    private function slotAmounts(Payment $payment): array
    {
        return Commission::where('payment_id', $payment->id)
            ->where('type', CommissionService::TYPE_SLOT)
            ->pluck('amount')
            ->map(fn ($a) => (float) $a)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{0: float, 1: float} one account's two leg balances, as the page shows them */
    private function legs(Consultant $account): array
    {
        $row = collect(
            $this->actingAs($this->consultantUser)->get(route('consultant.groups.index'))->viewData('accountRows')
        )->firstWhere('id', $account->id);

        return [(float) $row['flush']['leg1_total'], (float) $row['flush']['leg2_total']];
    }

    /** @return array{0: Consultant, 1: Consultant} */
    private function twoAccounts(): array
    {
        return $this->main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();
    }

    // ----- the owner's course, end to end --------------------------------------

    /** 20,000 now → 5,000 direct and no 2,500; 19,000 later → the 2,500s and no second direct. */
    public function test_the_half_plan_bills_20000_then_19000_and_pays_5000_direct_once(): void
    {
        $half = $this->confirm($this->register($this->elliott, 'elliott-half@example.com'), 'half');

        $this->assertSame(20000.0, (float) $half->amount, 'the first instalment is the course\'s own 20,000');

        $this->verify($half);

        $this->assertSame(5000.0, $this->direct($half), 'the registering consultant earns 5,000, not 4,000');
        $this->assertSame(1, $this->directRows($half));
        $this->assertSame([], $this->slotAmounts($half), 'a half payment earns no 2,500 yet — same rule as every course');
        $this->assertSame(15000.0, (float) CompanyRevenue::where('payment_id', $half->id)->value('amount'));

        $this->assertSame(19000.0, EnrollmentService::remainingBalance($this->elliott, $half->user_id));

        $this->actingAs($this->consultantUser)
            ->post(route('consultant.payments.pay-balance', $half), [
                'payment_date' => now()->toDateString(),
                'payment_reference_number' => 'REF-BAL',
                'payment_receipt' => UploadedFile::fake()->image('balance.jpg'),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $balance = Payment::where('user_id', $half->user_id)->where('payment_type', 'balance')->firstOrFail();
        $this->assertSame(19000.0, (float) $balance->amount);

        $this->verify($balance);

        $this->assertSame([2500.0], $this->slotAmounts($balance), 'the balance earns the normal 2,500s');
        $this->assertSame(0, $this->directRows($balance), 'and no second direct');
        $this->assertSame(39000.0, (float) Payment::where('user_id', $half->user_id)->where('status', 'verified')->sum('amount'));
    }

    public function test_a_full_payment_bills_39000_and_pays_5000_direct_and_the_normal_2500(): void
    {
        $full = $this->confirm($this->register($this->elliott, 'elliott-full@example.com'), 'full');

        $this->assertSame(39000.0, (float) $full->amount);

        $this->verify($full);

        $this->assertSame(5000.0, $this->direct($full));
        $this->assertSame([2500.0], $this->slotAmounts($full));
    }

    /** "parana courses tika kalin giya wagem" — nothing about an old course moves. */
    public function test_an_old_course_keeps_18000_and_4000(): void
    {
        $half = $this->confirm($this->register($this->old, 'old-half@example.com'), 'half');

        $this->assertSame(18000.0, (float) $half->amount);

        $this->verify($half);

        $this->assertSame(4000.0, $this->direct($half));
        $this->assertSame(10000.0, EnrollmentService::remainingBalance($this->old, $half->user_id));

        $full = $this->confirm($this->register($this->old, 'old-full@example.com'), 'full');
        $this->verify($full);

        $this->assertSame(28000.0, (float) $full->amount);
        $this->assertSame(4000.0, $this->direct($full));
        $this->assertSame([2500.0], $this->slotAmounts($full));
    }

    /** Unchanged: a course cheaper than the first instalment is never billed more than it costs. */
    public function test_the_half_is_never_more_than_the_course_costs(): void
    {
        $cheap = Course::create([
            'title' => 'Short Course', 'slug' => 'short-course', 'price' => 15000,
            'currency' => 'LKR', 'level' => 'beginner', 'status' => 'published',
        ]);

        $this->assertSame(15000.0, EnrollmentService::amountForType($cheap, 'half'));

        $cheap->update(['discount_price' => 12000, 'half_payment_amount' => 14000]);
        $this->assertSame(12000.0, EnrollmentService::amountForType($cheap->fresh(), 'half'));
    }

    public function test_a_free_card_on_the_course_still_pays_no_direct(): void
    {
        $free = $this->confirm($this->register($this->elliott, 'elliott-free@example.com'), 'free_card');

        $this->assertSame(0.0, (float) $free->amount);

        $this->verify($free);

        $this->assertSame(0, $this->directRows($free));
    }

    public function test_buying_the_course_yourself_still_pays_you_no_direct(): void
    {
        [$account] = $this->twoAccounts();

        $own = $this->verify($this->paymentOn($account, 1, $this->elliott, $this->consultantUser));

        $this->assertSame(0, $this->directRows($own));
    }

    public function test_running_the_engine_again_adds_nothing(): void
    {
        $full = $this->verify($this->confirm($this->register($this->elliott, 'elliott-again@example.com'), 'full'));
        $rows = Commission::count();

        app(CommissionService::class)->handleVerifiedPayment($full->fresh());

        $this->assertSame($rows, Commission::count());
        $this->assertSame(5000.0, $this->direct($full));
    }

    /** Commission Health → Repair re-creates a missing direct at the COURSE's amount. */
    public function test_repair_puts_a_missing_direct_back_at_5000(): void
    {
        $full = $this->verify($this->confirm($this->register($this->elliott, 'elliott-repair@example.com'), 'full'));

        Commission::where('payment_id', $full->id)->where('type', CommissionService::TYPE_DIRECT)->delete();
        $this->assertSame(0, $this->directRows($full), 'guard: the direct is gone');

        $this->artisan('commissions:backfill')->assertSuccessful();

        $this->assertSame(1, $this->directRows($full));
        $this->assertSame(5000.0, $this->direct($full));
    }

    /** A payment taken before the course was removed is paid what that course paid. */
    public function test_a_removed_course_still_pays_its_own_amount(): void
    {
        [$account] = $this->twoAccounts();
        $payment = $this->paymentOn($account, 1, $this->elliott);

        $this->elliott->delete();

        $this->verify($payment);

        $this->assertSame(5000.0, $this->direct($payment));
    }

    // ----- one system: the owner's condition -----------------------------------

    /**
     * An Elliott student on Service Group 1 and an old-course student on
     * Service Group 2 make a 1-to-1 pair, and the System Run settles it exactly
     * like a pair of two old-course students.
     */
    public function test_the_new_course_settles_in_the_same_system_run_as_the_old_ones(): void
    {
        [$mixed, $oldOnly] = $this->twoAccounts();

        $elliott = $this->seat($mixed, 1, $this->elliott);
        $oldOnTheSameAccount = $this->seat($mixed, 2, $this->old);
        $old1 = $this->seat($oldOnly, 1, $this->old);
        $old2 = $this->seat($oldOnly, 2, $this->old);

        $this->assertSame([2500.0, 2500.0], $this->legs($mixed), 'the Elliott student fills Service Group 1 with the normal 2,500');
        $this->assertSame([2500.0, 2500.0], $this->legs($oldOnly));

        $this->assertSame(5000.0, $this->direct($elliott));
        $this->assertSame(4000.0, $this->direct($oldOnTheSameAccount));
        $this->assertSame(4000.0, $this->direct($old1));
        $this->assertSame(4000.0, $this->direct($old2));

        $run = app(ServiceGroupFlush::class)->runAll(now()->addDay());
        $settled = collect($run['accounts'])->keyBy('account_id');
        $sameness = ['released', 'forfeited', 'records', 'cancelled', 'rounds', 'stage_before'];

        $this->assertTrue($settled->has($mixed->id), 'the mixed account is settled by the run');
        $this->assertSame(5000.0, (float) $settled[$mixed->id]['released'], '1-to-1: 2,500 from each side');
        $this->assertSame(
            collect($settled[$oldOnly->id])->only($sameness)->all(),
            collect($settled[$mixed->id])->only($sameness)->all(),
            'an Elliott + old pair settles exactly like an old + old pair',
        );
        $this->assertSame([0.0, 0.0], $this->legs($mixed));
        $this->assertSame([0.0, 0.0], $this->legs($oldOnly));

        // The direct is never part of a run.
        $this->assertSame(5000.0, $this->direct($elliott));
        $this->assertSame(4000.0, $this->direct($oldOnTheSameAccount));
    }

    /** "stage wise parana widiytama" — 1-to-1 then 2-to-2, from both sides, with both courses on the legs. */
    public function test_the_stages_walk_the_same_with_the_new_course_on_the_legs(): void
    {
        [$account] = $this->twoAccounts();

        for ($i = 0; $i < 3; $i++) {
            $this->seat($account, 1, $this->elliott);
            $this->seat($account, 2, $this->old);
        }

        $this->assertSame([7500.0, 7500.0], $this->legs($account), 'guard: three 2,500s a side');

        foreach ([self::SLOT, 2 * self::SLOT] as $i => $step) {
            [$before1, $before2] = $this->legs($account);

            app(ServiceGroupFlush::class)->runAll(now()->addDay());

            [$after1, $after2] = $this->legs($account);

            $this->assertSame((float) $step, $before1 - $after1, 'run #'.($i + 1).': Service Group 1 (Elliott students)');
            $this->assertSame((float) $step, $before2 - $after2, 'run #'.($i + 1).': Service Group 2 (old-course students)');
        }
    }

    // ----- the checks and the screens ------------------------------------------

    public function test_the_rule_audit_expects_each_courses_own_amount(): void
    {
        [$account] = $this->twoAccounts();
        $elliott = $this->seat($account, 1, $this->elliott);
        $old = $this->seat($account, 2, $this->old);

        $wrong = fn () => collect(app(CommissionRuleAudit::class)->run()['violations'])
            ->where('rule', 'wrong_direct_amount')
            ->pluck('payment_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([], $wrong(), '5,000 on the Elliott course and 4,000 on the old one are both right');

        Commission::where('payment_id', $elliott->id)->where('type', CommissionService::TYPE_DIRECT)->update(['amount' => 4000]);
        $this->assertSame([$elliott->id], $wrong(), 'a 4,000 on the Elliott course is wrong');

        Commission::where('payment_id', $elliott->id)->where('type', CommissionService::TYPE_DIRECT)->update(['amount' => 5000]);
        Commission::where('payment_id', $old->id)->where('type', CommissionService::TYPE_DIRECT)->update(['amount' => 5000]);
        $this->assertSame([$old->id], $wrong(), 'and so is a 5,000 on an old course');

        // A course removed later still has its own amount for the payments made on it.
        $this->elliott->delete();
        $this->assertSame([$old->id], $wrong(), 'the Elliott 5,000 is still right after the course is removed');
    }

    public function test_the_confirm_box_shows_the_courses_own_plan(): void
    {
        $this->register($this->elliott, 'elliott-modal@example.com');

        $this->actingAs($this->consultantUser)
            ->get(route('consultant.students.index'))
            ->assertOk()
            ->assertSee('Full payment — LKR 39,000')
            ->assertSee('Half payment — LKR 20,000 now, LKR 19,000 later');
    }

    public function test_the_confirm_box_for_an_old_course_shows_the_standard_plan(): void
    {
        $this->register($this->old, 'old-modal@example.com');

        $this->actingAs($this->consultantUser)
            ->get(route('consultant.students.index'))
            ->assertOk()
            ->assertSee('Full payment — LKR 28,000')
            ->assertSee('Half payment — LKR 18,000 now, LKR 10,000 later');
    }

    public function test_the_groups_page_names_the_courses_own_rate(): void
    {
        $this->actingAs($this->consultantUser)
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertSee('LKR 4,000/student')
            ->assertSee('MASTER IN ELLIOTT WAVE: LKR 5,000/student');
    }

    public function test_hand_entry_pays_the_courses_own_direct(): void
    {
        $account = $this->main->hiddenAccounts()->orderBy('id')->firstOrFail();
        $entry = app(ManualCommissionEntry::class);

        $withCourse = $entry->add($account, null, 'direct', 1, 'account', null, null, $this->elliott->id);
        $standard = $entry->add($account, null, 'direct', 1, 'account');
        $slot = $entry->add($account, null, 'slot', 1, 'account', null, null, $this->elliott->id);

        $this->assertSame(5000.0, (float) $withCourse['created'][0]['amount']);
        $this->assertSame(4000.0, (float) $standard['created'][0]['amount']);
        $this->assertSame(2500.0, (float) $slot['created'][0]['amount'], 'the 2,500 is the same for every course');
    }

    public function test_the_hand_entry_form_takes_the_course(): void
    {
        [$account] = $this->twoAccounts();

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->post(route('groups.manual-commission'), [
                'consultant_id' => $account->id,
                'type' => 'direct', 'tier' => 1, 'scope' => 'account',
                'course_id' => $this->elliott->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            5000.0,
            (float) Commission::where('consultant_id', $account->id)->where('type', CommissionService::TYPE_DIRECT)->sum('amount'),
        );

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('groups.index'))
            ->assertOk()
            ->assertSee('MASTER IN ELLIOTT WAVE');
    }
}
