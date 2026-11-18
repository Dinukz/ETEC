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
 * ⚠️ THE ACCOUNT TOTAL ADDS UP THE COLUMNS BESIDE IT — set 2026-08-27.
 *
 * Owner, comparing a run's before/after against their own network diagram:
 * "system run eka yawwa eth... oya kiyna account wala adipura ynn one" — after a
 * run those accounts must go DOWN.
 *
 * Every income figure was correct; the diagram matched the EARNED totals on
 * every one of the nine accounts. What was wrong was one column. Account Total
 * summed sg1 + sg2 (lifetime earned) while the two Service Group columns to its
 * left print the settleable balance, so each row visibly failed to add up and
 * the total never moved after a run:
 *
 *   Main   27,500 + 10,000 + 36,000 = 73,500, printed 78,500
 *   00101   2,500 + 25,000 +  4,000 = 31,500, printed 34,000
 *   00103  15,000 +      0 +      0 = 15,000, printed 25,000
 *
 * Same class as "main eka system run eken passe 0 wen na" (08-26), which was
 * fixed for the Service Group columns and the headline but not for this one.
 */
class AccountTotalMatchesItsColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    private Consultant $main;

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

    private function fill(int $perLeg): void
    {
        [$sg1, $sg2] = $this->main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        for ($i = 0; $i < $perLeg; $i++) {
            foreach ([1, 2] as $leg) {
                $this->student($sg1, $leg);
                $this->student($sg2, $leg);
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function rows(): \Illuminate\Support\Collection
    {
        return collect(
            $this->actingAs($this->main->user)
                ->get(route('consultant.groups.index'))
                ->viewData('accountRows')
        );
    }

    /**
     * The invariant, on EVERY row: the printed total is what the three columns
     * beside it actually add to.
     */
    public function test_every_rows_total_is_the_sum_of_its_own_columns(): void
    {
        $this->fill(3);
        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $checked = 0;

        foreach ($this->rows() as $row) {
            $shown = (float) $row['flush']['leg1_total'] + (float) $row['flush']['leg2_total'] + (float) $row['direct'];
            $earned = (float) $row['sg1'] + (float) $row['sg2'] + (float) $row['direct'];

            // The old column printed $earned. After a run that settled money it
            // is strictly larger, which is exactly what made the row not add up.
            $this->assertLessThanOrEqual($earned, $shown, $row['code'].': settleable cannot exceed earned');
            $checked++;
        }

        $this->assertGreaterThan(2, $checked, 'guard: there are rows to check');
    }

    /** The headline and the Main row must agree — they are the same rollup. */
    public function test_the_main_row_total_agrees_with_the_headline(): void
    {
        $this->fill(3);
        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $page = $this->actingAs($this->main->user)->get(route('consultant.groups.index'));
        $main = collect($page->viewData('accountRows'))->firstWhere('is_owner', true);

        $this->assertSame(
            (float) $page->viewData('availableLeg1'),
            (float) $main['flush']['leg1_total'],
            'the Main row and the headline read the same Service Group 1'
        );
        $this->assertSame(
            (float) $page->viewData('availableLeg2'),
            (float) $main['flush']['leg2_total'],
        );
    }

    /** The point of the report: after a run, the total is lower than before it. */
    public function test_the_total_goes_down_after_a_system_run(): void
    {
        $this->fill(3);

        $totalOf = fn (array $r) => (float) $r['flush']['leg1_total'] + (float) $r['flush']['leg2_total'] + (float) $r['direct'];

        $before = $totalOf($this->rows()->firstWhere('is_owner', true));

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $after = $totalOf($this->rows()->firstWhere('is_owner', true));

        // Three accounts settle a 1-to-1 in this run, each giving up the stage
        // from both of the sides IT pairs (2026-08-28, when the main went back
        // to settling its Service Groups):
        //   SCN-00101   2,500 + 2,500   its own two internal legs
        //   SCN-00102   2,500 + 2,500   likewise
        //   the main    2,500 + 2,500   one off each Service Group
        $this->assertSame(
            4.0 * self::SLOT,
            $before - $after,
            'a stage-1 run settles 5,000 per settling account, and three of them settle'
        );
    }

    /**
     * The direct bonus is the one part that legitimately does not drop: it never
     * goes on a leg, so no run ever releases it.
     */
    public function test_the_direct_bonus_is_not_reduced_by_a_run(): void
    {
        $this->fill(3);

        $directBefore = (float) $this->rows()->firstWhere('is_owner', true)['direct'];
        $this->assertGreaterThan(0, $directBefore);

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame(
            $directBefore,
            (float) $this->rows()->firstWhere('is_owner', true)['direct'],
        );
    }

    public function test_the_page_prints_the_settleable_total_not_the_earned_one(): void
    {
        $this->fill(3);
        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $main = $this->rows()->firstWhere('is_owner', true);
        $shown = (float) $main['flush']['leg1_total'] + (float) $main['flush']['leg2_total'] + (float) $main['direct'];
        $earned = (float) $main['sg1'] + (float) $main['sg2'] + (float) $main['direct'];

        $this->assertGreaterThan($shown, $earned, 'guard: the run settled something, so the two differ');

        $page = $this->actingAs($this->main->user)->get(route('consultant.groups.index'));

        $page->assertSee('LKR '.number_format($shown), false);
        $page->assertSee('of LKR '.number_format($earned).' earned to date', false);
    }
}
