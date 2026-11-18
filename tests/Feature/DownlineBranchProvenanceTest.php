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
 * ⚠️ TWO WALKS OF THE SAME TREE MUST NEVER DISAGREE ABOUT WHO IS IN IT.
 *
 * Consultant::downlineIdsBelow() is a SECURITY BOUNDARY: it feeds downlineIds()
 * -> reachableAccountIds() -> the $reachableIds that decide which accounts a
 * consultant is allowed to open on the Service Groups page. downlineBranchesBelow()
 * (2026-09-07) walks the same tree keeping the branch each person came down, so
 * the tabs can file them under Service Group 1 or 2.
 *
 * They were deliberately NOT merged — putting "who am I allowed to see" on new
 * display code buys nothing. The price of that decision is that they can drift,
 * and drift in the direction of the branch walk seeing MORE than the boundary
 * allows would be a leak. This file is the price being paid.
 */
class DownlineBranchProvenanceTest extends TestCase
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

    private function nextCourse(): Course
    {
        $template = Course::where('status', 'published')->firstOrFail();
        $n = Course::count() + 1;

        return Course::create([
            'title' => 'Provenance Course '.$n,
            'slug' => 'provenance-course-'.$n.'-'.Str::random(6),
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

    private function downlineUnder(Consultant $sponsorAccount, string $name, int $tier = 1): Consultant
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

    // ------------------------------------------------------------------------

    /** ⚠️ THE INVARIANT. Same tree, same people, whichever walk asks. */
    public function test_both_walks_agree_on_membership(): void
    {
        [$leg1, $leg2] = $this->sponsor->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $a = $this->downlineUnder($leg1, 'Branch A');
        $b = $this->downlineUnder($leg2, 'Branch B');
        $deep = $this->downlineUnder($this->firstAccount($a), 'Deep Under A');
        $this->downlineUnder($this->firstAccount($deep), 'Deeper Still');
        $this->downlineUnder($this->firstAccount($b), 'Under B');

        $roots = $this->sponsor->accountIds();

        $flat = Consultant::downlineIdsBelow($roots, [$this->sponsor->id]);
        $branched = array_keys(Consultant::downlineBranchesBelow($roots, [$this->sponsor->id]));

        sort($flat);
        sort($branched);

        $this->assertSame($flat, $branched);
        $this->assertCount(5, $branched, 'guard: the fixture really is five people deep and wide');
    }

    /** And they agree when rooted at ONE placement account, not the whole person. */
    public function test_both_walks_agree_when_rooted_at_a_single_account(): void
    {
        [$leg1, $leg2] = $this->sponsor->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $a = $this->downlineUnder($leg1, 'Only Under Leg One');
        $this->downlineUnder($this->firstAccount($a), 'Below A');
        $this->downlineUnder($leg2, 'Only Under Leg Two');

        $flat = Consultant::downlineIdsBelow([$leg1->id]);
        $branched = array_keys(Consultant::downlineBranchesBelow([$leg1->id]));

        sort($flat);
        sort($branched);

        $this->assertSame($flat, $branched);
        $this->assertCount(2, $branched, 'the leg-2 branch is not in a leg-1 walk');
    }

    /** The whole reason the second walk exists: it knows which root each person came through. */
    public function test_it_records_the_branch_each_person_came_down(): void
    {
        [$leg1, $leg2] = $this->sponsor->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $a = $this->downlineUnder($leg1, 'Top Of Leg One');
        $deep = $this->downlineUnder($this->firstAccount($a), 'Deep In Leg One');
        $b = $this->downlineUnder($leg2, 'Top Of Leg Two');

        $branches = Consultant::downlineBranchesBelow($this->sponsor->accountIds(), [$this->sponsor->id]);

        $this->assertSame($leg1->id, $branches[$a->id]['root_account_id']);
        $this->assertSame($leg1->id, $branches[$deep->id]['root_account_id'], 'a descendant inherits its branch root');
        $this->assertSame($leg2->id, $branches[$b->id]['root_account_id']);

        $this->assertSame(1, $branches[$a->id]['level']);
        $this->assertSame(2, $branches[$deep->id]['level'], 'and its depth below the selected account');
        $this->assertSame($a->id, $branches[$deep->id]['parent'], 'nested under the person, not the SCN bucket');
        $this->assertNull($branches[$a->id]['parent'], 'a level-1 person hangs off the selected account itself');
    }

    /** ⚠️ Hand-editable data. A cycle must terminate, not hang the page. */
    public function test_a_sponsorship_cycle_terminates(): void
    {
        $a = $this->downlineUnder($this->firstAccount($this->sponsor), 'Cycle A');
        $b = $this->downlineUnder($this->firstAccount($a), 'Cycle B');

        // Point A back at B's account — a loop no UI can create, but an admin
        // fixing data by hand can.
        Consultant::whereKey($a->id)->update(['parent_consultant_id' => $this->firstAccount($b)->id]);

        $branches = Consultant::downlineBranchesBelow([$this->firstAccount($b)->id]);

        $this->assertArrayHasKey($a->id, $branches);
        $this->assertLessThanOrEqual(50, max(array_column($branches, 'level')));
    }

    /** The depth cap is real and reported honestly rather than silently obeyed. */
    public function test_the_depth_cap_stops_the_walk(): void
    {
        $node = $this->downlineUnder($this->firstAccount($this->sponsor), 'Chain 1');

        for ($i = 2; $i <= 4; $i++) {
            $node = $this->downlineUnder($this->firstAccount($node), 'Chain '.$i);
        }

        $shallow = Consultant::downlineBranchesBelow($this->sponsor->accountIds(), [$this->sponsor->id], maxDepth: 2);

        $this->assertSame([1, 2], collect($shallow)->pluck('level')->unique()->sort()->values()->all());
        $this->assertCount(2, $shallow, 'only the first two levels came back');
    }
}
