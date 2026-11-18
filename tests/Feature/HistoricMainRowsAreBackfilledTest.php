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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️⚠️ HOW THE 2026-09-02 CHANGE REACHES MONEY THAT IS ALREADY IN THE DATABASE.
 *
 * Giving the main account its own commission rows only affects registrations
 * verified AFTER the deploy — `handleVerifiedPayment()` runs once, at
 * verification, and never looks at a payment again. Every consultant already
 * live therefore has a main holding nothing, and their Service Groups page
 * would drop from (say) "SG1 5,000 · SG2 12,500" to "0 · 0" the moment the
 * rollup stops standing in for it.
 *
 * That would read as the change LOSING their money, which is the single worst
 * outcome available here.
 *
 * ⚠️ NO NEW BACKFILL WAS WRITTEN, DELIBERATELY. `commissions:backfill` already
 * replays every verified payment back through the engine, and placeInto() is
 * idempotent per (seat, earning event) — so the hop that used to stop below the
 * main now runs, and every hop that already paid is refused a second time. A
 * purpose-built backfill would have been a second implementation of the climb,
 * which is exactly how the leg rule ended up living in three places.
 *
 * The host has no CLI, so the button is Commission Health → Repair, which calls
 * the same command (CommissionHealthController::repair).
 *
 * ⚠️ THIS TEST IS THE DEPLOY CHECKLIST. If it fails, do not ship the change
 * without a plan for existing balances.
 */
class HistoricMainRowsAreBackfilledTest extends TestCase
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

        $payment->forceFill(['created_at' => now()->subDays(3)])->save();

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    /**
     * Put the database into the state a LIVE install is in on the morning of the
     * deploy: every account credited exactly as the pre-2026-09-02 engine would
     * have, and the main holding nothing at all.
     *
     * Done by deleting what the climb's last hop produced, rather than by
     * reintroducing the old code path — the point is to prove the REPAIR fills a
     * gap, whatever left the gap there.
     */
    private function stripTheMainsRows(): void
    {
        $groupIds = Group::where('consultant_id', $this->main->id)->pluck('id');

        Commission::where('consultant_id', $this->main->id)
            ->where('type', \App\Services\CommissionService::TYPE_SLOT)
            ->delete();

        GroupMember::whereIn('group_id', $groupIds)->delete();
    }

    private function ownRows(Consultant $account): float
    {
        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', \App\Services\CommissionService::TYPE_SLOT)
            ->whereNull('released_at')
            ->sum('amount');
    }

    // ------------------------------------------------------------------------

    public function test_the_repair_gives_a_pre_existing_main_the_rows_it_never_earned(): void
    {
        [$sg1, $sg2] = $this->main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $this->student($sg1, 1);
        $this->student($sg1, 2);
        $this->student($sg2, 1);

        $childrenBefore = [$this->ownRows($sg1->fresh()), $this->ownRows($sg2->fresh())];

        $this->stripTheMainsRows();

        $this->assertSame(0.0, $this->ownRows($this->main->fresh()), 'guard: the pre-deploy state — the main holds nothing');

        $this->artisan('commissions:backfill')->assertSuccessful();

        // Two students climbed through Service Group 1 and one through Service
        // Group 2, so that is what the main is owed on each side.
        $this->assertSame(
            2.0 * self::SLOT,
            (float) Commission::where('consultant_id', $this->main->id)
                ->where('type', \App\Services\CommissionService::TYPE_SLOT)
                ->where('tier', 1)->sum('amount'),
            'the main is credited for both of Service Group 1\'s students',
        );
        $this->assertSame(
            (float) self::SLOT,
            (float) Commission::where('consultant_id', $this->main->id)
                ->where('type', \App\Services\CommissionService::TYPE_SLOT)
                ->where('tier', 2)->sum('amount'),
            'and for Service Group 2\'s one student, on the other side',
        );

        // ⚠️ THE SEATS COME BACK TOO, NOT JUST THE MONEY (2026-09-07).
        // The Service Groups student list is a SEAT query
        // (GroupController::index() -> GroupMember on the selected account's
        // groups), so on a pre-deploy database the main lists no students at
        // all — the owner reported exactly that: "upline eken ena student la...
        // mukuth methana penwan na, direct registration tika withri penne".
        // placeInto() writes the seat before it decides on any money, so the
        // same replay that credits the main also re-seats it. If this ever
        // stops being true, pressing Repair silently fixes the totals and
        // leaves the student list empty, which is indistinguishable from the
        // bug it was pressed to fix.
        $mainSeats = GroupMember::whereIn(
            "group_id",
            Group::where("consultant_id", $this->main->id)->select("id"),
        )->get();

        $this->assertCount(3, $mainSeats, "all three students are seated on the main again");
        $this->assertSame(
            [1, 1, 2],
            $mainSeats->pluck("tier")->sort()->values()->all(),
            "and on the sides they climbed through",
        );

        $this->assertSame(
            $childrenBefore,
            [$this->ownRows($sg1->fresh()), $this->ownRows($sg2->fresh())],
            '⚠️ and not a rupee moved off the children — the repair adds, it never re-attributes',
        );
    }

    /** ⚠️ The repair is run by hand, sometimes twice. It must never pay twice. */
    public function test_running_the_repair_again_adds_nothing(): void
    {
        [$sg1] = $this->main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $this->student($sg1, 1);
        $this->student($sg1, 2);

        $this->stripTheMainsRows();
        $this->artisan('commissions:backfill')->assertSuccessful();

        $afterFirst = (float) Commission::sum('amount');
        $rows = Commission::count();

        $this->artisan('commissions:backfill')->assertSuccessful();

        $this->assertSame($afterFirst, (float) Commission::sum('amount'), 'A second repair must add no money.');
        $this->assertSame($rows, Commission::count(), 'A second repair must add no rows.');
    }

    /**
     * ⚠️ THE POINT OF THE WHOLE EXERCISE, end to end: after the repair the main
     * holds a real balance on both sides, so the very next System Run settles it
     * — which is what eight reports asked for and never got.
     */
    public function test_a_repaired_main_settles_at_the_next_run(): void
    {
        [$sg1, $sg2] = $this->main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $this->student($sg1, 1);
        $this->student($sg2, 1);

        $this->stripTheMainsRows();
        $this->artisan('commissions:backfill')->assertSuccessful();

        $flush = app(ServiceGroupFlush::class);
        $group = Group::where('consultant_id', $this->main->id)->orderBy('id')->firstOrFail();
        $state = $flush->state($this->main->fresh(), $group, ServiceGroupFlush::defaultCutoff());

        $this->assertSame((float) self::SLOT, $state['leg1'], 'Service Group 1 holds its own 2,500...');
        $this->assertSame((float) self::SLOT, $state['leg2'], '...and Service Group 2 holds its own.');
        $this->assertTrue($state['can_flush'], 'so the run offers it');

        $result = $flush->flush(
            $this->main->fresh(),
            $group,
            ServiceGroupFlush::defaultCutoff(),
        );

        $this->assertTrue($result['flushed']);
        $this->assertSame(2.0 * self::SLOT, (float) $result['payout'], '1-to-1: 2,500 off each side.');
    }
}
