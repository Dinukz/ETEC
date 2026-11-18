<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The network-marketing tree the owner described:
 *
 *   A adds student D  ->  D becomes a consultant  ->  D adds student H
 *   ->  H becomes a consultant  ->  H adds students
 *
 * Every student H registers must earn H, D AND A. Nothing climbs above A,
 * because A has no sponsor. Each consultant must also be able to SEE and act
 * on everyone below them.
 *
 * The catch that broke this in practice: a consultant registers students
 * through one of their hidden PLACEMENT accounts (SCN-00166), so the promoted
 * student's sponsor is recorded as that hidden account — not as the real
 * consultant. Anything walking the tree has to resolve through it.
 */
class ConsultantNetworkTreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** Register a student through one of $owner's placement accounts and verify the payment. */
    private function addStudent(User $ownerUser, string $email, int $tier): User
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $course = Course::where('status', 'published')->firstOrFail();
        $partner = $ownerUser->consultant->hiddenAccounts()->first();

        $this->actingAs($ownerUser)->post(route('consultant.students.store'), [
            'name' => 'Student '.$email,
            'email' => $email,
            'course_id' => $course->id,
            'placement_tier' => $tier,
            'partner_consultant_id' => $partner->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $payment = Payment::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();

        $this->actingAs($ownerUser)
            ->patch(route('consultant.payments.confirm', $payment), ['payment_type' => 'full', 'payment_date' => now()->toDateString()])
            ->assertRedirect();

        app(PaymentService::class)->verify($payment->fresh(), $accountant);

        return User::where('email', $email)->firstOrFail();
    }

    /** Promote a student to consultant through the real admin route. */
    private function promote(User $student): Consultant
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('users.role', $student), ['role' => 'consultant'])
            ->assertRedirect();

        // A student registered by a consultant gets a generated password, so
        // they are held on the profile page until they set their own. Clear it
        // here the same way setting a password would, so the promoted consultant
        // can actually use their account.
        $student->forceFill(['password_must_change' => false])->save();

        return $student->fresh()->consultant;
    }

    public function test_a_promoted_student_is_recorded_in_their_sponsors_downline(): void
    {
        $aUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $a = $aUser->consultant;

        $dUser = $this->addStudent($aUser, 'student-d@example.com', 1);
        $d = $this->promote($dUser);

        // D was registered through A's PLACEMENT account, so D's recorded
        // sponsor is that hidden account — A must still own D as downline.
        $this->assertContains($d->id, $a->fresh()->downlineIds(), 'A must see D below them.');
        $this->assertContains($d->id, $a->fresh()->reachableAccountIds(), 'A must be able to act on D.');
    }

    public function test_the_downline_reaches_every_level_not_just_the_first(): void
    {
        $aUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $a = $aUser->consultant;

        $dUser = $this->addStudent($aUser, 'level-d@example.com', 1);
        $d = $this->promote($dUser);

        $hUser = $this->addStudent($dUser, 'level-h@example.com', 1);
        $h = $this->promote($hUser);

        $aDownline = $a->fresh()->downlineIds();

        $this->assertContains($d->id, $aDownline, 'A sees D (level 1).');
        $this->assertContains($h->id, $aDownline, 'A sees H (level 2).');
        $this->assertContains($h->id, $d->fresh()->downlineIds(), 'D sees H.');

        // Nothing climbs above A, and D never sees their own sponsor.
        $this->assertNotContains($a->id, $d->fresh()->downlineIds(), 'The tree only goes downward.');
    }

    public function test_a_grandchilds_student_earns_every_ancestor_but_only_the_registrant_gets_the_bonus(): void
    {
        $aUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $a = $aUser->consultant;

        $dUser = $this->addStudent($aUser, 'earn-d@example.com', 1);
        $d = $this->promote($dUser);

        $hUser = $this->addStudent($dUser, 'earn-h@example.com', 1);
        $h = $this->promote($hUser);

        $earned = fn (Consultant $c) => (float) Commission::whereIn('consultant_id', $c->fresh()->accountIds())->sum('amount');

        // H registers students of their own, one on each leg so H's pair settles.
        $this->addStudent($hUser, 'earn-x1@example.com', 1);
        $this->addStudent($hUser, 'earn-x2@example.com', 2);

        // H earns their own pair: 2 slots x 2500 + 2 direct bonuses x 3000,
        // PLUS 2 x 2500 on H's own MAIN account, which the climb now reaches.
        //
        // ⚠️ 11,000 -> 16,000 on 2026-09-02. $earned() sums accountIds(), which
        // includes the person's main, and the main was the one account in the
        // model that earned nothing — the climb stopped one hop below it. It no
        // longer does; see CommissionService::creditOwnAccountAncestors().
        $this->assertSame(16000.0, $earned($h), 'H earns their own 1-to-1 on both the placement and the main, plus both bonuses.');

        // The same two students must physically reach D's and A's service groups
        // — that linkage is what makes an ancestor earn as their legs balance.
        $paymentIds = Payment::whereHas('user', fn ($q) => $q->whereIn('email', ['earn-x1@example.com', 'earn-x2@example.com']))->pluck('id');

        foreach (['D' => $d, 'A' => $a] as $label => $ancestor) {
            $reached = GroupMember::whereIn('payment_id', $paymentIds)
                ->whereIn('group_id', Group::whereIn('consultant_id', $ancestor->fresh()->accountIds())->select('id'))
                ->count();

            // ⚠️ 2 -> 4 on 2026-09-02. Each student now takes TWO seats inside
            // the ancestor: one on the placement account they climbed through
            // and one on that person's MAIN, which the climb reaches since the
            // main earns its own rows. accountIds() covers both.
            //
            // The assertion this test exists for is unchanged — every one of H's
            // students must physically reach the ancestor, because a seat is
            // what pairs and pairing is what releases the money.
            $this->assertSame(4, $reached, "Both of H's students must fill a slot for {$label}, on the placement and the main.");
        }

        // But the direct-add bonus stays with H — it never climbs.
        foreach (['D' => $d, 'A' => $a] as $label => $ancestor) {
            $this->assertSame(
                0.0,
                (float) Commission::whereIn('consultant_id', $ancestor->fresh()->accountIds())
                    ->where('type', 'referral')
                    ->whereIn('payment_id', $paymentIds)
                    ->sum('amount'),
                "The direct-add bonus must not climb to {$label}.",
            );
        }
    }

    public function test_the_network_page_shows_every_level_below_but_never_above(): void
    {
        $aUser = User::where('email', 'consultant@etec.lk')->firstOrFail();

        $dUser = $this->addStudent($aUser, 'page-d@example.com', 1);
        $d = $this->promote($dUser);

        $hUser = $this->addStudent($dUser, 'page-h@example.com', 1);
        $h = $this->promote($hUser);

        // A sees both levels below them.
        $response = $this->actingAs($aUser)->get(route('consultant.network.index'));
        $response->assertOk();
        $response->assertSee($d->scn_code);
        $response->assertSee($h->scn_code);
        $response->assertSee('Level 1');
        $response->assertSee('Level 2');

        // D sees H below them, but never their own sponsor A.
        $dResponse = $this->actingAs($dUser)->get(route('consultant.network.index'));
        $dResponse->assertOk();
        $dResponse->assertSee($h->scn_code);
        $dResponse->assertDontSee($aUser->consultant->scn_code);

        // H is a leaf — nobody below them yet.
        $this->actingAs($hUser)->get(route('consultant.network.index'))
            ->assertOk()
            ->assertSee('No consultants below you yet.');
    }

    public function test_the_service_groups_search_finds_a_downline_consultants_account(): void
    {
        $aUser = User::where('email', 'consultant@etec.lk')->firstOrFail();

        $dUser = $this->addStudent($aUser, 'search-d@example.com', 1);
        $d = $this->promote($dUser);

        // Searching D's exact SCN from A's Service Groups page must resolve —
        // it is scoped to reachableAccountIds(), which walks the same tree.
        $response = $this->actingAs($aUser)->get(route('consultant.groups.index', ['scn' => $d->scn_code]));

        $response->assertOk();
        $response->assertSee($d->scn_code);
        $response->assertDontSee('No account in your network matches');

        // A consultant outside the network still must not resolve.
        $outsider = Consultant::real()->whereNotIn('id', array_merge([$aUser->consultant->id], $aUser->consultant->downlineIds()))->first();

        if ($outsider?->scn_code) {
            $this->actingAs($aUser)->get(route('consultant.groups.index', ['scn' => $outsider->scn_code]))
                ->assertOk()
                ->assertSee('No account in your network matches');
        }
    }

    /**
     * The account-specific counterpart to "an ancestor earns" above: WHICH of
     * A's accounts the climbing money lands on.
     *
     * ⚠️ THIS ASSERTION HAS NOW BEEN SET THREE TIMES. CURRENT: SUB ACCOUNT.
     *
     * - 2026-08-05: the money stayed on the exact hidden placement account D
     *   was sponsored through — each account earning independently.
     * - 2026-08-12: inverted to the ancestor's MAIN SCN account ("me dana ewa
     *   consultant ge main account eka watenn one"), because in real data the
     *   main account held zero students and had nothing to pair against.
     * - 2026-08-21: inverted BACK to the specific placement account, after the
     *   owner worked a case through by hand: "SCN main account ekata 2500
     *   ekathu wenawa eth sub account walat ekathu wen na... 4th account eken
     *   ena upline 2500 ya 2nd or 3rd account walata ekathu wen nathiw kelin
     *   main account ekata yanwa" — i.e. crediting the main was the BUG.
     *
     * The money still SHOWS on the main, because the main's figures are a
     * rollup of its accounts (see ServiceGroupRollupTest) — but the commission
     * row itself belongs to the sub account. That distinction is the whole
     * 08-21 correction: a rollup is a view, not a second credit.
     *
     * Do not "fix" this back without the same explicit confirmation. The rule
     * has been set both ways twice and the code alone will not say which way is
     * current. See commission-rules-2026-08.md, and UplineLandsOnSubAccountTest
     * which covers the same rule directly.
     */
    public function test_an_ancestors_upline_commission_lands_on_the_sub_account(): void
    {
        $aUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $a = $aUser->consultant;
        $placementAccount = $a->hiddenAccounts()->first();

        // D is registered through A's placement account (addStudent() always
        // uses hiddenAccounts()->first()) — that account is D's sponsor. This
        // registration itself already credits the placement account its own
        // slot (2,500) + direct bonus, so measure from here, not from zero.
        $dUser = $this->addStudent($aUser, 'acct-d@example.com', 1);
        $this->promote($dUser);

        $placementBefore = (float) Commission::where('consultant_id', $placementAccount->id)->where('type', 'bonus')->sum('amount');
        $mainBefore = (float) Commission::where('consultant_id', $a->id)->where('type', 'bonus')->sum('amount');

        // D registers a student of their own. This climbs to A — and lands on
        // the placement account D actually sits under, NOT on A's main.
        $this->addStudent($dUser, 'acct-d1@example.com', 1);

        $this->assertSame(
            $placementBefore + 2500.0,
            (float) Commission::where('consultant_id', $placementAccount->id)->where('type', 'bonus')->sum('amount'),
            "Climbing slot income belongs to the sub account D sits under ({$placementAccount->scn_code}).",
        );
        // ⚠️ INVERTED 2026-09-02. This asserted the main was NOT credited,
        // because its headline was a ROLLUP of the accounts beneath it and a row
        // of its own would have been the same student counted twice there.
        //
        // The rollup is gone: the main displays and settles exactly the rows
        // written against it, so it must be credited like every other account in
        // the climb — otherwise it holds nothing, can never pair, and can never
        // appear in a System Run, which is what the owner reported eight times.
        //
        // ⚠️ THE HALF THAT DID NOT CHANGE is the assertion above: the money still
        // lands on the placement account D actually sits under. The main earns IN
        // ADDITION to it, never INSTEAD of it.
        $this->assertSame(
            $mainBefore + 2500.0,
            (float) Commission::where('consultant_id', $a->id)->where('type', 'bonus')->sum('amount'),
            "A's main is the top of A's own tree, so the climb credits it too.",
        );
    }

    /**
     * The seat moves with the money. placeInto() creates the GroupMember on
     * whichever account is credited, so once the 08-21 reversal put the money
     * back on the sub account, the seat follows it there — the main account's
     * own service group stays untouched.
     *
     * This matters beyond bookkeeping: the seat is what PAIRS. Putting the seat
     * on the main would have it pair against the main's own leg rather than the
     * sub account's, which is a different flush and a different payout.
     */
    public function test_a_downlines_student_takes_a_seat_on_the_sub_accounts_service_group(): void
    {
        $aUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $a = $aUser->consultant;
        $placementAccount = $a->hiddenAccounts()->first();

        $dUser = $this->addStudent($aUser, 'seat-d@example.com', 1);
        $this->promote($dUser);

        $groupsOf = fn ($id) => \App\Models\GroupMember::whereIn(
            'group_id',
            \App\Models\Group::where('consultant_id', $id)->select('id'),
        )->count();

        $mainBefore = $groupsOf($a->id);
        $subBefore = $groupsOf($placementAccount->id);

        $this->addStudent($dUser, 'seat-d1@example.com', 1);

        $this->assertSame(
            $subBefore + 1,
            $groupsOf($placementAccount->id),
            "The downline's student occupies a seat on the sub account they sit under.",
        );
        // ⚠️ INVERTED 2026-09-02, for the same reason as the row above it: the
        // main earns now, and a credit needs a seat to hang on.
        //
        // The seat is what PAIRS, which is the point of this test. The student
        // occupies one seat on the sub account AND one on the main, and those
        // two pair independently — the sub account against its own other leg,
        // the main against its own. That independence is exactly what stops a
        // main draining a child that has not balanced.
        $this->assertSame(
            $mainBefore + 1,
            $groupsOf($a->id),
            "A's main pairs its own legs, so it holds its own seat for this student.",
        );
    }

    public function test_an_ancestor_earns_immediately_from_each_downline_registration_within_their_own_cap(): void
    {
        $aUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $a = $aUser->consultant;

        // A sponsors two consultants, one on each of A's legs.
        $dUser = $this->addStudent($aUser, 'bal-d@example.com', 1);
        $this->promote($dUser);
        $eUser = $this->addStudent($aUser, 'bal-e@example.com', 2);
        $this->promote($eUser);

        $earned = fn (Consultant $c) => (float) Commission::whereIn('consultant_id', $c->fresh()->accountIds())->sum('amount');
        $before = $earned($a);

        // A student under D climbs to fill another of A's OWN leg-1 slots
        // (network position, not pairing) and pays A immediately — no need
        // for A's other leg to grow first.
        // ⚠️ 2,500 -> 5,000 PER CLIMB on 2026-09-02, and it is not a rate change.
        // $earned() sums accountIds(), so it now sees TWO rows per climb: the
        // placement account the downline sits under, and A's main at the top of
        // A's own tree. Before, the main earned nothing at all.
        $this->addStudent($dUser, 'bal-d1@example.com', 1);
        $this->assertSame($before + 5000.0, $earned($a), "D's downline registration pays A's placement and A's main.");

        // A student under E likewise climbs to fill another of A's leg-2 slots.
        $this->addStudent($eUser, 'bal-e1@example.com', 1);
        $this->assertSame($before + 10000.0, $earned($a), 'Both climbs together, on both accounts each.');
    }
}
