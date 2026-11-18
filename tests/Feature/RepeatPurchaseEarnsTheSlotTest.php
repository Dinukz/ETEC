<?php

namespace Tests\Feature;

use App\Livewire\ServiceIncomeBoard;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\PaymentService;
use App\Services\RepeatPurchaseRules;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ⚠️ A REPEAT PURCHASE EARNS ITS OWN 2,500 ON THE ACCOUNT IT IS PLACED ON
 * (2026-10-22). See App\Services\RepeatPurchaseRules.
 *
 * Owner: "habi student register weddi hariyt enwa 2500 eth inn student or
 * consultant kenk buy kraddi 2500 calculate wen na meka fix krla denn".
 *
 * Reported on PYM-07242: Dinuka bought MASTER IN ELLIOTT WAVE placed on their
 * own SCN-00297. Their main (SCN-00295, Service Group 2) got its 2,500; SCN-00297
 * got nothing, because Dinuka already sat there from a September purchase whose
 * 2,500 had been removed by hand — and the per-seat guard counted that row.
 */
class RepeatPurchaseEarnsTheSlotTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    private User $consultantUser;

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
        ServiceGroupStaging::forget();

        $this->consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->main = $this->consultantUser->consultant;

        // After the rule began, unless a test says otherwise.
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00', 'Asia/Colombo'));
    }

    // ----- helpers -------------------------------------------------------------

    /** One of the consultant's two placement accounts, loaded on its own. */
    private function account(int $n = 1): Consultant
    {
        return $this->main->hiddenAccounts()->orderBy('id')->skip($n - 1)->firstOrFail();
    }

    private function student(): User
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function course(): Course
    {
        return Course::create([
            'title' => 'Course '.Str::random(6), 'slug' => 'course-'.Str::random(8),
            'price' => 39000, 'currency' => 'LKR', 'level' => 'beginner',
            'status' => 'published', 'published_at' => now(),
        ]);
    }

    private function pay(User $buyer, Consultant $account, int $leg, ?Course $course = null, string $type = 'full', ?float $amount = null): Payment
    {
        $course ??= Course::where('status', 'published')->orderBy('id')->firstOrFail();

        if (! Student::where('user_id', $buyer->id)->exists()) {
            Student::create([
                'user_id' => $buyer->id, 'student_code' => 'SRN'.Str::random(8),
                'status' => 'active', 'enrolled_at' => now(),
            ]);
        }

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => $amount ?? (float) $course->effective_price, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => $type, 'status' => 'pending', 'paid_at' => now(),
        ]);

        return app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    /** @return \Illuminate\Support\Collection<int, Commission> the live 2,500s one payment left on one account */
    private function slotsOn(Consultant $account, Payment $payment)
    {
        return Commission::where('consultant_id', $account->id)
            ->where('payment_id', $payment->id)
            ->where('type', CommissionService::TYPE_SLOT)
            ->where('status', '!=', 'cancelled')
            ->get();
    }

    private function seatsOf(User $person, Consultant $account): int
    {
        return GroupMember::whereIn('group_id', Group::where('consultant_id', $account->id)->select('id'))
            ->where('user_id', $person->id)
            ->count();
    }

    // ----- the owner's two cases -----------------------------------------------

    /** An existing STUDENT buys a second course on the same account. */
    public function test_a_returning_student_earns_the_2500_again_on_the_same_account(): void
    {
        $account = $this->account();
        $student = $this->student();

        $first = $this->pay($student, $account, 1);
        $second = $this->pay($student, $account, 1, $this->course());

        $this->assertCount(1, $this->slotsOn($account, $first));
        $this->assertCount(1, $this->slotsOn($account, $second), 'the second course earns the account its own 2,500');
        $this->assertSame(2500.0, (float) $this->slotsOn($account, $second)->first()->amount);

        // Only the money: still ONE seat, so the 12-student unlock count is unchanged.
        $this->assertSame(1, $this->seatsOf($student, $account));
        $this->assertSame(1, app(ServiceGroupStaging::class)->studentsOn($account->id));

        // The direct already paid per purchase, unchanged.
        $this->assertSame(2 * (float) self::DIRECT, (float) Commission::where('consultant_id', $account->id)
            ->where('type', CommissionService::TYPE_DIRECT)->sum('amount'));
    }

    /**
     * The owner's own case: a consultant buys again on their own account whose
     * earlier 2,500 was removed by hand.
     */
    public function test_a_consultant_buying_again_earns_the_2500_even_after_the_old_one_was_removed(): void
    {
        $account = $this->account(2);

        $september = $this->pay($this->consultantUser, $account, 1);
        $old = $this->slotsOn($account, $september)->firstOrFail();

        Livewire::actingAs(User::where('email', 'superadmin@etec.lk')->firstOrFail())
            ->test(ServiceIncomeBoard::class)
            ->call('askRemove', $old->id)
            ->call('removeRow', $old->id);
        $this->assertSame('cancelled', $old->fresh()->status, 'guard: removed by hand, like 6349 on SCN-00297');

        $mainBefore = (float) Commission::where('consultant_id', $this->main->id)->where('type', CommissionService::TYPE_SLOT)
            ->where('status', '!=', 'cancelled')->sum('amount');

        $elliott = $this->pay($this->consultantUser, $account, 2, $this->course());

        $own = $this->slotsOn($account, $elliott);
        $this->assertCount(1, $own, 'SCN-00297 earns the 2,500 for the new purchase');
        $this->assertSame(2, (int) $own->first()->tier, 'on the Service Group chosen for THIS purchase');
        $this->assertSame('cancelled', $old->fresh()->status, 'the removed September row stays removed');

        $this->assertSame(
            $mainBefore + self::SLOT,
            (float) Commission::where('consultant_id', $this->main->id)->where('type', CommissionService::TYPE_SLOT)
                ->where('status', '!=', 'cancelled')->sum('amount'),
            'the main is paid once by the climb, as before',
        );
        $this->assertSame(0, Commission::where('payment_id', $elliott->id)->where('type', CommissionService::TYPE_DIRECT)->count(),
            'buying for yourself still pays no direct');

        // Repair afterwards adds nothing.
        $rows = Commission::count();
        $this->artisan('commissions:backfill')->assertSuccessful();
        $this->assertSame($rows, Commission::count());
    }

    /** The old seat sits on Service Group 1; the new purchase chose Service Group 2. */
    public function test_the_new_2500_goes_on_the_side_chosen_now(): void
    {
        $account = $this->account();
        $student = $this->student();

        $this->pay($student, $account, 1);
        $second = $this->pay($student, $account, 2, $this->course());

        $this->assertSame(2, (int) $this->slotsOn($account, $second)->firstOrFail()->tier);
        $this->assertSame(1, (int) GroupMember::whereIn('group_id', Group::where('consultant_id', $account->id)->select('id'))
            ->where('user_id', $student->id)->value('tier'), 'the seat itself does not move');
    }

    // ----- still exactly once ----------------------------------------------------

    public function test_verifying_the_same_repeat_purchase_twice_pays_once(): void
    {
        $account = $this->account();
        $student = $this->student();

        $this->pay($student, $account, 1);
        $second = $this->pay($student, $account, 1, $this->course());
        $rows = Commission::count();

        app(CommissionService::class)->handleVerifiedPayment($second->fresh());

        $this->assertSame($rows, Commission::count());
    }

    /** A half earns no 2,500; its balance earns ONE — on a repeat purchase too. */
    public function test_a_half_and_its_balance_on_a_repeat_purchase_pay_one_2500(): void
    {
        $account = $this->account();
        $student = $this->student();
        $course = $this->course();

        $this->pay($student, $account, 1);
        $half = $this->pay($student, $account, 1, $course, 'half', 20000);

        $this->assertCount(0, $this->slotsOn($account, $half), 'the half pays the direct only, as always');

        $balance = $this->pay($student, $account, 1, $course, 'balance', 19000);

        $this->assertCount(1, $this->slotsOn($account, $balance), 'the balance earns the account its 2,500');

        app(CommissionService::class)->handleVerifiedPayment($balance->fresh());
        $this->assertCount(1, $this->slotsOn($account, $balance), 'and only once');
    }

    public function test_a_free_card_repeat_purchase_still_earns_nothing(): void
    {
        $account = $this->account();
        $student = $this->student();

        $this->pay($student, $account, 1);
        $free = $this->pay($student, $account, 1, $this->course(), 'free_card', 0);

        $this->assertSame(0, Commission::where('payment_id', $free->id)->count());
    }

    // ----- history ---------------------------------------------------------------

    /** Before 22 Oct 2026 a repeat purchase got no 2,500 there — and Repair must not add one now. */
    public function test_history_is_left_exactly_as_it_was(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Colombo'));

        $account = $this->account();
        $student = $this->student();

        $this->pay($student, $account, 1);
        $before = $this->pay($student, $account, 1, $this->course());

        $this->assertCount(0, $this->slotsOn($account, $before), 'guard: the old per-seat answer');

        $this->travelTo(Carbon::parse('2026-10-23 10:00:00', 'Asia/Colombo'));
        $this->artisan('commissions:backfill')->assertSuccessful();

        $this->assertCount(0, $this->slotsOn($account, $before), 'Repair does not pay history');
    }

    /** A purchase made today on the old code is completed by Repair, once. */
    public function test_repair_completes_a_repeat_purchase_made_today(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 15:49:00', 'Asia/Colombo'));

        $account = $this->account();
        $student = $this->student();

        $this->pay($student, $account, 1);
        $today = $this->pay($student, $account, 1, $this->course());

        // What the old code left behind: no 2,500 on the account for today's purchase.
        $this->slotsOn($account, $today)->each->delete();
        $this->assertCount(0, $this->slotsOn($account, $today), 'guard');

        $this->artisan('commissions:backfill')->assertSuccessful();
        $this->assertCount(1, $this->slotsOn($account, $today));

        $this->artisan('commissions:backfill')->assertSuccessful();
        $this->assertCount(1, $this->slotsOn($account, $today), 'a second Repair adds nothing');
    }

    public function test_the_rule_starts_at_midnight_22_october_sri_lanka_time(): void
    {
        $at = fn (string $utc) => RepeatPurchaseRules::appliesTo((new Payment)->forceFill(['verified_at' => Carbon::parse($utc, 'UTC')]));

        $this->assertFalse($at('2026-10-21 18:29:59'), '23:59:59 on the 21st in Colombo');
        $this->assertTrue($at('2026-10-21 18:30:00'), '00:00 on the 22nd in Colombo');
        $this->assertTrue($at('2026-10-22 10:19:00'), 'PYM-07242, verified 3:49 PM on the 22nd');
    }
}
