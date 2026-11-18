<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️⚠️ BUYING AN EXTRA ACCOUNT MUST PAY THE UPLINE 2,500 (fixed 2026-08-29).
 *
 * Reported four times, most recently: "Thamath ara 4th account eka buy kraddi
 * 2500 upline ekata calculate wen na. Thamange account ekat cal wenwa eth upline
 * eken caculate wen na. 4th, 5th, 6th, 7th... 12th account walinuth course buy
 * kraham upline eken issrahata calculate wenn one."
 *
 * ROOT CAUSE — the guard, not the climb. placeInto() refused to award a second
 * slot on a seat that already had one. That is right for the REGISTRANT and it
 * is what the 2026-08-14 duplicate-seat crash fix depends on. It was wrong for
 * an UPLINE hop, because of how a downline comes to exist: they became this
 * sponsor's downline by being registered as a STUDENT under them, which seated
 * them in the sponsor's group and paid that seat its 2,500 years ago. When that
 * same person later buys a course, placeInto() reuses that seat, sees the old
 * 2,500, and returns NULL.
 *
 * ⚠️ The null then cancelled the rest of the hop as well: creditUpline() wrapped
 * both the auto-approve and creditOwnAccountAncestors() in `if ($commission)`,
 * so one suppressed row at the bottom silenced the whole branch above it.
 *
 * ⚠️ WHY NO EXISTING TEST CAUGHT IT: the upline tests call
 * `Commission::query()->delete()` before asserting, which erases the very rows
 * that trigger the guard. Nothing here deletes anything.
 *
 * The guard is now per EARNING EVENT (payment, or the enrollment that a half
 * and its balance share) rather than per seat — so re-verification, the
 * half-to-balance rule and the backfill all still refuse to pay twice.
 */
class BuyingAnAccountPaysTheUplineTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private Consultant $sponsor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');

        $this->sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /**
     * ⚠️ EACH PURCHASE IS A DIFFERENT COURSE, and that is not a test
     * convenience — `enrollments` carries unique (user_id, course_id), so one
     * person genuinely cannot buy the same course twice. That constraint is
     * also what makes the new per-enrollment guard safe: two purchases can
     * never share an enrollment, while a half and its balance always do.
     */
    private function nextCourse(): Course
    {
        $template = Course::where('status', 'published')->firstOrFail();
        $n = Course::count() + 1;

        return Course::create([
            'title' => 'Load Course '.$n,
            'slug' => 'load-course-'.$n.'-'.Str::random(6),
            'description' => 'test',
            'price' => $template->price,
            'currency' => $template->currency,
            'status' => 'published',
            'lecturer_id' => $template->lecturer_id,
            'published_at' => now(),
        ]);
    }

    private function verify(User $buyer, Consultant $account, int $leg = 1, string $plan = 'full', float $amount = 39000, ?Course $course = null): Payment
    {
        $course ??= $this->nextCourse();

        $enrollment = \App\Models\Enrollment::create([
            'user_id' => $buyer->id,
            'course_id' => $course->id,
            'status' => 'pending',
            'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => $amount, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => $plan, 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    /**
     * A downline the way one really comes to exist: registered as a STUDENT
     * under the sponsor's account (which seats them there), then promoted.
     */
    private function downlineUnder(Consultant $sponsorAccount): Consultant
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $this->verify($user, $sponsorAccount, 1);

        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    /** Slot money on one account, for one specific payment. */
    private function slotFor(Payment $payment, Consultant $account): float
    {
        return (float) Commission::where('payment_id', $payment->id)
            ->where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->sum('amount');
    }

    private function sponsorAccount(): Consultant
    {
        return $this->sponsor->hiddenAccounts()->orderBy('id')->first();
    }

    // ---- The report --------------------------------------------------------

    public function test_buying_the_4th_account_pays_the_upline(): void
    {
        $sponsorAccount = $this->sponsorAccount();
        $downline = $this->downlineUnder($sponsorAccount);

        // The buyer already occupies a seat in the sponsor's group — this is
        // the condition that broke it, and in real life it is ALWAYS true.
        $this->assertTrue(
            \App\Models\GroupMember::where('user_id', $downline->user_id)
                ->whereIn('group_id', \App\Models\Group::where('consultant_id', $sponsorAccount->id)->select('id'))
                ->exists(),
            'guard: the buyer is seated in the sponsor group, because that is how they became a downline',
        );

        // Now they buy their 4th account, placed on one of their own accounts.
        $purchase = $this->verify($downline->user, $downline->hiddenAccounts()->orderBy('id')->first(), 1);

        $this->assertSame(
            (float) self::SLOT,
            $this->slotFor($purchase, $downline->hiddenAccounts()->orderBy('id')->first()),
            'their own account earns, which always worked',
        );

        $this->assertSame(
            (float) self::SLOT,
            $this->slotFor($purchase, $sponsorAccount),
            'AND the sponsor account earns its upline 2,500 — the reported bug',
        );
    }

    /** "4th, 5th, 6th, 7th... 12th" — every later purchase, not just the first. */
    public function test_every_later_purchase_pays_the_upline_too(): void
    {
        $sponsorAccount = $this->sponsorAccount();
        $downline = $this->downlineUnder($sponsorAccount);

        for ($purchaseNumber = 1; $purchaseNumber <= 4; $purchaseNumber++) {
            $accounts = $downline->fresh()->hiddenAccounts()->orderBy('id')->get();

            $purchase = $this->verify($downline->user, $accounts->last(), 1);

            $this->assertSame(
                (float) self::SLOT,
                $this->slotFor($purchase, $sponsorAccount),
                'purchase #'.$purchaseNumber.' must reach the sponsor as well',
            );
        }
    }

    /** The audit that found this must now report the network clean. */
    public function test_the_rule_audit_no_longer_reports_a_missing_sponsor(): void
    {
        $downline = $this->downlineUnder($this->sponsorAccount());
        $this->verify($downline->user, $downline->hiddenAccounts()->orderBy('id')->first(), 1);

        $audit = app(\App\Services\CommissionRuleAudit::class)->run();

        $this->assertSame([], $audit['violations'], 'no rule may be broken by a course purchase');
    }

    // ---- ...without paying anyone twice ------------------------------------

    /** ⚠️ Re-verifying the same payment must remain a no-op. */
    public function test_verifying_the_same_purchase_twice_pays_once(): void
    {
        $sponsorAccount = $this->sponsorAccount();
        $downline = $this->downlineUnder($sponsorAccount);

        $purchase = $this->verify($downline->user, $downline->hiddenAccounts()->orderBy('id')->first(), 1);

        app(PaymentService::class)->verify($purchase->fresh(), User::where('email', 'accountant@etec.lk')->firstOrFail());

        $this->assertSame(
            (float) self::SLOT,
            $this->slotFor($purchase, $sponsorAccount),
            'still exactly one 2,500 on the sponsor',
        );
    }

    /**
     * ⚠️ THE RULE THIS FIX MUST NOT BREAK (2026-08-23): a half payment earns
     * the 4,000 only, the balance earns the 2,500 — once, not twice. They share
     * an enrollment, which is exactly what the new guard pairs them on.
     */
    public function test_a_half_then_its_balance_pays_one_2500_not_two(): void
    {
        $sponsorAccount = $this->sponsorAccount();
        $downline = $this->downlineUnder($sponsorAccount);
        $account = $downline->hiddenAccounts()->orderBy('id')->first();

        $student = User::factory()->create();
        Student::create([
            'user_id' => $student->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $half = $this->verify($student, $account, 1, 'half', 18000);

        $this->assertSame(0.0, $this->slotFor($half, $account), 'guard: a half payment earns no slot');

        // The balance, carrying the SAME enrollment — as payBalance() copies it.
        $balance = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $student->id,
            'course_id' => $half->course_id, 'enrollment_id' => $half->enrollment_id,
            'consultant_id' => $account->id, 'requested_tier' => 1,
            'amount' => 21000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'balance', 'status' => 'pending', 'paid_at' => now(),
        ]);
        app(PaymentService::class)->verify($balance, User::where('email', 'accountant@etec.lk')->firstOrFail());

        $onSeat = (float) Commission::where('type', 'bonus')
            ->where('consultant_id', $account->id)
            ->where('user_id', $student->id)
            ->sum('amount');

        $this->assertSame((float) self::SLOT, $onSeat, 'exactly one 2,500 across the half and its balance');
    }

    /**
     * ⚠️ AND THE SEAT IS STILL REUSED — the 2026-08-14 production crash
     * ("Duplicate entry for key uniq_group_user" while verifying PYM-00054).
     * A second seat for the same student in the same group must never be
     * created, whatever the money does.
     */
    public function test_a_second_purchase_never_creates_a_second_seat(): void
    {
        $sponsorAccount = $this->sponsorAccount();
        $downline = $this->downlineUnder($sponsorAccount);

        $seatsBefore = \App\Models\GroupMember::whereIn(
            'group_id', \App\Models\Group::where('consultant_id', $sponsorAccount->id)->select('id')
        )->where('user_id', $downline->user_id)->count();

        $this->verify($downline->user, $downline->hiddenAccounts()->orderBy('id')->first(), 1);

        $this->assertSame(
            $seatsBefore,
            \App\Models\GroupMember::whereIn(
                'group_id', \App\Models\Group::where('consultant_id', $sponsorAccount->id)->select('id')
            )->where('user_id', $downline->user_id)->count(),
            'one seat per student per group — the unique key still holds',
        );
    }

    /** A consultant buying their own course still earns no 4,000 (2026-08-22). */
    public function test_a_self_purchase_still_pays_no_direct_bonus(): void
    {
        $downline = $this->downlineUnder($this->sponsorAccount());

        $purchase = $this->verify($downline->user, $downline->hiddenAccounts()->orderBy('id')->first(), 1);

        $this->assertSame(
            0.0,
            (float) Commission::where('payment_id', $purchase->id)->where('type', 'referral')->sum('amount'),
            'nobody was brought in, so no direct bonus is due',
        );
    }
}
