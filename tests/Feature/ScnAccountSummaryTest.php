<?php

namespace Tests\Feature;

use App\Livewire\ScnAccountSummary;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The SCN account summary popup on Direct Registrations.
 *
 * Asked for 2026-09-10: an info button on every row that answers, in one
 * place, "meka ebuwaham wenne e SCN account ekat adala detail okkom popup ekak
 * widiyt penwan eka — SCN account kiyak thiyend, e account monad, ewata adala
 * service group tika, mona courses da buy kral, admin direct add krapu kenk da
 * naththn wena SCN account ehekind awe, placement eka kohend, downline ekak
 * thiyeno nm eka."
 *
 * The tests here are the four ways this particular screen can be wrong:
 * reading somebody else's network, doing stage 2's work on open, 500ing on an
 * orphan row, and taking the leg from the wrong source.
 */
class ScnAccountSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
    }

    private function consultant(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** A second, unrelated consultant — nobody's upline and nobody's downline. */
    private function strangerConsultant(): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $account = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-9'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureGroup($account);

        return $account->fresh();
    }

    /** A student row belonging to $sponsor. */
    private function studentUnder(?Consultant $sponsor): Student
    {
        $user = User::factory()->create();
        $user->assignRole('student');

        return Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN-'.Str::random(6),
            'consultant_id' => $sponsor?->id,
            'status' => 'active',
        ]);
    }

    /**
     * The consultant's OWN student row.
     *
     * The seeder promotes a user to consultant without leaving a Student row
     * behind, and the popup is opened from a student row — so the placement
     * tests need one pointing at the consultant themself.
     */
    private function selfStudent(Consultant $c): Student
    {
        return Student::firstOrCreate(
            ['user_id' => $c->user_id],
            [
                'student_code' => 'SRN-'.Str::random(6),
                'consultant_id' => $c->id,
                'status' => 'active',
            ],
        );
    }

    /** Buy a course, placing the new set of three under $target's Service Group $tier. */
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

    // ----- who may open it ---------------------------------------------------

    /**
     * ⚠️ THE ONE THAT MATTERS. Livewire actions arrive on Livewire's own update
     * endpoint, so /consultant/students' role middleware never runs for them.
     * Without the check inside the component, any logged-in consultant could
     * post a student id and read a stranger's whole network.
     */
    public function test_a_consultant_cannot_open_a_student_outside_their_network(): void
    {
        $stranger = $this->strangerConsultant();
        $theirStudent = $this->studentUnder($stranger);

        Livewire::actingAs($this->consultant()->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $theirStudent->id)
            ->assertForbidden();
    }

    public function test_a_consultant_can_open_their_own_student(): void
    {
        $mine = $this->studentUnder($this->consultant());

        Livewire::actingAs($this->consultant()->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $mine->id)
            ->assertOk()
            ->assertSet('studentId', $mine->id);
    }

    /** Stage 2 is behind the same check, not just stage 1. */
    public function test_stage_two_is_authorised_too(): void
    {
        $stranger = $this->strangerConsultant();
        $theirStudent = $this->studentUnder($stranger);

        $component = Livewire::actingAs($this->consultant()->user)
            ->test(ScnAccountSummary::class);

        // Reach past open() the way a hand-made request would: set the prop,
        // then call the second stage directly.
        $component->set('studentId', $theirStudent->id)
            ->call('loadDeep')
            ->assertForbidden();
    }

    // ----- the two stages ----------------------------------------------------

    /**
     * ⚠️ THE COST GUARD. Stage 2 walks the downline; opening the popup must not
     * do it. If this ever goes green by accident the page cost stops being
     * bounded.
     */
    public function test_opening_does_not_do_stage_twos_work(): void
    {
        $mine = $this->studentUnder($this->consultant());

        $component = Livewire::actingAs($this->consultant()->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $mine->id);

        $component->assertSet('deepLoaded', false)
            ->assertSet('deep', []);

        $summary = $component->get('summary');

        $this->assertArrayHasKey('accounts', $summary, 'stage 1 did load');
        $this->assertArrayNotHasKey('placements', $summary, 'placement belongs to stage 2');
        $this->assertArrayNotHasKey('downline', $summary, 'the downline walk belongs to stage 2');
    }

    public function test_stage_two_loads_on_demand(): void
    {
        $c = $this->consultant();
        $me = $this->selfStudent($c);

        $component = Livewire::actingAs($c->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $me->id)
            ->call('loadDeep');

        $component->assertSet('deepLoaded', true);

        $this->assertArrayHasKey('placements', $component->get('deep'));
    }

    // ----- the orphan row ----------------------------------------------------

    /**
     * ⚠️ A Student can outlive its User — _table.blade.php carries the same
     * warning. One orphan row must not take the popup out.
     */
    public function test_a_student_whose_user_is_gone_does_not_blow_up(): void
    {
        $mine = $this->studentUnder($this->consultant());

        User::whereKey($mine->user_id)->forceDelete();

        Livewire::actingAs($this->consultant()->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $mine->id)
            ->assertOk();
    }

    // ----- placement ---------------------------------------------------------

    /**
     * ⚠️ THE GUARD THAT ACTUALLY BITES. The leg must come from
     * AccountPlacementMap's owner_group, which resolves position by CLIMBING —
     * NOT from Consultant::serviceGroupLeg(), which answers the account's
     * ordinal inside its own set. For a bought set the two disagree, and
     * substituting one for the other is the 2026-08-23 cross.
     *
     * Here a set of three is bought under the person's SECOND leg. The set's
     * own first leg is the 4th hidden account overall, so serviceGroupLeg()
     * calls it leg 1 — while it in fact belongs, with the whole set, to
     * Service Group 2.
     */
    public function test_the_leg_shown_comes_from_owner_group_not_the_ordinal(): void
    {
        $c = $this->consultant();
        $secondLeg = $c->hiddenAccounts()->orderBy('id')->get()[1];

        $this->buyPlacedUnder($c, $secondLeg, 2);

        $accounts = $c->fresh()->hiddenAccounts()->orderBy('id')->get();
        $setLeg1 = $accounts[3];

        // Guard on the guard: the two sources must really disagree here, or
        // this test proves nothing.
        $this->assertSame(1, $setLeg1->serviceGroupLeg(), 'ordinal says leg 1');

        $me = $this->selfStudent($c);

        $deep = Livewire::actingAs($c->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $me->id)
            ->call('loadDeep')
            ->get('deep');

        $shown = collect($deep['placements'])->firstWhere('code', $setLeg1->scn_code);

        $this->assertNotNull($shown, 'the account is listed');
        $this->assertSame(2, $shown['owner_group'], 'but the popup shows Service Group 2');
    }

    /**
     * ⚠️ A bought-foreign set is a WARNING, not a parent. The purchase was
     * attributed to an account this person does not hold, so drawing it as
     * their placement would state something untrue.
     */
    public function test_a_foreign_placement_is_flagged_rather_than_drawn_as_a_parent(): void
    {
        $c = $this->consultant();
        $stranger = $this->strangerConsultant();

        $this->buyPlacedUnder($c, $stranger, 1);

        $me = $this->selfStudent($c);

        $deep = Livewire::actingAs($c->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $me->id)
            ->call('loadDeep')
            ->get('deep');

        $flagged = collect($deep['placements'])->where('is_warning', true);

        $this->assertTrue($flagged->isNotEmpty(), 'the foreign placement is flagged');
        $this->assertSame('bought-foreign', $flagged->first()['kind']);
    }

    // ----- the cheap sections ------------------------------------------------

    public function test_a_student_added_by_an_admin_is_reported_as_admin_direct(): void
    {
        // No sponsoring consultant on the row is what an admin-created student
        // looks like — there is no created_by column to read.
        $orphan = $this->studentUnder(null);

        // Give the viewer a reason to reach it: the payment carries the account.
        $course = Course::where('status', 'published')->firstOrFail();

        Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $orphan->user_id,
            'course_id' => $course->id,
            'consultant_id' => $this->consultant()->id,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
        ]);

        $summary = Livewire::actingAs($this->consultant()->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $orphan->id)
            ->get('summary');

        $this->assertSame('admin', $summary['origin']['kind']);
    }

    public function test_the_summary_names_the_sponsoring_account(): void
    {
        $c = $this->consultant();
        $mine = $this->studentUnder($c);

        $summary = Livewire::actingAs($c->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $mine->id)
            ->get('summary');

        $this->assertSame('sponsored', $summary['origin']['kind']);
        $this->assertSame($c->scn_code, $summary['origin']['code']);
    }

    public function test_the_summary_lists_the_courses_bought(): void
    {
        $c = $this->consultant();
        $mine = $this->studentUnder($c);
        $course = Course::where('status', 'published')->firstOrFail();

        Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $mine->user_id,
            'course_id' => $course->id,
            'consultant_id' => $c->id,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'verified',
        ]);

        $summary = Livewire::actingAs($c->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $mine->id)
            ->get('summary');

        $this->assertSame([$course->title], array_column($summary['courses'], 'title'));
    }

    /** Closing must leave nothing behind for the next row to inherit. */
    public function test_closing_empties_the_popup(): void
    {
        $mine = $this->studentUnder($this->consultant());

        Livewire::actingAs($this->consultant()->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $mine->id)
            ->call('close')
            ->assertSet('studentId', null)
            ->assertSet('summary', [])
            ->assertSet('deep', []);
    }
}
