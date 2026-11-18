<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\HiddenAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks in the money rules:
 *  - slot = 2500 (consultant AND every upline), direct add bonus = 3000 (only
 *    the consultant who personally registered the student)
 *  - BOTH are recorded the moment a student is verified, per student — there
 *    is no pairing/waiting requirement (removed 2026-08-02 at the owner's
 *    explicit request; see commission-pair-matching.md for why)
 *  - only the first 3 students per leg earn AT ALL. An account pays out on
 *    6 students (3 + 3) and no more: a student past that cap is still placed
 *    and listed but earns neither slot income nor a direct bonus. (The bonus
 *    used to be uncapped; capped 2026-08-03 at the owner's explicit request.)
 */
class CommissionMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function makeConsultant(?Consultant $parent = null): Consultant
    {
        $user = User::factory()->create();

        return Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $parent?->id,
            'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    /** Register + verify one student on the given leg of the given account. */
    private function placeStudent(Consultant $account, int $tier): User
    {
        $user = User::factory()->create();

        Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN'.Str::random(6),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
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

        app(CommissionService::class)->handleVerifiedPayment($payment);

        return $user;
    }

    private function promote(User $user, Consultant $sponsor): Consultant
    {
        return Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsor->id,
            'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    /** Everything a consultant earned across their own account only (regardless of approval status). */
    private function earnedBy(Consultant $c): float
    {
        return (float) Commission::where('consultant_id', $c->id)->sum('amount');
    }

    /** Service-group slot income only. */
    private function slotEarnedBy(Consultant $c): float
    {
        return (float) Commission::where('consultant_id', $c->id)->where('type', 'bonus')->sum('amount');
    }

    /** Direct-add bonus only — paid at verification, never waits for anything, never climbs. */
    private function directEarnedBy(Consultant $c): float
    {
        return (float) Commission::where('consultant_id', $c->id)->where('type', 'referral')->sum('amount');
    }

    public function test_a_new_consultant_gets_three_accounts_each_with_a_service_group(): void
    {
        $c = $this->makeConsultant();

        $accountIds = $c->accountIds();
        $this->assertCount(3, $accountIds, 'A consultant should own 3 accounts (own + 2 hidden).');

        // Every account needs its service group up front, before any student.
        foreach ($accountIds as $id) {
            $this->assertDatabaseHas('groups', ['consultant_id' => $id]);
        }
    }

    public function test_each_registration_earns_its_slot_and_bonus_immediately(): void
    {
        $c = $this->makeConsultant();

        // A single student, alone on one leg, earns straight away — no waiting
        // for the opposite leg to have a match.
        $this->placeStudent($c, 1);
        $this->assertSame(2500.0, $this->slotEarnedBy($c), 'The slot pays immediately.');
        $this->assertSame(3000.0, $this->directEarnedBy($c));
        $this->assertSame(5500.0, $this->earnedBy($c));

        // A second student, same or opposite leg, just adds its own slot + bonus.
        $this->placeStudent($c, 2);
        $this->assertSame(11000.0, $this->earnedBy($c));

        $this->placeStudent($c, 1);
        $this->placeStudent($c, 2);
        $this->assertSame(22000.0, $this->earnedBy($c));

        // 3rd on each leg completes the group's earning cap (3+3).
        $this->placeStudent($c, 1);
        $this->placeStudent($c, 2);
        $this->assertSame(33000.0, $this->earnedBy($c), 'A full group (3+3 students) pays 33,000 total.');
    }

    public function test_students_past_the_leg_capacity_earn_nothing_at_all(): void
    {
        $c = $this->makeConsultant();

        for ($i = 0; $i < 3; $i++) {
            $this->placeStudent($c, 1);
            $this->placeStudent($c, 2);
        }

        $this->assertSame(33000.0, $this->earnedBy($c));
        $this->assertSame(15000.0, $this->slotEarnedBy($c), '6 slots x 2500.');
        $this->assertSame(18000.0, $this->directEarnedBy($c), '6 bonuses x 3000.');

        // One more student on each leg. The account has already paid out on its
        // 3 + 3 earning students, so these earn NOTHING — not the slot, and
        // (since 2026-08-03) not the direct bonus either.
        $this->placeStudent($c, 1);
        $this->placeStudent($c, 2);

        $this->assertSame(15000.0, $this->slotEarnedBy($c), 'Students past the cap fill no earning slot.');
        $this->assertSame(18000.0, $this->directEarnedBy($c), 'The bonus stops at the cap too.');
        $this->assertSame(33000.0, $this->earnedBy($c), 'A group can never pay more than 33,000.');

        $group = \App\Models\Group::where('consultant_id', $c->id)->first();
        $this->assertSame('open', $group->status, 'The group must stay open for further students.');
        $this->assertSame(4, (int) $group->tier1_filled, 'Extra students are still counted on the leg.');
    }

    public function test_extra_accounts_pay_the_same_way_as_the_original_ones(): void
    {
        $c = $this->makeConsultant();
        $extra = app(HiddenAccountService::class)->addExtraAccount($c);

        // No special treatment for an account's "first group" anymore — every
        // account pays the same way: immediately, per student, up to the cap.
        $this->placeStudent($extra, 1);
        $this->assertSame(5500.0, $this->earnedBy($extra), 'An admin-added extra account earns immediately too.');

        $this->placeStudent($extra, 2);
        $this->placeStudent($extra, 1);
        $this->placeStudent($extra, 2);
        $this->placeStudent($extra, 1);
        $this->placeStudent($extra, 2);
        $this->assertSame(33000.0, $this->earnedBy($extra), 'A full 3+3 group pays 33,000, same as any account.');

        // Beyond the cap: nothing at all, on an extra account exactly as on a
        // main one.
        $this->placeStudent($extra, 1);
        $this->assertSame(33000.0, $this->earnedBy($extra), 'An extra account caps at 33,000 too.');
    }

    public function test_uplines_earn_slot_income_immediately_but_never_the_direct_bonus(): void
    {
        $a = $this->makeConsultant();
        $d = $this->promote($this->placeStudent($a, 1), $a); // D sits on A's leg 1

        $this->assertSame(5500.0, $this->earnedBy($a), 'A already earned their own slot + bonus immediately.');

        // D registers a student of their own — this climbs to fill ANOTHER of
        // A's leg-1 slots (network position, not the student's own tier tag),
        // and pays A immediately: no opposite-leg wait under the current rule.
        $this->placeStudent($d, 1);

        $this->assertSame(5500.0, $this->earnedBy($d), 'D earns their own slot + bonus for their own registration.');
        $this->assertSame(8000.0, $this->earnedBy($a), "A gets +2500 slot only (A's leg 1, 2nd of 3 slots) — no bonus, A did not register this student.");
        $this->assertSame(3000.0, $this->directEarnedBy($a), "A's direct bonus is unchanged — only ever from A's own registration.");
    }

    public function test_a_students_registered_by_a_grandchild_reach_every_ancestor_leg(): void
    {
        $a = $this->makeConsultant();
        $d = $this->promote($this->placeStudent($a, 1), $a);
        $this->promote($this->placeStudent($a, 2), $a);

        // D's own student becomes consultant H, sitting on D's leg 1.
        $h = $this->promote($this->placeStudent($d, 1), $d);

        // H registers a student of their own.
        $this->placeStudent($h, 1);

        // That one student physically reached H's, D's and A's groups — H sits
        // on D's leg 1 and D sits on A's leg 1, so it lands on leg 1 all the way up.
        $this->assertSame(1, (int) \App\Models\Group::where('consultant_id', $h->id)->first()->tier1_filled);
        $this->assertSame(2, (int) \App\Models\Group::where('consultant_id', $d->id)->first()->tier1_filled);
        $this->assertSame(3, (int) \App\Models\Group::where('consultant_id', $a->id)->first()->tier1_filled);

        // Every one of those slots was within its account's cap, so D and A
        // were already credited the moment each slot filled — nothing waits.
        $dBefore = $this->earnedBy($d);
        $this->assertSame(
            8000.0,
            $dBefore,
            "D was already credited: 5,500 for D's own registration (that became H) + 2,500 more once H's own registration climbed to fill D's 2nd leg-1 slot.",
        );

        // A further, unrelated registration on D's OTHER leg just adds its own
        // slot + bonus — it does not "unlock" anything that was withheld.
        $this->placeStudent($d, 2);
        $this->assertSame($dBefore + 5500.0, $this->earnedBy($d), "D's own new registration adds its own slot + bonus, nothing more.");
    }
}
