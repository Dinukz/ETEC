<?php

namespace Tests\Feature;

use App\Livewire\PaymentQueue;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The GROUP SUPPORTER role (2026-09-17).
 *
 * Owner: "aluth rolle ekak hadann Group supporter kiyla ... Payments tab ekat
 * saha Admission Report ekat withri ynn puluwn wenne anith mokata wath meyat
 * access na". Confirmed on asking: they may LOOK at payments but never verify
 * or reject one (a verification mints commission), and they see every
 * consultant's data, like staff.
 *
 * ⚠️ THE PAYMENT POPUP IS A LIVEWIRE COMPONENT, so route middleware does not
 * guard its actions — verifyPayment()/rejectPayment() must refuse them inside
 * the component, which is what the Livewire tests below pin.
 */
class GroupSupporterRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
    }

    private function supporter(): User
    {
        Role::findOrCreate('group_supporter', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('group_supporter');

        return $user->fresh();
    }

    private function pendingPayment(): Payment
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        return Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant->id,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
    }

    // ----- creating one ----------------------------------------------------------------

    /** ⚠️ No CLI on the host to seed a role — creating the first user must create it. */
    public function test_a_super_admin_can_create_a_group_supporter_before_the_role_exists(): void
    {
        Role::where('name', 'group_supporter')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs(User::where('email', 'superadmin@etec.lk')->firstOrFail())
            ->post(route('users.store'), [
                'name' => 'Group Helper',
                'email' => 'helper@example.com',
                'role' => 'group_supporter',
                'password' => 'Str0ng!Passw0rd#',
                'password_confirmation' => 'Str0ng!Passw0rd#',
                'status' => 'active',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertTrue(User::where('email', 'helper@example.com')->firstOrFail()->hasRole('group_supporter'));
    }

    public function test_the_create_user_form_offers_the_role(): void
    {
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->get(route('users.create'))
            ->assertOk()
            ->assertSee('group_supporter');
    }

    // ----- where they can go ------------------------------------------------------------

    public function test_signing_in_lands_on_the_payments_tab(): void
    {
        $this->actingAs($this->supporter())
            ->get(route('dashboard'))
            ->assertRedirect(route('payments.index'));
    }

    /** ⚠️ 2026-10-05: the Admission Report was taken away — Payments only. */
    public function test_the_menu_holds_only_payments(): void
    {
        $labels = array_column(Navigation::for($this->supporter()), 'label');

        $this->assertSame(['Payments'], $labels);
    }

    public function test_the_admission_report_is_refused(): void
    {
        $this->actingAs($this->supporter())->get(route('reports.admissions'))->assertForbidden();
    }

    public function test_they_can_open_the_payments_tab_and_a_payment(): void
    {
        $supporter = $this->supporter();
        $payment = $this->pendingPayment();

        $this->actingAs($supporter)->get(route('payments.index'))->assertOk();
        $this->actingAs($supporter)->get(route('payments.show', $payment))->assertOk()->assertSee($payment->payment_code);
        // The two pages every signed-in role has.
        $this->actingAs($supporter)->get(route('profile.edit'))->assertOk();
        $this->actingAs($supporter)->get(route('notifications.index'))->assertOk();
    }

    public function test_every_other_page_is_refused(): void
    {
        $supporter = $this->supporter();

        foreach ([
            'reports.payments', 'reports.finance', 'commissions.index', 'groups.index',
            'system-run.index', 'commission-health.index', 'users.index', 'settings.index',
            'accountant.dashboard', 'admin.dashboard', 'super-admin.dashboard',
            'consultant.dashboard', 'student.dashboard', 'manage.courses.index',
        ] as $route) {
            $status = $this->actingAs($supporter)->get(route($route))->getStatusCode();

            $this->assertContains($status, [403, 302], "{$route} must not open for a group supporter (got {$status})");
            $this->assertNotSame(200, $status, $route);
        }
    }

    /** 2026-09-17: "Total Verified amount eka ... hide krann one group supporter ta withrk". */
    public function test_the_total_verified_amount_is_hidden_from_them_only(): void
    {
        $this->actingAs($this->supporter())
            ->get(route('payments.index'))
            ->assertOk()
            ->assertDontSee('Total Verified')
            ->assertViewHas('stats', fn ($stats) => $stats['total_verified'] === null);

        $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->get(route('payments.index'))
            ->assertOk()
            ->assertSee('Total Verified');
    }

    // ----- look, never touch ------------------------------------------------------------

    public function test_the_verify_and_reject_routes_are_refused(): void
    {
        $supporter = $this->supporter();
        $payment = $this->pendingPayment();

        $this->actingAs($supporter)->patch(route('payments.verify', $payment))->assertForbidden();
        $this->actingAs($supporter)->patch(route('payments.reject', $payment), ['remarks' => 'no'])->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_the_payment_page_shows_no_verify_or_reject_buttons(): void
    {
        $payment = $this->pendingPayment();

        $this->actingAs($this->supporter())
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertDontSee('Verify payment')
            ->assertDontSee('Reject payment');

        $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->get(route('payments.show', $payment))
            ->assertSee('Verify payment');
    }

    public function test_they_can_review_a_payment_in_the_popup_without_action_buttons(): void
    {
        $payment = $this->pendingPayment();

        Livewire::actingAs($this->supporter())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->assertOk()
            ->assertSee($payment->payment_code)
            ->assertDontSee('Verify payment')
            ->assertDontSee('Reject payment');
    }

    /** ⚠️ The real lock: Livewire actions skip route middleware. */
    public function test_the_popup_refuses_to_verify_or_reject_for_them(): void
    {
        $payment = $this->pendingPayment();

        Livewire::actingAs($this->supporter())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->call('verifyPayment')
            ->assertForbidden();

        Livewire::actingAs($this->supporter())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->set('remarks', 'wrong slip')
            ->call('rejectPayment')
            ->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, \App\Models\Commission::where('payment_id', $payment->id)->count());
    }

    /** And the accountant is untouched. */
    public function test_an_accountant_still_verifies_in_the_popup(): void
    {
        $payment = $this->pendingPayment();

        Livewire::actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->assertSee('Verify payment')
            ->call('verifyPayment')
            ->assertOk();

        $this->assertSame('verified', $payment->fresh()->status);
    }
}
