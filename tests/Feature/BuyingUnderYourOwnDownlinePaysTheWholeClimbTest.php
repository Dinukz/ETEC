<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Models\Setting;
use App\Services\ClimbRules;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A CONSULTANT BUYING A COURSE UNDER THEIR OWN DOWNLINE'S ACCOUNT MUST PAY
 * THE WHOLE CLIMB — including their own accounts (fixed 2026-09-15).
 *
 * Reported on SCN-00229 (Lakshan maduranga): he bought PYM-00079 placed under
 * SCN-00432, which belongs to chandima gunalath — who sits INSIDE SCN-00230,
 * Lakshan's own Service Group 1. The 2,500 reached SCN-00432 and SCN-00430 and
 * then nothing: not SCN-00230, not SCN-00229, and not one of the six accounts
 * above him. Owner: "e placement krapu account eken point eka meyata saha
 * meyagen udat calculate wenn one".
 *
 * ROOT CAUSE: creditUpline() `break`s the moment it meets an account owned by
 * the BUYER. A consultant buying under their own downline always climbs back
 * through themselves, so the entire rest of the chain was cut off. On the live
 * dump of 2026-09-15 this is 7 of 218 full payments — and all 7 stop exactly at
 * an account owned by the buyer.
 *
 * What must NOT change: the buyer still earns no 4,000 (nobody was brought in),
 * and a consultant buying under their OWN account still does not climb back into
 * themselves.
 *
 * ⚠️ HISTORY IS NOT REPLAYED (2026-09-17). The owner added the missing income by
 * hand, so the new climb applies only to payments verified after the build first
 * ran — see ClimbRules. A "Finish Upline Climbs" button existed for a day and was
 * removed for that reason.
 */
