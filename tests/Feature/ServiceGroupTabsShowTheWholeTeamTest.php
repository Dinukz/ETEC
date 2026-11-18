<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupMember;
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
 * ⚠️ THE THREE SERVICE GROUP TABS ARE THE TEAM — not a seat list.
 *
 * Asked for 2026-09-06, then moved into the existing tabs on 2026-09-07:
 * "mt mekama one aluth tab ehekin newei, one thiyen SCN account wala penwann
 * one... Udara add krna student consultant la eyala meke penwann one, e wagem
 * eyage yatathe inna consultant la add krana student and consultant la eyala
 * okkom... Ilagata eyala aithi wena SCN account widiyta SG1 and SG2 ewa wala."
 *
 * ⚠️ WHY THIS CANNOT GO BACK TO BEING A SEAT QUERY.
 *
 * A GroupMember seat is written by CommissionService only when a payment is
 * VERIFIED and the sponsor chain is walked. Four ordinary situations therefore
 * have a student with no seat anywhere, permanently, however the query is
 * widened:
 *
 *   half payment      -> seats nobody at all, not even the registrant
 *   free / zero card  -> seats the registrant, never the upline
 *   not yet verified  -> seats nobody; the student exists all the same
 *   sponsor link null -> the climb never starts, so no ancestor is seated
 *
 * Half of this file exists to pin exactly those. Anyone who "simplifies" this
 * back into a GroupMember query will pass every other test here and fail those.
 *
 * The seat count is NOT gone — each panel carries it as `seated`, because that
 * is the number the LKR 7,500 flush actually measures. The two are different
 * on purpose and `test_a_panel_reports_the_team_and_the_seats_separately` pins
 * that they are allowed to disagree.
 */
