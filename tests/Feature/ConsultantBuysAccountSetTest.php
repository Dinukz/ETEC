<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A consultant buying another course receives a NEW SET of SCN accounts
 * (owner-confirmed 2026-08-20).
 *
 * "consultant cource buy krhama eyta SCN account 3k hambenawa... plaweniyta
 * hambena account tika wagem" — three accounts, the same as the set they got
 * on day one — and it lands "e amount eka admin verify krapu gaman", i.e. the
 * moment the payment is verified, with NO requirement to finish the course.
 *
 * Deliberately separate from the admin's one-at-a-time "+ New account" button
 * (HiddenAccountService::addExtraAccount), which the owner asked to keep as it
 * was: "parana logic eka thibbe admin gihilla new SCN account open kran
 * widiyta, mt e logic eka wen karagannn one".
 */
class ConsultantBuysAccountSetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    private function buyCourse(User $buyer, float $amount = 39000, string $type = 'full'): Payment
    {
        Student::firstOrCreate(
            ['user_id' => $buyer->id],
            ['student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()],
        );

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $buyer->id,
            'course_id' => Course::first()->id,
            'amount' => $amount,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => $type,
            'status' => 'pending',
            'paid_at' => now(),
        ]);
    }

    private function hiddenCount(Consultant $consultant): int
    {
        return Consultant::where('owner_consultant_id', $consultant->id)->where('is_hidden', true)->count();
    }

    public function test_a_consultant_buying_a_course_receives_three_new_scn_accounts(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;

        $before = $this->hiddenCount($consultant);

        $payment = $this->buyCourse($consultantUser);
        app(PaymentService::class)->verify($payment, $this->accountant());

        $this->assertSame(
            $before + 3,
            $this->hiddenCount($consultant),
            'A whole set of three arrives, matching the set they got on day one.',
        );
    }

    /** Every new account gets its own Service Group, so it can earn immediately. */
    public function test_each_new_account_has_its_own_service_group_and_scn_code(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;

        $payment = $this->buyCourse($consultantUser);
        app(PaymentService::class)->verify($payment, $this->accountant());

        $created = Consultant::where('opened_by_payment_id', $payment->id)->get();

        $this->assertCount(3, $created);

        foreach ($created as $account) {
            $this->assertNotNull($account->scn_code, 'Each account takes the next sequential SCN.');
            $this->assertNotNull(
                \App\Models\Group::where('consultant_id', $account->id)->first(),
                'Each account has its own Service Group 1 / 2 from the moment it exists.',
            );
        }

        // Sequential, like every other SCN allocation.
        $codes = $created->pluck('scn_code')->sort()->values();
        $this->assertCount(3, $codes->unique(), 'Three distinct codes, never a duplicate.');
    }

    /** ⚠️ Verification is idempotent for money; it must be for accounts too. */
    public function test_re_verifying_the_same_payment_does_not_open_a_second_set(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;

        $payment = $this->buyCourse($consultantUser);

        app(PaymentService::class)->verify($payment, $this->accountant());
        $afterFirst = $this->hiddenCount($consultant);

        // A second verify of the same payment — an impatient double-click, or a
        // re-run — must change nothing.
        app(PaymentService::class)->verify($payment->fresh(), $this->accountant());

        $this->assertSame($afterFirst, $this->hiddenCount($consultant), 'One set per payment, ever.');
    }

    /** A second, genuinely different purchase does open another set. */
    public function test_a_second_purchase_opens_another_set(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;

        $before = $this->hiddenCount($consultant);

        app(PaymentService::class)->verify($this->buyCourse($consultantUser), $this->accountant());
        app(PaymentService::class)->verify($this->buyCourse($consultantUser), $this->accountant());

        $this->assertSame($before + 6, $this->hiddenCount($consultant), 'Two purchases, two sets.');
    }

    /**
     * ⚠️ INVERTED 2026-09-14: a course bought on a free card opens its set too.
     *
     * This pinned "a free card is worth nothing — it must not mint three SCN
     * accounts". The owner reported the missing accounts as a bug ("free card
     * dammaham user management eke e accounts penwan na"): on the live site 23
     * such purchases had no set while all 141 paid ones did. The accounts are
     * placement, not money. See FreeCardPurchaseOpensAccountsTest.
     */
    public function test_a_zero_value_payment_opens_its_accounts_too(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;

        $before = $this->hiddenCount($consultant);

        $payment = $this->buyCourse($consultantUser, 0, 'free_card');
        app(PaymentService::class)->verify($payment, $this->accountant());

        $this->assertSame($before + 3, $this->hiddenCount($consultant));
    }

    /** A half payer's later balance top-up is the same purchase, not a new one. */
    public function test_a_balance_top_up_opens_no_accounts(): void
    {
        // 'balance' was added to payments.payment_type by a migration that uses
        // a raw MySQL `ALTER TABLE ... MODIFY ... ENUM(...)`, which sqlite
        // cannot run — so on sqlite the column still rejects the value with a
        // CHECK constraint violation. The guard itself is the first line of
        // PaymentService::openAccountSetForBuyer(); this asserts it on the real
        // engine only.
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped("sqlite cannot store payment_type='balance' — MySQL-only enum widening.");
        }

        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;

        $before = $this->hiddenCount($consultant);

        $payment = $this->buyCourse($consultantUser, 10000, 'balance');
        app(PaymentService::class)->verify($payment, $this->accountant());

        $this->assertSame($before, $this->hiddenCount($consultant));
    }

    /**
     * A first-time buyer is still a STUDENT here — they get their opening set
     * when they are promoted to consultant, not from the purchase itself.
     */
    public function test_a_plain_student_buyer_gets_no_account_set(): void
    {
        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        $payment = $this->buyCourse($buyer);
        app(PaymentService::class)->verify($payment, $this->accountant());

        $this->assertSame(
            0,
            Consultant::where('opened_by_payment_id', $payment->id)->count(),
            'Students do not own SCN accounts — promotion is what creates the opening set.',
        );
    }

    /** The admin's manual "+ New account" path is untouched and still adds ONE. */
    public function test_the_admin_add_extra_account_button_still_adds_exactly_one(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        $before = $this->hiddenCount($consultant);

        $this->actingAs($admin)
            ->post(route('groups.add-account', $consultant))
            ->assertRedirect();

        $this->assertSame($before + 1, $this->hiddenCount($consultant), 'The admin path stays one-at-a-time.');
    }
}
