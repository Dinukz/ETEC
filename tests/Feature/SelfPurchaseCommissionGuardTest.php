<?php

namespace Tests\Feature;

use App\Models\Commission;
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
 * A consultant must never earn REGISTERING income from their own purchase.
 *
 * ⚠️ This closed a hole that was LIVE in production, reachable with no special
 * access (found 2026-08-19). /register captures any ?ref= into the session
 * (Auth\RegisterController), the session survives login, and
 * CheckoutController::referralConsultant() resolved it without ever checking
 * it was not the buyer's own account. So a consultant could open their OWN
 * referral link, buy any course, and take 2,500 slot + 4,000 direct bonus from
 * the company — plus placeInto() rewrote their own students.consultant_id to
 * themselves. Nothing in the engine compared payment.user_id to the
 * consultant's owning user.
 *
 * The UPLINE is still credited: that is the owner's rule for a consultant's own
 * purchase ("consultant kenk widiyta cource buy krata... meyage upline eke
 * kawru hari inwanm eyalatath 2500 gane ynwa").
 */
class SelfPurchaseCommissionGuardTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    /** Verify a payment attributed to $account for the course bought by $buyer. */
    private function verifyPurchase(User $buyer, Consultant $account, int $tier = 1): Payment
    {
        Student::firstOrCreate(
            ['user_id' => $buyer->id],
            ['student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()],
        );

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $buyer->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $account->id,
            'requested_tier' => $tier,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, $this->accountant());

        return $payment->fresh();
    }

    private function earnedBy(Consultant $account, string $type): float
    {
        return (float) Commission::where('consultant_id', $account->id)->where('type', $type)->sum('amount');
    }

    /**
     * ⚠️ REVISED 2026-08-22 — the SLOT is due, the DIRECT BONUS is not.
     *
     * From 2026-08-19 a self-purchase earned nothing at all. The owner has
     * since been explicit that the 2,500 must land: "eke course ekak buy
     * krahama thamanma consultant 2500 add wenn one, eka add wela na kohe
     * wath" — buying the 4th account credited nowhere.
     *
     * The split is not a compromise, it follows what each payment is FOR: the
     * 2,500 is for filling a seat on a service group, and the buyer really does
     * fill one (it still has to pair before releasing). The 4,000 is for
     * bringing someone in, and nobody was brought in — that was the part of the
     * 2026-08-19 hole that was genuinely free money, and it stays closed.
     */
    public function test_a_consultant_buying_their_own_course_earns_the_slot_but_not_the_bonus(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;

        $this->verifyPurchase($consultantUser, $consultant);

        $this->assertSame((float) self::SLOT, $this->earnedBy($consultant, 'bonus'), 'The seat is filled, so the 2,500 is earned.');
        $this->assertSame(0.0, $this->earnedBy($consultant, 'referral'), 'No direct-add bonus for being your own registrant.');
    }

    /** Same, routed through one of their own hidden placement accounts. */
    public function test_a_purchase_routed_through_an_own_hidden_account_credits_that_account(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $this->verifyPurchase($consultantUser, $hidden);

        $this->assertSame((float) self::SLOT, $this->earnedBy($hidden, 'bonus'), 'It lands on the account the purchase was placed under.');
        $this->assertSame(
            0.0,
            $this->earnedBy($hidden, 'referral'),
            'A hidden account has no user_id of its own — the bonus check must still resolve through owningPerson().',
        );
    }

    /** ...and it must not have broken the normal case. */
    public function test_registering_a_genuine_student_still_pays_normally(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $account = $consultantUser->consultant->hiddenAccounts()->first();

        $student = User::factory()->create();
        $this->verifyPurchase($student, $account);

        $this->assertSame((float) self::SLOT, $this->earnedBy($account, 'bonus'), 'A real registration still earns its 2,500.');
        $this->assertSame((float) self::DIRECT, $this->earnedBy($account, 'referral'), 'And its 4,000.');
    }

    /**
     * A self-purchase still credits the UPLINE — that is the owner's rule, and
     * it is what separates this guard from simply refusing the payment.
     */
    public function test_a_self_purchase_still_credits_the_upline(): void
    {
        $sponsorUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $sponsor = $sponsorUser->consultant;
        $sponsorPlacement = $sponsor->hiddenAccounts()->orderBy('id')->first();

        // A downline consultant sponsored through the sponsor's 1st placement.
        $downlineUser = User::factory()->create();
        $downlineUser->assignRole('consultant');
        $downline = Consultant::create([
            'user_id' => $downlineUser->id,
            'parent_consultant_id' => $sponsorPlacement->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        // ⚠️ Measured on the PLACEMENT ACCOUNT, not the sponsor's main.
        //
        // This asserted the main and had been red since the 2026-08-21
        // reversal put climbing income back on the specific account the
        // downline was sponsored through. The main shows it as a rollup; the
        // commission row itself belongs to SCN-101.
        $sponsorBefore = $this->earnedBy($sponsorPlacement, 'bonus');

        // The downline buys their own course.
        $this->verifyPurchase($downlineUser, $downline->fresh());

        $this->assertSame(
            0.0,
            $this->earnedBy($downline, 'referral'),
            'The buyer earns no direct-add bonus for being their own registrant.',
        );
        $this->assertSame(
            $sponsorBefore + (float) self::SLOT,
            $this->earnedBy($sponsorPlacement, 'bonus'),
            'But their upline still receives the 2,500 — the owner\'s stated rule for a consultant\'s own purchase.',
        );
    }

    /**
     * ⚠️ A self-PARENTED consultant must not pay themselves via the upline walk.
     *
     * creditUpline()'s $seen guard is evaluated at the top of the loop against
     * $node, while the money goes to $recipient — which only becomes $node on
     * the NEXT iteration. So the first hop paid out before the cycle was
     * detected, and it was not a seat-reuse no-op either.
     *
     * The 2026-08-22 account-tree climb does not change this: it stops at the
     * main, which rolls up rather than earning.
     */
    public function test_a_self_parented_consultant_is_not_paid_by_the_upline_walk(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        // Point the consultant's sponsor at their own hidden account, which
        // creditUpline() resolves back to them.
        $consultant->update(['parent_consultant_id' => $hidden->id]);

        $before = $this->earnedBy($consultant, 'bonus');

        // A genuine student registered on the hidden account.
        $student = User::factory()->create();
        $this->verifyPurchase($student, $hidden);

        // ⚠️ EXACTLY ONE 2,500, AND WHICH ONE MATTERS (2026-09-02).
        //
        // This asserted NO movement at all, which was the right test while the
        // climb stopped below the main. It now runs all the way up, so a student
        // on a hidden account legitimately credits the main once — the ordinary
        // own-tree climb, nothing to do with sponsorship.
        //
        // The guard this test exists for is the SPONSOR walk: with the
        // consultant pointed at their own hidden account as their sponsor, that
        // walk must resolve back to them and add NOTHING. If it ever fired, this
        // would read 5,000 — the own-tree climb plus a minted duplicate — so the
        // exact figure is what pins the guard, not the absence of movement.
        $this->assertSame(
            $before + 2500.0,
            $this->earnedBy($consultant, 'bonus'),
            'One climb, not two: being your own sponsor mints nothing on top of it.',
        );
    }
}
