<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A course bought on a free card opens its set of SCN accounts.
 *
 * Reported 2026-09-14: "courses buy krankot free card dammaham user management
 * eke e accounts penwan na register krana than walath hariyta ewa pen wan na".
 * PaymentService skipped every zero-value payment when opening a buyer's set;
 * on the live site 23 free-card purchases by consultants had no accounts,
 * against 141 paid purchases that all had theirs.
 */
class FreeCardPurchaseOpensAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function consultantUser(): User
    {
        return $this->user('consultant@etec.lk');
    }

    private function hiddenCount(Consultant $consultant): int
    {
        return Consultant::where('owner_consultant_id', $consultant->id)->where('is_hidden', true)->count();
    }

    private function freeCardPurchase(User $buyer, string $status = 'pending', ?\Illuminate\Support\Carbon $verifiedAt = null): Payment
    {
        Student::firstOrCreate(
            ['user_id' => $buyer->id],
            ['student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()],
        );

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $buyer->id,
            'course_id' => Course::first()->id,
            'amount' => 0,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'free_card',
            'status' => $status,
            'paid_at' => now(),
        ]);

        if ($verifiedAt) {
            DB::table('payments')->where('id', $payment->id)->update(['verified_at' => $verifiedAt]);
        }

        return $payment->fresh();
    }

    // ----- new purchases -----------------------------------------------------------

    /** ⚠️ THE REPORT. The three accounts arrive, marked with the purchase. */
    public function test_a_course_bought_on_a_free_card_opens_three_accounts(): void
    {
        $consultant = $this->consultantUser()->consultant;
        $before = $this->hiddenCount($consultant);

        $payment = $this->freeCardPurchase($this->consultantUser());
        app(PaymentService::class)->verify($payment, $this->user('accountant@etec.lk'));

        $this->assertSame($before + 3, $this->hiddenCount($consultant));
        $this->assertSame(3, Consultant::where('opened_by_payment_id', $payment->id)->count());
    }

    /** They show where the owner looked for them: the user's page in User Management. */
    public function test_the_new_accounts_show_in_user_management(): void
    {
        $payment = $this->freeCardPurchase($this->consultantUser());
        app(PaymentService::class)->verify($payment, $this->user('accountant@etec.lk'));

        $page = $this->actingAs($this->user('superadmin@etec.lk'))
            ->get(route('users.edit', $this->consultantUser()))
            ->assertOk();

        foreach (Consultant::where('opened_by_payment_id', $payment->id)->pluck('scn_code') as $code) {
            $page->assertSee($code);
        }
    }

    /** The accounts are placement, not money: a free card still pays nobody. */
    public function test_a_free_card_still_pays_nobody(): void
    {
        $payment = $this->freeCardPurchase($this->consultantUser());
        app(PaymentService::class)->verify($payment, $this->user('accountant@etec.lk'));

        $this->assertSame(0.0, (float) Commission::where('payment_id', $payment->id)->sum('amount'));
    }

    // ----- purchases the old rule skipped --------------------------------------------

    /** A free-card purchase verified under the old rule: verified, but no set was ever opened. */
    private function skippedPurchase(): Payment
    {
        return $this->freeCardPurchase($this->consultantUser(), 'verified', now()->addMinute());
    }

    public function test_commission_health_offers_to_open_the_missing_accounts(): void
    {
        $this->skippedPurchase();

        $this->actingAs($this->user('superadmin@etec.lk'))
            ->get(route('commission-health.index'))
            ->assertOk()
            ->assertSee('Open Missing Free-Card Accounts (1)');
    }

    /** ⚠️ Pressing it twice opens the set once. */
    public function test_the_repair_opens_each_set_once(): void
    {
        $consultant = $this->consultantUser()->consultant;
        $payment = $this->skippedPurchase();
        $before = $this->hiddenCount($consultant);

        $superAdmin = $this->user('superadmin@etec.lk');

        $this->actingAs($superAdmin)->post(route('commission-health.open-account-sets'))->assertSessionHas('success');
        $this->assertSame($before + 3, $this->hiddenCount($consultant));
        $this->assertSame(3, Consultant::where('opened_by_payment_id', $payment->id)->count());

        $this->actingAs($superAdmin)->post(route('commission-health.open-account-sets'));
        $this->assertSame($before + 3, $this->hiddenCount($consultant), 'the second press opens nothing');
    }

    /** Somebody who became a consultant only AFTER the purchase got their opening set then. */
    public function test_a_buyer_promoted_after_the_purchase_gets_no_extra_set(): void
    {
        $payment = $this->freeCardPurchase($this->consultantUser(), 'verified', now()->subDays(10));

        DB::table('consultants')->where('id', $this->consultantUser()->consultant->id)->update(['created_at' => now()->subDays(2)]);

        $this->assertFalse(
            app(\App\Services\MissingAccountSets::class)->payments()->contains('id', $payment->id),
        );
    }

    public function test_only_a_super_admin_can_open_them(): void
    {
        $this->skippedPurchase();

        $this->actingAs($this->user('admin@etec.lk'))
            ->post(route('commission-health.open-account-sets'))
            ->assertForbidden();
    }
}
