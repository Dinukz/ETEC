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
 * Downline income goes through the flush like everything else
 * (owner-confirmed 2026-08-14).
 *
 * ⚠️ THIS REVERSES THE 2026-08-12 RULE, and replaces the file that pinned it
 * (UplineIncomeNotStagedTest). For two days a downline-sourced 2,500 was
 * approved on the spot at payment verification, because an ancestor's legs were
 * thought unable to balance. The owner has since laid out the intended model in
 * full — downline money accumulates on the ancestor's legs exactly like their
 * own students and settles at 7,500 / 7,500 in a System Run — and confirmed it
 * against the alternative, with the frozen-money consequence spelled out.
 *
 * Why it can balance at all: an ancestor is credited on the leg the downline
 * BRANCH sits on (CommissionService::sideUnderParent()). A consultant who has
 * built BOTH legs accumulates on both and flushes normally. One who has built a
 * single leg does not — that is the accepted trade-off, not a bug.
 *
 * Do not flip this back without the same explicit, numbers-based confirmation:
 * the rule has now been set both ways and git history alone will not say which
 * is current. See commission-rules-2026-08.md.
 */
class UplineIncomeIsFlushedTest extends TestCase
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

    private function sponsor(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** Register + verify a student on $account/$leg, optionally back-dating the registration. */
    private function addStudent(Consultant $account, int $leg, ?Carbon $registeredAtUtc = null): User
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

        return $user;
    }

    private function promoteToConsultant(User $user, Consultant $sponsorAccount): Consultant
    {
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    /** Yesterday, so registrations land inside a default System Run cutoff. */
    private function yesterday(): Carbon
    {
        return Carbon::now('Asia/Colombo')->subDay()->setTime(10, 0)->setTimezone('UTC');
    }

    /** The upline row(s) sitting on the sponsor's MAIN account. */
    /**
     * Upline rows earned anywhere in this person's set of accounts.
     *
     * ⚠️ Widened 2026-08-21. Upline income now lands on the SPECIFIC PLACEMENT
     * ACCOUNT the downline hangs off, not on the owner's main account, so
     * looking only at $sponsor->id found nothing. accountIds() covers the
     * person's main plus every placement account, which is the right scope for
     * "what did this consultant earn from their downline".
     */
    private function uplineRows(Consultant $sponsor)
    {
        return Commission::whereIn('consultant_id', $sponsor->accountIds())
            ->where('type', 'bonus')
            ->with('payment')
            ->get()
            ->filter(fn ($c) => $c->isUplineCredit());
    }

    // ----- The headline rule ---------------------------------------------------

    /**
     * ⚠️ INVERTED 2026-08-19 — upline income is AUTO-APPROVED again.
     *
     * Third flip of this rule (auto 08-12, wait-for-a-run 08-14, auto again
     * now). The owner's reason is operational, not policy: one registration
     * credits EVERY ancestor, so hand-approving those rows does not survive the
     * volume — "manual danakot meke student la 200 ita wadi wen kota godka
     * manual krnn one, eka nisa eka auto dann upline 2500".
     */
    public function test_downline_income_is_auto_approved_at_verification(): void
    {
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1, $this->yesterday());
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);

        $this->addStudent($downline->hiddenAccounts()->first(), 1, $this->yesterday());

        $row = $this->uplineRows($sponsor)->first();

        $this->assertNotNull($row, 'The climbing row must still be recorded.');
        $this->assertSame('approved', $row->status, 'Upline income no longer waits for an accountant.');
        $this->assertNull(
            $row->approved_by,
            'An automatic approval must not stamp whichever accountant happened to be verifying.',
        );
    }

    /**
     * ...but the hold gate is still absolute. force: true skips ONLY the
     * release gate; a restricted or suspended consultant's income stops here
     * exactly as it always did.
     */
    public function test_auto_approval_still_cannot_release_held_income(): void
    {
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1, $this->yesterday());
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);

        // ⚠️ SCOPED TO THE ROWS THIS REGISTRATION CREATES (2026-09-02).
        //
        // uplineRows() spans accountIds() and this took ->first() off an
        // unordered set. That was unambiguous while only the placement account
        // could hold an upline row; since 2026-09-02 the climb reaches the MAIN
        // too, so the promotion above already left a legitimately APPROVED row
        // there — earned before the suspension — and ->first() could return it
        // instead of the one under test.
        //
        // Nothing about the gate changed; the test was picking the wrong row.
        $existing = $this->uplineRows($sponsor)->pluck('id')->all();

        // The ancestor is suspended BEFORE the downline's registration climbs.
        $sponsor->update(['finance_status' => 'suspended']);

        $this->addStudent($downline->hiddenAccounts()->first(), 1, $this->yesterday());

        $new = $this->uplineRows($sponsor)->reject(fn ($c) => in_array($c->id, $existing, true));

        $this->assertTrue($new->isNotEmpty(), 'guard: the registration did credit the suspended ancestor');

        foreach ($new as $row) {
            $this->assertSame(
                'pending',
                $row->status,
                'The hold / finance-status gate is not overridable by anything, including auto-approval.',
            );
        }
    }

    /** It sits on a leg, counting towards that leg's flush balance. */
    public function test_downline_income_counts_towards_the_ancestors_leg_balance(): void
    {
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1, $this->yesterday());
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);
        $downlineAccount = $downline->hiddenAccounts()->first();

        $this->addStudent($downlineAccount, 1, $this->yesterday());

        // ⚠️ The balance sits on the PLACEMENT ACCOUNT the downline hangs off,
        // not on the sponsor's main account (rule reset 2026-08-21).
        $group = Group::where('consultant_id', $sponsorAccount->id)->orderBy('id')->firstOrFail();
        $flush = app(ServiceGroupFlush::class);

        $leg1 = $flush->legBalance($sponsorAccount, $group, 1, ServiceGroupFlush::defaultCutoff());
        $leg2 = $flush->legBalance($sponsorAccount, $group, 2, ServiceGroupFlush::defaultCutoff());

        // 2 x 2,500: the downline's OWN registration onto this account, plus
        // the 2,500 that climbed when they registered their student. Both now
        // sit on the same account — before 2026-08-21 the climb went to the
        // main account and only the first was here.
        $this->assertSame(
            2 * (float) self::SLOT,
            $leg1 + $leg2,
            'The downline 2,500 is on a leg of the ancestor, exactly like their own students.',
        );
    }

    /** Approve-all must not sweep it up before a flush has released it. */
    /**
     * ⚠️ INVERTED 2026-08-19 alongside the test above — there is nothing left
     * for an accountant to approve, because the row was approved the moment the
     * payment was verified. The consultant's OWN slot income is untouched and
     * still waits for a System Run; only the climbing 2,500 is exempt.
     */
    public function test_downline_income_needs_no_accountant_action(): void
    {
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1, $this->yesterday());
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);
        $this->addStudent($downline->hiddenAccounts()->first(), 1, $this->yesterday());

        $before = $this->uplineRows($sponsor)->first()->fresh();
        $this->assertSame('approved', $before->status, 'Already approved before anyone clicks anything.');

        // Running the bulk approval changes nothing — it is already done.
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $this->assertSame('approved', $this->uplineRows($sponsor)->first()->fresh()->status);
    }

    /**
     * The whole point of the change: with downline on BOTH legs the ancestor's
     * account balances and the System Run settles it.
     */
    public function test_downline_income_on_both_legs_balances_and_flushes(): void
    {
        $sponsor = $this->sponsor();

        // ⚠️ One downline per PLACEMENT ACCOUNT, not per tier (2026-08-18).
        //
        // ⚠️ REWRITTEN 2026-08-21. Upline income lands on the PLACEMENT ACCOUNT
        // the downline hangs off, on the leg the downline sits on INSIDE that
        // account. So one account's two legs are filled by two downlines under
        // the SAME account on opposite tiers — not by one downline under each
        // of two accounts, which now fills two different accounts' leg 1.
        $account = $sponsor->hiddenAccounts()->orderBy('id')->first();

        foreach ([1, 2] as $leg) {
            $downlineUser = $this->addStudent($account, $leg, $this->yesterday());
            $downline = $this->promoteToConsultant($downlineUser, $account);
            $this->addStudent($downline->hiddenAccounts()->first(), 1, $this->yesterday());
        }

        $group = Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
        $result = app(ServiceGroupFlush::class)->flush($account, $group, ServiceGroupFlush::defaultCutoff());

        $this->assertTrue($result['flushed'], $result['reason']);

        // ⚠️ REVISED 2026-08-19. The run still FIRES — downline income sits on
        // the ancestor's legs and balances them exactly as before — but it has
        // nothing left to release, because the rows were auto-approved at
        // verification. They are SWEPT off the leg instead: no second payment,
        // and nothing forfeited to the company.
        // The account holds two KINDS of row now:
        //  - the two downlines' own registrations onto it, still PENDING
        //    (a consultant's own slot income is not auto-approved), so the run
        //    releases them — 2 x 2,500;
        //  - the two climbed 2,500s, already APPROVED at verification, so the
        //    run only sweeps them off the leg and pays nothing again.
        $this->assertSame(2 * (float) self::SLOT, $result['payout'], 'The still-pending own registrations are what this run releases.');
        $this->assertSame(0.0, $result['forfeit'], 'And sweeping must never book already-paid money as company revenue.');
        $this->assertSame(2, $result['swept'], 'Both auto-approved upline rows are taken off the leg.');

        // Both legs read zero afterwards, which is what lets the next pair build.
        $flush = app(ServiceGroupFlush::class);
        $this->assertSame(0.0, $flush->legBalance($account, $group, 1));
        $this->assertSame(0.0, $flush->legBalance($account, $group, 2));
    }

    /** A flush releases; an accountant still approves. */
    public function test_once_flushed_the_downline_income_becomes_approvable(): void
    {
        $sponsor = $this->sponsor();

        // Two downlines under the SAME placement account, on opposite tiers —
        // see the test above for why that is what fills one account's two legs.
        $account = $sponsor->hiddenAccounts()->orderBy('id')->first();

        foreach ([1, 2] as $leg) {
            $downlineUser = $this->addStudent($account, $leg, $this->yesterday());
            $downline = $this->promoteToConsultant($downlineUser, $account);
            $this->addStudent($downline->hiddenAccounts()->first(), 1, $this->yesterday());
        }

        $group = Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
        app(ServiceGroupFlush::class)->flush($account, $group, ServiceGroupFlush::defaultCutoff());

        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        // 4 x 2,500: two downlines' own registrations onto this account, plus
        // the two 2,500s that climbed when each registered a student. Both
        // kinds now land on the same account.
        $this->assertSame(
            4 * (float) self::SLOT,
            (float) Commission::where('consultant_id', $account->id)
                ->where('type', 'bonus')
                ->whereIn('status', ['approved', 'paid'])
                ->sum('amount'),
        );
    }

    // ----- The consultant's dashboard ------------------------------------------

    /**
     * The Total Earned card shows money that arrived through a balanced
     * release. Downline income now arrives that way, so it belongs there —
     * the isUplineCredit() exclusion added on 08-13 was removed with this
     * change ("group balance wela ena ewa withri ethan penwann one").
     */
    public function test_flushed_downline_income_counts_on_the_consultant_dashboard(): void
    {
        $sponsorUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $sponsor = $sponsorUser->consultant;
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        foreach ([1, 2] as $leg) {
            $downlineUser = $this->addStudent($sponsorAccount, $leg, $this->yesterday());
            $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);
            $this->addStudent($downline->hiddenAccounts()->first(), 1, $this->yesterday());
        }

        $group = Group::where('consultant_id', $sponsor->id)->orderBy('id')->firstOrFail();
        app(ServiceGroupFlush::class)->flush($sponsor, $group, ServiceGroupFlush::defaultCutoff());

        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $stats = $this->actingAs($sponsorUser)->get(route('consultant.dashboard'))->viewData('stats');

        $this->assertGreaterThanOrEqual(
            2 * (float) self::SLOT,
            $stats['total_earned'],
            'A flushed release counts here regardless of whether it came from own students or downline.',
        );
    }

    /** Unflushed downline income must still be kept off that card. */
    public function test_unflushed_downline_income_stays_off_the_dashboard(): void
    {
        $sponsorUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $sponsor = $sponsorUser->consultant;
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1, $this->yesterday());
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);
        $this->addStudent($downline->hiddenAccounts()->first(), 1, $this->yesterday());

        $stats = $this->actingAs($sponsorUser)->get(route('consultant.dashboard'))->viewData('stats');

        $this->assertSame(
            0.0,
            $stats['total_earned'] - ((float) Commission::whereIn('consultant_id', $sponsor->accountIds())
                ->where('type', 'referral')->whereIn('status', ['approved', 'paid'])->sum('amount')),
            'Before a flush the only thing on this card is the direct-add bonus.',
        );
    }

    // ----- Unchanged neighbours ------------------------------------------------

    public function test_the_direct_bonus_is_still_never_carried_up_the_chain(): void
    {
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1, $this->yesterday());
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);

        $before = (float) Commission::where('consultant_id', $sponsor->id)->where('type', 'referral')->sum('amount');

        $this->addStudent($downline->hiddenAccounts()->first(), 1, $this->yesterday());

        $this->assertSame(
            $before,
            (float) Commission::where('consultant_id', $sponsor->id)->where('type', 'referral')->sum('amount'),
            'The 4,000 belongs only to whoever personally registered the student.',
        );
    }

    /** A restricted consultant's downline income is held, and a flush must not touch it. */
    public function test_held_downline_income_is_not_flushed(): void
    {
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        foreach ([1, 2] as $leg) {
            $downlineUser = $this->addStudent($sponsorAccount, $leg, $this->yesterday());
            $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);
            $this->addStudent($downline->hiddenAccounts()->first(), 1, $this->yesterday());
        }

        Consultant::whereKey($sponsor->id)->update(['finance_status' => 'restricted']);
        Commission::where('consultant_id', $sponsor->id)->where('status', 'pending')->update(['held_at' => now()]);

        $group = Group::where('consultant_id', $sponsor->id)->orderBy('id')->firstOrFail();
        $result = app(ServiceGroupFlush::class)->flush($sponsor, $group, ServiceGroupFlush::defaultCutoff());

        $this->assertFalse($result['flushed'], 'Held income is neither released nor forfeited.');
        $this->assertSame(0, Commission::where('consultant_id', $sponsor->id)->where('status', 'cancelled')->count());
    }
}
