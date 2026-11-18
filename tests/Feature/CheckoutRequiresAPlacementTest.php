<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\AccountPlacementMap;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ AN EXISTING CONSULTANT MUST PICK A PLACEMENT — set 2026-08-27.
 *
 * Owner, reporting this class of bug for the THIRD time: "mn second SCN account
 * ekin courses ekak buy kra... 4th account eken studentla add krnakota 2nd SCN
 * account ekat calculate wen na 2500, habai main account ekt calculate wenwa."
 *
 * The climb itself was never broken — verified across 27 accounts built by
 * buying from the 2nd through the 9th, with zero violations. What was broken is
 * where the bought set gets PLACED.
 *
 * CheckoutController fell back to `$request->user()->consultant` — the buyer's
 * own MAIN — when no placement was picked, and never recorded a
 * requested_tier. AccountPlacementMap reads a set's position from that payment,
 * so the set landed directly under the main with NO Service Group. The main
 * represents neither group, so:
 *
 *   - creditOwnAccountAncestors() stops instantly (its stop condition IS the
 *     main), and a student on that set credits only the account they sat on;
 *   - the account the consultant thought they bought from earns nothing;
 *   - the main's rollup still counts the set-main as a direct child, so the
 *     money appears there instead.
 *
 * A groupless set is also where "2500 waradi account walata cross wela" comes
 * from: with no owner group the headline falls back to each row's raw tier, so
 * the money lands on whichever side the student happened to be seated on.
 *
 * Picking the main is still allowed — the form takes a Service Group for it
 * explicitly. What is no longer allowed is picking NOTHING and being placed.
 */
class CheckoutRequiresAPlacementTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    protected function setUp(): void
    {
        parent::setUp();
        // Checkout now requires a slip; keep it out of the real disk.
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');
    }

    private function course(): Course
    {
        return Course::where('status', 'published')->firstOrFail();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    public function test_a_consultant_cannot_buy_without_choosing_where_it_is_placed(): void
    {
        $this->actingAs($this->consultantUser())
            ->post(route('checkout.store', $this->course()), ['method' => 'bank_transfer', 'reference_number' => 'TXN-TEST', 'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf')])
            ->assertSessionHasErrors('placement_consultant_id');

        $this->assertNull(
            Payment::where('user_id', $this->consultantUser()->id)->first(),
            'and nothing is recorded, so no set can be opened in the wrong place'
        );
    }

    /**
     * ⚠️ THE OLD FLOW MUST STILL WORK — "parana logic walata gataluwk wen nathi
     * wenn hadann". A plain student has no accounts to place under, so the
     * sponsor chain takes over exactly as before and nothing is required.
     */
    public function test_a_plain_student_with_no_accounts_still_buys_with_no_placement(): void
    {
        $student = User::factory()->create();
        Student::create([
            'user_id' => $student->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $this->actingAs($student)
            ->post(route('checkout.store', $this->course()), ['method' => 'bank_transfer', 'reference_number' => 'TXN-TEST', 'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf')])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Payment::where('user_id', $student->id)->first());
    }

    /** Choosing an account works as before, and the set lands under THAT account. */
    public function test_the_chosen_account_is_where_the_new_set_is_opened(): void
    {
        $user = $this->consultantUser();
        $second = $user->consultant->hiddenAccounts()->orderBy('id')->first();   // SCN-00101

        $this->actingAs($user)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'placement_consultant_id' => $second->id,
            'placement_tier' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $payment = Payment::where('user_id', $user->id)->firstOrFail();
        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        $map = app(AccountPlacementMap::class)->for($user->consultant->fresh());
        $fourth = Consultant::where('owner_consultant_id', $user->consultant->id)->orderBy('id')->get()[2];

        $this->assertSame($second->id, $map[$fourth->id]['under_id'], 'the set hangs off the account that was chosen');
        $this->assertSame(1, $map[$fourth->id]['owner_group'], 'and it belongs to a real Service Group');
    }

    /**
     * The whole point, end to end: a student added on the 4th account credits
     * the 2nd account too. This is the number the owner said was missing.
     */
    public function test_a_student_on_the_fourth_account_credits_the_second_account(): void
    {
        $user = $this->consultantUser();
        $second = $user->consultant->hiddenAccounts()->orderBy('id')->first();

        $this->actingAs($user)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'placement_consultant_id' => $second->id,
            'placement_tier' => 1,
        ])->assertRedirect();

        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        app(PaymentService::class)->verify(Payment::where('user_id', $user->id)->firstOrFail(), $accountant);

        $fourth = Consultant::where('owner_consultant_id', $user->consultant->id)->orderBy('id')->get()[2];

        Commission::query()->delete();

        $buyer = User::factory()->create();
        Student::create([
            'user_id' => $buyer->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);
        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $this->course()->id, 'consultant_id' => $fourth->id,
            'requested_tier' => 1, 'amount' => 39000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);
        app(PaymentService::class)->verify($payment, $accountant);

        $this->assertSame(
            (float) self::SLOT,
            (float) Commission::where('consultant_id', $second->id)->where('type', 'bonus')->sum('amount'),
            'the 2,500 climbs from the 4th account to the 2nd — this is what was missing'
        );

        // ⚠️ 0 -> 2,500 on 2026-09-02, when the rollup was removed and the main
        // began earning its own rows. The assertion above is the one this test
        // is named for and is untouched: the 2,500 must reach the 2nd account
        // the 4th was placed under, rather than skipping it.
        $this->assertSame(
            (float) self::SLOT,
            (float) Commission::where('consultant_id', $user->consultant->id)->where('type', 'bonus')->sum('amount'),
            'and carries on to the main, which is the top of the same tree'
        );
    }
}
