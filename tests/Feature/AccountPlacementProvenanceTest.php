<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\AccountPlacementMap;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every SCN account says where it hangs.
 *
 * Owner, 2026-08-22: "SCN account mokatad aithi wenne kiyala placement krla
 * thiyenne mokind kiyla... SCN 133 aithi wenne placement krla thiyenne SCN 123
 * ta, e wage 4th and 7th and issrahat buy krana cource aithi wenne mokatd
 * kiyla penwann one."
 *
 * The screen listed nine bare codes. This pins the relationships behind them,
 * including the one that actually needs looking up: a bought set-main sits
 * wherever the buyer picked at checkout.
 */
class AccountPlacementProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function consultant(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** Buy a course, placing the new set under $target's Service Group $tier. */
    private function buyPlacedUnder(Consultant $buyer, Consultant $target, int $tier): void
    {
        $course = Course::where('status', 'published')->firstOrFail();

        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $buyer->user_id,
            'course_id' => $course->id,
            'consultant_id' => $target->id,
            'requested_tier' => $tier,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($payment, User::role('admin')->first());
    }

    public function test_the_opening_pair_sit_under_the_persons_own_main(): void
    {
        $c = $this->consultant();
        $map = app(AccountPlacementMap::class)->for($c);

        $this->assertSame('main', $map[$c->id]['kind'], "The person's own account is the root.");

        $accounts = $c->hiddenAccounts()->orderBy('id')->get();

        foreach ([1, 2] as $leg) {
            $entry = $map[$accounts[$leg - 1]->id];

            $this->assertSame('opening', $entry['kind']);
            $this->assertSame($c->id, $entry['under_id']);
            $this->assertSame($c->scn_code, $entry['under_code']);
            $this->assertSame($leg, $entry['tier'], "The opening pair are the main's leg 1 and leg 2.");
        }
    }

    /**
     * ⚠️ THE CASE THE OWNER NAMED. A bought set-main reports the account the
     * buyer actually chose — not its owner, and not the previous account.
     */
    public function test_a_bought_set_main_names_the_account_it_was_placed_under(): void
    {
        $c = $this->consultant();
        $secondLeg = $c->hiddenAccounts()->orderBy('id')->get()[1]; // "SCN-00123"

        $this->buyPlacedUnder($c, $secondLeg, 2);

        $accounts = $c->fresh()->hiddenAccounts()->orderBy('id')->get();
        $this->assertCount(5, $accounts, 'Opening pair + a bought set of three.');

        $setMain = $accounts[2]; // "SCN-00133" — the 4th account overall
        $map = app(AccountPlacementMap::class)->for($c->fresh());

        $this->assertSame('bought', $map[$setMain->id]['kind']);
        $this->assertSame($secondLeg->id, $map[$setMain->id]['under_id'], 'It hangs off the account picked at checkout.');
        $this->assertSame($secondLeg->scn_code, $map[$setMain->id]['under_code']);
        $this->assertSame(2, $map[$setMain->id]['tier'], 'On the Service Group picked at checkout.');
    }

    /** The set's other two accounts sit inside their own set-main. */
    public function test_a_bought_sets_legs_sit_inside_their_set_main(): void
    {
        $c = $this->consultant();
        $this->buyPlacedUnder($c, $c->hiddenAccounts()->orderBy('id')->first(), 1);

        $accounts = $c->fresh()->hiddenAccounts()->orderBy('id')->get();
        $map = app(AccountPlacementMap::class)->for($c->fresh());

        [$setMain, $leg1, $leg2] = [$accounts[2], $accounts[3], $accounts[4]];

        foreach ([[$leg1, 1], [$leg2, 2]] as [$account, $leg]) {
            $this->assertSame('set-leg', $map[$account->id]['kind']);
            $this->assertSame($setMain->id, $map[$account->id]['under_id']);
            $this->assertSame($leg, $map[$account->id]['tier']);
        }

        // And the legs agree with the leg mapping the money engine uses.
        $this->assertSame(1, $leg1->serviceGroupLeg());
        $this->assertSame(2, $leg2->serviceGroupLeg());
        $this->assertNull($setMain->serviceGroupLeg());
    }

    /**
     * ⚠️ "issrahat buy krana" — a SECOND purchase must be placed where THAT
     * checkout chose, not inherit the first set's placement.
     */
    public function test_each_further_set_keeps_its_own_placement(): void
    {
        $c = $this->consultant();
        $pair = $c->hiddenAccounts()->orderBy('id')->get();

        $this->buyPlacedUnder($c, $pair[1], 2);   // 4th account -> under leg 2
        $this->buyPlacedUnder($c, $pair[0], 1);   // 7th account -> under leg 1

        $accounts = $c->fresh()->hiddenAccounts()->orderBy('id')->get();
        $this->assertCount(8, $accounts, 'Opening pair + two bought sets.');

        $map = app(AccountPlacementMap::class)->for($c->fresh());

        $fourth = $accounts[2];
        $seventh = $accounts[5];

        $this->assertNull($seventh->serviceGroupLeg(), 'The 7th account is the second set-main.');

        $this->assertSame($pair[1]->id, $map[$fourth->id]['under_id']);
        $this->assertSame(2, $map[$fourth->id]['tier']);

        $this->assertSame($pair[0]->id, $map[$seventh->id]['under_id'], 'The 7th follows its OWN checkout choice.');
        $this->assertSame(1, $map[$seventh->id]['tier']);
    }

    /**
     * ⚠️ AN ACCOUNT THE OWNER DOES NOT HOLD IS NOT A PARENT.
     *
     * Reported 2026-08-22, the day the provenance line shipped: SCN-00142 read
     * "Placed under SCN-00124" — "124 aththe na... mn course eka buy kre me
     * account, ethkot 124 enn widiyk na". payments.consultant_id answers "which
     * account earns from this purchase", which coincides with the set's own
     * position only when the buyer picked one of their own accounts.
     */
    public function test_a_purchase_credited_to_someone_elses_account_is_flagged_not_drawn_as_a_parent(): void
    {
        $c = $this->consultant();

        $strangerUser = User::factory()->create();
        $strangerUser->assignRole('consultant');
        $stranger = Consultant::create([
            'user_id' => $strangerUser->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-00124',
            'status' => 'active',
        ]);

        $this->buyPlacedUnder($c, $stranger, 1);

        $setMain = $c->fresh()->hiddenAccounts()->orderBy('id')->get()[2];
        $map = app(AccountPlacementMap::class)->for($c->fresh());

        $this->assertSame('bought-foreign', $map[$setMain->id]['kind'], 'Not presented as a placement.');

        $this->actingAs($c->user)
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertSee('which is not your account')
            ->assertDontSee('Placed under SCN-00124');
    }

    /** An account with no payment on record degrades to a label, never an error. */
    public function test_an_account_with_no_recorded_payment_is_marked_unknown(): void
    {
        $c = $this->consultant();
        app(\App\Services\HiddenAccountService::class)->addAccountSet($c); // no payment marker

        $setMain = $c->fresh()->hiddenAccounts()->orderBy('id')->get()[2];
        $map = app(AccountPlacementMap::class)->for($c->fresh());

        $this->assertSame('unknown', $map[$setMain->id]['kind']);
        $this->assertNull($map[$setMain->id]['under_code']);
    }

    public function test_the_service_groups_page_shows_where_each_account_hangs(): void
    {
        $c = $this->consultant();
        $target = $c->hiddenAccounts()->orderBy('id')->get()[1];

        $this->buyPlacedUnder($c, $target, 2);

        $this->actingAs($c->user)
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertSee('Placed under')
            ->assertSee($target->scn_code)
            ->assertSee('Opened by a course purchase');
    }

    public function test_the_service_income_cards_show_where_each_account_hangs(): void
    {
        $c = $this->consultant();
        $target = $c->hiddenAccounts()->orderBy('id')->get()[1];

        $this->buyPlacedUnder($c, $target, 2);

        $this->actingAs($c->user)
            ->get(route('consultant.earnings.index'))
            ->assertOk()
            ->assertSee('Placed under')
            ->assertSee($target->scn_code);
    }
}
