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
 * ⚠️ EACH ACCOUNT PAIRS ITS OWN TWO SIDES — set 2026-08-27 (owner-chosen).
 *
 * Owner, with three worked examples off a real run:
 *
 *   101: "eke service group 1 eke patan ganakot 2500i, service group 2 eke
 *   27500k... dn meka run unaham service 1 eke 2500 adu wela na... logic eka
 *   hatiyat 1to1 eke 2500 balance wela group 1 eken 2500i group 2 eken 2500 adu
 *   wela ynn one. anith stage walth depaththenm 5000k saha 7500k adu wenn one."
 *
 *   102: "meke service group balance nathuw adu wela thiyeno... eka wardi,
 *   sampuran ehem wenn ba. eka group ehek wath stage samana nathiw system run
 *   yann ba."
 *
 *   109: "5000 gane depaththenm thiyeno, stage eke 1to1 eka thiyenne, eth meke
 *   eka paththakin [5000]k adu wela. eka hari nm 2500k adu wenn one."
 *
 * So the settlement unit is the ACCOUNT, and its two sides must move together:
 * it releases the stage amount from BOTH of its own legs, and only when both
 * hold at least that much. Nothing is ever released from an account that is not
 * itself balanced.
 *
 * ⚠️ THIS IS THE RULE, AND IT IS NOW THE ONLY ONE (settled 2026-08-30).
 *
 * The main's rollup was brought back on 08-28 and removed again on 08-30 after
 * two days of it produced five separate money faults in a single run. A main's
 * Service Group is the SUM of one child's two legs, so settling there spends a
 * child's rupees without the child ever being balanced.
 *
 * The whole rule now lives in SystemRunRules and every caller reads it from
 * there — flush(), state() and fullPlan() hold no copy of their own, which is
 * what let them drift apart four times.
 */
class EachAccountPairsItsOwnSidesTest extends TestCase
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

    private function student(Consultant $account, int $leg): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now()->subDays(3),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now()->subDays(3),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    /** @return array{0: Consultant, 1: Consultant} SCN-00101 and SCN-00102 */
    private function pair(): array
    {
        return $this->main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();
    }

    /** @return array{0: float, 1: float} one account's two leg balances, as the page shows them */
    private function legs(Consultant $account): array
    {
        $row = collect(
            $this->actingAs($this->main->user)->get(route('consultant.groups.index'))->viewData('accountRows')
        )->firstWhere('id', $account->id);

        return [(float) $row['flush']['leg1_total'], (float) $row['flush']['leg2_total']];
    }

    private function systemRun(): array
    {
        return app(ServiceGroupFlush::class)->runAll(now()->addDay());
    }

    /** 101: 2,500 / 27,500 at 1-to-1 must give up 2,500 from EACH side. */
    public function test_a_balanced_account_gives_up_the_stage_from_both_sides(): void
    {
        [$a101] = $this->pair();

        $this->student($a101, 1);
        for ($i = 0; $i < 11; $i++) {
            $this->student($a101, 2);
        }

        $this->assertSame([2500.0, 27500.0], $this->legs($a101), 'guard: the shape the owner reported');

        $this->systemRun();

        $this->assertSame(
            [0.0, 25000.0],
            $this->legs($a101),
            '2,500 off Service Group 1 AND 2,500 off Service Group 2'
        );
    }

    /** 102: 12,500 / 0 must give up NOTHING — "sampuran ehem wenn ba". */
    public function test_an_unbalanced_account_releases_nothing(): void
    {
        [, $a102] = $this->pair();

        for ($i = 0; $i < 5; $i++) {
            $this->student($a102, 1);
        }

        $this->assertSame([12500.0, 0.0], $this->legs($a102), 'guard: one side is empty');

        $this->systemRun();

        $this->assertSame(
            [12500.0, 0.0],
            $this->legs($a102),
            'not one rupee moves until both sides can pair'
        );
    }

    /**
     * Both together, exactly the run the owner sent.
     *
     * ⚠️ INVERTED 08-28 (the main also spent the surplus), RESTORED 08-30 when
     * that produced five money faults in one run. Each account settles its own
     * two sides and nothing else touches them, so 102 — which cannot pair —
     * keeps every rupee.
     */
    public function test_the_owners_two_accounts_behave_independently_in_one_run(): void
    {
        [$a101, $a102] = $this->pair();

        $this->student($a101, 1);
        for ($i = 0; $i < 11; $i++) {
            $this->student($a101, 2);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->student($a102, 1);
        }

        $this->systemRun();

        $this->assertSame([0.0, 25000.0], $this->legs($a101), '101 pairs and pays');
        $this->assertSame([12500.0, 0.0], $this->legs($a102), '102 cannot pair, so it pays nothing');
    }

    /** 109: at 1-to-1 a side gives up 2,500 — never 5,000. */
    public function test_a_run_never_takes_more_than_the_current_stage(): void
    {
        [$a101] = $this->pair();

        for ($i = 0; $i < 2; $i++) {
            $this->student($a101, 1);
            $this->student($a101, 2);
        }

        $this->assertSame([5000.0, 5000.0], $this->legs($a101), 'guard: 5,000 a side, stage 1-to-1');

        $this->systemRun();

        $this->assertSame(
            [2500.0, 2500.0],
            $this->legs($a101),
            'a 1-to-1 takes 2,500 a side and leaves the rest for the next run'
        );
    }

    /** The ramp: 1-to-1, then 2-to-2, always from both sides. */
    public function test_the_stages_walk_and_always_take_from_both_sides(): void
    {
        [$a101] = $this->pair();

        for ($i = 0; $i < 8; $i++) {
            $this->student($a101, 1);
            $this->student($a101, 2);
        }

        foreach ([self::SLOT, 2 * self::SLOT] as $i => $step) {
            [$before1, $before2] = $this->legs($a101);

            $this->systemRun();

            [$after1, $after2] = $this->legs($a101);

            $this->assertSame((float) $step, $before1 - $after1, 'run #'.($i + 1).': Service Group 1');
            $this->assertSame((float) $step, $before2 - $after2, 'run #'.($i + 1).': Service Group 2');
        }
    }
}
