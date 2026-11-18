<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️⚠️ THE CROSS — an upline credit landing on the wrong leg (fixed 2026-09-01).
 *
 * Reported for the EIGHTH time: "upline 2500 th hariyta calculate wen na, eke
 * amount eka cross wela yanwa, hari amount eka calculate wen na."
 *
 * CAUSE. placeInto() reuses an existing seat when the student already sits in
 * the target account's group — and on that path it DISCARDED the leg the caller
 * asked for. awardSlot() then wrote `tier = $member->tier`, the seat's leg. So a
 * climb crediting leg 2 of an account where that person was seated on leg 1
 * wrote the 2,500 on LEG 1. Right amount, right account, wrong side.
 *
 * ⚠️ IT IS SELF-PERPETUATING, and that is why the SETTLEMENT rule was blamed for
 * eight rounds and flipped six times. Every upline credit piling onto one side
 * means the account is never balanced, so it never settles, so the money never
 * leaves — which on screen reads exactly like "main account eke amount eka
 * yanne na". The settlement rule was never the fault.
 *
 * ⚠️ MADE VISIBLE BY THE 2026-08-29 FIX. Before it, the per-seat guard
 * suppressed the row entirely (that was the missing-upline-2,500 bug). Once the
 * row started being written, it was written on the old seat's leg.
 *
 * WHY NOTHING CAUGHT IT: the one test named as if it pinned the leg asserted
 * only `tier in [1,2]`, which a cross passes; and CommissionRuleAudit has no leg
 * check at all.
 */
class UplineCreditTakesTheRequestedLegTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

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
    }

    private function sponsor(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function verify(User $buyer, Consultant $account, ?int $leg): Payment
    {
        $course = Course::where('status', 'published')->firstOrFail();

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'consultant_id' => $account->id,
            'requested_tier' => $leg, 'amount' => 39000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full',
            'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    private function student(Consultant $account, int $leg): User
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $this->verify($user, $account, $leg);

        return $user;
    }

    /**
     * A consultant seated on leg 1 of the sponsor's account — which is how a
     * downline always comes to exist — who then buys a course of their own.
     *
     * @return array{0: Consultant, 1: Consultant} the sponsor account, the downline's own account
     */
    private function downlineSeatedOnLegOne(): array
    {
        $sponsorAccount = $this->sponsor()->hiddenAccounts()->orderBy('id')->first();

        $user = $this->student($sponsorAccount, 1);
        $user->syncRoles(['consultant']);

        $downline = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount->id,
            'placement_tier' => 1,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($downline);

        return [$sponsorAccount, $downline->fresh()->hiddenAccounts()->orderBy('id')->first()];
    }

    // ------------------------------------------------------------------------

    /** The seat records where the student sits, and is never moved by a credit. */
    public function test_the_students_original_seat_is_never_moved(): void
    {
        [$sponsorAccount, $downlineAccount] = $this->downlineSeatedOnLegOne();
        $buyer = User::find($downlineAccount->owner->user_id);

        $seatBefore = GroupMember::where('user_id', $buyer->id)
            ->whereIn('group_id', \App\Models\Group::where('consultant_id', $sponsorAccount->id)->select('id'))
            ->value('tier');

        $this->assertSame(1, (int) $seatBefore, 'guard: seated on leg 1');

        $this->verify($buyer, $downlineAccount, 2);

        $this->assertSame(
            1,
            (int) GroupMember::where('user_id', $buyer->id)
                ->whereIn('group_id', \App\Models\Group::where('consultant_id', $sponsorAccount->id)->select('id'))
                ->value('tier'),
            'the seat still records where the student actually sits',
        );
    }

    /**
     * ⚠️ THE FIX. A credit that reuses that seat must still land on the leg the
     * climb asked for, not on the seat's leg.
     */
    public function test_a_reused_seat_does_not_drag_the_credit_onto_its_own_leg(): void
    {
        [$sponsorAccount, $downlineAccount] = $this->downlineSeatedOnLegOne();
        $buyer = User::find($downlineAccount->owner->user_id);

        Commission::query()->delete();

        // The downline buys a course placed on leg 2 of their own account. The
        // upline credit climbs to the sponsor account, where this person
        // already holds a leg-1 seat.
        $purchase = $this->verify($buyer, $downlineAccount, 2);

        $onSponsor = Commission::where('payment_id', $purchase->id)
            ->where('consultant_id', $sponsorAccount->id)
            ->where('type', 'bonus')
            ->first();

        $this->assertNotNull($onSponsor, 'the sponsor is credited at all');
        $this->assertSame(
            (float) self::SLOT,
            (float) $onSponsor->amount,
            'the right amount — that half was fixed on 2026-08-29',
        );
        $this->assertSame(
            1,
            (int) $onSponsor->tier,
            'and it takes the leg the downline occupies inside this account, not the buyer\'s chosen leg',
        );
    }

    /**
     * ⚠️ A row whose leg differs from its seat's must still be VISIBLE to the
     * run on that leg.
     *
     * legScopeQuery's own-rows branch used to filter on the SEAT's tier as well
     * as the row's. Once the two can differ, that pair of conditions
     * contradicts: a row carrying leg 2 with a leg-1 seat would be returned by
     * NEITHER leg — the money would vanish from both sides of the account,
     * visible nowhere and settleable never.
     */
    public function test_a_credit_is_visible_on_its_own_leg_even_when_the_seat_differs(): void
    {
        $account = $this->sponsor()->hiddenAccounts()->orderBy('id')->first();
        $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();

        $this->student($account, 1);

        // Force the divergence the fix now permits: the money on leg 2, the
        // seat left on leg 1.
        Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->update(['tier' => 2]);

        $flush = app(ServiceGroupFlush::class);

        $this->assertSame(
            (float) self::SLOT,
            $flush->legBalance($account->fresh(), $group, 2),
            'the run sees it on the leg the ROW carries',
        );
        $this->assertSame(
            0.0,
            $flush->legBalance($account->fresh(), $group, 1),
            'and not on the leg its seat carries',
        );
    }

    /**
     * ⚠️ THE CONSEQUENCE THE OWNER ACTUALLY REPORTED. With credits landing on
     * the correct opposite legs, an account can balance and settle. That is
     * what "amount eka yanne na" was really about — not the settlement rule.
     */
    public function test_credits_on_opposite_legs_let_an_account_balance_and_settle(): void
    {
        $account = $this->sponsor()->hiddenAccounts()->orderBy('id')->first();
        $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();

        $this->student($account, 1);
        $this->student($account, 2);

        $flush = app(ServiceGroupFlush::class);

        $this->assertSame((float) self::SLOT, $flush->legBalance($account->fresh(), $group, 1));
        $this->assertSame((float) self::SLOT, $flush->legBalance($account->fresh(), $group, 2));

        $result = $flush->runAll(now()->addDay());

        $this->assertSame(
            2.0 * self::SLOT,
            (float) $result['payout'],
            'both sides hold 2,500, so the 1-to-1 settles and the money finally moves',
        );
    }
}
