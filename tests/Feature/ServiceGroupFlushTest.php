<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The manual FLUSH-OUT (owner-confirmed 2026-08-13, with a worked example).
 *
 * Each leg accumulates a balance. Once BOTH hold the stage threshold the
 * consultant may flush: the matched rows are released for approval and
 * EVERYTHING else on both legs is cancelled into company revenue, leaving both
 * legs at zero.
 *
 * The owner's own numbers, which this pins:
 *   "435000 n 7500k adu wela ithuru gana company eke revenue ekata ekathu
 *    wenwa ita passe anith ewath 0 wenwa"
 *   — leg 1 holding 435,000 against a leg 2 topped up to 7,500 pays 15,000 and
 *   sends the remaining 427,500 to the company.
 */
class ServiceGroupFlushTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        Setting::set('stage_first_pairs', '1', 'integer', 'commission');
        Setting::set('stage_second_pairs', '2', 'integer', 'commission');
        Setting::set('stage_repeat_pairs', '3', 'integer', 'commission');
        Setting::set('account_unlock_students', '12', 'integer', 'commission');
        ServiceGroupStaging::forget();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function account(): Consultant
    {
        return $this->consultantUser()->consultant->hiddenAccounts()->first();
    }

    private function addStudent(Consultant $account, int $leg): User
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
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

        app(PaymentService::class)->verify($payment, $accountant);

        return $user;
    }

    private function groupFor(Consultant $account): Group
    {
        return Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
    }

    private function flusher(): ServiceGroupFlush
    {
        return app(ServiceGroupFlush::class);
    }

    // ----- Nothing releases on its own any more ------------------------------

    /**
     * The whole premise: placing students must NOT release anything. Under the
     * previous build placeInto() advanced the release depth automatically.
     */
    public function test_a_balanced_pair_is_not_released_until_it_is_flushed(): void
    {
        $account = $this->account();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $slotRows = Commission::where('consultant_id', $account->id)->where('type', 'bonus')->get();

        $this->assertCount(2, $slotRows);
        foreach ($slotRows as $row) {
            $this->assertNull($row->released_at, 'Nothing may be released without a deliberate flush.');
        }

        // And so an accountant sweeping the queue cannot move it either.
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $this->assertSame(
            0.0,
            (float) Commission::where('consultant_id', $account->id)->where('type', 'bonus')
                ->whereIn('status', ['approved', 'paid'])->sum('amount'),
            'Approve-all must not release unflushed slot income.',
        );
    }

    // ----- The flush itself ---------------------------------------------------

    public function test_flushing_releases_the_matched_rows_for_approval(): void
    {
        $account = $this->account();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $result = $this->flusher()->flush($account, $this->groupFor($account));

        $this->assertTrue($result['flushed'], $result['reason']);
        $this->assertSame(2 * (float) self::SLOT, $result['payout'], 'A 1-to-1 flush releases 5,000.');
        $this->assertSame(0.0, $result['forfeit'], 'Nothing to forfeit when both legs are exactly at the threshold.');

        $this->assertSame(
            2,
            Commission::where('consultant_id', $account->id)->where('type', 'bonus')->whereNotNull('released_at')->count(),
        );
    }

    /** A flush releases; it never approves. The accountant still decides. */
    public function test_a_flush_does_not_move_money_by_itself(): void
    {
        $account = $this->account();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);
        $this->flusher()->flush($account, $this->groupFor($account));

        $this->assertSame(
            0.0,
            (float) $account->fresh()->total_earned,
            'Releasing is not approving — total_earned must not move until an accountant approves.',
        );

        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $this->assertSame(
            2 * (float) self::SLOT,
            (float) Commission::where('consultant_id', $account->id)->where('type', 'bonus')
                ->whereIn('status', ['approved', 'paid'])->sum('amount'),
            'Once flushed, the normal approval path pays it.',
        );
    }

    // ----- The forfeit — the owner's worked example ---------------------------

    /**
     * The heavier leg loses everything above the threshold, and that money
     * becomes company revenue rather than vanishing.
     */
    public function test_the_heavier_leg_is_forfeited_to_company_revenue(): void
    {
        $account = $this->account();

        // Leg 1: 5 students = 12,500. Leg 2: 3 students = 7,500.
        for ($i = 0; $i < 5; $i++) {
            $this->addStudent($account, 1);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($account, 2);
        }

        // A fresh account is on stage 1 (1-to-1), so push it straight to the
        // repeating 3-to-3 stage — this test is about the FORFEIT, not the
        // ladder. Written on THE ACCOUNT, not owningPerson(): the stage became
        // per-account on 2026-08-18, so setting it on the person no longer
        // changes this account's threshold at all.
        Consultant::whereKey($account->id)->update(['lifetime_pair_stage' => 2]);
        $account->refresh();

        $revenueBefore = (float) CompanyRevenue::sum('amount');

        $result = $this->flusher()->flush($account, $this->groupFor($account));

        $this->assertTrue($result['flushed'], $result['reason']);

        // 3 + 3 released = 15,000. Leg 1 had 2 extra rows = 5,000 forfeited.
        $this->assertSame(6 * (float) self::SLOT, $result['payout']);
        $this->assertSame(2 * (float) self::SLOT, $result['forfeit']);

        $this->assertSame(
            $revenueBefore + (2 * (float) self::SLOT),
            (float) CompanyRevenue::sum('amount'),
            'The forfeited amount must land in company revenue, not disappear.',
        );

        $this->assertSame(
            1,
            CompanyRevenue::where('source_type', 'group_overflow')->count(),
            'Booked under the existing group_overflow source type.',
        );
    }

    public function test_forfeited_rows_are_cancelled_not_deleted(): void
    {
        $account = $this->account();

        for ($i = 0; $i < 5; $i++) {
            $this->addStudent($account, 1);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($account, 2);
        }

        Consultant::whereKey($account->id)->update(['lifetime_pair_stage' => 2]); // per-ACCOUNT since 2026-08-18
        $account->refresh();

        $totalRowsBefore = Commission::where('consultant_id', $account->id)->where('type', 'bonus')->count();

        $this->flusher()->flush($account, $this->groupFor($account));

        $this->assertSame(
            $totalRowsBefore,
            Commission::where('consultant_id', $account->id)->where('type', 'bonus')->count(),
            'Rows must be cancelled, never deleted — the audit trail has to survive.',
        );

        $this->assertSame(
            2,
            Commission::where('consultant_id', $account->id)->where('status', 'cancelled')->count(),
        );
    }

    /** After a flush both legs read zero — that is what the consultant sees. */
    public function test_both_legs_are_zero_after_a_flush(): void
    {
        $account = $this->account();

        for ($i = 0; $i < 5; $i++) {
            $this->addStudent($account, 1);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($account, 2);
        }

        Consultant::whereKey($account->id)->update(['lifetime_pair_stage' => 2]); // per-ACCOUNT since 2026-08-18
        $account->refresh();

        $group = $this->groupFor($account);
        $this->flusher()->flush($account, $group);

        $this->assertSame(0.0, $this->flusher()->legBalance($account, $group, 1));
        $this->assertSame(0.0, $this->flusher()->legBalance($account, $group, 2));
    }

    // ----- Guards ---------------------------------------------------------------

    public function test_an_unbalanced_account_cannot_be_flushed(): void
    {
        $account = $this->account();

        // Everything on one leg — nothing to match against.
        for ($i = 0; $i < 5; $i++) {
            $this->addStudent($account, 1);
        }

        $result = $this->flusher()->flush($account, $this->groupFor($account));

        $this->assertFalse($result['flushed']);
        $this->assertSame(0, Commission::where('consultant_id', $account->id)->where('status', 'cancelled')->count(),
            'A refused flush must not forfeit anything.');
    }

    /** Held income belongs to a restricted consultant — a flush must not consume it. */
    public function test_held_income_is_neither_released_nor_forfeited(): void
    {
        $account = $this->account();
        $owner = $account->owningPerson();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        // Restrict the person, then hold their income.
        //
        // ⚠️ CALLS THE REAL SERVICE SINCE 2026-09-02. This held a hand-written
        // copy — `where('status', 'pending')` — which ConsultantFinanceService
        // itself stopped doing on 2026-08-25, when income began being approved
        // at verification and "pending" became empty by the time anyone is
        // restricted. So the copy stamped NOTHING, and the test passed only
        // because the run's default cutoff happened to exclude same-day
        // registrations: it was asserting the date, not the hold. Moving the
        // default cutoff to today exposed it.
        Consultant::whereKey($owner->id)->update(['finance_status' => 'restricted']);
        app(\App\Services\ConsultantFinanceService::class)->hold($owner->fresh());

        $result = $this->flusher()->flush($account, $this->groupFor($account));

        $this->assertFalse($result['flushed'], 'With every row held there is no balance to flush.');
        $this->assertSame(
            0,
            Commission::where('consultant_id', $account->id)->where('status', 'cancelled')->count(),
            'Held income must never be forfeited — the consultant can still earn it back.',
        );
    }

    /**
     * A consultant has no way to flush at all any more — not their own account
     * and not anyone else's.
     *
     * The per-account button and its route existed for one day (2026-08-13) and
     * were removed on 2026-08-14 when the owner replaced them with the accounts
     * unit's daily System Run. This asserts the route is genuinely gone rather
     * than merely hidden from the page, since a hidden-but-live POST endpoint
     * that forfeits money is exactly the kind of thing that gets found later.
     */
    public function test_consultants_have_no_flush_route_any_more(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('consultant.groups.flush'),
            'Flushing belongs to the daily System Run now — a consultant must not be able to trigger a forfeit.',
        );
    }

    /** The Service Groups page is read-only again: it may show readiness, never a flush control. */
    public function test_the_service_groups_page_offers_no_flush_action(): void
    {
        $account = $this->account();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'));

        $response->assertOk();
        $response->assertDontSee('Flush out —', false);
        $response->assertSee('at the next System Run', false);
    }

    // ----- The one-time discount stages were RETIRED 2026-08-16 -----------------

    /**
     * SUPERSEDED — kept as a marker, not as coverage.
     *
     * This tested the one-time-per-person 1-to-1 (5,000) then 2-to-2 (10,000)
     * discount, before every account settled at the repeating 3-to-3 (15,000).
     * That progression is gone: EVERY flush, for every account, every time,
     * needs the full 7,500 on each side. The discount caused a real bug — a
     * person's accounts share one `lifetime_pair_stage` and are processed by
     * id within a System Run, so whichever account flushed first consumed the
     * cheap stage and could silently raise the threshold under a second
     * account of the same person before it was even checked. See
     * ServiceGroupFlush's class docblock for the full account.
     *
     * The replacement coverage lives in FixedFlushThresholdTest, including the
     * specific order-independence case this bug came from.
     */
    public function test_the_two_one_time_stages_are_consumed_in_order(): void
    {
        $this->markTestSkipped('The one-time discount stages were retired 2026-08-16 — see FixedFlushThresholdTest.');
    }
}
