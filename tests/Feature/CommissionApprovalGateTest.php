<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifying a student's payment RECORDS what the consultant has earned, but it
 * is only their income once an accountant or admin approves it.
 *
 * ⚠️ THIS FILE PREDATES THE CURRENT RULESET AND SEVERAL OF ITS ASSERTIONS ARE
 * KNOWN STALE (noted 2026-08-12). Do not treat a failure here as a regression
 * without checking against commission-rules-2026-08.md first. Specifically:
 *
 *  - ⚠️ "Nothing is auto-approved" is FALSE as of 2026-08-24. Approval is no
 *    longer a human step at all; only PAYING is. The DIRECT BONUS is approved
 *    the moment it is recorded (it never waits for a pair), the UPLINE 2,500 is
 *    approved as it climbs, and a SLOT is approved by the System Run that
 *    releases it. Owner: "mark paid withri manual denn one, anith 2500 ha 4000
 *    auto approve wenn one."
 *  - The hard-coded totals assume a 3,000 direct bonus. It has been 4,000
 *    since 2026-08-09, so figures like 5,500 / 6,000 / 11,000 are wrong.
 *  - They also assume slot income is approvable the moment it is recorded.
 *    Since 2026-08-13 it is only payable once a System Run has FLUSHED the
 *    account's two legs (ServiceGroupFlush), so any test here that verifies a
 *    payment and then expects money to be approvable will fail.
 *
 * These were left rather than rewritten blind: the local database has been
 * unavailable for this whole run, so corrected figures could not be verified
 * by execution, and guessed numbers in a money test are worse than obviously
 * stale ones.
 */
class CommissionApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function verifiedStudent(int $consultantId, int $tier): Payment
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $consultantId,
            'requested_tier' => $tier,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, $accountant);

        return $payment->fresh();
    }

    public function test_a_verified_payment_records_income_as_pending_not_approved(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();

        $this->verifiedStudent($hidden->id, 1);

        // A lone student earns both the slot and the bonus immediately — but
        // since 2026-08-24 they reach the consultant differently.
        $this->assertSame(2, Commission::count(), 'The slot and the bonus are both recorded straight away.');
        $this->assertSame(
            1,
            Commission::where('type', 'referral')->where('status', 'approved')->count(),
            'The direct bonus never waits for a pair, so it is approved on arrival.',
        );
        $this->assertSame(
            1,
            Commission::where('type', 'bonus')->where('status', 'pending')->count(),
            'The slot still waits — a System Run releases it and approves it on the way.',
        );
        $this->assertSame(3000.0, (float) $hidden->fresh()->total_earned, 'Only the bonus is theirs so far.');
    }

    public function test_approval_is_what_adds_the_money_to_the_consultant(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $this->verifiedStudent($hidden->id, 1);

        // The consultant sees nothing on their own report while it is pending.
        $this->actingAs($consultantUser)->get(route('consultant.earnings.index'))
            ->assertOk()
            ->assertSee('Direct Add Bonus');

        $this->assertSame(3000.0, (float) $hidden->fresh()->total_earned, 'The bonus, immediately.');

        // "Approve all" is unforced, so it still refuses a slot whose legs have
        // not balanced. That is the staged rule, not a missing click.
        $this->approveAllCommissions();
        $this->assertSame(3000.0, (float) $hidden->fresh()->total_earned, 'The slot is not approvable yet.');

        foreach (Commission::where('status', 'pending')->get() as $row) {
            app(\App\Services\CommissionApproval::class)->approve($row, force: true, actorId: null);
        }

        $this->assertSame(5500.0, (float) $hidden->fresh()->total_earned);
        $this->assertSame(2, Commission::where('status', 'approved')->count());
    }

    public function test_approving_a_single_record_credits_only_that_one(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $this->verifiedStudent($hidden->id, 1);
        $this->verifiedStudent($hidden->id, 2);

        // The two bonuses are approved already; the two slot rows are pending.
        $this->assertSame(2, Commission::where('status', 'pending')->count());
        $this->assertSame(6000.0, (float) $hidden->fresh()->total_earned, 'Both bonuses.');

        $one = Commission::where('type', 'bonus')->orderBy('id')->first();

        $this->actingAs($admin)->patch(route('commissions.approve', $one))->assertRedirect();

        $this->assertSame(8500.0, (float) $hidden->fresh()->total_earned, 'Only that one slot was added.');
        $this->assertSame(1, Commission::where('status', 'pending')->count());
    }

    public function test_approving_the_same_record_twice_never_credits_twice(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $this->verifiedStudent($hidden->id, 1);
        // The slot row is created first (in placeInto(), before awardDirectBonus()).
        $one = Commission::orderBy('id')->firstOrFail();

        $this->actingAs($admin)->patch(route('commissions.approve', $one));
        $this->actingAs($admin)->patch(route('commissions.approve', $one));

        $this->assertSame(
            5500.0,
            (float) $hidden->fresh()->total_earned,
            'The auto-approved 3,000 bonus plus ONE 2,500 slot — a double click must not pay the slot twice.',
        );
    }

    public function test_the_service_groups_page_only_shows_approved_income(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $this->verifiedStudent($hidden->id, 1);
        $this->verifiedStudent($hidden->id, 2);

        // 11,000 is recorded but unapproved, so the consultant's group page
        // must not present any of it as theirs yet.
        $this->assertSame(11000.0, (float) Commission::sum('amount'));

        $before = $this->actingAs($consultantUser)->get(route('consultant.groups.index'));
        $before->assertOk();

        $this->assertSame(0.0, (float) ($before->viewData('tierTotals')[1]->total ?? 0), 'Unapproved slot income is not shown.');
        $this->assertSame(6000.0, (float) $before->viewData('directIncome'), 'The bonus is approved on arrival, so it does show.');

        foreach (Commission::where('status', 'pending')->get() as $row) {
            app(\App\Services\CommissionApproval::class)->approve($row, force: true, actorId: null);
        }

        $after = $this->actingAs($consultantUser)->get(route('consultant.groups.index'));
        $after->assertOk();

        // ⚠️ BOTH land on Service Group 1. The two students were placed on the
        // two internal legs of ONE placement account, and that account IS the
        // owner's Service Group 1 — so its whole income belongs to that side,
        // whichever internal leg it sat on. See AccountPlacementMap.
        $tierTotals = $after->viewData('tierTotals');

        $this->assertSame(5000.0, (float) $tierTotals[1]->total);
        $this->assertSame(0.0, (float) ($tierTotals[2]->total ?? 0));
        $this->assertSame(6000.0, (float) $after->viewData('directIncome'));
    }

    /**
     * The dashboard queue is grouped by PERSON, not by row. One student
     * produces two records (slot income + direct bonus) and a consultant holds
     * three accounts, so an ungrouped list repeated the same name over and
     * over — which is what made the queue unreadable.
     */
    public function test_the_dashboard_queue_groups_income_under_one_consultant(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $accounts = $consultant->hiddenAccounts;

        // Two students on two DIFFERENT placement accounts of the same person.
        $this->verifiedStudent($accounts[0]->id, 1);
        $this->verifiedStudent($accounts[1]->id, 2);

        $response = $this->actingAs($admin)->get(route('commissions.index'));
        $response->assertOk();

        $groups = $response->viewData('incomeGroups');

        $this->assertCount(1, $groups, 'Both accounts belong to one person, so the queue shows one group.');

        $group = $groups->first();

        $this->assertSame($consultant->id, $group['owner']->id, 'The group is keyed to the owning consultant, not a placement account.');
        $this->assertSame(4, $group['row_count'], '2 students x (slot + bonus).');
        $this->assertSame(
            5000.0,
            $group['pending_total'],
            'Only the two slot rows are still pending — the bonuses were approved on arrival.',
        );

        // The two income kinds stay apart and are never summed together.
        $this->assertSame(5000.0, $group['slot_total'], '2 x 2,500 of service group income.');
        $this->assertSame(6000.0, $group['direct_total'], '2 x 3,000 of direct bonus.');

        // Broken down per SCN account, and inside each account per service group.
        $blocks = collect($group['accounts']);

        $this->assertCount(2, $blocks, 'The two placement accounts that earned are each listed.');

        foreach ($blocks as $block) {
            $this->assertContains($block['account']->id, $consultant->accountIds());
            $this->assertCount(2, $block['legs'], 'Every account shows both of its service groups.');
        }
    }

    public function test_approving_one_consultant_covers_all_of_their_accounts_and_nobody_else(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $accounts = $consultant->hiddenAccounts;

        $this->verifiedStudent($accounts[0]->id, 1);
        $this->verifiedStudent($accounts[1]->id, 2);

        // A second, unrelated consultant whose income must be left alone.
        // Only one consultant is seeded, so this one is created here.
        $otherUser = User::factory()->create();
        $otherUser->assignRole('consultant');
        $other = \App\Models\Consultant::create([
            'user_id' => $otherUser->id,
            'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)),
            'status' => 'active',
        ]);

        $this->verifiedStudent($other->id, 1);

        $otherPendingBefore = Commission::where('consultant_id', $other->id)->where('status', 'pending')->count();
        $this->assertGreaterThan(0, $otherPendingBefore);

        // Force-approved the way the single-row button does: "approve all" is
        // unforced and correctly leaves an unbalanced slot alone, so it can no
        // longer clear a queue that is now slot-only.
        foreach (Commission::whereIn('consultant_id', $consultant->accountIds())->where('status', 'pending')->get() as $row) {
            $this->actingAs($admin)->patch(route('commissions.approve', $row))->assertRedirect();
        }

        $this->assertSame(
            0,
            Commission::whereIn('consultant_id', $consultant->accountIds())->where('status', 'pending')->count(),
            'Every account this person owns is cleared.',
        );
        $this->assertSame(
            $otherPendingBefore,
            Commission::where('consultant_id', $other->id)->where('status', 'pending')->count(),
            "Another consultant's queue must be untouched.",
        );
    }

    /**
     * SUPERSEDED — kept as a marker, not as coverage.
     *
     * This tested `commissions.approve-leg`, the "release a whole 3-student leg
     * at once" action. That route no longer exists: a release is now a matched
     * PAIR spanning both legs (1-to-1 / 2-to-2 / 3-to-3), not one leg reaching
     * a count, so approve-leg was replaced by `commissions.approve-stage`. As
     * written this test cannot pass — it would fail on route resolution before
     * asserting anything, which reads as a broken suite rather than an
     * intentionally retired rule.
     *
     * The replacement coverage lives in StagedServiceIncomeTest and
     * UplineIncomeRoutingTest.
     */
    public function test_a_full_service_group_leg_can_be_approved_in_one_7500_action(): void
    {
        $this->markTestSkipped('approve-leg was replaced by approve-stage on 2026-08-09 — see the docblock.');

        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $account = $consultant->hiddenAccounts()->first();

        // Two students on leg 1 — the group is NOT full yet, so it must refuse.
        $this->verifiedStudent($account->id, 1);
        $this->verifiedStudent($account->id, 1);

        $this->actingAs($admin)
            ->patch(route('commissions.approve-leg', ['consultant' => $account->id, 'tier' => 1]))
            ->assertSessionHas('error');

        $this->assertSame(
            0.0,
            (float) Commission::where('consultant_id', $account->id)->where('status', 'approved')->sum('amount'),
            'A partial group must not be released by the full-group action.',
        );

        // The page must not offer the full-group button while the leg fills.
        $legOf = function ($response) use ($account) {
            $block = collect($response->viewData('incomeGroups')->first()['accounts'])
                ->firstWhere(fn ($b) => $b['account']->id === $account->id);

            return collect($block['legs'])->firstWhere('tier', 1);
        };

        $before = $legOf($this->actingAs($admin)->get(route('commissions.index')));

        $this->assertFalse($before['is_complete'], 'A 2-of-3 leg is not releasable as a whole group.');
        $this->assertSame(2, $before['filled']);

        // The third student completes the leg.
        $this->verifiedStudent($account->id, 1);

        $after = $legOf($this->actingAs($admin)->get(route('commissions.index')));

        $this->assertTrue($after['is_complete'], 'A filled leg is now offered as a whole group.');
        $this->assertSame(3, $after['filled']);
        $this->assertSame(7500.0, (float) $after['leg_value']);
        $this->assertSame(7500.0, (float) $after['pending_total'], '3 x 2,500 of slot income still to release.');

        $this->actingAs($admin)
            ->patch(route('commissions.approve-leg', ['consultant' => $account->id, 'tier' => 1]))
            ->assertRedirect();

        // The 7,500 of slot income is approved; the direct bonuses are NOT —
        // this action releases one service group leg, nothing else.
        $this->assertSame(
            7500.0,
            (float) Commission::where('consultant_id', $account->id)->where('type', 'bonus')->where('status', 'approved')->sum('amount'),
        );
        $this->assertSame(
            0.0,
            (float) Commission::where('consultant_id', $account->id)->where('type', 'referral')->where('status', 'approved')->sum('amount'),
            'The direct-add bonus is a separate figure and is not swept up by a group approval.',
        );
    }

    public function test_a_consultant_cannot_approve_their_own_income(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $this->verifiedStudent($hidden->id, 1);
        $one = Commission::firstOrFail();

        $this->actingAs($consultantUser)->patch(route('commissions.approve', $one))->assertForbidden();
        $this->actingAs($consultantUser)->patch(route('commissions.approve-all'))->assertForbidden();

        // 3,000 is the auto-approved direct bonus, which no click created.
        // What matters is that the consultant could not move the slot.
        $this->assertSame(3000.0, (float) $hidden->fresh()->total_earned);
        $this->assertSame(1, Commission::where("status", "pending")->count(), "The slot is untouched.");
    }

    public function test_an_accountant_can_approve(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $hidden = $consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $this->verifiedStudent($hidden->id, 1);

        $this->actingAs($accountant)->patch(route('commissions.approve-all'))->assertRedirect();

        // Unforced, so the unbalanced slot correctly stays put, and the bonus
        // was approved on arrival. What this test is about is that the
        // accountant is allowed through the gate at all.
        $this->assertSame(3000.0, (float) $hidden->fresh()->total_earned);
    }
}
