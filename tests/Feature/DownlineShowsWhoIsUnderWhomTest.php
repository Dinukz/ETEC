<?php

namespace Tests\Feature;

use App\Livewire\ScnAccountSummary;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Who is under whom" — the downline popup, and the SCN codes around it.
 *
 * Reported 2026-09-22 against the live site. The owner opened Navini Navodaya
 * (SCN-08116), saw "Nobody under this account yet", and read it against her
 * upline Kavidu's popup, which showed a downline five levels deep — so the
 * tree looked broken and he asked whether the 2,500 still climbs.
 *
 * The tree was right: every one of those people hangs off Naleen, who sits
 * BESIDE Navini on the same upline account, so none of them is hers. What was
 * wrong was the reading. The popup's one placement column printed
 * root_account_id — the branch the person descended from, which is always one
 * of the SUBJECT'S OWN accounts — for every row at every depth. A person five
 * levels down therefore read as if they sat directly under the subject.
 *
 * So this file pins three things the screen must say:
 *   - who each person was actually added under (one hop, their sponsor),
 *   - which of the subject's own legs the branch belongs to, kept separate,
 *   - and SCN codes, never the internal GRP-XXXXXX group key nobody can place.
 */
class DownlineShowsWhoIsUnderWhomTest extends TestCase
{
    use RefreshDatabase;

    private Consultant $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => 2500,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');

        $this->subject = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    // ----- fixtures ----------------------------------------------------------

    private function nextCourse(): Course
    {
        $template = Course::where('status', 'published')->firstOrFail();
        $n = Course::count() + 1;

        return Course::create([
            'title' => 'Tree Course '.$n,
            'slug' => 'tree-course-'.$n.'-'.Str::random(6),
            'description' => 'test',
            'price' => $template->price,
            'currency' => $template->currency,
            'status' => 'published',
            'lecturer_id' => $template->lecturer_id,
            'published_at' => now(),
        ]);
    }

