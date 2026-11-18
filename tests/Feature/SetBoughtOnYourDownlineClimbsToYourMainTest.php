<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AccountPlacementMap;
use App\Services\ClimbRules;
use App\Services\CommissionService;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A SET BOUGHT ON YOUR OWN DOWNLINE'S ACCOUNT STILL BELONGS TO YOUR TREE
 * (fixed 2026-09-16).
 *
 * Reported on SCN-00229 the day after the buyer-climb fix: students added under
 * SCN-00442 (the set opened by PYM-00079) earned SCN-00443 / SCN-00442 and the
 * upline above SCN-00229 — but never SCN-00229 itself: "4th account eken add
 * krapu student denekge 2500k add wela na ... 5000k add wela na main ekat".
 *
 * ROOT CAUSE: AccountPlacementMap labelled the set `bought-foreign`, because
 * PYM-00079 was attributed to SCN-00432 — an account the buyer does not hold.
 * A foreign set has no parent in the owner's tree, so creditOwnAccountAncestors()
 * stopped at the set-main, and the sponsor walk that follows starts ABOVE the
 * main. The main was the one account nothing could reach.
 *
 * Now: when that foreign account sits in the buyer's OWN DOWNLINE, the set hangs
 * under the buyer's account that downline is inside (SCN-00432 → chandima →
 * SCN-00230, on chandima's side of it) — exactly where the owner believed it was
 * bought ("4th eka buy krla thiyenne 2nd account eken"). On the live dump all 7
 * foreign sets are of this kind; a set bought on an UPLINE's or a stranger's
 * account is still foreign and still climbs nowhere in the buyer's tree.
 *
 * ⚠️ AND REMOVED MONEY STAYS REMOVED. Re-placing the set gives old payments a
 * route to accounts that hold no row for them yet, so a replay (Run Repair)
 * could mint income on a payment an admin removed entirely (live: PYM-00381,
 * all 12 rows removed on 8 Sep). The own-tree climb now stops above any account
 * whose row for that payment was removed by hand.
 *
 * ⚠️ AND HISTORY IS NOT REPLAYED AT ALL (2026-09-17). The owner added the missing
 * income by hand, so a student verified before this build first ran keeps the old
 * climb for money — see ClimbRules. Screens show the set in its new place either way.
 */
