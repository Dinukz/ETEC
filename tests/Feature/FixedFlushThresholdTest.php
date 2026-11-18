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
 * ONE fixed flush threshold, always (owner-confirmed 2026-08-16). Supersedes
 * the one-time-per-person 1-to-1 (5,000) / 2-to-2 (10,000) discount stages
 * pinned in the earlier `test_the_two_one_time_stages_are_consumed_in_order`
 * test (removed with this change).
 *
 * ----- Why -------------------------------------------------------------------
 * The staged discount produced a real, previously undocumented bug: a
 * person's accounts are processed by id within one System Run and share ONE
 * `lifetime_pair_stage`. Whichever account happened to flush FIRST consumed
 * the cheap stage, and a SECOND account of the SAME person — sitting on a
 * balance that would have qualified moments earlier — could be refused
 * because the threshold had already moved up under it before it was even
 * checked. Money that was genuinely ready got stuck on the accident of
 * processing order.
 *
 * A single fixed threshold (7,500 each side, every account, every time)
 * removes the whole class of bug: order can never change the outcome. Pinned
 * below by running the SAME two accounts in both orders and asserting an
 * identical result.
 *
 * A fresh consultant's first couple of students no longer get a cheaper
 * first release — the only way to pay them out before the legs reach 7,500 is
 * the accountant's per-row manual "Approve" override (kept 2026-08-15),
 * which does not reduce the leg balance a flush later measures.
 */
class FixedFlushThresholdTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const THRESHOLD = 7500; // 3 students, always — never 2,500 or 5,000

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

    private function account(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant->hiddenAccounts()->first();
    }

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
    }

    private function groupFor(Consultant $account): Group
    {
        return Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
    }

    // ----- The staged progression, PER ACCOUNT ----------------------------------
    //
    // ⚠️ INVERTED 2026-08-18. These three tests pinned the 2026-08-16 "one
    // fixed threshold, always" rule. The owner reinstated the staged amounts
    // with explicit numbers ("student la dennek add krahama... 5000 auto out
    // wenwa... studentla 4k add karoth eyta auto 10000k... 3 to 3 eka eke
    // 15000k"), but PER ACCOUNT rather than per person — which is what keeps
    // the order-independence tests below passing, since that shared counter
    // was the actual cause of the 08-16 retirement, not the staging itself.

    /** A brand new account's FIRST flush is the 1-to-1 stage: 1 student a side, pays 5,000. */
    public function test_a_fresh_accounts_first_flush_is_the_1_to_1_stage(): void
    {
        $account = $this->account();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $result = app(ServiceGroupFlush::class)->flush($account, $this->groupFor($account));

        $this->assertTrue($result['flushed'], $result['reason']);
        $this->assertSame(
            2 * (float) self::SLOT,
            $result['payout'],
            'One student on each side balances the 1-to-1 stage and releases 5,000.',
        );
        $this->assertSame(0.0, $result['forfeit'], 'Exactly 1+1 leaves nothing to forfeit.');
    }

    /** The SECOND flush on that same account is the 2-to-2 stage: 2 a side, pays 10,000. */
    public function test_the_second_flush_on_an_account_is_the_2_to_2_stage(): void
    {
        $account = $this->account();
        $group = $this->groupFor($account);
        $flush = app(ServiceGroupFlush::class);

        // Stage 1.
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);
        $this->assertTrue($flush->flush($account, $group)['flushed']);

        // ONE more each side is now NOT enough — the account has moved to 2-to-2.
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);
        $this->assertFalse(
            $flush->flush($account->fresh(), $group->fresh())['flushed'],
            'After the 1-to-1 is consumed the account needs 5,000 a side, so 1+1 is refused.',
        );

        // A second on each side reaches it.
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        $result = $flush->flush($account->fresh(), $group->fresh());

        $this->assertTrue($result['flushed'], $result['reason']);
        $this->assertSame(4 * (float) self::SLOT, $result['payout'], '2 a side releases 10,000.');
    }

    /** The THIRD flush onward is the repeating 3-to-3 stage: 3 a side, pays 15,000, forever. */
    public function test_the_third_flush_onward_is_the_repeating_3_to_3_stage(): void
    {
        $account = $this->account();
        $group = $this->groupFor($account);
        $flush = app(ServiceGroupFlush::class);

        // Consume stage 1 (1+1) and stage 2 (2+2).
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);
        $flush->flush($account->fresh(), $group->fresh());

        for ($i = 0; $i < 2; $i++) {
            $this->addStudent($account, 1);
            $this->addStudent($account, 2);
        }
        $flush->flush($account->fresh(), $group->fresh());

        // ⚠️⚠️ REVISED AGAIN 2026-08-27 — THIRD SETTING.
        //
        // On 08-26 this said a side at 3-to-3 need NOT reach 7,500: it released
        // whatever it held and was emptied. The owner reversed that after
        // seeing SCN-00103 release 7,500 against an empty other side and lose
        // 30,000 to the company: "meke paththaka hari 7500ta wada adui nam run
        // wen na". Two a side (5,000) is now short of 7,500, so it REFUSES and
        // keeps everything.
        for ($i = 0; $i < 2; $i++) {
            $this->addStudent($account, 1);
            $this->addStudent($account, 2);
        }

        $partial = $flush->flush($account->fresh(), $group->fresh());

        $this->assertFalse($partial['flushed'], 'Two a side is under 7,500 — the account waits.');
        $this->assertSame(0.0, $partial['payout']);
        $this->assertSame(0.0, $partial['forfeit'], 'And nothing is lost while it waits.');

        // A full 3 a side then releases the whole stage amount.
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($account, 1);
            $this->addStudent($account, 2);
        }

        $result = $flush->flush($account->fresh(), $group->fresh());
        $this->assertTrue($result['flushed'], $result['reason']);
        $this->assertSame(6 * (float) self::SLOT, $result['payout'], '3 a side releases 15,000.');

        // ...and it STAYS at 3-to-3 rather than advancing to anything cheaper
        // or dearer — this is the repeating stage.
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($account, 1);
            $this->addStudent($account, 2);
        }

        $again = $flush->flush($account->fresh(), $group->fresh());
        $this->assertTrue($again['flushed'], $again['reason']);
        $this->assertSame(6 * (float) self::SLOT, $again['payout'], '3-to-3 repeats forever at 15,000.');
    }

    /** Each account of the SAME person walks its own 1→2→3 ladder independently. */
    public function test_each_account_of_one_person_has_its_own_stage_ladder(): void
    {
        \App\Services\FinanceSchemaRepair::ensure();

        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $accountA = $consultant->hiddenAccounts()->first();
        $accountB = $consultant->hiddenAccounts()->skip(1)->first();

        // B is normally gated behind A reaching 12 students. Open it by hand so
        // this test measures the STAGE ladder rather than the unlock rule —
        // otherwise B's refusal below would prove nothing about thresholds.
        $accountB->update(['manual_lock_override' => \App\Models\Consultant::LOCK_FORCED_OPEN]);
        $accountB->refresh();

        $flush = app(ServiceGroupFlush::class);

        // A consumes its 1-to-1.
        $this->addStudent($accountA, 1);
        $this->addStudent($accountA, 2);
        $this->assertTrue($flush->flush($accountA, $this->groupFor($accountA))['flushed']);

        // B is untouched by that and still gets its OWN 1-to-1 at 1+1. Under
        // the retired per-PERSON counter this is precisely what broke.
        $this->addStudent($accountB, 1);
        $this->addStudent($accountB, 2);

        $result = $flush->flush($accountB->fresh(), $this->groupFor($accountB));

        $this->assertTrue($result['flushed'], $result['reason']);
        $this->assertSame(
            2 * (float) self::SLOT,
            $result['payout'],
            'B has its own 1-to-1 stage — A consuming its own must not raise B\'s threshold.',
        );
    }

    // ----- Order independence — the bug this fixes -------------------------------

    /**
     * THE HEADLINE FIX. Two accounts of the SAME person: A comfortably clears
     * the threshold, B sits exactly on the shape that used to qualify for the
     * OLD one-time "2-to-2" discount (5,000) but never the full 7,500. Flush
     * A FIRST — under the old staged rule this is precisely the case that
     * broke: A consuming the shared discount stage would move B's threshold
     * up before B was even checked. B must be refused on its own merits
     * alone, unaffected by whatever A did moments earlier.
     */
    public function test_flushing_account_a_first_does_not_change_account_bs_threshold(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $accountA = $consultant->hiddenAccounts()->first();
        $accountB = $consultant->hiddenAccounts()->skip(1)->first();

        $this->assertNotNull($accountB, 'This test needs the consultant to own two placement accounts.');

        // A: comfortably over the threshold on both legs (5 + 3).
        for ($i = 0; $i < 5; $i++) {
            $this->addStudent($accountA, 1);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($accountA, 2);
        }

        // ⚠️ B IS ONE-SIDED, so it cannot pair at ANY stage (2026-08-27).
        //
        // It used to be seeded 2 + 3 and was refused — but on the 12-student
        // CHAIN LOCK, not on its balance: 5,000/7,500 clears a 1-to-1 easily.
        // Once the chain stopped withholding payouts, that seeding flushed
        // quite legitimately and this test failed, having passed for the wrong
        // reason ever since the fixed 7,500 threshold was replaced by stages.
        //
        // A genuinely insufficient balance is what this test is about.
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($accountB, 1);
        }

        $flush = app(ServiceGroupFlush::class);

        $resultA = $flush->flush($accountA, $this->groupFor($accountA));
        $this->assertTrue($resultA['flushed'], 'A has plenty on both legs and must flush.');

        $resultB = $flush->flush($accountB, $this->groupFor($accountB));
        $this->assertFalse(
            $resultB['flushed'],
            'B must be refused on its own balance alone — it must NOT matter that A (same person) flushed moments before it.',
        );
    }

    /** And the reverse order reaches the identical per-account verdicts. */
    public function test_flushing_account_b_first_still_refuses_it_and_a_still_flushes(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $accountA = $consultant->hiddenAccounts()->first();
        $accountB = $consultant->hiddenAccounts()->skip(1)->first();

        for ($i = 0; $i < 5; $i++) {
            $this->addStudent($accountA, 1);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($accountA, 2);
        }
        // One-sided, so B cannot pair at any stage — see the note in the
        // test above for why the old 2 + 3 seeding stopped being a refusal.
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($accountB, 1);
        }

        $flush = app(ServiceGroupFlush::class);

        // B first this time.
        $resultB = $flush->flush($accountB, $this->groupFor($accountB));
        $this->assertFalse($resultB['flushed'], 'B is refused outright, before A is touched.');

        $resultA = $flush->flush($accountA, $this->groupFor($accountA));
        $this->assertTrue($resultA['flushed'], 'A still flushes normally — B\'s refusal has no effect on it either.');
    }

    /**
     * A flush advances THE ACCOUNT'S stage, and leaves the owning person's
     * counter alone.
     *
     * ⚠️ INVERTED 2026-08-18. This used to assert that nothing incremented the
     * counter at all (correct while the staged discount was retired). The
     * stages are back, so a flush DOES advance one — but on the account row,
     * never on the person. Asserting both halves is the point: the person's
     * counter staying put is exactly what makes a sibling account's threshold
     * independent.
     */
    public function test_a_flush_advances_the_accounts_own_stage_not_the_persons(): void
    {
        $account = $this->account();
        $owner = $account->owningPerson();

        $this->assertNotSame($account->id, $owner->id, 'This test needs a hidden placement account, not the main one.');

        $ownerBefore = (int) $owner->fresh()->lifetime_pair_stage;
        $accountBefore = (int) $account->fresh()->lifetime_pair_stage;

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        app(ServiceGroupFlush::class)->flush($account, $this->groupFor($account));

        $this->assertSame(
            $accountBefore + 1,
            (int) $account->fresh()->lifetime_pair_stage,
            'The ACCOUNT moves up a stage after flushing.',
        );
        $this->assertSame(
            $ownerBefore,
            (int) $owner->fresh()->lifetime_pair_stage,
            'The owning PERSON\'s counter is never touched — sharing it is what caused the 2026-08-16 order-dependence bug.',
        );
    }

    // ----- The forfeit math -----------------------------------------------------

    /**
     * ⚠️ REVERSED 2026-08-23 — the 1-to-1 stage forfeits NOTHING.
     *
     * This asserted that everything past the threshold was taken to the
     * company at the very first flush, which cost a consultant six rows'
     * worth on their opening stage. The owner has made 1-to-1 and 2-to-2 a
     * ramp instead: "1to1, 2to2 stage wala inn ayage... wadi amount eka
     * company ekata add wen na, amount eka e widiytama thiyeno". Only 3-to-3
     * still cuts — see StageRampNoForfeitTest for the full matrix.
     */
    public function test_the_1_to_1_stage_releases_its_threshold_and_forfeits_nothing(): void
    {
        $account = $this->account();

        // A fresh account is at the 1-to-1 stage, so the threshold is ONE
        // student (2,500) a side. Leg 1: 5 students = 12,500. Leg 2: 3 = 7,500.
        for ($i = 0; $i < 5; $i++) {
            $this->addStudent($account, 1);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($account, 2);
        }

        $revenueBefore = (float) CompanyRevenue::sum('amount');

        $result = app(ServiceGroupFlush::class)->flush($account, $this->groupFor($account));

        $this->assertTrue($result['flushed']);
        $this->assertSame(2 * (float) self::SLOT, $result['payout'], '1+1 released = 5,000.');
        $this->assertSame(0.0, $result['forfeit'], 'The 6 rows past the threshold stay on the legs.');
        $this->assertSame(0, $result['cancelled']);
        $this->assertSame($revenueBefore, (float) CompanyRevenue::sum('amount'), 'The company takes nothing on a ramp stage.');
    }

    // ----- The manual override is the only remaining early-release path ---------

    /** An accountant can still push an individual row through before the leg reaches 7,500. */
    public function test_the_manual_approve_override_still_works_below_the_threshold(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $account = $this->account();

        $this->addStudent($account, 1); // far short of 7,500

        $row = Commission::where('consultant_id', $account->id)->where('type', 'bonus')->firstOrFail();

        $this->actingAs($admin)->patch(route('commissions.approve', $row))->assertRedirect();

        $this->assertSame('approved', $row->fresh()->status, 'The per-row override is the only way to pay a fresh consultant\'s first student early now.');
    }

    /**
     * A manually-approved row keeps its place on the leg until a System Run.
     *
     * ⚠️ INVERTED 2026-08-17, not newly written. The assertion here used to
     * expect the opposite (2,500 — the approved row dropping out of the pool)
     * while the test's own NAME said "does not reduce the leg balance". The
     * name matched the documented rule; the assertion matched the code, which
     * filtered the leg on `status = 'pending'`. They cannot both be right, and
     * the rule is what the owner has stated twice:
     *
     *   "the per-row manual Approve override — it does not reduce the leg
     *    balance a later flush measures, so it is a genuine one-off, not a
     *    backdoor discount"                    (commission-rules, 2026-08-15)
     *
     * Reported as a live bug on 2026-08-17: approving SCN-00107's only student
     * emptied that account's Service Group 1 to LKR 0 while the Main account's
     * rollup still showed the 2,500, so the money looked like it had moved
     * between accounts. Owner's instruction: "dekenm 2500 penwann one, eka 0
     * wenn one system run ekedi" — both must show 2,500; only a System Run
     * zeroes a leg.
     */
    public function test_a_manually_approved_row_does_not_reduce_the_leg_balance(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $account = $this->account();

        $this->addStudent($account, 1);
        $row = Commission::where('consultant_id', $account->id)->where('type', 'bonus')->firstOrFail();
        $this->actingAs($admin)->patch(route('commissions.approve', $row));

        $this->assertSame('approved', $row->fresh()->status, 'Precondition: the override actually approved it.');

        // One more on leg 1, plus three on leg 2 — still short of 7,500.
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);
        $this->addStudent($account, 2);
        $this->addStudent($account, 2);

        $group = $this->groupFor($account);
        $flush = app(ServiceGroupFlush::class);

        // Both rows count: the approved one has been PAID, not removed from
        // the leg. Approving must never cost the consultant progress towards
        // their own threshold.
        $this->assertSame(
            2 * (float) self::SLOT,
            $flush->legBalance($account, $group, 1),
            'A manually-approved row still holds its place on the leg — only a System Run takes it off.',
        );
    }

    /**
     * ...and when the run finally comes, that already-paid row is swept off
     * the leg WITHOUT being paid twice or forfeited to the company.
     *
     * This is the money-safety half of the rule above. Counting an approved
     * row towards the threshold is only safe if the flush then treats it
     * differently from a pending one — cancelling it would claw back a real
     * payout and book it as company revenue.
     */
    public function test_a_flush_sweeps_an_already_approved_row_without_paying_or_forfeiting_it(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $account = $this->account();

        // Pin the account on the repeating 3-to-3 stage. This test is about
        // the SWEEP, not about the staged thresholds — leaving it on a fresh
        // account's 1-to-1 would flush after a single pair and never exercise
        // an already-approved row sitting inside a 3-wide threshold window.
        $account->update(['lifetime_pair_stage' => \App\Services\ServiceGroupStaging::ONE_TIME_STAGES]);
        $account->refresh();

        // Leg 1: one student, approved by hand before any run.
        $this->addStudent($account, 1);
        $row = Commission::where('consultant_id', $account->id)->where('type', 'bonus')->firstOrFail();
        $this->actingAs($admin)->patch(route('commissions.approve', $row));

        // Fill both legs to exactly the threshold (3 each side).
        $this->addStudent($account, 1);
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);
        $this->addStudent($account, 2);
        $this->addStudent($account, 2);

        $group = $this->groupFor($account);
        $revenueBefore = (float) CompanyRevenue::sum('amount');

        $result = app(ServiceGroupFlush::class)->flush($account, $group);

        $this->assertTrue($result['flushed'], $result['reason']);

        // 6 rows at the threshold, but one was already paid — so this run
        // releases 5, not 6.
        // ⚠️ REVISED 2026-08-25 — all 6, not 5.
        //
        // "Swept" used to mean "already approved, so nothing left to pay", back
        // when a slot stayed pending until a run released it. Income is now
        // approved the instant its payment is verified, so EVERY row would fall
        // into that bucket and every run would report a payout of 0.00 for work
        // it had just done. What a run decides today is which money is PAYABLE,
        // so an approved row within the threshold is exactly its payout. Only a
        // genuinely PAID row is swept.
        $this->assertSame(
            6 * (float) self::SLOT,
            $result['payout'],
            'Everything within the threshold that has not been paid out yet.',
        );

        $this->assertSame(0.0, $result['forfeit'], 'Nothing sits past the threshold, so nothing is forfeited.');
        $this->assertSame($revenueBefore, (float) CompanyRevenue::sum('amount'), 'An already-paid row must never be booked as company revenue.');

        $this->assertSame('approved', $row->fresh()->status, 'Released, not cancelled — it keeps its approved status.');
        $this->assertNotNull($row->fresh()->released_at, 'It is stamped so the next run does not count it again.');

        // Both legs now read 0 — the run is the only thing that empties them.
        $this->assertSame(0.0, app(ServiceGroupFlush::class)->legBalance($account, $group, 1));
        $this->assertSame(0.0, app(ServiceGroupFlush::class)->legBalance($account, $group, 2));
    }

    /** An admin's manual unlock opens a placement account the student rule would keep shut. */
    public function test_an_admin_can_manually_unlock_a_locked_placement_account(): void
    {
        // The override columns are created by the self-healing repair rather
        // than a migration (this host has no CLI), and it normally runs at an
        // HTTP entry point. This test drives the service directly, so it has
        // to ask for the schema itself.
        \App\Services\FinanceSchemaRepair::ensure();

        $account = $this->account();
        $owner = $account->owningPerson();

        // A second placement account is gated behind the first reaching 12.
        $second = \App\Models\Consultant::where('owner_consultant_id', $owner->id)
            ->where('is_hidden', true)
            ->where('id', '!=', $account->id)
            ->orderBy('id')
            ->first();

        if (! $second) {
            $this->markTestSkipped('This fixture has only one placement account.');
        }

        $staging = app(\App\Services\ServiceGroupStaging::class);

        $this->assertFalse($staging->isUnlocked($second), 'Precondition: the automatic rule keeps it shut.');

        $second->update(['manual_lock_override' => \App\Models\Consultant::LOCK_FORCED_OPEN]);

        $this->assertTrue(
            $staging->isUnlocked($second->fresh()),
            'A manual unlock overrides the 12-student rule.',
        );

        // ...and the override works in the other direction too.
        $second->update(['manual_lock_override' => \App\Models\Consultant::LOCK_FORCED_SHUT]);
        $this->assertFalse($staging->isUnlocked($second->fresh()), 'A manual lock shuts an account the rule would open.');

        // Cleared, it falls back to the automatic rule.
        $second->update(['manual_lock_override' => null]);
        $this->assertFalse($staging->isUnlocked($second->fresh()), 'Back to Automatic — the student rule governs again.');
    }
}
