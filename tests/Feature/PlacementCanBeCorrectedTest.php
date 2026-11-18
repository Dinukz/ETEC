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
 * ⚠️ A BOUGHT SET WITH NO PLACEMENT CAN BE RE-POINTED — the SCN-00122 report.
 *
 * Owner, 2026-08-31: "SCN 122 account eke withrk, 5th account eke upline ekedi
 * 2nd account ekat calculate wela na — main account ekat upline 2500 gihill
 * thiyenne, cross wela. Habai SCN 122 account eke withri me issue eka thiyenne;
 * anith account walin hariyt calculate wela thiyeno."
 * = only on that one account, the upline 2,500 from its 5th account never
 *   reached the 2nd account — it went to the main. Every other account is fine.
 *
 * ⚠️ "ONLY ONE ACCOUNT" IS THE DIAGNOSIS, NOT A DETAIL. The climb is one code
 * path used by everybody, so when a single account misbehaves it is the DATA:
 * that set's position was never recorded, so AccountPlacementMap has no parent
 * to walk to and the climb stops dead. Do not go looking for a branch in
 * CommissionService.
 *
 * The correction is stored on the ACCOUNT, never by rewriting
 * payments.consultant_id — that column decides who earned the purchase, which
 * is real money and not ours to move.
 */
class PlacementCanBeCorrectedTest extends TestCase
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

    /** A course purchase that opens a set, with NO placement account recorded. */
    private function buyASetWithNoPlacement(): Consultant
    {
        $course = Course::where('status', 'published')->firstOrFail();

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $this->main->user_id,
            'course_id' => $course->id,
            'consultant_id' => $this->main->id,     // the MAIN, i.e. nothing was chosen
            'requested_tier' => 1,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        // The set-main it opened: the third hidden account.
        return $this->main->fresh()->hiddenAccounts()->orderBy('id')->get()[2];
    }

    private function student(Consultant $account, int $leg): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    private function slotOn(Consultant $account): float
    {
        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')->sum('amount');
    }

    private function placementOf(Consultant $account): array
    {
        return app(AccountPlacementMap::class)->for($this->main->fresh())[$account->id] ?? [];
    }

    // ------------------------------------------------------------------------

    /** The reported symptom, reproduced: the climb has nowhere to go. */
    public function test_a_set_with_no_placement_has_no_account_above_it(): void
    {
        $set = $this->buyASetWithNoPlacement();

        $placement = $this->placementOf($set);

        $this->assertTrue(
            ($placement['under_id'] ?? null) === null || (int) $placement['under_id'] === (int) $this->main->id,
            'nothing above it but the main — which never earns, so the 2,500 stops',
        );
    }

    public function test_re_pointing_it_gives_the_climb_somewhere_to_go(): void
    {
        $set = $this->buyASetWithNoPlacement();
        $second = $this->main->hiddenAccounts()->orderBy('id')->get()[1];   // SCN-00102

        $set->forceFill([
            'placed_under_consultant_id' => $second->id,
            'placement_tier' => 2,
        ])->save();

        $placement = $this->placementOf($set->fresh());

        $this->assertSame((int) $second->id, (int) $placement['under_id'], 'it now sits under the 2nd account');
        $this->assertSame('bought', $placement['kind']);
        $this->assertSame(2, (int) $placement['tier'], 'on the leg that was chosen');
    }

    /** ⚠️ The point of the whole fix: money now climbs where it could not. */
    public function test_the_2500_reaches_the_account_above_after_the_correction(): void
    {
        $set = $this->buyASetWithNoPlacement();
        $second = $this->main->hiddenAccounts()->orderBy('id')->get()[1];

        $set->forceFill(['placed_under_consultant_id' => $second->id, 'placement_tier' => 2])->save();

        Commission::query()->delete();

        $this->student($set->fresh(), 1);

        $this->assertSame((float) self::SLOT, $this->slotOn($set), 'the account itself earns, as before');
        $this->assertSame(
            (float) self::SLOT,
            $this->slotOn($second),
            'and the 2,500 now climbs to the account it was pointed at',
        );
    }

    /** An override outside the owner's own tree is ignored, not obeyed. */
    public function test_a_placement_outside_the_owners_tree_is_ignored(): void
    {
        $set = $this->buyASetWithNoPlacement();

        $stranger = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $other = Consultant::create([
            'user_id' => $stranger->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-90001',
            'status' => 'active',
        ]);

        $set->forceFill(['placed_under_consultant_id' => $other->id])->save();

        $this->assertNotSame(
            (int) $other->id,
            (int) ($this->placementOf($set->fresh())['under_id'] ?? 0),
            'a typo must not route income into somebody else\'s network',
        );
    }

    // ---- The screen ---------------------------------------------------------

    public function test_a_super_admin_can_open_the_placements_page(): void
    {
        $this->buyASetWithNoPlacement();

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('commission-health.placements'))
            ->assertOk()
            ->assertSee('Where each bought account sits', false);
    }

    public function test_an_admin_cannot(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get(route('commission-health.placements'))
            ->assertForbidden();
    }

    public function test_the_form_moves_the_account(): void
    {
        $set = $this->buyASetWithNoPlacement();
        $second = $this->main->hiddenAccounts()->orderBy('id')->get()[1];

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->post(route('commission-health.repoint'), [
                'consultant_id' => $set->id,
                'under_id' => $second->id,
                'tier' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame((int) $second->id, (int) $set->fresh()->placed_under_consultant_id);
    }

    public function test_it_refuses_to_move_an_account_under_a_different_person(): void
    {
        $set = $this->buyASetWithNoPlacement();

        $other = Consultant::create([
            'user_id' => User::where('email', 'accountant@etec.lk')->firstOrFail()->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-90002', 'status' => 'active',
        ]);

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->post(route('commission-health.repoint'), [
                'consultant_id' => $set->id,
                'under_id' => $other->id,
                'tier' => 1,
            ])
            ->assertSessionHas('error');

        $this->assertNull($set->fresh()->placed_under_consultant_id);
    }

    /** ⚠️ It must never rewrite who earned the purchase. */
    public function test_moving_an_account_does_not_touch_the_payment(): void
    {
        $set = $this->buyASetWithNoPlacement();
        $second = $this->main->hiddenAccounts()->orderBy('id')->get()[1];

        $payment = Payment::findOrFail($set->opened_by_payment_id);
        $before = $payment->consultant_id;

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->post(route('commission-health.repoint'), [
                'consultant_id' => $set->id, 'under_id' => $second->id, 'tier' => 1,
            ]);

        $this->assertSame($before, $payment->fresh()->consultant_id, 'who earned the purchase is untouched');
    }
}
