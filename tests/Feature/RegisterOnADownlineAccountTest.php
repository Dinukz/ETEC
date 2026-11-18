<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ REGISTERING A STUDENT ON AN ACCOUNT FURTHER DOWN YOUR OWN TREE.
 *
 * The owner's worked example, 2026-09-25:
 *
 *   "SCN 112 consultant, eyage SCN 113 account eka system run eken balance
 *    krgann one nisa, eyage yathe inn SCN 120 account ekat student kenk add
 *    krnwa. SCN 120 account eka upline eka — placement krla thiyenne — SCN 113
 *    eke. ethkot direct 4000 add wenne SCN 120. e wagem 2500 add wenwa SCN
 *    120ta ha eyage upline eke inn SCN 113 ta. SCN 113 sub account ekak nisa
 *    SCN 112tath add wenwa. e wagem upline eken udata e 2500 cal wenwa. thaw
 *    deyak: SCN 120 sub account ekak nm eyage main account ekatath cal wenwa."
 *
 * In this fixture:
 *   TOP        = SCN-112  — the consultant doing the registering
 *   TOP's SG1  = SCN-113  — the account he is trying to balance
 *   DOWN       = a consultant sitting under SCN-113
 *   DOWN's SG1 = SCN-120  — the account the student is actually placed on
 *
 * ⚠️ THIS FILE ASSERTS THE ENGINE AS IT STANDS. It was written before any
 * change, to establish exactly which accounts a placement like this already
 * credits — so that the form work that follows cannot quietly move money.
 */
class RegisterOnADownlineAccountTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        Cache::forget('commission_settings.active');

        // ⚠️ AppServiceProvider::boot() reads mail settings out of the database
        // on every request and memoises them forever. Posting the registration
        // form boots the provider again, and in a test that has already healed
        // the finance schema the `settings` table is not reachable from that
        // boot — the query throws before the controller is ever reached.
        // Priming the key it caches skips the query and leaves mail on its
        // .env defaults, which is what a test wants anyway.
        Cache::forever('settings.mail.grouped', collect());
    }

    private function top(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** A real consultant placed under $sponsorAccount, with their own accounts. */
    private function consultantUnder(Consultant $sponsorAccount, string $name): Consultant
    {
        $user = User::factory()->create(['name' => $name]);
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-9'.random_int(1000, 9999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    /** Register a student ON $placement, paid for by whoever — then verify it. */
    private function registerOn(Consultant $placement, int $tier = 1): Payment
    {
        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        Student::create([
            'user_id' => $buyer->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $course = Course::where('status', 'published')->firstOrFail();

        $enrollment = Enrollment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $placement->id, 'requested_tier' => $tier,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    /** @return array<string, array{slot: float, direct: float}> keyed by SCN code */
    private function creditedBy(Payment $payment): array
    {
        $rows = Commission::where('payment_id', $payment->id)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->get();

        $accounts = Consultant::whereIn('id', $rows->pluck('consultant_id')->unique())
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($rows as $row) {
            $account = $accounts[$row->consultant_id] ?? null;
            $code = $account?->scn_code ?: $account?->consultant_code ?: ('#'.$row->consultant_id);

            $out[$code]['slot'] = ($out[$code]['slot'] ?? 0) + ($row->type === 'referral' ? 0 : (float) $row->amount);
            $out[$code]['direct'] = ($out[$code]['direct'] ?? 0) + ($row->type === 'referral' ? (float) $row->amount : 0);
        }

        return $out;
    }

    // ------------------------------------------------------------------------

    /**
     * ⚠️ THE OWNER'S EXAMPLE, END TO END. Everything from the placed account
     * upwards earns its 2,500; the direct bonus lands once.
     */
    public function test_a_student_placed_on_a_downline_account_credits_the_whole_chain(): void
    {
        $top = $this->top();                                    // SCN-112
        $topSg1 = $top->hiddenAccounts()->orderBy('id')->firstOrFail();   // SCN-113

        $down = $this->consultantUnder($topSg1, 'Down Line');
        $downSg1 = $down->hiddenAccounts()->orderBy('id')->firstOrFail(); // SCN-120

        $credited = $this->creditedBy($this->registerOn($downSg1));

        $expected = [
            $downSg1->scn_code,          // the account the student sits on
            $down->scn_code,             // its own main
            $topSg1->scn_code,           // the account it hangs under
            $top->scn_code,              // and that account's main
        ];

        foreach ($expected as $code) {
            $this->assertArrayHasKey($code, $credited, "{$code} earned nothing");
            $this->assertSame((float) self::SLOT, $credited[$code]['slot'], "{$code} should hold one 2,500");
        }

        // ⚠️ THE DIRECT BONUS IS NOT A CLIMB — exactly one account gets it.
        $withDirect = array_keys(array_filter($credited, fn ($r) => ($r['direct'] ?? 0) > 0));

        $this->assertCount(1, $withDirect, 'the 4,000 lands once, not up the chain');
        $this->assertSame((float) self::DIRECT, $credited[$withDirect[0]]['direct']);
    }

    /** Which account the 4,000 belongs to — stated, so a form change cannot move it. */
    public function test_the_direct_bonus_lands_on_the_account_the_student_was_placed_on(): void
    {
        $top = $this->top();
        $topSg1 = $top->hiddenAccounts()->orderBy('id')->firstOrFail();

        $down = $this->consultantUnder($topSg1, 'Down Line');
        $downSg1 = $down->hiddenAccounts()->orderBy('id')->firstOrFail();

        $credited = $this->creditedBy($this->registerOn($downSg1));

        $this->assertSame(
            (float) self::DIRECT,
            $credited[$downSg1->scn_code]['direct'] ?? 0.0,
            'the 4,000 follows the placement account, not the person who typed it in',
        );
    }

    /**
     * ⚠️ THE FORM CAN NOW REACH THAT ACCOUNT DIRECTLY (2026-09-25).
     *
     * The Partner Consultant picker used to offer only the accounts of whoever
     * was named in "Consultant (Requested By)", so filling SCN-120 meant first
     * knowing — and selecting — the downline person who happens to hold it. The
     * owner picks the ACCOUNT he wants balanced, and the money follows from it.
     * Since 2026-11-03 Requested By (the viewer, when none is sent) stays and
     * pays — see RequestedByPaysForADownlineAccountTest.
     */
    public function test_the_form_accepts_any_service_group_account_in_the_downline(): void
    {
        $top = $this->top();
        $topSg1 = $top->hiddenAccounts()->orderBy('id')->firstOrFail();

        $down = $this->consultantUnder($topSg1, 'Down Line');
        $downSg1 = $down->hiddenAccounts()->orderBy('id')->firstOrFail();

        $course = Course::where('status', 'published')->firstOrFail();

        $this->actingAs($top->user)
            ->post(route('consultant.students.store'), [
                'name' => 'Placed Student',
                'email' => 'placed.student@example.com',
                'course_id' => $course->id,
                'placement_tier' => 1,
                'language' => 'Sinhala',
                'class_type' => 'Online',
                'course_type' => 'Group',
                'phone' => '0771234567',
                'date_of_birth' => '2004-05-05',
                'gender' => 'male',
                'address' => '42 Temple Road',
                'city' => 'Kandy',
                'district' => 'Kandy',
                'nic' => '200412345678',
                'nic_owned_by_student' => 1,
                // No requested_by at all — the viewer is Requested By and pays;
                // the account decides the money.
                'language' => 'Sinhala',
                'class_type' => 'Online',
                'course_type' => 'Group',
                'phone' => '0771234567',
                'date_of_birth' => '2004-05-05',
                'gender' => 'male',
                'address' => '42 Temple Road',
                'city' => 'Kandy',
                'district' => 'Kandy',
                'nic' => '200412345678',
                'nic_owned_by_student' => 1,
                'partner_consultant_id' => $downSg1->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payment = Payment::whereHas('user', fn ($q) => $q->where('email', 'placed.student@example.com'))->firstOrFail();

        $this->assertSame(
            (int) $downSg1->id,
            (int) $payment->consultant_id,
            'the registration sits on the account that was picked',
        );
    }

    /** ⚠️ AND THE BOUNDARY HOLDS: a stranger's account is still refused. */
    public function test_the_form_refuses_an_account_outside_the_downline(): void
    {
        $top = $this->top();

        $strangerUser = User::factory()->create();
        $strangerUser->assignRole('consultant');
        $stranger = Consultant::create([
            'user_id' => $strangerUser->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-6'.random_int(1000, 9999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($stranger);

        $course = Course::where('status', 'published')->firstOrFail();

        $this->actingAs($top->user)
            ->post(route('consultant.students.store'), [
                'name' => 'Not Allowed',
                'email' => 'not.allowed@example.com',
                'course_id' => $course->id,
                'placement_tier' => 1,
                'language' => 'Sinhala',
                'class_type' => 'Online',
                'course_type' => 'Group',
                'phone' => '0771234567',
                'date_of_birth' => '2004-05-05',
                'gender' => 'male',
                'address' => '42 Temple Road',
                'city' => 'Kandy',
                'district' => 'Kandy',
                'nic' => '200412345678',
                'nic_owned_by_student' => 1,
                'partner_consultant_id' => $stranger->fresh()->hiddenAccounts()->orderBy('id')->firstOrFail()->id,
            ])
            ->assertSessionHasErrors('partner_consultant_id');

        $this->assertFalse(
            User::where('email', 'not.allowed@example.com')->exists(),
            'nothing was registered',
        );
    }

    /** Nobody outside that line is touched. */
    public function test_a_stranger_earns_nothing_from_it(): void
    {
        $top = $this->top();
        $topSg1 = $top->hiddenAccounts()->orderBy('id')->firstOrFail();
        $down = $this->consultantUnder($topSg1, 'Down Line');
        $downSg1 = $down->hiddenAccounts()->orderBy('id')->firstOrFail();

        $strangerUser = User::factory()->create();
        $strangerUser->assignRole('consultant');
        $stranger = Consultant::create([
            'user_id' => $strangerUser->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-7'.random_int(1000, 9999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        $credited = $this->creditedBy($this->registerOn($downSg1));

        $this->assertArrayNotHasKey($stranger->scn_code, $credited);
    }
}
