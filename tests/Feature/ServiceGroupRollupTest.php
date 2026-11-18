<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A placement account IS one of the owner's Service Groups, so everything it
 * earns rolls up to THAT leg — whatever tier the row sits on inside it.
 *
 * Owner's worked example (2026-08-21), with SCN-00127 main + SCN-00128 +
 * SCN-00129: "SCN 127 eke Service group 2 eka yatathe thama SCN 129 thiyenne,
 * ethkot eken dana ewa ynn one Service group 2 ekata... SCN 129 service group
 * 1 dammath 2 dammath ynn one SCN 127 eke service group 2 ekata."
 *
 * The headline used to GROUP BY the raw `tier` column across all of a person's
 * accounts, so a tier-1 row earned on the second placement account landed in
 * the owner's Service Group 1 — the Main row read SG1 2,500 / SG2 0 when the
 * only earnings were on the account that IS Service Group 2.
 */
class ServiceGroupRollupTest extends TestCase
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

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    /** Register a student on $account, seated on $leg INSIDE that account. */
    private function addStudent(Consultant $account, int $leg): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        // The headline counts APPROVED money only.
        foreach (Commission::where('status', 'pending')->get() as $c) {
            $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
                ->patch(route('commissions.approve', $c));
        }
    }

    private function headline(User $as): array
    {
        $response = $this->actingAs($as)->get(route('consultant.groups.index'));
        $response->assertOk();

        $totals = $response->viewData('tierTotals');

        return [
            1 => (float) ($totals[1]->total ?? 0),
            2 => (float) ($totals[2]->total ?? 0),
        ];
    }

    /** ⚠️ The reported bug, exactly. */
    public function test_earnings_on_the_second_placement_account_roll_up_to_service_group_2(): void
    {
        $consultantUser = $this->consultantUser();
        $second = $consultantUser->consultant->hiddenAccounts()->orderBy('id')->get()[1];

        // Seated on that account's OWN Service Group 1 — the tier deliberately
        // disagrees with the leg the account represents.
        $this->addStudent($second, 1);

        $totals = $this->headline($consultantUser);

        $this->assertSame(
            (float) self::SLOT,
            $totals[2],
            'The second placement account IS Service Group 2, so its earnings roll up there.',
        );
        $this->assertSame(
            0.0,
            $totals[1],
            'Service Group 1 must stay empty — crediting it is the reported error.',
        );
    }

    /** ...and the mirror case for the first placement account. */
    public function test_earnings_on_the_first_placement_account_roll_up_to_service_group_1(): void
    {
        $consultantUser = $this->consultantUser();
        $first = $consultantUser->consultant->hiddenAccounts()->orderBy('id')->first();

        // Seated on that account's own Service Group 2 — again disagreeing.
        $this->addStudent($first, 2);

        $totals = $this->headline($consultantUser);

        $this->assertSame((float) self::SLOT, $totals[1], 'The first placement account IS Service Group 1.');
        $this->assertSame(0.0, $totals[2]);
    }

    /**
     * A MAIN account represents no leg, so its own rows keep their own tier —
     * that is where a student actually chose to sit.
     */
    public function test_a_main_accounts_own_rows_keep_their_own_tier(): void
    {
        $consultantUser = $this->consultantUser();

        $this->addStudent($consultantUser->consultant, 2);

        $totals = $this->headline($consultantUser);

        $this->assertSame((float) self::SLOT, $totals[2], 'A main account has no leg identity of its own.');
        $this->assertSame(0.0, $totals[1]);
    }

    /**
     * ⚠️ A downline's 2,500 lands on the owner's MAIN account, so the placement
     * account it arrived through read as empty — "101 penwan na, eka main eke
     * penwanne... dekem penwnn one" (2026-08-21).
     *
     * It is the SAME money, surfaced on the account that IS that leg, not a
     * second amount: the row's own flush balance and this figure are labelled
     * differently and are not added together anywhere.
     */
    public function test_a_placement_accounts_row_shows_the_leg_it_represents(): void
    {
        $consultantUser = $this->consultantUser();
        $sponsor = $consultantUser->consultant;
        $first = $sponsor->hiddenAccounts()->orderBy('id')->first();

        // A downline consultant sponsored through the FIRST placement account,
        // which IS Service Group 1.
        $downlineUser = User::factory()->create();
        Student::create([
            'user_id' => $downlineUser->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => now(),
        ]);
        $downlineUser->syncRoles(['consultant']);
        $downline = Consultant::create([
            'user_id' => $downlineUser->id,
            'parent_consultant_id' => $first->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);
        app(\App\Services\HiddenAccountService::class)->ensureFor($downline);

        // The downline registers their own student — this is what climbs.
        $this->addStudent($downline->fresh()->hiddenAccounts()->first(), 1);

        $response = $this->actingAs($consultantUser)->get(route('consultant.groups.index'));
        $response->assertOk();

        $rows = collect($response->viewData('accountRows'));
        $firstRow = $rows->firstWhere('id', $first->id);

        $this->assertSame(1, $firstRow['represents_leg'], 'The first placement account IS Service Group 1.');
        $this->assertSame(
            (float) self::SLOT,
            $firstRow['represents_leg_total'],
            'The downline 2,500 that climbed through this branch is visible on its own row, not only on Main.',
        );

        $response->assertSee('This account IS Service Group 1');
    }

    /** Both sides together, which is what makes a pair balance. */
    public function test_the_two_placement_accounts_fill_opposite_sides(): void
    {
        $consultantUser = $this->consultantUser();
        $accounts = $consultantUser->consultant->hiddenAccounts()->orderBy('id')->get();

        $this->addStudent($accounts[0], 2); // first account -> leg 1
        $this->addStudent($accounts[1], 1); // second account -> leg 2

        $totals = $this->headline($consultantUser);

        $this->assertSame((float) self::SLOT, $totals[1]);
        $this->assertSame((float) self::SLOT, $totals[2]);
    }
}
