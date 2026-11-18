<?php

namespace Tests\Feature;

use App\Livewire\ServiceIncomeBoard;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ⚠️ TAKING A WRONG 2,500 / 4,000 OFF THE BOOKS (asked for 2026-09-06).
 *
 * Going live, a miscalculated commission had no way off at all:
 * consultants.total_earned has no recompute path and the only unwind in the app
 * was a whole-account System Run reversal. The owner asked for a per-row
 * escape hatch — "2500 ho 4000 ewa one by one 0 krann button ekak... eka
 * ebuwaham e account eken add wena 2500 ho 4000, upline 2500 th remove wenwa" —
 * under two hard constraints stated twice:
 *
 *   "parana logic walat mukuth wen nathi wenn"  — do not disturb calculation.
 *   "SCN ekat adala hama thaninm 2500 hari 4000 hari 0 wenn one"
 *                                               — it must read 0 EVERYWHERE.
 *
 * The second one is why the amount is zeroed in the row rather than filtered
 * out of a dozen SUM(amount) queries, and most of what is asserted below is
 * that the money is gone from a figure somebody actually looks at.
 */
class CommissionRemovalTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    private Consultant $sponsor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        Cache::forget('commission_settings.active');

        $this->sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    // ---- Scaffolding, copied from BuyingAnAccountPaysTheUplineTest ---------

    private function nextCourse(): Course
    {
        $template = Course::where('status', 'published')->firstOrFail();
        $n = Course::count() + 1;

        return Course::create([
            'title' => 'Removal Course '.$n,
            'slug' => 'removal-course-'.$n.'-'.Str::random(6),
            'description' => 'test',
            'price' => $template->price,
            'currency' => $template->currency,
            'status' => 'published',
            'lecturer_id' => $template->lecturer_id,
            'published_at' => now(),
        ]);
    }

    private function verify(User $buyer, Consultant $account, int $leg = 1): Payment
    {
        $course = $this->nextCourse();

        $enrollment = Enrollment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    /** A downline the way one really comes to exist: seated as a student, then promoted. */
    private function downlineUnder(Consultant $sponsorAccount): Consultant
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $this->verify($user, $sponsorAccount, 1);
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

    private function sponsorAccount(): Consultant
    {
        return $this->sponsor->hiddenAccounts()->orderBy('id')->first();
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@etec.lk')->firstOrFail();
    }

    /**
     * One purchase that credits BOTH the buyer's own account and the sponsor
     * above it — the shape the whole feature is about.
     *
     * @return array{0: Payment, 1: Commission, 2: Commission}
     *                                          the payment, the buyer's own slot row, the sponsor's upline row
     */
    private function purchaseWithAnUpline(): array
    {
        $sponsorAccount = $this->sponsorAccount();
        $downline = $this->downlineUnder($sponsorAccount);
        $ownAccount = $downline->hiddenAccounts()->orderBy('id')->first();

        $payment = $this->verify($downline->user, $ownAccount, 1);

        $own = Commission::where('payment_id', $payment->id)
            ->where('consultant_id', $ownAccount->id)
            ->where('type', 'bonus')
            ->firstOrFail();

        $upline = Commission::where('payment_id', $payment->id)
            ->where('consultant_id', $sponsorAccount->id)
            ->where('type', 'bonus')
            ->firstOrFail();

        return [$payment, $own, $upline];
    }

    private function remove(Commission $row): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->superAdmin())
            ->test(ServiceIncomeBoard::class)
            ->call('askRemove', $row->id)
            ->call('removeRow', $row->id);
    }

    // ---- What Remove must do ----------------------------------------------

    /**
     * ⚠️ THE HEADLINE. Removing one row takes the upline income from the same
     * registration with it — "e account eken add wena 2500 ho 4000, upline 2500
     * th remove wenwa". Leaving the sponsor's 2,500 behind would pay for income
     * that no longer exists.
     */
    public function test_removing_a_row_takes_the_upline_income_with_it(): void
    {
        [, $own, $upline] = $this->purchaseWithAnUpline();

        $this->remove($own)->assertSet('level', 'success');

        foreach ([$own, $upline] as $row) {
            $fresh = $row->fresh();
            $this->assertSame('cancelled', $fresh->status);
            $this->assertNotNull($fresh->removed_at, 'stamped so it is not mistaken for a System Run forfeit');
            $this->assertSame(0.0, (float) $fresh->amount, 'the money reads 0 in the row itself');
            $this->assertSame((float) self::SLOT, (float) $fresh->removed_amount, 'what it used to be is not lost');
        }
    }

    /**
     * The confirmation names every row before anything is touched.
     *
     * ⚠️ THE CLIMB REACHES FURTHER THAN THE IMMEDIATE SPONSOR — a purchase
     * credits the buyer's own account, the sponsor account, and the sponsor's
     * own main above it. The panel must name all of them, which is exactly why
     * the owner asked for the warning: "upline yana ekak thiyeno nm alert msg
     * ekak denn eyage thiyen upline account tikath ekk remove krannd kiyla".
     */
    public function test_the_confirmation_names_the_upline_rows_first(): void
    {
        [$payment, $own, $upline] = $this->purchaseWithAnUpline();

        $whole = (float) Commission::where('payment_id', $payment->id)->sum('amount');

        $this->assertGreaterThan(2 * self::SLOT, $whole, 'guard: this purchase really did climb past one account');

        Livewire::actingAs($this->superAdmin())
            ->test(ServiceIncomeBoard::class)
            ->call('askRemove', $own->id)
            ->assertSet('confirming', $own->id)
            ->assertSet('confirmTotal', $whole)
            ->assertSee($upline->consultant->scn_code ?? $upline->consultant->consultant_code);

        $this->assertSame('approved', $own->fresh()->status, 'asking must not have moved anything');
    }

    public function test_cancelling_the_confirmation_changes_nothing(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        Livewire::actingAs($this->superAdmin())
            ->test(ServiceIncomeBoard::class)
            ->call('askRemove', $own->id)
            ->call('cancelRemove')
            ->assertSet('confirming', null);

        $this->assertSame('approved', $own->fresh()->status);
    }

    /**
     * ⚠️ THE EXACT MIRROR OF CommissionApproval::approve(), which is the only
     * thing that ever increments these. An approved row was counted into both
     * totals; removing it must take exactly that much back out and no more.
     */
    public function test_it_decrements_total_earned_and_the_group_total(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        $account = $own->consultant;
        $earnedBefore = (float) $account->fresh()->total_earned;
        $groupBefore = (float) $own->group->fresh()->total_commission_earned;

        $this->assertSame('approved', $own->status, 'guard: only an approved row was ever credited');

        $this->remove($own);

        $this->assertSame($earnedBefore - self::SLOT, (float) $account->fresh()->total_earned);
        $this->assertSame($groupBefore - self::SLOT, (float) $own->group->fresh()->total_commission_earned);
    }

    /**
     * ⚠️ THE 4,000 MUST NOT BE TAKEN OFF THE GROUP TOTAL. The direct bonus was
     * never added to it — that counter is slot income only, measured against
     * the leg cap — so decrementing it would drive the bar on the Service Group
     * page below zero. Approval's increment has the same guard; this is its
     * mirror.
     *
     * Note the direct bonus cannot be removed ALONE: it belongs to the same
     * earning event as that registration's slot income and the whole event goes
     * together. So the test asserts the SIZE of the decrement — only the slot
     * part — rather than that nothing moved.
     */
    public function test_removing_a_direct_bonus_does_not_take_it_off_the_group_total(): void
    {
        $sponsorAccount = $this->sponsorAccount();
        $student = User::factory()->create();
        Student::create([
            'user_id' => $student->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = $this->verify($student, $sponsorAccount, 1);

        $direct = Commission::where('payment_id', $payment->id)
            ->where('type', 'referral')
            ->firstOrFail();

        $group = \App\Models\Group::where('consultant_id', $sponsorAccount->id)->orderBy('id')->firstOrFail();
        $groupBefore = (float) $group->total_commission_earned;

        // What this event put ON that group: its slot income, never the bonus.
        $slotOnGroup = (float) Commission::where('payment_id', $payment->id)
            ->where('group_id', $group->id)
            ->where('type', 'bonus')
            ->whereIn('status', ['approved', 'paid'])
            ->sum('amount');

        $this->assertGreaterThan(0, (float) $direct->amount, 'guard: there is a 4,000 in this event');
        $this->assertGreaterThan(0, $slotOnGroup, 'guard: and slot income on this group');

        $this->remove($direct);

        $this->assertSame(
            $groupBefore - $slotOnGroup,
            (float) $group->fresh()->total_commission_earned,
            'the group total loses the slot income and nothing else — the 4,000 was never in it',
        );
    }

    /** ⚠️ PAID MONEY IS THE HARD LINE — the same one ServiceGroupFlush draws. */
    public function test_a_paid_row_is_refused_and_nothing_changes(): void
    {
        [, $own, $upline] = $this->purchaseWithAnUpline();

        $upline->update(['status' => 'paid', 'paid_at' => now()]);

        Livewire::actingAs($this->superAdmin())
            ->test(ServiceIncomeBoard::class)
            ->call('askRemove', $own->id)
            ->assertSet('level', 'error')
            ->assertSee('already been marked paid');

        $this->assertSame('approved', $own->fresh()->status, 'the clicked row must be untouched');
        $this->assertSame('paid', $upline->fresh()->status);
    }

    /**
     * Two ways a row ends up `cancelled`, and they need different answers: one
     * says "you already did this", the other says "the company has that money,
     * this button is not how you get it back".
     */
    public function test_an_already_removed_row_says_so(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        $this->remove($own);

        Livewire::actingAs($this->superAdmin())
            ->test(ServiceIncomeBoard::class)
            ->call('askRemove', $own->id)
            ->assertSet('level', 'error')
            ->assertSee('already been removed');
    }

    public function test_a_system_run_forfeit_points_at_the_reversal_instead(): void
    {
        [$payment, $own] = $this->purchaseWithAnUpline();

        // What a forfeit leaves behind: cancelled, but never stamped removed.
        // The WHOLE event, because one live sibling would make the removal
        // legitimate — which is itself the right behaviour.
        Commission::where('payment_id', $payment->id)
            ->update(['status' => 'cancelled', 'released_at' => now()]);

        Livewire::actingAs($this->superAdmin())
            ->test(ServiceIncomeBoard::class)
            ->call('askRemove', $own->id)
            ->assertSet('level', 'error')
            ->assertSee('Reverse the run');
    }

    // ---- "0 everywhere" ----------------------------------------------------

    /** The card at the top of the Service Income dashboard — the first thing the owner looks at. */
    public function test_the_total_service_income_card_drops_by_the_removed_amount(): void
    {
        [$payment, $own] = $this->purchaseWithAnUpline();

        $before = (float) Commission::sum('amount');
        $event = (float) Commission::where('payment_id', $payment->id)->sum('amount');

        $this->remove($own);

        $this->assertSame($before - $event, (float) Commission::sum('amount'),
            'an unfiltered SUM(amount) — the shape this feature must survive');

        $this->actingAs($this->superAdmin())
            ->get(route('commissions.index'))
            ->assertOk();
    }

    /**
     * ⚠️ THE PER-STUDENT FIGURE ON THE SERVICE GROUP PAGE. This is a stored
     * counter, not a live sum, so it has to be zeroed by hand or the removed
     * 2,500 keeps sitting beside the student's name.
     */
    public function test_the_seat_amount_on_the_service_group_page_reads_zero(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        $this->assertSame((float) self::SLOT, (float) GroupMember::findOrFail($own->group_member_id)->commission_amount);

        $this->remove($own);

        $this->assertSame(0.0, (float) GroupMember::findOrFail($own->group_member_id)->commission_amount);
    }

    /**
     * ⚠️ THE RESURRECTION GUARD — the real risk in this feature.
     *
     * The owner has been told to press Commission Health -> Repair after a
     * deploy. Repair replays the engine over every verified payment. Two things
     * had to be true for a removal to survive it: the row must still EXIST
     * (placeInto()'s $alreadySettled has no status filter, so a cancelled row
     * still blocks re-creation — a deleted one would not), and
     * resyncMemberAmounts() must skip cancelled rows or it writes the seat
     * figure straight back to 2,500.
     */
    public function test_the_repair_does_not_bring_the_money_back(): void
    {
        [, $own] = $this->purchaseWithAnUpline();
        $seatId = $own->group_member_id;

        $this->remove($own);

        $rowsBefore = Commission::count();

        $this->artisan('commissions:backfill')->assertSuccessful();

        $this->assertSame($rowsBefore, Commission::count(), 'the repair must not mint a replacement row');
        $this->assertSame('cancelled', $own->fresh()->status);
        $this->assertSame(0.0, (float) $own->fresh()->amount);
        $this->assertSame(0.0, (float) GroupMember::findOrFail($seatId)->commission_amount,
            'resyncMemberAmounts() must not restore the seat figure');
    }

    // ---- What it must NOT do ----------------------------------------------

    /**
     * ⚠️ "parana logic walat mukuth wen nathi wenn". Only the money is wrong —
     * the student really is placed where they were placed, and unwinding that
     * would be the calculation change the owner explicitly ruled out.
     */
    public function test_the_student_stays_seated_and_the_group_counters_are_untouched(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        $seat = GroupMember::findOrFail($own->group_member_id);
        $group = $own->group;
        $filledBefore = (int) $group->filled_slots;

        $this->remove($own);

        $this->assertDatabaseHas('group_members', ['id' => $seat->id, 'user_id' => $seat->user_id, 'tier' => $seat->tier]);
        $this->assertSame($filledBefore, (int) $group->fresh()->filled_slots, 'the seat still occupies its slot');
    }

    /** Company revenue is deliberately left alone — the owner chose this. */
    public function test_company_revenue_is_not_adjusted(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        $before = (float) \App\Models\CompanyRevenue::sum('amount');

        $this->remove($own);

        $this->assertSame($before, (float) \App\Models\CompanyRevenue::sum('amount'));
    }

    /** total_paid belongs to money that actually left the company. */
    public function test_total_paid_is_not_touched(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        $before = (float) $own->consultant->fresh()->total_paid;

        $this->remove($own);

        $this->assertSame($before, (float) $own->consultant->fresh()->total_paid);
    }

    // ---- Who may do it -----------------------------------------------------

    /**
     * ⚠️ SUPER ADMIN ONLY, and checked INSIDE the component. /commissions sits
     * behind role:super_admin|admin|accountant, but a Livewire action posts to
     * Livewire's own endpoint and never passes that middleware — so an
     * accountant could otherwise remove money by posting directly.
     */
    public function test_an_accountant_cannot_remove_income(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        Livewire::actingAs($accountant)
            ->test(ServiceIncomeBoard::class)
            ->call('removeRow', $own->id)
            ->assertForbidden();

        $this->assertSame('approved', $own->fresh()->status, 'the money must not have moved');
    }

    public function test_an_admin_cannot_remove_income(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ServiceIncomeBoard::class)
            ->call('askRemove', $own->id)
            ->assertForbidden();

        $this->assertSame('approved', $own->fresh()->status);
    }

    public function test_a_consultant_cannot_remove_their_own_income(): void
    {
        [, $own] = $this->purchaseWithAnUpline();

        Livewire::actingAs($own->consultant->owningPerson()->user)
            ->test(ServiceIncomeBoard::class)
            ->call('removeRow', $own->id)
            ->assertForbidden();

        $this->assertSame('approved', $own->fresh()->status);
    }

    /**
     * The button itself is gated too. Hiding it is not the security boundary —
     * authorizeRemoval() is — but an accountant who can see a Remove button
     * that always refuses has been given a worse screen, not a safer one.
     */
    public function test_only_a_super_admin_sees_the_remove_button(): void
    {
        $this->purchaseWithAnUpline();

        Livewire::actingAs($this->superAdmin())
            ->test(ServiceIncomeBoard::class)
            ->assertSee('Remove');

        Livewire::actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->test(ServiceIncomeBoard::class)
            ->assertDontSee('Remove');
    }

    /** The board is live — a row can vanish between render and click. */
    public function test_removing_a_missing_row_reports_instead_of_erroring(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(ServiceIncomeBoard::class)
            ->call('askRemove', 999999)
            ->assertSet('level', 'error')
            ->assertSee('no longer exists');
    }

    // ---- The rest of the app must cope ------------------------------------

    /**
     * The rule audit expects a slot row per seat, so a removal looks exactly
     * like `missing_slot`. Reporting every deliberate correction as a permanent
     * defect is how a health page stops being read.
     */
    public function test_the_rule_audit_does_not_report_a_removal_as_a_fault(): void
    {
        [$payment, $own] = $this->purchaseWithAnUpline();

        $this->remove($own);

        $audit = app(\App\Services\CommissionRuleAudit::class)->run();

        $this->assertGreaterThan(0, $audit['skipped_removed'], 'the skip is counted, not silent');
        $this->assertEmpty(
            array_filter($audit['violations'], fn ($v) => (int) $v['payment_id'] === $payment->id),
            'a hand-corrected payment must not be reported as broken',
        );
    }
}
