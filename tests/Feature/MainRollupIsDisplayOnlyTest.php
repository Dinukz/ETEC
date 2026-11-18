<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A MAIN DISPLAYS ITS SERVICE GROUPS. IT NEVER SETTLES THEM. (2026-08-30)
 *
 * These tests outlived two files written for the opposite rule
 * (TheMainReportsItsOwnLegsInARunTest, AMainNeverForfeitsItsAccountsMoneyTest,
 * both removed on 08-30). Everything kept here is true under EVERY setting of
 * who settles what, and each one covers something no other file does — so it
 * was carried across rather than dropped with the rule it was written beside.
 *
 * The settlement rule itself lives in SystemRunRules and is tested by
 * SystemRunCommonRuleTest.
 */
class MainRollupIsDisplayOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private Consultant $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');


        $this->main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function student(Consultant $account, int $leg, bool $old = true): void
    {
        $when = $old ? now()->subDays(10) : now();

        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => $when,
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => $when,
        ]);

        $payment->forceFill(['created_at' => $when])->save();

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    /** @return array{0: Consultant, 1: Consultant} */
    private function pair(): array
    {
        return $this->main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();
    }

    private function preview(): array
    {
        return app(ServiceGroupFlush::class)->preview(now()->addDay());
    }

    private function skippedRow(array $preview, string $code): ?array
    {
        return collect($preview['skipped'])->firstWhere('code', $code);
    }

    // ------------------------------------------------------------------------

    /**
     * ⚠️ THE FIGURE CHANGED ON 2026-09-02 AND THE RULE DID NOT. READ BOTH HALVES.
     *
     * The bug this test was written for: the "holding but not flushing" total
     * counted the same rupees twice, because a main DISPLAYED the rows its
     * children held while every child was also listed in the same table in its
     * own right. The screenshot reading "23 account(s), LKR 427,500" was roughly
     * double the money actually waiting.
     *
     * The main now earns its OWN row for each student instead of displaying the
     * child's, so 20,000 across two accounts is 20,000 of real, distinct,
     * unflushed liability — the child's 10,000 and the main's 10,000. Summing
     * them is no longer double counting; it is addition.
     *
     * ⚠️ THE INVARIANT THAT STILL BITES is that this total must never read a row
     * belonging to somebody else. It sums leg*_OWN_total for exactly that
     * reason, and that name must keep meaning what it says.
     */
    public function test_the_holding_total_counts_each_accounts_own_rows_once(): void
    {
        [$a101] = $this->pair();
        $a101->forceFill(['lifetime_pair_stage' => 2])->save();

        // Service Group 1 = 10,000, Service Group 2 = 0, so neither the child
        // nor the main can pair and both are listed.
        for ($i = 0; $i < 4; $i++) {
            $this->student($a101, 2);
        }

        $preview = $this->preview();

        $this->assertNotNull($this->skippedRow($preview, 'SCN-00100'), 'the main is still listed');
        $this->assertNotNull($this->skippedRow($preview, 'SCN-00101'), 'and so is the account holding it');

        // Both sides of the sum, pinned individually — so a future regression
        // says WHICH account went wrong instead of just moving the total.
        $this->assertSame(10000.0, (float) $this->skippedRow($preview, 'SCN-00101')['leg2_total']);
        $this->assertSame(10000.0, (float) $this->skippedRow($preview, 'SCN-00100')['leg1_total']);

        $this->assertSame(
            20000.0,
            (float) $preview['waiting_total'],
            'The child\'s 10,000 plus the main\'s own 10,000 — two accounts, each counted once.',
        );
    }

    /**
     * ⚠️ state() once measured the in-scope legs with the rollup OFF and the
     * totals with it ON, then called the difference "registered after the
     * cutoff" — telling the main its whole Service Group was outside the date
     * for money the same run was settling one row below. The note must still be
     * RIGHT when the money genuinely was registered late.
     *
     * ⚠️ NOW USES AN EXPLICIT CUTOFF (2026-09-02). It used to rely on
     * defaultCutoff() being the end of YESTERDAY, so "registered today" was out
     * of scope by default. The default is the end of TODAY since 2026-09-02 —
     * precisely so that a correct run stops looking broken — which left this
     * test with nothing outside its window and no note to find.
     *
     * Settling up to an earlier date is still fully supported (the picker goes
     * back as far as the accountant likes), so the note has to be right for it;
     * that case is simply no longer the default, and the test now says so out
     * loud instead of inheriting it.
     */
    public function test_a_genuine_after_the_cutoff_note_still_appears(): void
    {
        [$a101] = $this->pair();
        $a101->forceFill(['lifetime_pair_stage' => 2])->save();

        $this->student($a101, 1);              // 10 days ago — inside the cutoff
        $this->student($a101, 2, old: false);  // today — deliberately outside it

        $yesterday = \Illuminate\Support\Carbon::now('Asia/Colombo')->subDay()->endOfDay();

        $preview = app(ServiceGroupFlush::class)->preview($yesterday);

        $this->assertStringContainsString(
            'after the cutoff',
            $this->skippedRow($preview, 'SCN-00101')['reason'] ?? '',
            'still right when the money really was registered late',
        );
    }

    /**
     * ⚠️ AND THE NOTE MUST NOT APPEAR ON A DEFAULT RUN. This is the screen the
     * owner reported: a main reading "LKR 0 · LKR 0" with "LKR 10,000 on these
     * legs was registered after the cutoff — move the date forward to include
     * it", for students registered that same day and visible in full on the
     * Service Groups page one click away.
     */
    public function test_todays_registrations_are_in_scope_for_a_default_run(): void
    {
        [$a101] = $this->pair();

        $this->student($a101, 1, old: false);
        $this->student($a101, 2, old: false);

        $preview = app(ServiceGroupFlush::class)->preview();

        $this->assertNull(
            $this->skippedRow($preview, 'SCN-00101'),
            'nothing is held back, so the account is not in the "not flushing" list at all',
        );
        $this->assertSame(
            5000.0,
            (float) $preview['payout'],
            'registered today, settled today — 2,500 off each side at 1-to-1',
        );
    }

    /**
     * ⚠️ A STAGE IS EARNED BY PAIRING, NOTHING ELSE.
     *
     * runAll() used to bump lifetime_pair_stage for any account whose rows some
     * OTHER account's flush had released. That raised the threshold an account
     * had to reach for a pairing it never made — 1-to-1 to 2-to-2 to 3-to-3 on
     * somebody else's payout — and is how accounts ended up advertising 3-to-3
     * while holding almost nothing, then quietly refusing to run.
     */
    public function test_a_stage_only_moves_when_the_account_itself_pairs(): void
    {
        [$a101, $a102] = $this->pair();

        // One side only on each, so neither can ever pair.
        $this->student($a101, 2);
        $this->student($a101, 2);
        $this->student($a102, 1);
        $this->student($a102, 1);

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame(0, (int) $a101->fresh()->lifetime_pair_stage, 'it never balanced, so it does not move');
        $this->assertSame(0, (int) $a102->fresh()->lifetime_pair_stage, 'nor this one');
    }

    /** ...while an account that DOES pair still advances, exactly as before. */
    public function test_an_account_that_pairs_itself_still_advances(): void
    {
        [$a101] = $this->pair();

        $this->student($a101, 1);
        $this->student($a101, 2);

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame(
            1,
            (int) $a101->fresh()->lifetime_pair_stage,
            'it balanced 2,500 a side, so it has spent its 1-to-1',
        );
    }

    /**
     * The main is never silent about money it displays, even though it settles
     * none of it — a consultant looking for their own SCN code must find it.
     */
    public function test_the_main_is_still_listed_with_its_rollup(): void
    {
        [$a101] = $this->pair();

        for ($i = 0; $i < 4; $i++) {
            $this->student($a101, 2);
        }

        $row = $this->skippedRow($this->preview(), 'SCN-00100');

        $this->assertNotNull($row, 'the main must appear');
        $this->assertSame(10000.0, (float) $row['leg1_total'], 'showing the Service Group 1 rollup');
    }
}