    private function verify(User $buyer, Consultant $account, int $tier = 1): void
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
    }

    /** A real consultant placed under $sponsorAccount, with their own accounts. */
    private function personUnder(Consultant $sponsorAccount, string $name, int $tier = 1): Consultant
    {
        $user = User::factory()->create(['name' => $name]);

        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $this->verify($user, $sponsorAccount, $tier);
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-8'.random_int(1000, 9999),
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

    /**
     * The person's own student row — the popup opens from one.
     *
     * ⚠️ consultant_id IS SET EVEN WHEN THE ROW ALREADY EXISTS. personUnder()
     * leaves it null (they were registered by their upline, not by themselves),
     * and the popup's own guard reads students.consultant_id ∪ the payment's
     * account — so a firstOrCreate that skipped the update made the component
     * abort 403 on its own owner, which surfaces only as an unusable Livewire
     * snapshot on the NEXT call.
     */
    private function selfStudent(Consultant $c): Student
    {
        $student = Student::firstOrNew(['user_id' => $c->user_id]);

        $student->fill([
            'student_code' => $student->student_code ?: 'SRN-'.Str::random(6),
            'consultant_id' => $c->id,
            'status' => 'active',
        ])->save();

        return $student;
    }

    /** @return array<string, mixed> the stage-2 payload for $viewer's own popup */
    private function deepFor(Consultant $viewer): array
    {
        return Livewire::actingAs($viewer->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $this->selfStudent($viewer)->id)
            ->call('loadDeep')
            ->get('deep');
    }

    // ----- who is under whom -------------------------------------------------

    /**
     * ⚠️ THE REPORTED BUG. A level-2 person is under the level-1 person, not
     * under the account the branch started at.
     */
    public function test_a_deeper_member_is_shown_under_their_real_sponsor(): void
    {
        $leg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();

        $middle = $this->personUnder($leg1, 'Middle Person');
        $middleAccount = $this->firstAccount($middle);
        $deepPerson = $this->personUnder($middleAccount, 'Deep Person');

        $rows = collect($this->deepFor($this->subject)['downline']);

        $shown = $rows->firstWhere('code', $deepPerson->scn_code);

        $this->assertNotNull($shown, 'the deep person is listed');
        $this->assertSame(2, $shown['level']);
        $this->assertSame(
            $middleAccount->scn_code ?: $middleAccount->consultant_code,
            $shown['under'],
            'shown under the account that actually sponsored them',
        );
        $this->assertSame('Middle Person', $shown['under_name']);

        // The bug in one assertion: the subject's own account is NOT the answer
        // to "who are they under" for anybody past level 1.
        $this->assertNotSame($leg1->scn_code, $shown['under']);
    }

    /** A level-1 person IS under the subject's own account — same column, same rule. */
    public function test_a_direct_member_is_shown_under_the_subjects_own_account(): void
    {
        $leg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();
        $direct = $this->personUnder($leg1, 'Direct Person');

        $shown = collect($this->deepFor($this->subject)['downline'])->firstWhere('code', $direct->scn_code);

        $this->assertNotNull($shown);
        $this->assertSame(1, $shown['level']);
        $this->assertSame($leg1->scn_code ?: $leg1->consultant_code, $shown['under']);
    }

    /** The branch — which of the subject's legs the whole line hangs off — is kept. */
    public function test_the_branch_column_still_names_the_subjects_own_leg(): void
    {
        [$leg1, $leg2] = $this->subject->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $onTwo = $this->personUnder($leg2, 'Second Leg Person', 2);
        $belowThem = $this->personUnder($this->firstAccount($onTwo), 'Below Second Leg', 2);

        $rows = collect($this->deepFor($this->subject)['downline']);

        foreach ([$onTwo, $belowThem] as $person) {
            $shown = $rows->firstWhere('code', $person->scn_code);

            $this->assertNotNull($shown);
            $this->assertSame(
                $leg2->scn_code ?: $leg2->consultant_code,
                $shown['branch'],
                'the branch is the subject account the line descends from',
            );
            $this->assertNotSame($leg1->scn_code, $shown['branch']);
        }
    }

    /**
     * Somebody beside you on the same upline account is NOT in your downline —
     * exactly Navini's case, and the popup must say so in words rather than
     * leaving "nobody" to be read as a fault.
     */
    public function test_a_person_with_nobody_below_them_is_told_why_in_full(): void
    {
        $leg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();

        $navini = $this->personUnder($leg1, 'Navini');
        $naleen = $this->personUnder($leg1, 'Naleen');
        $this->personUnder($this->firstAccount($naleen), 'Under Naleen');

        $hers = Livewire::actingAs($navini->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $this->selfStudent($navini)->id)
            ->call('loadDeep');

        $this->assertSame([], $hers->get('deep')['downline'], 'nobody is under her');
        $hers->assertSee('Nobody has been added under this person yet');

        // ⚠️ The person beside her is checked in its own test, not here: two
        // Livewire component instances acting as two different users inside one
        // test method leave the second with an unusable snapshot.
        $this->assertSame($naleen->parent_consultant_id, $navini->parent_consultant_id, 'they sit on the same account');
    }

    /**
     * The other half of the same fact: the line the owner saw belongs to the
     * person BESIDE her, and they still have it.
     */
    public function test_the_person_beside_them_keeps_their_own_line(): void
    {
        $leg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->personUnder($leg1, 'Navini');
        $naleen = $this->personUnder($leg1, 'Naleen');
        $under = $this->personUnder($this->firstAccount($naleen), 'Under Naleen');

        $rows = collect($this->deepFor($naleen)['downline']);

        $this->assertCount(1, $rows);
        $this->assertSame($under->scn_code, $rows->first()['code']);
    }

    // ----- the people the tree cannot show -----------------------------------

    /**
     * ⚠️ THE 2026-09-23 REPORT. A person seated on your account while they are
     * still only a STUDENT has never been in the downline table — that table
     * walks parent_consultant_id, and a student has no consultants row at all.
     * Measured on the live data: 6,897 of 9,056 seats were invisible this way,
     * which is what made a working account read as "nobody under this account".
     */
    public function test_a_student_seated_on_the_account_is_listed(): void
    {
        $leg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();

        // A plain student: they buy, they are seated, they are never promoted.
        $buyer = User::factory()->create(['name' => 'Seated Student']);
        $buyer->assignRole('student');

        Student::create([
            'user_id' => $buyer->id, 'student_code' => 'SRN-SEATED1',
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $this->verify($buyer, $leg1, 1);

        $this->assertNull(
            Consultant::where('user_id', $buyer->id)->first(),
            'still only a student — the downline walk cannot see them',
        );

        $deep = $this->deepFor($this->subject);

        $seats = collect($deep['placed'])->where('name', 'Seated Student');

        $this->assertTrue($seats->isNotEmpty(), 'but the seat list does');
        $this->assertSame(['SRN-SEATED1'], $seats->pluck('code')->unique()->all());
        $this->assertSame([false], $seats->pluck('is_consultant')->unique()->all());

        // ⚠️ ONE PERSON, SEVERAL SEATS, AND THAT IS CORRECT. Slot income CLIMBS,
        // so a seat is written on every account the money passed through — the
        // account they were placed on AND the main above it. Each row is that
        // account's own earning, which is exactly what this list is for.
        $onLeg = $seats->firstWhere('account', $leg1->scn_code ?: $leg1->consultant_code);

        $this->assertNotNull($onLeg, 'listed on the account they were placed on');
        $this->assertSame(1, $onLeg['leg'], 'and on the Service Group they were seated on');

        $this->assertNotNull(
            $seats->firstWhere('account', $this->subject->scn_code),
            'and on the main, which earned from them too',
        );

        // The downline table still says nothing about them — the two lists
        // answer different questions and neither is a substitute.
        $this->assertSame([], collect($deep['downline'])->pluck('name')->all());
    }

    /** A promoted person is shown by their SCN, not by the SRN they started with. */
    public function test_a_promoted_person_is_seated_under_their_scn(): void
    {
        $leg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();
        $person = $this->personUnder($leg1, 'Promoted Person');

        $seat = collect($this->deepFor($this->subject)['placed'])->firstWhere('name', 'Promoted Person');

        $this->assertNotNull($seat);
        $this->assertSame($person->scn_code, $seat['code']);
        $this->assertTrue($seat['is_consultant']);
    }

    /** Nothing seated anywhere is said plainly rather than left blank. */
    public function test_an_account_with_no_seats_says_so(): void
    {
        $deep = $this->deepFor($this->subject);

        $this->assertSame([], $deep['placed']);
        $this->assertSame(0, $deep['placed_total']);
    }

    // ----- SCN codes, not GRP keys ------------------------------------------

    /**
     * ⚠️ NO GRP-XXXXXX ON SCREEN. *"group code eka samanaya kenkt theren na …
     * SCN number or name use krnn"* (2026-09-22).
     */
    public function test_the_service_groups_table_is_labelled_by_scn_account(): void
    {
        $component = Livewire::actingAs($this->subject->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $this->selfStudent($this->subject)->id);

        $groups = $component->get('summary')['groups'];

        $this->assertNotEmpty($groups);

        $codes = array_column($groups, 'code');

        foreach ($codes as $code) {
            $this->assertStringStartsNotWith('GRP', $code, 'the internal group key never reaches the screen');
        }

        // The main account's own group is the first row, and it is named after
        // the account the reader already knows.
        $this->assertSame($this->subject->scn_code, $codes[0]);
        $this->assertTrue($groups[0]['is_main']);

        $component->assertDontSee('GRP-');
    }

    // ----- the upline, in order (2026-09-27) ---------------------------------

    /** The stage-2 payload for $subject's popup, opened by $viewer. */
    private function deepOf(Consultant $subject, Consultant $viewer): array
    {
        return Livewire::actingAs($viewer->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $this->selfStudent($subject)->id)
            ->call('loadDeep')
            ->get('deep');
    }

    /**
     * ⚠️ THE REPORTED CASE. The popup named one account above the person and
     * stopped. It now lists every person above them, nearest first, one row
     * each, with that person's accounts the money passes through.
     */
    public function test_the_upline_is_listed_in_order_nearest_first(): void
    {
        $topSg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();
        $middle = $this->personUnder($topSg1, 'Middle Person');
        $deep = $this->personUnder($this->firstAccount($middle), 'Deep Person');

        $chain = $this->deepOf($deep, $this->subject)['upline_chain'];

        $this->assertSame(['Middle Person', $this->subject->user->name], array_column(array_slice($chain, 0, 2), 'name'));

        // Each person's row runs up to THEIR main — the step the old walks missed.
        $this->assertSame($middle->scn_code, $chain[0]['main'], 'the middle person\'s main is in their row');
        $this->assertSame($this->subject->scn_code, $chain[1]['main'], 'and so is the next person\'s');
        $this->assertContains($topSg1->scn_code, $chain[1]['codes'], 'reached through the account the middle person hangs off');
    }

    /**
     * ⚠️ NOTHING ABOVE THE VIEWER. A consultant sees the line up to and
     * including themself — never the people above them.
     */
    public function test_a_consultant_sees_the_upline_only_up_to_themself(): void
    {
        $topSg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();
        $middle = $this->personUnder($topSg1, 'Middle Person');
        $deep = $this->personUnder($this->firstAccount($middle), 'Deep Person');

        $chain = $this->deepOf($deep, $middle)['upline_chain'];

        $this->assertCount(1, $chain, 'only the viewer themself');
        $this->assertSame('Middle Person', $chain[0]['name']);
        $this->assertTrue($chain[0]['is_viewer']);
        $this->assertNotContains(
            $this->subject->scn_code,
            array_merge(...array_column($chain, 'codes')),
            'the viewer\'s own upline is never shown to them',
        );
    }

    /**
     * ⚠️ WHO IS ABOVE = WHO EARNS. The upline is read from the engine's own
     * climb, so every account on it is an account the engine credits.
     */
    public function test_the_upline_is_exactly_who_earns_from_this_person(): void
    {
        $topSg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();
        $middle = $this->personUnder($topSg1, 'Middle Person');
        $deep = $this->personUnder($this->firstAccount($middle), 'Deep Person');

        $shown = array_merge(...array_column($this->deepOf($deep, $this->subject)['upline_chain'], 'codes'));

        // A real student brought in on the deep person's main.
        $buyer = User::factory()->create();
        $buyer->assignRole('student');
        $this->verify($buyer, $deep, 1);

        $paymentId = Payment::where('user_id', $buyer->id)->value('id');

        $earned = \App\Models\Commission::where('payment_id', $paymentId)
            ->where('type', \App\Services\CommissionService::TYPE_SLOT)
            ->where('consultant_id', '!=', $deep->id)
            ->pluck('consultant_id');

        $earnedCodes = Consultant::whereIn('id', $earned)->pluck('scn_code')->filter()->sort()->values()->all();

        $shownSorted = $shown;
        sort($shownSorted);

        $this->assertSame($earnedCodes, $shownSorted, 'the upline on screen is the list of accounts that earned');
    }

    /** "Sponsoring account" no longer names one of the person's OWN accounts. */
    public function test_the_sponsor_line_never_names_the_persons_own_account(): void
    {
        $topSg1 = $this->subject->hiddenAccounts()->orderBy('id')->firstOrFail();
        $middle = $this->personUnder($topSg1, 'Middle Person');

        // Their own student row pointed at one of their OWN accounts — the
        // state that produced "Sponsoring account SCN-13242" for Lakshan.
        $own = $this->firstAccount($middle);
        $student = Student::where('user_id', $middle->user_id)->firstOrFail();
        $student->forceFill(['consultant_id' => $own->id])->save();

        $summary = Livewire::actingAs($this->subject->user)
            ->test(ScnAccountSummary::class)
            ->call('open', $student->id)
            ->get('summary');

        $this->assertSame($topSg1->scn_code, $summary['origin']['code'], 'the real sponsor');
        $this->assertNotSame($own->scn_code, $summary['origin']['code']);
    }

    // ----- the downline, in tree order ---------------------------------------

    /**
     * ⚠️ EACH PERSON FOLLOWED BY THEIR OWN DOWNLINE. Sorted level-then-code,
     * every level-2 person came before every level-3 one regardless of whose
     * they were.
     */
    public function test_the_downline_is_in_tree_order(): void
    {
        [$leg1, $leg2] = $this->subject->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $a = $this->personUnder($leg1, 'A Person');
        $b = $this->personUnder($leg2, 'B Person');
        $underA = $this->personUnder($this->firstAccount($a), 'Under A');

        // ⚠️ FIXED CODES, CHOSEN SO THE TWO ORDERS DISAGREE. With personUnder()'s
        // random codes the old level-then-code sort sometimes happened to put
        // "Under A" straight after A anyway, and this test passed with the old
        // sort put back. A < B < Under A by code means level-then-code gives
        // A, B, Under A — and only a real tree order gives A, Under A, B.
        $a->forceFill(['scn_code' => 'SCN-81000'])->save();
        $b->forceFill(['scn_code' => 'SCN-81001'])->save();
        $underA->forceFill(['scn_code' => 'SCN-89999'])->save();

        $names = array_column($this->deepFor($this->subject)['downline'], 'name');

        $this->assertSame(
            ['A Person', 'Under A', 'B Person'],
            array_values(array_intersect($names, ['A Person', 'Under A', 'B Person'])),
            'A, then everyone under A, then the next branch',
        );
    }
}
