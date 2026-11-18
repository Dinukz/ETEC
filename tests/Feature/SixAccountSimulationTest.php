<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A WALK-THROUGH, not a pass/fail rule.
 *
 * Owner asked (2026-08-21) to see the real numbers without touching the
 * database: one consultant with SCN-100 (main) + 101 + 102, then a bought set
 * of 4th (main) + 5th + 6th, ten students on every account, then approve and
 * mark paid — and report the group income each account ends up with.
 *
 * Everything below runs the REAL code paths (PaymentService::verify ->
 * CommissionService -> ServiceGroupFlush -> the approve/paid controllers), so
 * the figures it prints are what the app would actually produce.
 */
class SixAccountSimulationTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    private const COURSE_PRICE = 39000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    /** Register one student on $account, seated on $leg, and verify the payment. */
    private function addStudent(Consultant $account, int $leg, ?string $paidAt = null): void
    {
        $user = User::factory()->create();

        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $account->id,
            'requested_tier' => $leg,
            'amount' => self::COURSE_PRICE,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => $paidAt ?? now()->subDay(),
        ]);

        // The System Run's cutoff filters on payments.created_at, so backdate
        // it to yesterday — otherwise every registration here is "today" and
        // the default run (which settles yesterday) would skip the lot.
        // created_at is not fillable, hence the direct write.
        Payment::whereKey($payment->id)->update(['created_at' => now()->subDay()]);

        app(PaymentService::class)->verify($payment->fresh(), $this->accountant());
    }

    public function test_walkthrough_six_accounts_ten_students_each(): void
    {
        $out = [];
        $line = function (string $s) use (&$out) {
            $out[] = $s;
        };

        // ---- 1. The consultant and their opening set ----------------------
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;

        $opening = $consultant->hiddenAccounts()->orderBy('id')->get();

        $line('STEP 1 — opening set');
        $line("  main  {$consultant->scn_code}");
        $line("  #1    {$opening[0]->scn_code}  (IS Service Group 1)");
        $line("  #2    {$opening[1]->scn_code}  (IS Service Group 2)");

        // ---- 2. Buy a course -> a further set of three --------------------
        $buy = Payment::create([
            'payment_code' => 'PYM'.Str::random(8),
            'user_id' => $consultantUser->id,
            'course_id' => Course::first()->id,
            'amount' => self::COURSE_PRICE,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now()->subDay(),
        ]);

        Student::firstOrCreate(
            ['user_id' => $consultantUser->id],
            ['student_code' => 'SRN'.Str::random(8), 'status' => 'active', 'enrolled_at' => now()],
        );

        Payment::whereKey($buy->id)->update(['created_at' => now()->subDay()]);

        app(PaymentService::class)->verify($buy->fresh(), $this->accountant());

        $all = $consultant->hiddenAccounts()->orderBy('id')->get();
        $this->assertCount(5, $all, 'Opening pair + a bought set of three.');

        $line('');
        $line('STEP 2 — bought a course, new set opened');
        $line("  #3 (set main) {$all[2]->scn_code}  (represents NO leg)");
        $line("  #4            {$all[3]->scn_code}  (IS Service Group 1)");
        $line("  #5            {$all[4]->scn_code}  (IS Service Group 2)");

        // ---- 3. Ten students on EVERY account, split 5 / 5 ----------------
        $accounts = collect([$consultant])->concat($all);

        foreach ($accounts as $account) {
            for ($i = 0; $i < 5; $i++) {
                $this->addStudent($account, 1);
                $this->addStudent($account, 2);
            }
        }

        $line('');
        $line('STEP 3 — 10 students on each of the '.$accounts->count().' accounts (5 per side)');
        $line('  total students registered: '.($accounts->count() * 10));

        // ---- 4. The System Run ------------------------------------------
        $run = app(ServiceGroupFlush::class)->runAll();

        $line('');
        $line('STEP 4 — System Run');
        $line('  accounts settled : '.$run['flushed']);
        $line('  released         : LKR '.number_format($run['payout']));
        $line('  FORFEITED to co. : LKR '.number_format($run['forfeit']));

        // ---- 4b. WHY most accounts did not settle -------------------------
        // The 12-students-unlocks-the-next-account rule: each account after the
        // first placement stays shut until the PREVIOUS one has 12 students.
        // Ten is not twelve, so most of these are locked and the run skips them.
        $staging = app(\App\Services\ServiceGroupStaging::class);

        $line('');
        $line('  per-account state at run time:');
        foreach ($accounts as $account) {
            // isUnlocked -> owningPerson() reads the `owner` relation, and
            // preventLazyLoading() is on outside production.
            $account->loadMissing('owner');
            $unlocked = $staging->isUnlocked($account);
            $line(sprintf(
                '    %-12s %-10s students=%d',
                $account->scn_code,
                $unlocked ? 'UNLOCKED' : 'LOCKED',
                $staging->studentsOn($account->id),
            ));
        }

        // ---- 5. Approve what the run released -----------------------------
        $this->actingAs($this->admin())->patch(route('commissions.approve-all'));

        $naturallyApproved = (float) Commission::whereIn('status', ['approved', 'paid'])->sum('amount');

        // ---- 5b. Then force the rest through, and count it SEPARATELY -----
        // This is the accountant's per-row override. It is NOT what the system
        // does on its own — without it every locked account below stays at 0.
        $forced = 0.0;

        foreach (Commission::where('status', 'pending')->whereNull('held_at')->get() as $c) {
            $this->actingAs($this->admin())->patch(route('commissions.approve', $c));
            $forced += (float) $c->amount;
        }

        // ---- 6. Mark every approved row paid ------------------------------
        foreach (Commission::where('status', 'approved')->get() as $c) {
            $this->actingAs($this->admin())->patch(route('commissions.paid', $c));
        }

        // ---- 7. Report ----------------------------------------------------
        $line('');
        $line('STEP 5/6 — approved, then marked paid');
        $line('');
        $line(sprintf(
            '%-12s %-14s %12s %12s %12s %12s',
            'ACCOUNT', 'ROLE', 'SVC GROUP 1', 'SVC GROUP 2', 'DIRECT', 'TOTAL',
        ));
        $line(str_repeat('-', 80));

        $grand = ['sg1' => 0.0, 'sg2' => 0.0, 'direct' => 0.0];

        foreach ($accounts as $account) {
            $leg = $account->serviceGroupLeg();
            $role = $account->is_hidden
                ? ($leg === null ? 'set main' : 'IS SG'.$leg)
                : 'main';

            $paid = fn (string $type, ?int $tier = null) => (float) Commission::where('consultant_id', $account->id)
                ->where('type', $type)
                ->where('status', 'paid')
                ->when($tier !== null, fn ($q) => $q->where('tier', $tier))
                ->sum('amount');

            $sg1 = $paid('bonus', 1);
            $sg2 = $paid('bonus', 2);
            $direct = $paid('referral');

            $grand['sg1'] += $sg1;
            $grand['sg2'] += $sg2;
            $grand['direct'] += $direct;

            $line(sprintf(
                '%-12s %-14s %12s %12s %12s %12s',
                $account->scn_code, $role,
                number_format($sg1), number_format($sg2), number_format($direct),
                number_format($sg1 + $sg2 + $direct),
            ));
        }

        $line(str_repeat('-', 80));
        $line(sprintf(
            '%-12s %-14s %12s %12s %12s %12s',
            'TOTAL', '',
            number_format($grand['sg1']), number_format($grand['sg2']), number_format($grand['direct']),
            number_format($grand['sg1'] + $grand['sg2'] + $grand['direct']),
        ));

        // ---- 8. Where the rest of the money went --------------------------
        $cancelled = (float) Commission::where('status', 'cancelled')->sum('amount');
        $stillPending = (float) Commission::where('status', 'pending')->sum('amount');
        $recorded = (float) Commission::sum('amount');
        $seats = GroupMember::whereIn('group_id', Group::whereIn('consultant_id', $accounts->pluck('id'))->select('id'))->count();

        $line('');
        $line('RECONCILIATION');
        $line('  commission recorded in total : LKR '.number_format($recorded));
        $line('  ...of which PAID             : LKR '.number_format($grand['sg1'] + $grand['sg2'] + $grand['direct']));
        $line('  ...of which CANCELLED (co.)  : LKR '.number_format($cancelled));
        $line('  ...still pending/held        : LKR '.number_format($stillPending));
        $line('  seats across these accounts  : '.$seats);

        $line('');
        $line('⚠️  HOW MUCH OF THAT THE SYSTEM PAID ON ITS OWN');
        $line('  released by the System Run + approved : LKR '.number_format($naturallyApproved));
        $line('  pushed through by the per-row override: LKR '.number_format($forced));
        $line('  (without the override the second figure stays unpaid —');
        $line('   those accounts are LOCKED until the previous one reaches 12 students)');

        fwrite(STDERR, "\n".implode("\n", $out)."\n\n");

        $this->assertTrue(true);
    }
}
