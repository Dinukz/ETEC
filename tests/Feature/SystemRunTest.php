<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\SystemRun;
use App\Models\User;
use App\Notifications\SystemRunCompleted;
use App\Notifications\SystemRunScheduled;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The daily SYSTEM RUN (owner-specified 2026-08-14).
 *
 * Flushing stopped being a consultant's own per-account button and became a
 * single network-wide operation the accounts unit performs once a day, settling
 * the PREVIOUS day's activations: "manual newei flush out kranne eka
 * sampuranen ain krala denn... system run eka admin or accountant kranwa...
 * meka auto wenne thiyena okkogem account walin".
 *
 * The cutoff rule is the subtle part and is pinned below: it keys off when the
 * student was REGISTERED, not when their payment cleared, because the owner was
 * explicit that a student added yesterday whose balance is only settled today
 * still belongs to yesterday's run.
 */
class SystemRunTest extends TestCase
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

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    private function account(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant->hiddenAccounts()->first();
    }

    /**
     * Register + verify a student. $registeredAt back-dates the REGISTRATION
     * (payments.created_at), which is what the run's cutoff keys off.
     */
    private function addStudent(Consultant $account, int $leg, ?Carbon $registeredAt = null): User
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

        if ($registeredAt) {
            // Straight to the DB so the timestamp is not overwritten on save.
            \Illuminate\Support\Facades\DB::table('payments')
                ->where('id', $payment->id)
                ->update(['created_at' => $registeredAt]);
        }

        app(PaymentService::class)->verify($payment->fresh(), $this->accountant());

        return $user;
    }

    private function yesterday(): Carbon
    {
        return Carbon::now('Asia/Colombo')->subDay()->setTime(10, 0);
    }

    // ----- The cutoff ---------------------------------------------------------

    /**
     * The rule the owner spelled out: registration date decides, not payment
     * date. A student added yesterday counts even though the money only landed
     * today; a student added today waits for tomorrow's run.
     */
    public function test_the_run_settles_by_registration_date_not_payment_date(): void
    {
        $account = $this->account();

        // Registered yesterday — belongs to this run even though verify() ran
        // just now, in this test, i.e. "today".
        $this->addStudent($account, 1, $this->yesterday());
        $this->addStudent($account, 2, $this->yesterday());

        // Registered today — must be left alone.
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'RUN'])
            ->assertRedirect();

        // Only yesterday's pair was released; today's two are still untouched.
        $this->assertSame(
            2,
            Commission::where('consultant_id', $account->id)->where('type', 'bonus')->whereNotNull('released_at')->count(),
            "Yesterday's pair should be released.",
        );
        // ⚠️ Not `status = pending` any more: since 2026-08-25 income is
        // approved the moment its payment is verified, so an untouched row is
        // approved-and-unreleased. What this test is about is the CUTOFF —
        // that today's registrations were not swept into yesterday's run.
        $this->assertSame(
            2,
            Commission::where('consultant_id', $account->id)->where('type', 'bonus')
                ->whereNull('released_at')->where('status', '!=', 'cancelled')->count(),
            "Today's registrations must be left for the next run, not swept in.",
        );
    }

    /** Skipping a day strands nothing — "on or before" the cutoff, not "on". */
    public function test_a_skipped_day_is_picked_up_by_the_next_run(): void
    {
        $account = $this->account();
        $threeDaysAgo = Carbon::now('Asia/Colombo')->subDays(3);

        $this->addStudent($account, 1, $threeDaysAgo);
        $this->addStudent($account, 2, $threeDaysAgo);

        $this->actingAs($this->accountant())->post(route('system-run.run'), ['confirm' => 'RUN']);

        $this->assertSame(
            2,
            Commission::where('consultant_id', $account->id)->where('type', 'bonus')->whereNotNull('released_at')->count(),
        );
    }

    // ----- The run itself -----------------------------------------------------

    public function test_the_run_flushes_every_eligible_account_at_once(): void
    {
        \App\Services\FinanceSchemaRepair::ensure();

        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $accounts = $consultant->hiddenAccounts;

        foreach ($accounts as $account) {
            // Every account after the first is gated behind the previous one
            // reaching 12 students, so without this only the first is ELIGIBLE
            // and the loop below asserts against an account the run was right
            // to skip. Opening them all is what makes "every eligible account"
            // mean every account here.
            $account->update(['manual_lock_override' => \App\Models\Consultant::LOCK_FORCED_OPEN]);

            $this->addStudent($account, 1, $this->yesterday());
            $this->addStudent($account, 2, $this->yesterday());
        }

        $this->actingAs($this->accountant())->post(route('system-run.run'), ['confirm' => 'RUN']);

        foreach ($accounts as $account) {
            $this->assertGreaterThan(
                0,
                Commission::where('consultant_id', $account->id)->whereNotNull('released_at')->count(),
                "Every eligible account should be settled by one run, including {$account->id}.",
            );
        }
    }

    /** The heavier leg is still forfeited to the company, exactly as a manual flush did. */
    public function test_the_run_forfeits_the_unmatched_side_to_company_revenue(): void
    {
        $account = $this->account();
        Consultant::whereKey($account->id)->update(['lifetime_pair_stage' => 2]); // per-ACCOUNT since 2026-08-18
        $account->refresh();

        for ($i = 0; $i < 5; $i++) {
            $this->addStudent($account, 1, $this->yesterday());
        }
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($account, 2, $this->yesterday());
        }

        $revenueBefore = (float) CompanyRevenue::sum('amount');

        $this->actingAs($this->accountant())->post(route('system-run.run'), ['confirm' => 'RUN']);

        $this->assertSame(
            $revenueBefore + (2 * (float) self::SLOT),
            (float) CompanyRevenue::sum('amount'),
            'The two unmatched rows on the heavier leg go to the company.',
        );
    }

    public function test_the_run_is_recorded_for_audit(): void
    {
        $account = $this->account();
        $this->addStudent($account, 1, $this->yesterday());
        $this->addStudent($account, 2, $this->yesterday());

        $this->actingAs($this->accountant())->post(route('system-run.run'), ['confirm' => 'RUN']);

        $run = SystemRun::latest('id')->first();

        $this->assertNotNull($run, 'Every run must leave a record — nothing else can reconstruct it.');
        $this->assertSame($this->accountant()->id, $run->run_by);
        $this->assertSame(1, $run->accounts_flushed);
        $this->assertSame(2 * (float) self::SLOT, (float) $run->total_released);
    }

    // ----- Guards -------------------------------------------------------------

    public function test_the_run_refuses_without_the_typed_confirmation(): void
    {
        $account = $this->account();
        $this->addStudent($account, 1, $this->yesterday());
        $this->addStudent($account, 2, $this->yesterday());

        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'yes'])
            ->assertSessionHas('error');

        $this->assertSame(
            0,
            Commission::where('consultant_id', $account->id)->whereNotNull('released_at')->count(),
            'A mistyped confirmation must move nothing.',
        );
        $this->assertSame(0, SystemRun::count());
    }

    public function test_a_consultant_cannot_reach_the_system_run(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();

        $this->actingAs($consultantUser)->get(route('system-run.index'))->assertForbidden();
        $this->actingAs($consultantUser)->post(route('system-run.run'), ['confirm' => 'RUN'])->assertForbidden();
    }

    // ----- The notice ----------------------------------------------------------

    public function test_the_notice_reaches_every_active_consultant(): void
    {
        Notification::fake();

        $this->actingAs($this->accountant())
            ->post(route('system-run.announce'), ['run_at' => '1.00 pm'])
            ->assertRedirect();

        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();

        Notification::assertSentTo($consultantUser, SystemRunScheduled::class);
    }

    /** The preview must never move money — it is what the accountant reads before deciding. */
    public function test_the_preview_page_changes_nothing(): void
    {
        $account = $this->account();
        $this->addStudent($account, 1, $this->yesterday());
        $this->addStudent($account, 2, $this->yesterday());

        $this->actingAs($this->accountant())->get(route('system-run.index'))->assertOk();

        $this->assertSame(
            0,
            Commission::where('consultant_id', $account->id)->whereNotNull('released_at')->count(),
            'Opening the preview must not release anything.',
        );
        $this->assertSame(0, SystemRun::count());
    }

    public function test_the_preview_reports_what_the_run_would_do(): void
    {
        $account = $this->account();
        $this->addStudent($account, 1, $this->yesterday());
        $this->addStudent($account, 2, $this->yesterday());

        $preview = app(ServiceGroupFlush::class)->preview();

        $this->assertSame(1, $preview['count']);
        $this->assertSame(2 * (float) self::SLOT, $preview['payout']);
    }

    // ----- The completion notice -----------------------------------------------

    /**
     * "System eka run krata passe notification ekak ynn one me widiyta system
     * run eka iwari kiyala" (2026-08-14) — the confirmation half of the pair,
     * matching the accounts unit's own WhatsApp habit: "13th of August System
     * Run Has Been Done ✅".
     */
    public function test_a_completed_run_notifies_every_active_consultant(): void
    {
        Notification::fake();

        $account = $this->account();
        $this->addStudent($account, 1, $this->yesterday());
        $this->addStudent($account, 2, $this->yesterday());

        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'RUN'])
            ->assertRedirect();

        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();

        Notification::assertSentTo($consultantUser, SystemRunCompleted::class);
    }

    /**
     * The message is "today's activations are settled", which is true whether
     * or not any account actually had a balanced pair — so the notice must
     * still go out on a quiet day.
     */
    public function test_the_completion_notice_still_fires_when_nothing_was_flushed(): void
    {
        Notification::fake();

        // No students at all — nothing eligible to flush.
        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'RUN'])
            ->assertRedirect();

        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();

        Notification::assertSentTo($consultantUser, SystemRunCompleted::class);
    }

    /** A mistyped confirmation must not fire the completion notice — nothing ran. */
    public function test_the_completion_notice_does_not_fire_on_a_refused_run(): void
    {
        Notification::fake();

        $this->actingAs($this->accountant())->post(route('system-run.run'), ['confirm' => 'nope']);

        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();

        Notification::assertNotSentTo($consultantUser, SystemRunCompleted::class);
    }

    /**
     * The label is the SETTLED (cutoff) date, not the day the run was clicked.
     *
     * ⚠️ THE CUTOFF IS NOW PASSED EXPLICITLY (2026-09-02), and that makes this a
     * stronger test than it was. It used to post no cutoff at all and rely on
     * the default being the end of YESTERDAY — so "the cutoff date" and "a date
     * that is not today" were the same thing by accident, and a notice labelled
     * with the default would have passed either way. The default is the end of
     * today since 2026-09-02, so the two are now genuinely different days and
     * the label has to follow the chosen one.
     */
    public function test_the_completion_notice_is_dated_by_the_cutoff_not_today(): void
    {
        Notification::fake();

        $account = $this->account();
        $this->addStudent($account, 1, $this->yesterday());
        $this->addStudent($account, 2, $this->yesterday());

        $cutoff = \Illuminate\Support\Carbon::now('Asia/Colombo')->subDay();

        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => $cutoff->toDateString()]);

        $expectedLabel = $cutoff->format('jS \o\f F');
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();

        Notification::assertSentTo(
            $consultantUser,
            SystemRunCompleted::class,
            function ($notification) use ($expectedLabel) {
                $data = $notification->toDatabase(new \stdClass());

                return str_starts_with($data['title'], $expectedLabel);
            },
        );
    }
}
