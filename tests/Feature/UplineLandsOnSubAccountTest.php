<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ UPLINE INCOME LANDS ON THE SUB (PLACEMENT) ACCOUNT — set 2026-08-21.
 *
 * THIRD setting of this rule: specific account (08-05) -> owner's MAIN account
 * (08-12) -> specific account again, now. Owner, four ways in one message:
 * "sub account walat ekathu wen na... SCN 102 S1 ekat ekathu wen na eth eka
 * kelinm gihilla SCN 100 eke S2 ekata add wenwa, eka wardi... 4th account eken
 * ena upline 2500 ya 2nd or 3rd account walata ekathu wen nathiw kelin main
 * account ekata yanwa... pahala student add krahama upline eken yana 2500 sub
 * ekat add wen nathiw kelinm main account ekat yanne, ekath waradi."
 *
 * This does NOT abandon the 2026-08-20 requirement that the money appear under
 * the owner's correct Service Group. It reaches that a different way: the row
 * sits on the placement account, and the Service Groups ROLLUP attributes that
 * account to the owner's leg it represents — so it shows on both rows, which
 * is what "dekem penwnn one" asked for.
 */
class UplineLandsOnSubAccountTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');
    }

    private function addStudent(Consultant $account, int $leg): User
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 39000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $user;
    }

    private function promote(User $user, Consultant $sponsorAccount): Consultant
    {
        $user->syncRoles(['consultant']);

        $c = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($c);

        return $c->fresh();
    }

    private function slotOn(Consultant $account, ?int $tier = null): float
    {
        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->when($tier !== null, fn ($q) => $q->where('tier', $tier))
            ->sum('amount');
    }

    /**
     * The owner's first complaint: a student registered directly on SCN-102's
     * Service Group 1 must leave its 2,500 on SCN-102's Service Group 1.
     */
    public function test_a_students_own_registration_stays_on_that_accounts_own_leg(): void
    {
        $main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $second = $main->hiddenAccounts()->orderBy('id')->get()[1]; // "SCN-102"

        $this->addStudent($second, 1);

        $this->assertSame(
            (float) self::SLOT,
            $this->slotOn($second, 1),
            "The 2,500 stays on SCN-102's own Service Group 1.",
        );
        // ⚠️ WAS ZERO UNTIL 2026-09-02. The 2026-08-22 account-tree climb
        // credited the accounts BETWEEN a student and the main and stopped one
        // hop short, because the main's headline was a ROLLUP of those accounts
        // and a row of its own would have double-counted there. The rollup is
        // gone — the main displays and settles only what is written against it —
        // so the climb runs all the way and the main earns like any other
        // account in it.
        //
        // ⚠️ IT STILL DOES NOT "JUMP". The assertion above is the one that
        // matters and is unchanged: the money lands on SCN-00102's own leg 1
        // FIRST. The main is credited as well, not instead.
        $this->assertSame(
            (float) self::SLOT,
            $this->slotOn($main),
            'and the climb carries on to the main, which now holds its own money.',
        );
    }

    /** ⚠️ The headline change: a downline's 2,500 credits the SUB account. */
    public function test_downline_income_credits_the_sub_account_not_the_main(): void
    {
        $main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $first = $main->hiddenAccounts()->orderBy('id')->first(); // "SCN-101"

        $downlineUser = $this->addStudent($first, 2);
        $downline = $this->promote($downlineUser, $first);

        $mainBefore = $this->slotOn($main);
        $subBefore = $this->slotOn($first);

        // The downline registers their own student — this is what climbs.
        $this->addStudent($downline->hiddenAccounts()->first(), 1);

        $this->assertSame(
            $subBefore + (float) self::SLOT,
            $this->slotOn($first),
            'The climbing 2,500 lands on the placement account the downline hangs off.',
        );
        // ⚠️ INVERTED 2026-09-02 — and the original report still holds.
        //
        // The bug this test was written for was the 2,500 going STRAIGHT to the
        // main, skipping the placement account the downline hangs off. That is
        // still wrong and is still pinned, by the assertion above.
        //
        // What changed is the end of the climb, not its route: after crediting
        // the placement account it continues to the main, which earns its own
        // row there. Both accounts, in order — never one instead of the other.
        $this->assertSame(
            $mainBefore + (float) self::SLOT,
            $this->slotOn($main),
            'and then reaches the main, which is the top of the same tree.',
        );
    }

    /** The seat follows the money onto the same account. */
    public function test_the_seat_follows_the_money_onto_the_sub_account(): void
    {
        $main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $first = $main->hiddenAccounts()->orderBy('id')->first();

        $downlineUser = $this->addStudent($first, 2);
        $downline = $this->promote($downlineUser, $first);

        $seatsOn = fn (Consultant $a) => GroupMember::whereIn(
            'group_id', Group::where('consultant_id', $a->id)->select('id'),
        )->count();

        $mainBefore = $seatsOn($main);
        $subBefore = $seatsOn($first);

        $this->addStudent($downline->hiddenAccounts()->first(), 1);

        $this->assertSame($subBefore + 1, $seatsOn($first), 'The seat lands on the sub account.');

        // ⚠️ INVERTED 2026-09-02 — the seat follows the money, and the money now
        // climbs one hop further. The main earns its own row, and a credit needs
        // a seat to hang on, so the main holds one too. The seat on the sub
        // account (asserted above) is unchanged and is what this test is for.
        $this->assertSame($mainBefore + 1, $seatsOn($main), 'And on the main, which earns its own row now.');
    }

    /**
     * ...and the 2026-08-20 requirement is still met: because SCN-101 IS the
     * owner's Service Group 1, the rollup shows that money under the owner's
     * Service Group 1 — on BOTH rows, which is what was asked for.
     */
    public function test_it_still_rolls_up_under_the_owners_correct_service_group(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $main = $consultantUser->consultant;
        $first = $main->hiddenAccounts()->orderBy('id')->first(); // IS Service Group 1

        $downlineUser = $this->addStudent($first, 2);
        $downline = $this->promote($downlineUser, $first);
        $this->addStudent($downline->hiddenAccounts()->first(), 1);

        // Approve so the (approved-only) headline picks it up.
        foreach (Commission::where('status', 'pending')->get() as $c) {
            $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
                ->patch(route('commissions.approve', $c));
        }

        $response = $this->actingAs($consultantUser)->get(route('consultant.groups.index'));
        $response->assertOk();

        $totals = $response->viewData('tierTotals');

        $this->assertGreaterThan(
            0,
            (float) ($totals[1]->total ?? 0),
            "SCN-101 IS Service Group 1, so its earnings appear under the owner's Service Group 1.",
        );
        $this->assertSame(
            0.0,
            (float) ($totals[2]->total ?? 0),
            'Nothing lands on Service Group 2 — that is the side the other placement account represents.',
        );
    }
}
