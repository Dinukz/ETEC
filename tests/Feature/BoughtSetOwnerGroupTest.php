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
 * A bought set belongs, whole, to the Service Group it was bought into.
 *
 * Reported 2026-08-23 — "2500 cross wela wadinwa udata ynakota":
 *
 *   "6th account ekata api student kenk add karoth S1 eke 2500 4th ekatath add
 *    enwa, 152 tath add wenwa, eth 151 ta giyama S2 ekata thama add wenne, eka
 *    waradi. 4th eka thiyenne 151 eke Service group 1 eka athule thama,
 *    ethkota eke dana ewa okkom Service group 1 ekata enn one, ohom cross wenn
 *    ba. anith paththen buy kranm eketh wenne ohom — udata ynakota depaththa
 *    maru wela cross wenwa."
 *
 * Consultant::serviceGroupLeg() numbers accounts by POSITION (#4 -> 1, #5 -> 2),
 * which says nothing about where the set was actually placed. So the second leg
 * of every bought set was filed under the opposite side of the owner's
 * headline, and buying into the other group mirrored the same error.
 */
class BoughtSetOwnerGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function owner(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** Buy a course, placing the new set under $target's Service Group $tier. */
    private function buyPlacedUnder(Consultant $buyer, Consultant $target, int $tier): void
    {
        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $buyer->user_id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
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

    /** @return array<string, ?int> owner-group of every account, keyed by SCN code */
    private function ownerGroups(Consultant $owner): array
    {
        $map = app(AccountPlacementMap::class)->for($owner->fresh());
        $out = [];

        foreach (Consultant::whereIn('id', $owner->fresh()->accountIds())->orderBy('id')->get() as $account) {
            $out[$account->scn_code] = $map[$account->id]['owner_group'] ?? null;
        }

        return $out;
    }

    /**
     * ⚠️ THE REPORTED CASE. The set is bought into Service Group 1, so all
     * three of its accounts belong to Service Group 1 — including the one the
     * ordinal rule called "leg 2".
     */
    public function test_a_set_bought_into_group_one_belongs_entirely_to_group_one(): void
    {
        $main = $this->owner();                                      // "151"
        $legOne = $main->hiddenAccounts()->orderBy('id')->get()[0];   // "152" — the main's SG1

        $this->buyPlacedUnder($main, $legOne, 1);

        $accounts = $main->fresh()->hiddenAccounts()->orderBy('id')->get();
        [$setMain, $setLeg1, $setLeg2] = [$accounts[2], $accounts[3], $accounts[4]];

        $groups = $this->ownerGroups($main);

        $this->assertSame(1, $groups[$setMain->scn_code], 'The 4th account sits in Service Group 1.');
        $this->assertSame(1, $groups[$setLeg1->scn_code], 'So does the 5th.');
        $this->assertSame(
            1,
            $groups[$setLeg2->scn_code],
            'And so does the 6th — this is the one that used to cross to Service Group 2.',
        );

        // The ordinal rule still says otherwise, and that is fine: it answers a
        // different question (which leg WITHIN its own set), which is what tells
        // a set-main apart for the placement picker.
        $this->assertSame(2, $setLeg2->serviceGroupLeg(), 'Position is unchanged — only the attribution moved.');
    }

    /** ⚠️ "anith paththen buy kranm eketh wenne ohom" — mirrored, same fix. */
    public function test_a_set_bought_into_group_two_belongs_entirely_to_group_two(): void
    {
        $main = $this->owner();
        $legTwo = $main->hiddenAccounts()->orderBy('id')->get()[1];   // "153" — the main's SG2

        $this->buyPlacedUnder($main, $legTwo, 1);

        $accounts = $main->fresh()->hiddenAccounts()->orderBy('id')->get();
        $groups = $this->ownerGroups($main);

        foreach ([$accounts[2], $accounts[3], $accounts[4]] as $account) {
            $this->assertSame(
                2,
                $groups[$account->scn_code],
                "{$account->scn_code} was bought into Service Group 2 and must stay there.",
            );
        }
    }

    /** The opening pair are unchanged — they really do sit directly under the main. */
    public function test_the_opening_pair_keep_their_own_sides(): void
    {
        $main = $this->owner();
        $accounts = $main->hiddenAccounts()->orderBy('id')->get();
        $groups = $this->ownerGroups($main);

        $this->assertSame(1, $groups[$accounts[0]->scn_code]);
        $this->assertSame(2, $groups[$accounts[1]->scn_code]);
        $this->assertNull(
            app(AccountPlacementMap::class)->for($main)[$main->id]['owner_group'],
            'The main is the root — it is not inside either of its own groups.',
        );
    }

    /** A set placed DIRECTLY under the main takes the group chosen at checkout. */
    public function test_a_set_placed_directly_under_the_main_takes_the_chosen_group(): void
    {
        $main = $this->owner();

        $this->buyPlacedUnder($main, $main, 2);

        $accounts = $main->fresh()->hiddenAccounts()->orderBy('id')->get();
        $groups = $this->ownerGroups($main);

        foreach ([$accounts[2], $accounts[3], $accounts[4]] as $account) {
            $this->assertSame(2, $groups[$account->scn_code]);
        }
    }

    /**
     * ⚠️ The end-to-end symptom: the Service Groups headline must show the
     * money on Service Group 1, not Service Group 2.
     */
    public function test_the_headline_puts_a_bought_sets_income_on_the_right_side(): void
    {
        $main = $this->owner();
        $legOne = $main->hiddenAccounts()->orderBy('id')->get()[0];

        $this->buyPlacedUnder($main, $legOne, 1);

        $sixth = $main->fresh()->hiddenAccounts()->orderBy('id')->get()[4];

        // A real student into the 6th account — the account the ordinal rule
        // called "Service Group 2".
        $student = User::factory()->create();
        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $sixth->id,
            'requested_tier' => 1,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($payment, User::role('admin')->first());

        // Approve everything so it reaches the headline (which counts approved).
        foreach (\App\Models\Commission::whereIn('consultant_id', $main->fresh()->accountIds())->get() as $c) {
            app(\App\Services\CommissionApproval::class)->approve($c, force: true, actorId: null);
        }

        $response = $this->actingAs($main->user)->get(route('consultant.groups.index'));
        $response->assertOk();

        $rows = $response->viewData('accountRows');
        $sixthRow = collect($rows)->firstWhere('id', $sixth->id);

        $this->assertSame(1, $sixthRow['represents_leg'], 'The 6th account reports as Service Group 1.');

        $tierTotals = $response->viewData('tierTotals');

        $this->assertGreaterThan(
            0,
            (float) ($tierTotals[1]->total ?? 0),
            "The money is on the owner's Service Group 1.",
        );
        $this->assertSame(
            0.0,
            (float) ($tierTotals[2]->total ?? 0),
            'And nothing crossed to Service Group 2.',
        );
    }
}
