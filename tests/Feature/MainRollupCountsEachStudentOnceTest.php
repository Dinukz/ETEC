<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The main counts each student ONCE, at the highest point their money reached.
 *
 * Slot income climbs: a student seated deep credits their own account and then
 * every account above it, stopping at the one sitting directly under the main.
 * So summing the whole subtree counted the same student once per level they
 * passed through, and the main over-reported.
 *
 * Reported 2026-08-25 against SCN-00100 — SCN-00101 held 10,000, SCN-00106
 * (under 00101) 2,500 and SCN-00107 (under 00106) 5,000, and the main showed
 * 17,500: "me amount tika duplicate wela, meka wardi, methanat enn one amount
 * eka 10000".
 */
class MainRollupCountsEachStudentOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function owner(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function verify(User $buyer, Consultant $placement, int $tier): void
    {
        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $buyer->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $placement->id,
            'requested_tier' => $tier,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($payment, User::role('admin')->first());
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->assignRole('student');

        return $user;
    }

    /**
     * Builds the owner's exact shape:
     *   main → 00101 (Service Group 1) → a bought set → its leg.
     *
     * @return array{0: Consultant, 1: Consultant, 2: Consultant, 3: Consultant}
     */
    private function tree(): array
    {
        $main = $this->owner();
        $pair = $main->hiddenAccounts()->orderBy('id')->get();

        // A set bought under the first opening account.
        $this->verify($main->user, $pair[0], 2);

        $accounts = $main->fresh()->hiddenAccounts()->orderBy('id')->get();

        return [$main->fresh(), $pair[0], $accounts[2], $accounts[4]];
    }

    /** ⚠️ THE REPORTED CASE. One deep student must not be counted twice. */
    public function test_a_deep_students_money_is_counted_once_by_the_main(): void
    {
        [$main, $directChild, , $deepLeg] = $this->tree();

        Commission::query()->delete();

        $this->verify($this->student(), $deepLeg, 2);

        // The climb really does put it in two places...
        $this->assertSame(2500.0, (float) Commission::where('consultant_id', $deepLeg->id)->where('type', 'bonus')->sum('amount'));
        $this->assertSame(2500.0, (float) Commission::where('consultant_id', $directChild->id)->where('type', 'bonus')->sum('amount'));

        // ...but the main counts the student once, where their money ended up.
        $response = $this->actingAs($main->user)->get(route('consultant.groups.index'));
        $response->assertOk();

        $tierTotals = $response->viewData('tierTotals');
        $headline = (float) ($tierTotals[1]->total ?? 0) + (float) ($tierTotals[2]->total ?? 0);

        $this->assertSame(2500.0, $headline, 'One student, one 2,500 on the main — not 5,000.');
    }

    /** The owner's arithmetic: 10,000, not 17,500. */
    public function test_the_main_reports_only_what_hangs_directly_off_it(): void
    {
        [$main, $directChild, , $deepLeg] = $this->tree();

        Commission::query()->delete();

        // Two students deep, one directly on the child.
        $this->verify($this->student(), $deepLeg, 2);
        $this->verify($this->student(), $deepLeg, 2);
        $this->verify($this->student(), $directChild, 1);

        $onChild = (float) Commission::where('consultant_id', $directChild->id)->where('type', 'bonus')->sum('amount');
        $onDeep = (float) Commission::where('consultant_id', $deepLeg->id)->where('type', 'bonus')->sum('amount');

        $this->assertSame(7500.0, $onChild, '2 climbed + 1 of its own.');
        $this->assertSame(5000.0, $onDeep, 'The 2 that sit there.');

        $response = $this->actingAs($main->user)->get(route('consultant.groups.index'));
        $response->assertOk();

        $tierTotals = $response->viewData('tierTotals');
        $headline = (float) ($tierTotals[1]->total ?? 0) + (float) ($tierTotals[2]->total ?? 0);

        $this->assertSame(
            7500.0,
            $headline,
            'Three students, three 2,500s — not 12,500, which would count the deep two twice.',
        );
    }

    /**
     * ⚠️ And the flush must agree, or the run would settle money the headline
     * never showed.
     */
    public function test_the_mains_flush_uses_the_same_direct_children(): void
    {
        [$main, $directChild, , $deepLeg] = $this->tree();

        Commission::query()->delete();

        $this->verify($this->student(), $deepLeg, 2);

        $group = \App\Models\Group::where('consultant_id', $main->id)->orderBy('id')->firstOrFail();
        // End of TODAY: the student was registered today, and the default cutoff
        // is end of yesterday, which would leave the legs legitimately empty.
        $state = app(ServiceGroupFlush::class)->state($main, $group, ServiceGroupFlush::latestSelectableCutoff());

        // ⚠️ leg*_total, NOT leg1/leg2 (2026-08-27).
        //
        // Since the ACCOUNT became the settlement unit, leg1/leg2 mean "what
        // THIS account can settle in this run" — for a main holding no students
        // of its own, correctly zero. leg*_total is the DISPLAY figure, still
        // rolled up over the whole Service Group, and it is what the headline
        // prints. Counting the student ONCE is the point of this file, and that
        // is unchanged.
        $this->assertSame(
            2500.0,
            $state['leg1_total'] + $state['leg2_total'],
            "The main's rolled-up legs hold the student once.",
        );
    }

    /** The DIRECT BONUS never climbs, so it still sums every account. */
    public function test_the_direct_bonus_still_counts_every_account(): void
    {
        [$main, , , $deepLeg] = $this->tree();

        Commission::query()->delete();

        $this->verify($this->student(), $deepLeg, 2);

        $rate = (float) \App\Models\CommissionSetting::current()->direct_commission;

        $response = $this->actingAs($main->user)->get(route('consultant.groups.index'));
        $response->assertOk();

        $this->assertSame(
            $rate,
            (float) $response->viewData('directIncome'),
            'Recorded in one place only, so it is not at risk of double counting.',
        );
    }
}