class BuyingUnderYourOwnDownlinePaysTheWholeClimbTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    /** The top of the tree ("SCN-00131 / SCN-00130" in the report). */
    private Consultant $top;

    /** The buyer ("Lakshan", SCN-00229), sponsored through $top's first account. */
    private Consultant $buyer;

    /** The buyer's own downline ("chandima", SCN-00430), inside the buyer's first account. */
    private Consultant $downline;

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

        $this->top = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $this->buyer = $this->downlineUnder($this->firstAccountOf($this->top));
        $this->downline = $this->downlineUnder($this->firstAccountOf($this->buyer));
    }

    private function nextCourse(): Course
    {
        $template = Course::where('status', 'published')->firstOrFail();
        $n = Course::count() + 1;

        return Course::create([
            'title' => 'Climb Course '.$n,
            'slug' => 'climb-course-'.$n.'-'.Str::random(6),
            'description' => 'test',
            'price' => $template->price,
            'currency' => $template->currency,
            'status' => 'published',
            'lecturer_id' => $template->lecturer_id,
            'published_at' => now(),
        ]);
    }

    private function verify(User $buyer, Consultant $account, int $leg = 1): Payment
    {
        $course = $this->nextCourse();

        $enrollment = \App\Models\Enrollment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    /** A downline the way one really comes to exist: seated as a student, then promoted. */
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

    private function firstAccountOf(Consultant $person): Consultant
    {
        return $person->hiddenAccounts()->orderBy('id')->firstOrFail();
    }

    /** Slot money on one account for one payment, removed rows included at 0. */
    private function slotFor(Payment $payment, Consultant $account): float
    {
        return (float) Commission::where('payment_id', $payment->id)
            ->where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->sum('amount');
    }

    /** The report: the buyer buys a course placed on their downline's account. */
    private function buyUnderOwnDownline(): Payment
    {
        return $this->verify($this->buyer->user, $this->firstAccountOf($this->downline), 2);
    }

    // ----- the report ------------------------------------------------------------

    /** The downline's accounts earn, exactly as before. */
    public function test_the_account_it_was_placed_on_still_earns(): void
    {
        $purchase = $this->buyUnderOwnDownline();

        $this->assertSame((float) self::SLOT, $this->slotFor($purchase, $this->firstAccountOf($this->downline)));
        $this->assertSame((float) self::SLOT, $this->slotFor($purchase, $this->downline), 'and the downline main above it');
    }

    /** ⚠️ THE REPORT — the buyer's own Service Group account and main now earn. */
    public function test_the_buyers_own_accounts_above_the_placement_earn(): void
    {
        $purchase = $this->buyUnderOwnDownline();

        $this->assertSame(
            (float) self::SLOT,
            $this->slotFor($purchase, $this->firstAccountOf($this->buyer)),
            'SCN-00230 — the buyer\'s account the downline sits in',
        );
        $this->assertSame(
            (float) self::SLOT,
            $this->slotFor($purchase, $this->buyer),
            'SCN-00229 — the buyer\'s main',
        );
    }

    /** ⚠️ THE REPORT — and the climb carries on ABOVE the buyer. */
    public function test_the_climb_continues_above_the_buyer(): void
    {
        $purchase = $this->buyUnderOwnDownline();

        $this->assertSame(
            (float) self::SLOT,
            $this->slotFor($purchase, $this->firstAccountOf($this->top)),
            'the account the buyer was sponsored through',
        );
        $this->assertSame((float) self::SLOT, $this->slotFor($purchase, $this->top), 'and its main');
    }

    // ----- what must not change -----------------------------------------------------

    public function test_the_buyer_still_earns_no_direct_bonus(): void
    {
        $purchase = $this->buyUnderOwnDownline();

        $buyerAccountIds = $this->buyer->fresh()->accountIds();

        $this->assertSame(
            0.0,
            (float) Commission::where('payment_id', $purchase->id)
                ->where('type', 'referral')
                ->whereIn('consultant_id', $buyerAccountIds)
                ->sum('amount'),
            'nobody was brought in by the buyer',
        );
        $this->assertSame(
            4000.0,
            (float) Commission::where('payment_id', $purchase->id)->where('type', 'referral')->sum('amount'),
            'the account the course was registered on keeps its 4,000, as before',
        );
    }

    public function test_verifying_twice_pays_every_account_once(): void
    {
        $purchase = $this->buyUnderOwnDownline();
        $rows = Commission::where('payment_id', $purchase->id)->count();

        app(PaymentService::class)->verify($purchase->fresh(), User::where('email', 'accountant@etec.lk')->firstOrFail());
        app(\App\Services\CommissionService::class)->handleVerifiedPayment($purchase->fresh());

        $this->assertSame($rows, Commission::where('payment_id', $purchase->id)->count());
        $this->assertSame((float) self::SLOT, $this->slotFor($purchase, $this->buyer));
    }

    /** Buying on your OWN account must still never climb back into yourself. */
    public function test_buying_on_your_own_account_does_not_pay_you_twice(): void
    {
        $purchase = $this->verify($this->buyer->user, $this->firstAccountOf($this->buyer), 1);

        $this->assertSame((float) self::SLOT, $this->slotFor($purchase, $this->firstAccountOf($this->buyer)));
        $this->assertSame((float) self::SLOT, $this->slotFor($purchase, $this->buyer), 'one climb into the main, not two');
        $this->assertSame((float) self::SLOT, $this->slotFor($purchase, $this->firstAccountOf($this->top)), 'and the sponsor above');
    }

    // ----- history: the owner added it by hand (2026-09-17) -----------------------------

    /** Make every payment verified from now on count as history, i.e. before the new rules. */
    private function rulesStartTomorrow(): void
    {
        Setting::set(ClimbRules::KEY, now()->addDay()->toDateTimeString(), 'string', 'internal');
    }

    /** ⚠️ A purchase verified before the rules climbs exactly as it always did. */
    public function test_a_purchase_verified_before_the_new_rules_keeps_its_old_climb(): void
    {
        $this->rulesStartTomorrow();

        $purchase = $this->buyUnderOwnDownline();

        $this->assertSame((float) self::SLOT, $this->slotFor($purchase, $this->firstAccountOf($this->downline)), 'the downline still earns');
        $this->assertSame(0.0, $this->slotFor($purchase, $this->firstAccountOf($this->buyer)), 'stops at the buyer, as before');
        $this->assertSame(0.0, $this->slotFor($purchase, $this->buyer));
        $this->assertSame(0.0, $this->slotFor($purchase, $this->top));
    }

    /**
     * ⚠️ THE REASON FOR THE GATE. The owner added the missing history by hand
     * (no payment_id), so Run Repair must not mint it a second time.
     */
    public function test_run_repair_adds_nothing_to_a_purchase_from_before_the_new_rules(): void
    {
        $this->rulesStartTomorrow();

        $purchase = $this->buyUnderOwnDownline();
        $rows = Commission::count();

        \Illuminate\Support\Facades\Artisan::call('commissions:backfill');

        $this->assertSame($rows, Commission::count(), 'Repair minted nothing');
        $this->assertSame(0.0, $this->slotFor($purchase, $this->buyer));
    }

    public function test_the_start_of_the_new_rules_is_recorded_once_and_never_moves(): void
    {
        $first = ClimbRules::since();

        $this->travel(3)->days();

        $this->assertTrue($first->equalTo(ClimbRules::since()), 'the recorded moment stays put');
        $this->assertTrue(ClimbRules::appliesTo($this->buyUnderOwnDownline()), 'and a payment verified after it follows the new rules');
    }

}
