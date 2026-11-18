<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Payments/commissions/groups are very often attributed to a HIDDEN placement
 * account (SCN-00101/102 — no login, no ->user of its own). Every page showing
 * "Marketer Code" style info must fall back to Consultant::display_name (which
 * resolves to the real owner), not $x->consultant->user->name — that silently
 * showed blank/"Consultant"/"Direct signup" for anything routed through a
 * hidden account, and would throw under preventLazyLoading if the owner
 * relation wasn't eager-loaded.
 */
class ConsultantNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function makeConsultantWithHiddenAccount(): array
    {
        $user = User::factory()->create(['name' => 'Real Owner Name']);
        $user->assignRole('consultant');

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)),
            'status' => 'active',
        ]);

        $hidden = $consultant->hiddenAccounts()->first();

        return [$consultant, $hidden];
    }

    private function placeStudentAndVerify(Consultant $account, int $tier): Payment
    {
        $studentUser = User::factory()->create();

        Student::create([
            'user_id' => $studentUser->id,
            'student_code' => 'SRN'.Str::random(6),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $studentUser->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $account->id,
            'requested_tier' => $tier,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        app(\App\Services\CommissionService::class)->handleVerifiedPayment($payment);

        return $payment->fresh();
    }

    public function test_payment_pages_show_the_real_owner_name_for_a_hidden_account(): void
    {
        [$consultant, $hidden] = $this->makeConsultantWithHiddenAccount();
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $payment = $this->placeStudentAndVerify($hidden, 1);
        $payment->update(['status' => 'pending', 'payment_type' => 'full']); // keep it listed

        $this->actingAs($admin)->get(route('payments.index'))
            ->assertOk()
            ->assertSee('Real Owner Name')
            ->assertDontSee('Direct signup');

        $this->actingAs($admin)->get(route('payments.show', $payment))
            ->assertOk()
            ->assertSee('Real Owner Name')
            ->assertDontSee('Direct signup');
    }

    public function test_commission_and_group_pages_show_the_real_owner_name_for_a_hidden_account(): void
    {
        [$consultant, $hidden] = $this->makeConsultantWithHiddenAccount();
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        // Balance a 1-to-1 pair on the hidden account so a Commission + Group exist.
        $this->placeStudentAndVerify($hidden, 1);
        $this->placeStudentAndVerify($hidden, 2);

        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id]);
        $group = Group::where('consultant_id', $hidden->id)->firstOrFail();

        $this->actingAs($admin)->get(route('commissions.index'))->assertOk()->assertSee('Real Owner Name');
        $this->actingAs($admin)->get(route('commissions.history'))->assertOk()->assertSee('Real Owner Name');
        $this->actingAs($admin)->get(route('groups.index'))->assertOk()->assertSee('Real Owner Name');
        $this->actingAs($admin)->get(route('groups.show', $group))->assertOk()->assertSee('Real Owner Name');

        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $this->actingAs($accountant)->get(route('accountant.dashboard'))->assertOk();

        // Reports: the marketer filter dropdown must show the real name too.
        $this->actingAs($admin)->get(route('reports.admissions'))->assertOk()->assertSee('Real Owner Name');
        $this->actingAs($admin)->get(route('reports.payments'))->assertOk()->assertSee('Real Owner Name');
    }
}