class SetBoughtOnYourDownlineClimbsToYourMainTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    /** The account above the buyer ("SCN-00131"). */
    private Consultant $top;

    /** The buyer ("SCN-00229"). */
    private Consultant $buyer;

    /** The buyer's downline ("chandima", inside SCN-00230). */
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
        $this->buyer = $this->downlineUnder($this->nthAccount($this->top, 1));
        $this->downline = $this->downlineUnder($this->nthAccount($this->buyer, 1));
    }

    private function nextCourse(): Course
    {
        $template = Course::where('status', 'published')->firstOrFail();
        $n = Course::count() + 1;

        return Course::create([
            'title' => 'Set Course '.$n,
            'slug' => 'set-course-'.$n.'-'.Str::random(6),
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

    private function student(): User
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function downlineUnder(Consultant $sponsorAccount): Consultant
    {
        $user = $this->student();
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

    /** The person's n-th hidden account, in creation order (1 = SCN-00230). */
    private function nthAccount(Consultant $person, int $n): Consultant
    {
        return $person->fresh()->hiddenAccounts()->orderBy('id')->get()[$n - 1];
    }

    private function slotFor(Payment $payment, Consultant $account): float
    {
        return (float) Commission::where('payment_id', $payment->id)
            ->where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->sum('amount');
    }

    /** The report: the buyer buys a set on the downline's account → SCN-00442 (#3). */
    private function buySetOnDownline(): Consultant
    {
        $this->verify($this->buyer->user, $this->nthAccount($this->downline, 1), 2);

        return $this->nthAccount($this->buyer, 3);
    }

    // ----- where the set hangs ------------------------------------------------------

    public function test_the_set_hangs_under_the_buyers_account_the_downline_sits_in(): void
    {
        $setMain = $this->buySetOnDownline();

        $entry = app(AccountPlacementMap::class)->for($this->buyer)[$setMain->id];

        $this->assertSame('bought', $entry['kind'], 'no longer a foreign set');
        $this->assertSame($this->nthAccount($this->buyer, 1)->id, $entry['under_id'], 'under SCN-00230');
        $this->assertSame(1, $entry['tier'], 'on the side the downline occupies inside it');
        $this->assertSame(1, $entry['owner_group'], 'so it belongs to the main\'s Service Group 1');
    }

    public function test_a_set_bought_on_an_uplines_account_is_still_foreign(): void
    {
        $this->verify($this->buyer->user, $this->nthAccount($this->top, 2), 1);
        $setMain = $this->nthAccount($this->buyer, 3);

        $entry = app(AccountPlacementMap::class)->for($this->buyer)[$setMain->id];

        $this->assertSame('bought-foreign', $entry['kind'], 'the upline is not inside the buyer\'s tree');
        $this->assertNull($entry['owner_group']);
    }

    /**
     * A stranger with a real sponsor chain of their own — a sibling under the
     * same top account — whose chain climbs PAST the buyer's tree without ever
     * entering it. Stays foreign.
     */
    public function test_a_set_bought_on_a_siblings_account_is_still_foreign(): void
    {
        $sibling = $this->downlineUnder($this->nthAccount($this->top, 1));

        $this->verify($this->buyer->user, $this->nthAccount($sibling, 1), 1);
        $setMain = $this->nthAccount($this->buyer, 3);

        $entry = app(AccountPlacementMap::class)->for($this->buyer)[$setMain->id];

        $this->assertSame('bought-foreign', $entry['kind'], 'the sibling is not inside the buyer\'s tree');
        $this->assertNull($entry['owner_group']);
    }

    public function test_a_set_bought_on_your_own_account_is_unchanged(): void
    {
        $this->verify($this->buyer->user, $this->nthAccount($this->buyer, 2), 2);
        $setMain = $this->nthAccount($this->buyer, 3);

        $entry = app(AccountPlacementMap::class)->for($this->buyer)[$setMain->id];

        $this->assertSame('bought', $entry['kind']);
        $this->assertSame($this->nthAccount($this->buyer, 2)->id, $entry['under_id']);
        $this->assertSame(2, $entry['tier']);
    }

    // ----- the report: a student in that set -------------------------------------------

    /** ⚠️ THE REPORT — the student's 2,500 reaches the buyer's main. */
    public function test_a_student_in_the_set_credits_the_buyers_main(): void
    {
        $setMain = $this->buySetOnDownline();
        $payment = $this->verify($this->student(), $this->nthAccount($this->buyer, 4), 1);

        $this->assertSame((float) self::SLOT, $this->slotFor($payment, $this->nthAccount($this->buyer, 4)), 'the leg it was placed on');
        $this->assertSame((float) self::SLOT, $this->slotFor($payment, $setMain), 'SCN-00442');
        $this->assertSame((float) self::SLOT, $this->slotFor($payment, $this->nthAccount($this->buyer, 1)), 'SCN-00230');
        $this->assertSame((float) self::SLOT, $this->slotFor($payment, $this->buyer), 'SCN-00229 — the main, the reported gap');
    }

    public function test_the_upline_above_the_buyer_is_still_credited_once(): void
    {
        $this->buySetOnDownline();
        $payment = $this->verify($this->student(), $this->nthAccount($this->buyer, 4), 1);

        $this->assertSame((float) self::SLOT, $this->slotFor($payment, $this->nthAccount($this->top, 1)));
        $this->assertSame((float) self::SLOT, $this->slotFor($payment, $this->top));
    }

    public function test_the_downline_it_was_bought_through_earns_nothing_from_the_sets_students(): void
    {
        $this->buySetOnDownline();
        $payment = $this->verify($this->student(), $this->nthAccount($this->buyer, 4), 1);

        $this->assertSame(
            0.0,
            (float) Commission::where('payment_id', $payment->id)
                ->whereIn('consultant_id', $this->downline->fresh()->accountIds())
                ->sum('amount'),
            'the set is the buyer\'s — chandima does not earn from students placed in it',
        );
    }

    public function test_verifying_twice_pays_once(): void
    {
        $this->buySetOnDownline();
        $payment = $this->verify($this->student(), $this->nthAccount($this->buyer, 4), 1);
        $rows = Commission::where('payment_id', $payment->id)->count();

        app(CommissionService::class)->handleVerifiedPayment($payment->fresh());

        $this->assertSame($rows, Commission::where('payment_id', $payment->id)->count());
    }


    // ----- history: the owner added it by hand (2026-09-17) -----------------------------

    private function removeByHand(Payment $payment, ?Consultant $only = null): void
    {
        Commission::where('payment_id', $payment->id)
            ->when($only, fn ($q) => $q->where('consultant_id', $only->id))
            ->each(fn (Commission $c) => $c->update([
                'status' => 'cancelled', 'removed_amount' => $c->amount, 'amount' => 0, 'removed_at' => now(),
            ]));
    }

    /**
     * ⚠️ A student verified BEFORE the new rules keeps the old climb — even when
     * Run Repair replays it. The owner added that 2,500 by hand already.
     */
    public function test_a_student_from_before_the_new_rules_is_not_re_paid_by_repair(): void
    {
        Setting::set(ClimbRules::KEY, now()->addDay()->toDateTimeString(), 'string', 'internal');

        $this->buySetOnDownline();
        $payment = $this->verify($this->student(), $this->nthAccount($this->buyer, 4), 1);

        $this->assertSame(0.0, $this->slotFor($payment, $this->buyer), 'the old climb never reached the main');

        \Illuminate\Support\Facades\Artisan::call('commissions:backfill');

        $this->assertSame(0.0, $this->slotFor($payment, $this->nthAccount($this->buyer, 1)), 'Repair adds nothing to SCN-00230');
        $this->assertSame(0.0, $this->slotFor($payment, $this->buyer), 'or to the main');
    }

    public function test_the_screens_still_show_where_an_old_set_hangs(): void
    {
        Setting::set(ClimbRules::KEY, now()->addDay()->toDateTimeString(), 'string', 'internal');

        $setMain = $this->buySetOnDownline();

        $this->assertSame('bought', app(AccountPlacementMap::class)->for($this->buyer)[$setMain->id]['kind'],
            'only the money follows the old rule — the page shows the set in its place');
    }

    /** Removed on the set-main only: nothing climbs past the removal on a replay. */
    public function test_a_payment_removed_part_way_up_the_set_is_not_re_paid(): void
    {
        $this->buySetOnDownline();
        $payment = $this->verify($this->student(), $this->nthAccount($this->buyer, 4), 1);

        Commission::where('payment_id', $payment->id)
            ->whereIn('consultant_id', [$this->nthAccount($this->buyer, 1)->id, $this->buyer->id])
            ->delete();
        $this->removeByHand($payment, $this->nthAccount($this->buyer, 3));

        app(CommissionService::class)->handleVerifiedPayment($payment->fresh());

        $this->assertSame(0, Commission::where('payment_id', $payment->id)
            ->whereIn('consultant_id', [$this->nthAccount($this->buyer, 1)->id, $this->buyer->id])
            ->count(), 'nothing climbs past the removed row');
    }

    /** ⚠️ PYM-00381 on live: every row removed by hand. Nothing may come back. */
    public function test_a_payment_removed_by_hand_is_not_re_paid(): void
    {
        $this->buySetOnDownline();
        $payment = $this->verify($this->student(), $this->nthAccount($this->buyer, 4), 1);

        Commission::where('payment_id', $payment->id)
            ->whereIn('consultant_id', [$this->nthAccount($this->buyer, 1)->id, $this->buyer->id])
            ->delete();
        $this->removeByHand($payment);

        app(CommissionService::class)->handleVerifiedPayment($payment->fresh());

        $this->assertSame(0, Commission::where('payment_id', $payment->id)
            ->whereIn('consultant_id', [$this->nthAccount($this->buyer, 1)->id, $this->buyer->id])
            ->count(), 'no new row on SCN-00230 or the main');
        $this->assertSame(0.0, (float) Commission::where('payment_id', $payment->id)->sum('amount'));
    }
}