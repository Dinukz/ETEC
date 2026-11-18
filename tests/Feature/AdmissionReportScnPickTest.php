<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ "MARKETER CODE" BECAME "SCN ACCOUNT", AND A PICK LOADS EVERYTHING UNDER IT
 * (2026-10-01).
 *
 * *"Marketer Code wenuwata SCN account kiyla danna … SCN account ekak select
 * karoth ekata yatathe inn okkom account tika load wenn one — student and
 * consultant okkom ekat yatathe inne e widiyt."*
 *
 * Before, picking narrowed to that one person's own accounts and nobody under
 * them. Now:
 *   - a MAIN account loads the whole person and everyone below (the same set as
 *     reachableAccountIds());
 *   - a PLACEMENT account loads only the branch hanging off that account;
 *   - the rows come in tree order, indented, so who-is-under-whom reads down
 *     the page;
 *   - and none of it can widen what a consultant was already allowed to see.
 *
 * The tree:   A ─(SG1 account)─ B ─(B's SG1 account)─ C
 *               └(SG2 account)─ D            and a stranger S, nobody's.
 */
class AdmissionReportScnPickTest extends TestCase
{
    use RefreshDatabase;

    private Consultant $a;

    private Consultant $b;

    private Consultant $c;

    private Consultant $d;

    private Consultant $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        $this->a = $this->person('SCN-30100', null);
        $this->b = $this->person('SCN-30200', $this->leg($this->a, 1));
        $this->c = $this->person('SCN-30300', $this->leg($this->b, 1));
        $this->d = $this->person('SCN-30400', $this->leg($this->a, 2));
        $this->s = $this->person('SCN-39900', null);

        foreach ([$this->a, $this->b, $this->c, $this->d, $this->s] as $person) {
            $this->registerStudent($this->leg($person, 1));
        }
    }

    private function person(string $code, ?Consultant $under): Consultant
    {
        $user = User::factory()->create();
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $under?->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => $code,
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    /** The person's placement account for Service Group $n. */
    private function leg(Consultant $person, int $n): Consultant
    {
        return $person->hiddenAccounts()->orderBy('id')->get()[$n - 1];
    }

    private function registerStudent(Consultant $account): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'consultant_id' => $account->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => 1,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    /** @return array<int> account ids in the order the report lists them */
    private function rowIds(User $viewer, array $query): array
    {
        return $this->actingAs($viewer)
            ->get(route('reports.admissions', $query))
            ->assertOk()
            ->viewData('rows')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function idsOf(Consultant ...$people): array
    {
        return collect($people)->flatMap(fn (Consultant $p) => $p->accountIds())->map(fn ($id) => (int) $id)->all();
    }

    // ------------------------------------------------------------------------

    public function test_a_main_pick_is_the_same_set_as_reachable_account_ids(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map('intval', $this->a->reachableAccountIds()),
            Consultant::subtreeAccountIds($this->a->accountIds()),
        );
    }

    public function test_picking_a_main_loads_everyone_under_it(): void
    {
        $ids = $this->rowIds($this->admin(), ['marketer' => $this->a->id]);

        foreach ([$this->a, $this->b, $this->c, $this->d] as $person) {
            $this->assertContains($person->id, $ids, $person->scn_code.' is under A');
            $this->assertContains($this->leg($person, 1)->id, $ids, $person->scn_code.' SG1 holds a student');
        }

        $this->assertEmpty(array_intersect($ids, $this->idsOf($this->s)), 'the stranger is not under A');
    }

    public function test_picking_a_placement_account_loads_only_its_branch(): void
    {
        $ids = $this->rowIds($this->admin(), ['marketer' => $this->leg($this->a, 1)->id]);

        $this->assertContains($this->leg($this->a, 1)->id, $ids);
        $this->assertContains($this->b->id, $ids);
        $this->assertContains($this->c->id, $ids);

        $this->assertNotContains($this->a->id, $ids, 'the main is above the picked account');
        $this->assertNotContains($this->d->id, $ids, 'D hangs off the OTHER Service Group');
        $this->assertNotContains($this->leg($this->d, 1)->id, $ids);
    }

    public function test_rows_come_in_tree_order_with_their_level(): void
    {
        $response = $this->actingAs($this->admin())->get(route('reports.admissions', ['marketer' => $this->a->id]));
        $rows = $response->viewData('rows')->values();
        $at = fn (Consultant $c) => $rows->search(fn ($r) => (int) $r->id === (int) $c->id);

        $this->assertSame(0, $at($this->a), 'the picked account comes first');
        $this->assertLessThan($at($this->c), $at($this->b), 'B before the person under B');
        $this->assertLessThan($at($this->d), $at($this->c), 'B\'s whole branch before the next branch');

        $this->assertSame(0, $rows[$at($this->a)]->tree_level);
        $this->assertSame(1, $rows[$at($this->b)]->tree_level);
        $this->assertSame(2, $rows[$at($this->c)]->tree_level);
        $this->assertSame(1, $rows[$at($this->d)]->tree_level);
    }

    /** Without a pick the page is exactly as before: most students first, no levels. */
    public function test_without_a_pick_nothing_changes(): void
    {
        $rows = $this->actingAs($this->admin())->get(route('reports.admissions'))->viewData('rows');

        $this->assertTrue($rows->every(fn ($r) => $r->tree_level === null));
        $counts = $rows->pluck('student_count')->all();
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts);
    }

    /**
     * A filter must not orphan a branch. M registered nobody, so "min 1" drops
     * every one of M's rows — but N, under M, has a student, so M stays as the
     * row N hangs under.
     */
    public function test_a_filtered_out_row_stays_while_someone_under_it_is_shown(): void
    {
        $m = $this->person('SCN-30500', $this->leg($this->d, 1));
        $n = $this->person('SCN-30600', $this->leg($m, 1));
        $this->registerStudent($this->leg($n, 1));

        $ids = $this->rowIds($this->admin(), ['marketer' => $this->a->id, 'min' => 1]);

        $this->assertContains($this->leg($n, 1)->id, $ids, 'N\'s account has a student');
        $this->assertContains($m->id, $ids, 'M has no students of its own but N is under M');
        $this->assertNotContains($this->leg($m, 1)->id, $ids, 'M\'s empty placement accounts still go');
    }

    /** ⚠️ THE BOUNDARY. A consultant picking someone ABOVE them sees only their own part. */
    public function test_a_consultants_pick_never_reaches_outside_their_network(): void
    {
        $this->b->forceFill(['page_access' => json_encode(['admissions'])])->save();

        $ids = $this->rowIds($this->b->user, ['marketer' => $this->a->id]);

        $this->assertEmpty(array_intersect($ids, $this->idsOf($this->a, $this->d, $this->s)));
        $this->assertContains($this->c->id, $ids);
    }

    public function test_a_consultant_can_pick_a_placement_account_in_their_network(): void
    {
        $this->b->forceFill(['page_access' => json_encode(['admissions'])])->save();

        $options = $this->actingAs($this->b->user)->get(route('reports.admissions'))->viewData('marketers');

        $this->assertContains($this->leg($this->c, 1)->id, $options->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotContains($this->a->id, $options->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_labels_say_scn_account(): void
    {
        $this->actingAs($this->admin())->get(route('reports.admissions'))
            ->assertSee('SCN Account')
            ->assertSee('Exclude SCN Accounts')
            ->assertDontSee('Marketer Code');
    }

    public function test_the_csv_carries_the_level_when_an_account_is_picked(): void
    {
        $csv = $this->actingAs($this->admin())
            ->get(route('reports.admissions', ['marketer' => $this->a->id, 'export' => 'csv']))
            ->streamedContent();

        $this->assertStringStartsWith('"SCN Account",', ltrim($csv, "\xEF\xBB\xBF"));
        $this->assertStringContainsString('Level', strtok(ltrim($csv, "\xEF\xBB\xBF"), "\n"));
    }
}
