<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ⚠️ A REMOVED COMMISSION MUST SHOW NOWHERE — asked for 2026-09-10.
 *
 * "Service Income Dashboard eken commission eka remove kraham... kohewath
 * penwann ba."
 *
 * The removal itself already worked: the row is cancelled, its amount zeroed,
 * `total_earned` and `total_commission_earned` decremented. Every figure on the
 * consultant's own Service Income page was already correct. Two places were not:
 *
 *  1. The dashboard's own stat cards. They are plain Blade rendered once by
 *     CommissionController::index(); only the Livewire board re-renders after an
 *     action. It dispatched 'service-income-updated' for them and NOTHING HAS
 *     EVER LISTENED. So the row vanished while "Total Service Income" kept
 *     printing the old money until a manual reload.
 *  2. "Service Income Summary" (history) had no exclusion at all — the row was
 *     listed, exported to CSV, and counted by a COUNT(*) that does not care the
 *     amount is zero.
 *
 * ⚠️ THE ROW IS STILL NOT DELETED, and that is a safety limit, not a
 * preference: placeInto()'s $alreadySettled and awardDirectBonus()'s
 * $alreadyPaid both ask "does a row exist" WITHOUT filtering status, so a
 * deleted row is re-minted at full value by the next Commission Health →
 * Repair. See CommissionRemovalTest::test_the_repair_does_not_bring_the_money_back.
 */
class RemovedIncomeShowsNowhereTest extends TestCase
{
    use RefreshDatabase;

    private Consultant $sponsor;

