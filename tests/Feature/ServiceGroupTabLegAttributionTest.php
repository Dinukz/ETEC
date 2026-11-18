<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
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
 * ⚠️⚠️ WHICH SERVICE GROUP DOES A PERSON SIX LEVELS DOWN BELONG TO?
 *
 * This codebase answers leg questions in five places, and they answer THREE
 * DIFFERENT questions. Mixing them up has caused three separate production
 * incidents — 2026-08-18 ("pathi deka maru wela"), 2026-08-20 (the ordinal read
 * after placement_tier) and 2026-08-23 (the cross). The Service Group tabs
 * became a subtree view on 2026-09-07 and now have to answer it too.
 *
 * The rule, and the ONLY rule:
 *
 *   A person belongs to the Service Group of the ROOT ACCOUNT their branch
 *   hangs off; when the branch hangs off the selected account itself, to the
 *   leg they occupy INSIDE it.
 *
 * The trap this file exists to catch: the leg a person sits on INSIDE
 * SCN-00102 is a different quantity from the leg SCN-00102 *is* inside the
 * main. Someone seated on tier 1 inside SCN-00102 is Service Group **2** of
 * the main, because SCN-00102 IS Service Group 2.
 * `test_a_person_seated_on_tier_one_inside_a_leg_two_account_is_still_leg_two`
 * fails the instant anyone widens the seat lookup to the parent's whole account
 * set, or substitutes Consultant::serviceGroupLeg() for the placement map.
 */
class ServiceGroupTabLegAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Consultant $sponsor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => 2500,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');

        $this->sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    // ----- scaffolding ------------------------------------------------------

    private function nextCourse(): Course
    {
        $template = Course::where('status', 'published')->firstOrFail();
        $n = Course::count() + 1;

        return Course::create([
            'title' => 'Leg Course '.$n,
            'slug' => 'leg-course-'.$n.'-'.Str::random(6),
            'description' => 'test',
            'price' => $template->price,
            'currency' => $template->currency,
            'status' => 'published',
            'lecturer_id' => $template->lecturer_id,
            'published_at' => now(),
        ]);
    }

    private function verify(User $buyer, Consultant $account, int $tier = 1): Payment
    {
        $course = $this->nextCourse();

        $enrollment = Enrollment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $account->id, 'requested_tier' => $tier,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    private function newStudent(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function downlineUnder(Consultant $sponsorAccount, string $name, int $tier = 1): Consultant
    {
        $user = $this->newStudent($name);
        $this->verify($user, $sponsorAccount, $tier);
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

    private function firstAccount(Consultant $person): Consultant
    {
        return $person->hiddenAccounts()->orderBy('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function panel(string|int $tab, ?Consultant $selectAccount = null): array
    {
        $query = $selectAccount ? ['account' => $selectAccount->id] : [];

        $response = $this->actingAs($this->sponsor->user)
            ->get(route('consultant.groups.index', $query));

        $response->assertOk();

        return $response->viewData('team')['legs'][$tab];
    }

    /** @return array<string> */
    private function peopleIn(array $panel): array
    {
        return collect($panel['rows'])
            ->reject(fn ($r) => $r['is_root'])
            ->pluck('name')
            ->sort()->values()->all();
    }

    /** @return array{0: Consultant, 1: Consultant} the sponsor's Service Group 1 and 2 accounts */
    private function legs(): array
    {
        [$leg1, $leg2] = $this->sponsor->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        return [$leg1, $leg2];
    }

    /**
     * The sponsor buys another course, placed under $target — which mints a new
     * SET of three accounts hanging there. Copied from BoughtSetOwnerGroupTest,
     * which is where this shape is documented.
     */
    private function buyASetPlacedUnder(Consultant $target, int $tier): void
    {
        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $this->sponsor->user_id,
            'course_id' => $this->nextCourse()->id,
            'consultant_id' => $target->id,
            'requested_tier' => $tier,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    // ----- the rule ---------------------------------------------------------

    /**
     * ⚠️⚠️ THE CROSS-BUG GUARD. Read the class docblock before touching this.
     *
     * D is seated on tier ONE inside SCN-00102 — and SCN-00102 IS the main's
     * Service Group TWO. The seat tier answers "which side of SCN-00102", which
     * is not the question the main's tabs ask. D belongs to Service Group 2.
     */
    public function test_a_person_seated_on_tier_one_inside_a_leg_two_account_is_still_leg_two(): void
    {
        [, $leg2] = $this->legs();

        // tier: 1 — deliberately the OPPOSITE of the leg the account represents.
        $crossed = $this->downlineUnder($leg2, 'Crossed Consultant', tier: 1);
        $this->verify($this->newStudent('Crossed Student'), $this->firstAccount($crossed));

        $this->assertSame(
            ['Crossed Consultant'],
            $this->peopleIn($this->panel(2)),
            'SCN-00102 IS Service Group 2, so everyone below it is Service Group 2 — '
            .'whichever of its own two sides they happen to sit on.',
        );

        $this->assertSame([], $this->peopleIn($this->panel(1)), 'and they are NOT on Service Group 1');
    }

    /**
     * ⚠️⚠️ THE ASSERTION THAT ACTUALLY CATCHES THE WRONG HELPER.
     *
     * The test above is not enough on its own: for the OPENING PAIR,
     * Consultant::serviceGroupLeg() (the ordinal) and AccountPlacementMap's
     * owner_group (the climb) give the same answer, so swapping one for the
     * other changes nothing there. I verified that by making the swap and
     * watching every other test still pass.
     *
     * They diverge on a BOUGHT SET. Buy a set into Service Group 1 and its
     * third account — the one the ordinal calls "leg 2" — still belongs to
     * Service Group 1, because that is where the whole set was placed. Anyone
     * below it is Service Group 1 too.
     *
     * This is the 2026-08-23 cross. If this test passes while the code reads
     * serviceGroupLeg(), the fixture is wrong, not the rule.
     */
    public function test_a_downline_under_a_bought_set_follows_where_the_set_was_placed(): void
    {
        [$leg1] = $this->legs();

        $this->buyASetPlacedUnder($leg1, 1);

        $accounts = $this->sponsor->fresh()->hiddenAccounts()->orderBy('id')->get();
        $setLegTwo = $accounts[4];   // the 6th account: ordinal says leg 2

        $this->assertSame(2, $setLegTwo->serviceGroupLeg(), 'guard: by position this account is "leg 2"');

        $this->downlineUnder($setLegTwo, 'Bought Set Downline');

        $this->assertSame(
            ['Bought Set Downline'],
            $this->peopleIn($this->panel(1)),
            'the whole set was bought into Service Group 1, so everyone below it is Service Group 1 — '
            .'the ordinal says "leg 2" and the ordinal is answering a different question',
        );

        $this->assertSame([], $this->peopleIn($this->panel(2)), 'and they must NOT cross to Service Group 2');
    }

    /** A descendant many levels down inherits the branch it came through. */
    public function test_a_deep_descendant_inherits_its_branch_root(): void
    {
        [, $leg2] = $this->legs();

        $d = $this->downlineUnder($leg2, 'Level One');
        $e = $this->downlineUnder($this->firstAccount($d), 'Level Two');
        $f = $this->downlineUnder($this->firstAccount($e), 'Level Three');

        $this->verify($this->newStudent('Deep Student'), $this->firstAccount($f));

        $this->assertSame(
            ['Level One', 'Level Three', 'Level Two'],
            $this->peopleIn($this->panel(2)),
            'the whole branch is Service Group 2',
        );

        $this->assertSame([], $this->peopleIn($this->panel(1)));

        $deepStudentOnLeg2 = collect($this->panel(2)['rows'])
            ->flatMap(fn ($r) => collect($r['students'])->pluck('name'));

        $this->assertContains('Deep Student', $deepStudentOnLeg2, 'and so is the student at the bottom of it');
    }

    /**
     * ⚠️ THE TWO QUESTIONS, ASKED SIDE BY SIDE.
     *
     * Selecting SCN-00102 asks "which of ITS two sides", so its own tier 1 and
     * tier 2 downlines split. Selecting the MAIN asks "which of the MAIN's two
     * sides", and both of them are Service Group 2 — because SCN-00102 is.
     * Same two people, two different correct answers.
     */
    public function test_a_placement_account_splits_by_its_own_tiers_while_the_main_does_not(): void
    {
        [, $leg2] = $this->legs();

        $this->downlineUnder($leg2, 'Inside Tier One', tier: 1);
        $this->downlineUnder($leg2, 'Inside Tier Two', tier: 2);

        // Viewed from SCN-00102 itself.
        $this->assertSame(['Inside Tier One'], $this->peopleIn($this->panel(1, $leg2)));
        $this->assertSame(['Inside Tier Two'], $this->peopleIn($this->panel(2, $leg2)));

        // Viewed from the main, both are on the side SCN-00102 represents.
        $this->assertSame(
            ['Inside Tier One', 'Inside Tier Two'],
            $this->peopleIn($this->panel(2)),
        );
        $this->assertSame([], $this->peopleIn($this->panel(1)));
    }

    /** Both sides at once, so nothing is quietly filed on the wrong one. */
    public function test_the_two_legs_do_not_bleed_into_each_other(): void
    {
        [$leg1, $leg2] = $this->legs();

        $this->downlineUnder($leg1, 'On Side One');
        $this->downlineUnder($leg2, 'On Side Two');

        $this->assertSame(['On Side One'], $this->peopleIn($this->panel(1)));
        $this->assertSame(['On Side Two'], $this->peopleIn($this->panel(2)));
        $this->assertSame(['On Side One', 'On Side Two'], $this->peopleIn($this->panel('all')));
    }

    // ----- when there is no answer ------------------------------------------

    /**
     * A person whose branch cannot be determined must still be visible — in All,
     * in neither leg, and named as unattributed. The same promise the money side
     * already makes for income in neither group.
     */
    public function test_a_person_with_no_determinable_leg_appears_in_all_but_neither_side(): void
    {
        // Hung directly off the MAIN account, never seated, no placement_tier —
        // so there is no ordinal to read and no seat to fall back to.
        $user = $this->newStudent('No Branch');
        $user->syncRoles(['consultant']);

        $orphan = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $this->sponsor->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);
        app(HiddenAccountService::class)->ensureFor($orphan);

        $this->assertSame(['No Branch'], $this->peopleIn($this->panel('all')));
        $this->assertSame([], $this->peopleIn($this->panel(1)));
        $this->assertSame([], $this->peopleIn($this->panel(2)));

        $team = $this->actingAs($this->sponsor->user)
            ->get(route('consultant.groups.index'))
            ->viewData('team');

        $this->assertSame(1, $team['unattributed']);

        $this->actingAs($this->sponsor->user)
            ->get(route('consultant.groups.index'))
            ->assertSee('not attributed to a Service Group');
    }

    /** ⚠️ All is the union PLUS the unattributed — never fewer than the two sides. */
    public function test_all_is_never_smaller_than_the_two_sides_together(): void
    {
        [$leg1, $leg2] = $this->legs();

        $this->downlineUnder($leg1, 'Side One Person');
        $this->downlineUnder($leg2, 'Side Two Person');

        $all = $this->panel('all');
        $one = $this->panel(1);
        $two = $this->panel(2);

        $this->assertGreaterThanOrEqual(
            $one['people_total'] + $two['people_total'],
            $all['people_total'],
        );
        $this->assertGreaterThanOrEqual(
            $one['students_total'] + $two['students_total'],
            $all['students_total'],
        );
    }

    // ----- safety -----------------------------------------------------------

    /** A hand-edited sponsorship cycle must render a page, not hang it. */
    public function test_a_sponsorship_cycle_still_renders(): void
    {
        [$leg1] = $this->legs();

        $a = $this->downlineUnder($leg1, 'Cycle A');
        $b = $this->downlineUnder($this->firstAccount($a), 'Cycle B');

        Consultant::whereKey($a->id)->update(['parent_consultant_id' => $this->firstAccount($b)->id]);

        $this->actingAs($this->sponsor->user)
            ->get(route('consultant.groups.index'))
            ->assertOk();
    }

    /** The page still renders when the placement_tier column has not healed yet. */
    public function test_it_degrades_when_placement_tier_is_unavailable(): void
    {
        [$leg1] = $this->legs();
        $this->downlineUnder($leg1, 'Still Listed');

        \Illuminate\Support\Facades\Cache::forget('finance.placementtier.ready');
        \App\Services\FinanceSchemaRepair::forgetMemo();

        $this->assertSame(['Still Listed'], $this->peopleIn($this->panel(1)));
    }
}
