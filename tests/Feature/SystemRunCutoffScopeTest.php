<?php

namespace Tests\Feature;

use App\Models\Commission;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The System Run cutoff: timezone, orphaned rows, and the page that describes it.
 *
 * Reported 2026-08-14: the Service Groups page said SCN-00107 was "3-to-3 ready
 * — LKR 15,000 at the next System Run" while the System Run preview for the
 * same moment reported 0 accounts to flush. Both computations were of the same
 * quantity and disagreed.
 *
 * Cause: the page called state() with NO cutoff, so it described a flush of
 * everything on the legs; the run applies a cutoff of end-of-yesterday, and the
 * students in question had been registered that same day. The page was
 * promising something the next run would not do.
 *
 * Two genuine defects were fixed alongside it:
 *   - the cutoff Carbon (Asia/Colombo) was bound straight into a query against
 *     a UTC column, moving the real boundary 5h30m later;
 *   - a commission whose payment was deleted (payment_id nulled) could never be
 *     matched by the cutoff's IN-list, so it was neither released nor forfeited
 *     — stuck on its leg permanently.
 */
class SystemRunCutoffScopeTest extends TestCase
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

    /** Register + verify a student, back-dating the REGISTRATION (payments.created_at, stored UTC). */
    private function addStudent(Consultant $account, int $leg, ?Carbon $registeredAtUtc = null): Payment
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

        if ($registeredAtUtc) {
            DB::table('payments')->where('id', $payment->id)->update(['created_at' => $registeredAtUtc]);
        }

        app(PaymentService::class)->verify($payment->fresh(), User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    private function groupFor(Consultant $account): Group
    {
        return Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
    }

    /**
     * The end of YESTERDAY, Colombo — what defaultCutoff() used to return.
     *
     * ⚠️ Named explicitly here since 2026-09-02, when the default moved to the
     * end of TODAY (see ServiceGroupFlush::defaultCutoff() for why). Settling up
     * to an earlier date is still fully supported and still has to get the
     * timezone right, which is what most of this file is about — so the tests
     * that are ABOUT the boundary now name the boundary instead of inheriting a
     * default that no longer sits there.
     */
    private function endOfYesterday(): Carbon
    {
        return Carbon::now('Asia/Colombo')->subDay()->endOfDay();
    }

    // ----- Timezone -------------------------------------------------------------

    /**
     * The cutoff is built in Asia/Colombo but compared against a UTC column.
     * A student registered at 02:00 Colombo TODAY is 20:30 UTC YESTERDAY — the
     * unconverted comparison wrongly counted them as yesterday's.
     */
    public function test_a_registration_made_early_today_is_not_swept_into_yesterdays_run(): void
    {
        $account = $this->account();

        // 02:00 Colombo today == 20:30 UTC yesterday.
        $earlyToday = Carbon::now('Asia/Colombo')->startOfDay()->addHours(2)->setTimezone('UTC');

        $this->addStudent($account, 1, $earlyToday);
        $this->addStudent($account, 2, $earlyToday);

        $flush = app(ServiceGroupFlush::class);
        $group = $this->groupFor($account);

        $this->assertSame(
            0.0,
            $flush->legBalance($account, $group, 1, $this->endOfYesterday()),
            'A registration made after local midnight is on the far side of a yesterday cutoff.',
        );
    }

    public function test_a_registration_made_late_yesterday_is_still_in_scope(): void
    {
        $account = $this->account();

        // 23:30 Colombo yesterday == 18:00 UTC yesterday, inside the boundary.
        $lateYesterday = Carbon::now('Asia/Colombo')->subDay()->setTime(23, 30)->setTimezone('UTC');

        $this->addStudent($account, 1, $lateYesterday);

        $this->assertSame(
            (float) self::SLOT,
            app(ServiceGroupFlush::class)->legBalance($account, $this->groupFor($account), 1, ServiceGroupFlush::defaultCutoff()),
            'Genuinely-yesterday registrations must not be lost by the timezone fix.',
        );
    }

    // ----- Orphaned rows ---------------------------------------------------------

    /**
     * commissions.payment_id and group_members.payment_id are both
     * nullOnDelete, so deleting a payment orphans its slot row. A NULL can
     * never match an IN-list, which left that money unreachable by any run.
     */
    public function test_a_commission_whose_payment_was_deleted_is_still_in_scope(): void
    {
        $account = $this->account();
        $payment = $this->addStudent($account, 1, Carbon::now('Asia/Colombo')->subDays(2)->setTimezone('UTC'));

        $row = Commission::where('payment_id', $payment->id)->where('type', 'bonus')->firstOrFail();

        // Orphan it exactly the way a deleted payment would.
        DB::table('commissions')->where('id', $row->id)->update(['payment_id' => null]);

        $this->assertSame(
            (float) self::SLOT,
            app(ServiceGroupFlush::class)->legBalance($account, $this->groupFor($account), 1, ServiceGroupFlush::defaultCutoff()),
            'An orphaned slot row must stay reachable, or it can never be released OR forfeited.',
        );
    }

    // ----- The page and the run must agree ---------------------------------------

    /**
     * The headline contradiction: the Service Groups page and the System Run
     * must never describe the same money differently.
     *
     * ⚠️ THE AGREEMENT IS THE INVARIANT; THE ANSWER THEY AGREE ON MOVED
     * (2026-09-02). Both used to say "not ready" for a same-day registration,
     * because the run's default cutoff was the end of yesterday. Both now say
     * "ready", because it is the end of today. What must never happen — one
     * screen promising a release the other will not make — is unchanged, and is
     * what this asserts.
     */
    public function test_the_service_groups_page_and_the_next_run_agree(): void
    {
        $account = $this->account();

        // Registered today.
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($account, 1);
            $this->addStudent($account, 2);
        }

        Consultant::whereKey($account->owningPerson()->id)->update(['lifetime_pair_stage' => 2]);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'));
        $response->assertOk();

        $row = collect($response->viewData('accountRows'))->firstWhere('id', $account->id);

        $this->assertTrue(
            $row['flush']['can_flush'],
            'Registered today and balanced, so the next run settles it and the page says so.',
        );

        // And the preview the accountant sees must agree with that.
        $this->assertGreaterThan(0, app(ServiceGroupFlush::class)->preview()['count']);
    }

    /**
     * ⚠️ THE SCREEN THE OWNER REPORTED (2026-09-02). Two students registered
     * today, in balance, and the run said:
     *
     *   "LKR 0 · LKR 0 — LKR 10,000 on these legs was registered after the
     *    cutoff — move the date forward to include it"
     *
     * while the Service Groups page one click away showed the full balance. A
     * correct run that looks broken is a bug in its own right, and it was read
     * exactly that way: "system run eka krann den na, amount eka 0 penwanawa".
     */
    public function test_todays_balance_is_in_scope_and_carries_no_cutoff_caveat(): void
    {
        $account = $this->account();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'));
        $response->assertOk();

        $row = collect($response->viewData('accountRows'))->firstWhere('id', $account->id);

        $this->assertSame((float) self::SLOT, $row['flush']['leg1_total'], 'The full balance is reported...');
        $this->assertSame((float) self::SLOT, $row['flush']['leg1'], '...and all of it is in scope.');
        $this->assertSame(0.0, $row['flush']['waiting_after_cutoff'], 'Nothing is waiting on a date.');

        $response->assertDontSee('settles at a later System Run', false);
    }

    // ----- Settling today must be POSSIBLE ---------------------------------------

    /**
     * The accountant controls the window in BOTH directions.
     *
     * The first build clamped the ceiling to yesterday, so same-day
     * registrations could not be settled by any route: the date input refused
     * to offer today and posting it anyway was silently rewound. Reported
     * 2026-08-14 — "me tika mn kalin damm tikak eth run krann den na".
     *
     * ⚠️ RE-AIMED 2026-09-02, when today became the DEFAULT rather than the
     * ceiling. The thing worth pinning is no longer "today is reachable" (it is
     * where the run starts) but that choosing an EARLIER date still genuinely
     * narrows the window — otherwise the picker would be decorative and the
     * settle-yesterday routine would silently stop working.
     */
    public function test_the_accountant_controls_the_window_in_both_directions(): void
    {
        $account = $this->account();

        // Registered today.
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $flush = app(ServiceGroupFlush::class);

        $this->assertSame(1, $flush->preview()['count'], 'In scope by default.');

        $this->assertSame(
            0,
            $flush->preview($this->endOfYesterday())['count'],
            'And settling up to yesterday still excludes them — the picker really moves the boundary.',
        );
    }

    public function test_the_preview_page_accepts_todays_date(): void
    {
        $account = $this->account();
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $today = ServiceGroupFlush::latestSelectableCutoff()->toDateString();

        $response = $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->get(route('system-run.index', ['cutoff' => $today]));

        $response->assertOk();

        $this->assertSame(
            $today,
            $response->viewData('preview')['cutoff']->toDateString(),
            'The chosen date must survive, not be rewound to yesterday.',
        );
        $this->assertSame(1, $response->viewData('preview')['count']);
    }

    /** And running with today actually settles them. */
    public function test_running_with_todays_cutoff_flushes_same_day_registrations(): void
    {
        $account = $this->account();
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $today = ServiceGroupFlush::latestSelectableCutoff()->toDateString();

        $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => $today])
            ->assertRedirect();

        $this->assertSame(
            2,
            Commission::where('consultant_id', $account->id)->where('type', 'bonus')->whereNotNull('released_at')->count(),
        );
    }

    /** A future date settles nothing extra and must not be taken at face value. */
    public function test_a_future_cutoff_is_clamped_to_today(): void
    {
        $tomorrow = Carbon::now('Asia/Colombo')->addDay()->toDateString();

        $response = $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->get(route('system-run.index', ['cutoff' => $tomorrow]));

        $response->assertOk();

        $this->assertSame(
            Carbon::now('Asia/Colombo')->toDateString(),
            $response->viewData('preview')['cutoff']->toDateString(),
        );
    }

    // ----- The preview must explain what it skips --------------------------------

    /**
     * An account holding money that will not flush must still be listed, with
     * the reason. Reported 2026-08-14: the admin queue showed "LKR 25,000
     * waiting on a stage" for a consultant the run did not touch, and the
     * preview said nothing about them at all — so a correct "1 account" was
     * indistinguishable from a bug.
     */
    public function test_an_unbalanced_account_is_reported_with_a_reason(): void
    {
        $account = $this->account();
        $yesterday = Carbon::now('Asia/Colombo')->subDay()->setTime(10, 0)->setTimezone('UTC');

        // Everything on one leg — real money, but it can never pair.
        for ($i = 0; $i < 4; $i++) {
            $this->addStudent($account, 1, $yesterday);
        }

        $preview = app(ServiceGroupFlush::class)->preview();

        $this->assertSame(0, $preview['count'], 'It cannot flush.');

        $skipped = collect($preview['skipped'])->firstWhere('id', $account->id);

        $this->assertNotNull($skipped, 'An account holding money must never silently vanish from the preview.');
        $this->assertStringContainsString('Service Group 2', $skipped['reason']);
        $this->assertGreaterThan(0, $preview['waiting_total']);
    }

    /** An empty account must NOT be listed, or the table is unreadable. */
    public function test_accounts_holding_nothing_are_not_listed(): void
    {
        $preview = app(ServiceGroupFlush::class)->preview();

        $this->assertSame([], $preview['skipped'], 'With no balances anywhere there is nothing to explain.');
    }

    /**
     * When the only problem is the cutoff, the reason says so and points at the
     * fix.
     *
     * ⚠️ NEEDS AN EXPLICIT EARLIER CUTOFF SINCE 2026-09-02. Nothing can be after
     * the default any more, so this message is only reachable when the
     * accountant has deliberately settled up to an earlier date — which is
     * exactly the case it exists for, and the ONLY case it should ever have
     * been shown in.
     */
    public function test_a_balance_registered_after_the_cutoff_is_explained_as_such(): void
    {
        $account = $this->account();

        // Registered today — in balance, but outside a yesterday cutoff.
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $skipped = collect(app(ServiceGroupFlush::class)->preview($this->endOfYesterday())['skipped'])
            ->firstWhere('id', $account->id);

        $this->assertNotNull($skipped);
        $this->assertStringContainsString('registered after the cutoff', $skipped['reason']);
    }

    /** With everything registered yesterday, page and preview both say ready. */
    public function test_page_and_preview_agree_when_the_balance_is_in_scope(): void
    {
        $account = $this->account();
        $yesterday = Carbon::now('Asia/Colombo')->subDay()->setTime(10, 0)->setTimezone('UTC');

        $this->addStudent($account, 1, $yesterday);
        $this->addStudent($account, 2, $yesterday);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'));
        $row = collect($response->viewData('accountRows'))->firstWhere('id', $account->id);

        $this->assertTrue($row['flush']['can_flush'], 'Yesterday\'s balanced pair is ready.');
        $this->assertSame(1, app(ServiceGroupFlush::class)->preview()['count'], 'And the run agrees.');
    }
}