class ServiceGroupTabsShowTheWholeTeamTest extends TestCase
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
            'title' => 'Team Course '.$n,
            'slug' => 'team-course-'.$n.'-'.Str::random(6),
            'description' => 'test',
            'price' => $template->price,
            'currency' => $template->currency,
            'status' => 'published',
            'lecturer_id' => $template->lecturer_id,
            'published_at' => now(),
        ]);
    }

    private function makePayment(User $buyer, Consultant $account, string $plan = 'full', float $amount = 39000, int $tier = 1): Payment
    {
        $course = $this->nextCourse();

        $enrollment = Enrollment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $account->id, 'requested_tier' => $tier,
            'amount' => $amount, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => $plan, 'status' => 'pending', 'paid_at' => now(),
        ]);
    }

    private function verify(User $buyer, Consultant $account, string $plan = 'full', float $amount = 39000, int $tier = 1): Payment
    {
        $payment = $this->makePayment($buyer, $account, $plan, $amount, $tier);

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

    /** A downline the way one really comes to exist: seated as a student, then promoted. */
    private function downlineUnder(Consultant $sponsorAccount, string $name, int $tier = 1): Consultant
    {
        $user = $this->newStudent($name);
        $this->verify($user, $sponsorAccount, 'full', 39000, $tier);
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

    /** @return array<string, mixed> the whole `team` payload the page renders */
    private function teamFor(Consultant $viewer, ?Consultant $selectAccount = null): array
    {
        $query = $selectAccount ? ['account' => $selectAccount->id] : [];

        $response = $this->actingAs($viewer->user)
            ->get(route('consultant.groups.index', $query));

        $response->assertOk();

        return $response->viewData('team');
    }

    /**
     * One tab as rendered — 'all' | 1 | 2, the same three the page shows.
     *
     * @return array<string, mixed>
     */
    private function panel(Consultant $viewer, string|int $tab = 'all', ?Consultant $selectAccount = null): array
    {
        return $this->teamFor($viewer, $selectAccount)['legs'][$tab];
    }

    /** Every student name that tab would print, flattened across the tree. */
    private function namesIn(array $panel): array
    {
        return collect($panel['rows'])
            ->flatMap(fn ($r) => collect($r['students'])->pluck('name'))
            ->sort()->values()->all();
    }

    /** Every downline consultant that tab would print. The root row is not one. */
    private function peopleIn(array $panel): array
    {
        return collect($panel['rows'])
            ->reject(fn ($r) => $r['is_root'])
            ->pluck('name')
            ->sort()->values()->all();
    }

    // ----- what the owner asked for -----------------------------------------

    /** The headline: a downline registers a student, and both show up. */
    public function test_the_all_tab_lists_the_team_and_their_students(): void
    {
        $downline = $this->downlineUnder($this->firstAccount($this->sponsor), 'Kamal Perera');
        $this->verify($this->newStudent('Nimal Silva'), $this->firstAccount($downline));

        $panel = $this->panel($this->sponsor);

        $this->assertSame(1, $panel['people_total'], 'the downline consultant is a person in the team');
        $this->assertSame(['Kamal Perera'], $this->peopleIn($panel));
        $this->assertContains('Nimal Silva', $this->namesIn($panel), 'and their student underneath them');
    }

    /**
     * ⚠️ THE ONE THAT PINS THE WHOLE FEATURE. Each branch lands on the leg of
     * the account it hangs off, and All is the union.
     */
    public function test_each_branch_lands_on_the_service_group_of_the_account_it_hangs_off(): void
    {
        [$leg1, $leg2] = $this->sponsor->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $onLegOne = $this->downlineUnder($leg1, 'Leg One Consultant');
        $onLegTwo = $this->downlineUnder($leg2, 'Leg Two Consultant');

        $this->verify($this->newStudent('Student Of Leg One'), $this->firstAccount($onLegOne));
        $this->verify($this->newStudent('Student Of Leg Two'), $this->firstAccount($onLegTwo));

        $this->assertSame(['Leg One Consultant'], $this->peopleIn($this->panel($this->sponsor, 1)));
        $this->assertSame(['Leg Two Consultant'], $this->peopleIn($this->panel($this->sponsor, 2)));

        $this->assertContains('Student Of Leg One', $this->namesIn($this->panel($this->sponsor, 1)));
        $this->assertNotContains('Student Of Leg Two', $this->namesIn($this->panel($this->sponsor, 1)));

        // "saha main account ekatath e tika penwnn one" — All is the union.
        $this->assertSame(
            ['Leg One Consultant', 'Leg Two Consultant'],
            $this->peopleIn($this->panel($this->sponsor, 'all')),
        );
    }

    /** "SCN account ekat adalawa" — one account shows only the branch under it. */
    public function test_a_placement_account_shows_only_its_own_branch(): void
    {
        [$leg1, $leg2] = $this->sponsor->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $onLegOne = $this->downlineUnder($leg1, 'Under Leg One');
        $onLegTwo = $this->downlineUnder($leg2, 'Under Leg Two');

        $this->verify($this->newStudent('Belongs To Leg One'), $this->firstAccount($onLegOne));
        $this->verify($this->newStudent('Belongs To Leg Two'), $this->firstAccount($onLegTwo));

        $this->assertSame(['Under Leg One'], $this->peopleIn($this->panel($this->sponsor, 'all', $leg1)));
        $this->assertSame(['Under Leg Two'], $this->peopleIn($this->panel($this->sponsor, 'all', $leg2)));

        $this->assertContains('Belongs To Leg One', $this->namesIn($this->panel($this->sponsor, 'all', $leg1)));
        $this->assertNotContains('Belongs To Leg Two', $this->namesIn($this->panel($this->sponsor, 'all', $leg1)));
    }

    /** The whole team, not just the first level — and the depth is recorded. */
    public function test_it_reaches_every_level_below_and_records_the_depth(): void
    {
        $child = $this->downlineUnder($this->firstAccount($this->sponsor), 'First Level');
        $grandchild = $this->downlineUnder($this->firstAccount($child), 'Second Level');

        $this->verify($this->newStudent('Grandchild Student'), $this->firstAccount($grandchild));

        $panel = $this->panel($this->sponsor);

        $this->assertSame(2, $panel['people_total'], 'both levels are people in the team');
        $this->assertContains('Grandchild Student', $this->namesIn($panel));

        $levels = collect($panel['rows'])->reject(fn ($r) => $r['is_root'])->pluck('level', 'name');

        $this->assertSame(1, $levels['First Level']);
        $this->assertSame(2, $levels['Second Level'], 'nested, so the view can indent it');
    }

    /** Grouped by the person who registered them, not by SCN bucket. */
    public function test_students_are_filed_under_the_consultant_who_registered_them(): void
    {
        $one = $this->downlineUnder($this->firstAccount($this->sponsor), 'Consultant One');
        $two = $this->downlineUnder($this->firstAccount($this->sponsor), 'Consultant Two');

        $this->verify($this->newStudent('Belongs To One'), $this->firstAccount($one));
        $this->verify($this->newStudent('Belongs To Two'), $this->firstAccount($two));

        $byName = collect($this->panel($this->sponsor)['rows'])->keyBy('name');

        $this->assertContains('Belongs To One', collect($byName['Consultant One']['students'])->pluck('name'));
        $this->assertContains('Belongs To Two', collect($byName['Consultant Two']['students'])->pluck('name'));
        $this->assertNotContains('Belongs To Two', collect($byName['Consultant One']['students'])->pluck('name'));
    }

    /**
     * "eya add krapu aya" — the selected account's OWN registrations are the
     * level-0 row, so the tab is everyone I added plus everyone below them.
     */
    public function test_the_selected_accounts_own_students_are_the_root_row(): void
    {
        $this->verify($this->newStudent('My Own Student'), $this->firstAccount($this->sponsor));

        $panel = $this->panel($this->sponsor);
        $root = collect($panel['rows'])->firstWhere('is_root', true);

        $this->assertNotNull($root, 'there is always a row for this account itself');
        $this->assertContains('My Own Student', collect($root['students'])->pluck('name'));
        $this->assertSame(0, $root['level']);
    }

    // ----- the cases a seat query can NEVER cover ---------------------------

    /**
     * ⚠️ THE ONE THAT PROVES THIS IS NOT A SEAT QUERY. A half payment
     * deliberately seats NOBODY — not the registrant, not one ancestor.
     */
    public function test_a_half_paid_student_is_listed_even_though_nobody_is_seated(): void
    {
        $downline = $this->downlineUnder($this->firstAccount($this->sponsor), 'Half Payer');
        $student = $this->newStudent('Half Paid Student');

        $this->verify($student, $this->firstAccount($downline), 'half', 18000);

        $this->assertSame(
            0,
            GroupMember::where('user_id', $student->id)->count(),
            'guard: a half payment really does seat nobody, anywhere',
        );

        $this->assertContains('Half Paid Student', $this->namesIn($this->panel($this->sponsor)));
    }

    /** Registered but not yet verified — real, and invisible to every seat query. */
    public function test_an_unverified_registration_is_listed_with_its_real_status(): void
    {
        $downline = $this->downlineUnder($this->firstAccount($this->sponsor), 'Waiting Payer');
        $student = $this->newStudent('Unverified Student');

        $this->makePayment($student, $this->firstAccount($downline));

        $this->assertSame(0, GroupMember::where('user_id', $student->id)->count(), 'guard: nothing is seated yet');

        $panel = $this->panel($this->sponsor);

        $this->assertContains('Unverified Student', $this->namesIn($panel));
        $this->assertSame(
            'pending',
            collect($panel['rows'])->flatMap(fn ($r) => $r['students'])
                ->firstWhere('name', 'Unverified Student')['payment']->status,
            'shown as pending, not as if it were settled',
        );
    }

    /**
     * ⚠️ BOTH NUMBERS, IN THE SAME PANEL, ALLOWED TO DISAGREE. The tab counts
     * the team; `seated` counts what fills the leg toward LKR 7,500. Collapsing
     * them into one figure is what this assertion forbids.
     */
    public function test_a_panel_reports_the_team_and_the_seats_separately(): void
    {
        $downline = $this->downlineUnder($this->firstAccount($this->sponsor), 'Mixed Payer');

        $this->verify($this->newStudent('Fully Paid'), $this->firstAccount($downline));
        $this->verify($this->newStudent('Only Half Paid'), $this->firstAccount($downline), 'half', 18000);

        $panel = $this->panel($this->sponsor);

        $this->assertContains('Fully Paid', $this->namesIn($panel));
        $this->assertContains('Only Half Paid', $this->namesIn($panel), 'the team count includes the half payment');

        $seatedUserIds = GroupMember::whereIn(
            'group_id',
            Group::whereIn('consultant_id', $this->sponsor->accountIds())->select('id'),
        )->pluck('user_id');

        $this->assertTrue($seatedUserIds->contains(User::where('name', 'Fully Paid')->firstOrFail()->id));
        $this->assertFalse(
            $seatedUserIds->contains(User::where('name', 'Only Half Paid')->firstOrFail()->id),
            'the half payment seated nobody — so `seated` and the tab count MUST differ, by exactly this student',
        );

        $this->assertLessThan(
            $panel['students_total'],
            $panel['seated'],
            'and the panel reports both, not one standing in for the other',
        );
    }

    // ----- and it must not leak ---------------------------------------------

    /** Somebody else's team is nobody's business. */
    public function test_a_consultant_outside_the_downline_never_appears(): void
    {
        $mine = $this->downlineUnder($this->firstAccount($this->sponsor), 'My Downline');
        $this->verify($this->newStudent('My Downlines Student'), $this->firstAccount($mine));

        $strangerUser = $this->newStudent('Stranger');
        $strangerUser->syncRoles(['consultant']);
        $stranger = Consultant::create([
            'user_id' => $strangerUser->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);
        app(HiddenAccountService::class)->ensureFor($stranger);
        $this->verify($this->newStudent('Strangers Student'), $this->firstAccount($stranger->fresh()));

        $names = $this->namesIn($this->panel($this->sponsor));

        $this->assertContains('My Downlines Student', $names);
        $this->assertNotContains('Strangers Student', $names, 'another network must never leak in');
        $this->assertNotContains('Stranger', $this->peopleIn($this->panel($this->sponsor)));
    }

    /** The viewer is the root row, never one of the people below themselves. */
    public function test_the_viewer_is_not_listed_among_their_own_downline(): void
    {
        $this->downlineUnder($this->firstAccount($this->sponsor), 'Somebody Below');

        $panel = $this->panel($this->sponsor);

        $this->assertSame(1, $panel['people_total'], 'one person below, not two');
        $this->assertNotContains(
            $this->sponsor->scn_code,
            collect($panel['rows'])->reject(fn ($r) => $r['is_root'])->pluck('code'),
        );
    }

    // ----- the page itself ---------------------------------------------------

    /** The fourth tab is gone; its content is in the three that remain. */
    public function test_there_is_no_separate_team_students_tab(): void
    {
        $downline = $this->downlineUnder($this->firstAccount($this->sponsor), 'Rendered Consultant');
        $this->verify($this->newStudent('Rendered Student'), $this->firstAccount($downline));

        $this->actingAs($this->sponsor->user)
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertDontSee('Team Students')
            ->assertSee('All Students')
            ->assertSee('Service Group 1')
            ->assertSee('Service Group 2')
            ->assertSee('Rendered Consultant')
            ->assertSee('Rendered Student');
    }

    /** A consultant with nobody below them gets an honest empty state, not an error. */
    public function test_an_empty_team_renders(): void
    {
        $panel = $this->panel($this->sponsor);

        $this->assertSame(0, $panel['people_total']);
        $this->assertSame(0, $panel['students_total']);

        $this->actingAs($this->sponsor->user)
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertSee('All Students');
    }
}