    private User $superAdmin;

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
        $this->superAdmin = User::role('super_admin')->firstOrFail();
    }

    private function nextCourse(): Course
    {
        $t = Course::where('status', 'published')->firstOrFail();
        $n = Course::count() + 1;

        return Course::create([
            'title' => 'Removal Course '.$n,
            'slug' => 'removal-course-'.$n.'-'.Str::random(6),
            'description' => 'x', 'price' => $t->price, 'currency' => $t->currency,
            'status' => 'published', 'lecturer_id' => $t->lecturer_id, 'published_at' => now(),
        ]);
    }

    /** Register and verify a student, returning the slot commission it minted. */
    private function earnOne(): Commission
    {
        $user = User::factory()->create(['name' => 'Removal Student '.Str::random(4)]);
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $course = $this->nextCourse();
        $enr = Enrollment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $account = $this->sponsor->hiddenAccounts()->orderBy('id')->firstOrFail();

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => $course->id, 'enrollment_id' => $enr->id,
            'consultant_id' => $account->id, 'requested_tier' => 1,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return Commission::where('payment_id', $payment->id)
            ->where('type', \App\Services\CommissionService::TYPE_SLOT)
            ->firstOrFail();
    }

    private function remove(Commission $commission): void
    {
        Livewire::actingAs($this->superAdmin)
            ->test(\App\Livewire\ServiceIncomeBoard::class)
            ->call('removeRow', $commission->id);
    }

    // ----- the summary page -------------------------------------------------

    /** ⚠️ THE COUNT was the figure that kept showing money. It is a COUNT(*). */
    public function test_the_summary_stops_counting_a_removed_row(): void
    {
        $commission = $this->earnOne();

        $before = $this->actingAs($this->superAdmin)
            ->get(route('commissions.history'))->viewData('totals');

        $this->remove($commission);

        $after = $this->actingAs($this->superAdmin)
            ->get(route('commissions.history'))->viewData('totals');

        $this->assertLessThan($before['count'], $after['count'], 'the removed row is no longer counted');
        $this->assertLessThan($before['amount'], $after['amount'], 'and its money is gone from the total');
    }

    /** And the row itself is not in the table. */
    public function test_a_removed_row_is_not_listed_in_the_summary(): void
    {
        $commission = $this->earnOne();
        $studentName = $commission->student?->name;

        $this->assertNotNull($studentName, 'guard: the fixture has a named student');

        $this->actingAs($this->superAdmin)
            ->get(route('commissions.history'))
            ->assertOk()
            ->assertSee($studentName);

        $this->remove($commission->fresh());

        $rows = $this->actingAs($this->superAdmin)
            ->get(route('commissions.history'))
            ->viewData('commissions');

        $this->assertNotContains(
            $commission->id,
            $rows->pluck('id'),
            'a removed row must not appear in Service Income Summary',
        );
    }

    /** Even when the status filter deliberately asks for cancelled rows. */
    public function test_it_cannot_be_found_by_filtering_for_cancelled(): void
    {
        $commission = $this->earnOne();
        $this->remove($commission);

        $rows = $this->actingAs($this->superAdmin)
            ->get(route('commissions.history', ['status' => 'cancelled']))
            ->viewData('commissions');

        $this->assertNotContains($commission->id, $rows->pluck('id'));
    }

    /**
     * ⚠️ THE OTHER HALF. A row a SYSTEM RUN forfeited is also 'cancelled', and
     * that money really did go to the company — it must stay auditable. Only a
     * hand removal disappears.
     */
    public function test_a_system_run_forfeit_is_still_listed(): void
    {
        $commission = $this->earnOne();

        // Forfeited by a run: cancelled, but never removed by hand.
        $commission->update(['status' => 'cancelled', 'released_at' => now()]);

        $rows = $this->actingAs($this->superAdmin)
            ->get(route('commissions.history'))
            ->viewData('commissions');

        $this->assertContains(
            $commission->id,
            $rows->pluck('id'),
            'company revenue must stay visible — only hand removals are hidden',
        );
    }

    // ----- the dashboard cards ----------------------------------------------

    /**
     * ⚠️ THE REPORTED BUG. After a successful removal the page must be showing
     * recomputed totals, not the ones rendered before the click.
     */
    public function test_a_successful_removal_reloads_the_dashboard(): void
    {
        $commission = $this->earnOne();

        Livewire::actingAs($this->superAdmin)
            ->test(\App\Livewire\ServiceIncomeBoard::class)
            ->call('removeRow', $commission->id)
            ->assertRedirect(route('commissions.index'));
    }

    /**
     * ...and the reloaded cards really are smaller.
     *
     * The drop is the WHOLE earning event, not just the clicked row — Remove
     * takes the registrant's 2,500, the 4,000 direct bonus and every upline
     * 2,500 that climbed from the same payment. That is the documented
     * behaviour ("e account eken add wena 2500 ho 4000, upline 2500 th remove
     * wenwa"), so the assertion is written against the event's own total rather
     * than a hardcoded figure.
     */
    public function test_the_total_card_drops_by_the_whole_earning_event(): void
    {
        $commission = $this->earnOne();

        $event = (float) Commission::where('payment_id', $commission->payment_id)->sum('amount');

        $this->assertGreaterThan(0.0, $event, 'guard: the fixture really earned something');

        $before = $this->actingAs($this->superAdmin)
            ->get(route('commissions.index'))->viewData('stats')['total'];

        $this->remove($commission);

        $after = $this->actingAs($this->superAdmin)
            ->get(route('commissions.index'))->viewData('stats')['total'];

        $this->assertSame(
            $before - $event,
            $after,
            'Total Service Income drops by exactly what that registration earned',
        );
    }

    /** A refusal must NOT reload — the accountant keeps their place and sees why. */
    public function test_a_refused_removal_stays_on_the_board(): void
    {
        $commission = $this->earnOne();
        $commission->update(['status' => 'paid', 'paid_at' => now()]);

        Livewire::actingAs($this->superAdmin)
            ->test(\App\Livewire\ServiceIncomeBoard::class)
            ->call('removeRow', $commission->id)
            ->assertNoRedirect()
            ->assertSet('level', 'error');
    }

    // ----- the consultant is not told ---------------------------------------

    public function test_the_consultant_is_not_told_about_the_removal(): void
    {
        $commission = $this->earnOne();
        $this->remove($commission);

        $this->actingAs($this->sponsor->user)
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertDontSee('removed by the office');
    }
}
