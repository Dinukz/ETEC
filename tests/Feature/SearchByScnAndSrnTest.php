<?php

namespace Tests\Feature;

use App\Livewire\PaymentQueue;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Finding people and payments by their SCN / SRN number (2026-09-17).
 *
 * Owner: "User Management eke thiyen search bar ekat Consultant ekenk nm SCN
 * numbers tiken search wenn one student kenk nm SRN number eken ... ethan thiyen
 * table ekat student nm SRN number saha Consultant kenk nm SCN number eka dala
 * denn mekat user roll eka updaet kraddi ekath update wenn one ... Payment
 * Verification search bar ekata SRN and SCN number eka dann one eken search
 * krann group supportertath penwann one".
 *
 * ⚠️ THE NUMBER SHOWN FOLLOWS THE ROLE, NOT WHICH PROFILE ROWS EXIST. A consultant
 * demoted to student keeps their consultants row (nothing deletes it), so a
 * "consultant first" rule would keep printing the SCN after the role changed.
 */
class SearchByScnAndSrnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@etec.lk')->firstOrFail();
    }

    private function student(string $name, string $code): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('student');
        Student::create(['user_id' => $user->id, 'student_code' => $code, 'status' => 'active', 'enrolled_at' => now()]);

        return $user->fresh();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function supporter(): User
    {
        Role::findOrCreate('group_supporter', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole('group_supporter');

        return $user->fresh();
    }

    private function paymentFor(User $buyer, Consultant $account): Payment
    {
        return Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $buyer->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id,
            'amount' => 10000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);
    }

    /** @return array<int> user ids the User Management list returns for this search */
    private function usersFound(string $q): array
    {
        return $this->actingAs($this->superAdmin())
            ->get(route('users.index', ['q' => $q]))
            ->assertOk()
            ->viewData('users')
            ->pluck('id')
            ->all();
    }

    // ----- User Management search -------------------------------------------------------

    public function test_a_student_is_found_by_their_srn(): void
    {
        $student = $this->student('Kasun Perera', 'SRN-07771');
        $this->student('Someone Else', 'SRN-07772');

        $this->assertSame([$student->id], $this->usersFound('SRN-07771'));
    }

    public function test_a_consultant_is_found_by_their_scn(): void
    {
        $consultant = $this->consultantUser();
        $scn = $consultant->consultant->scn_code;

        $this->assertNotNull($scn, 'guard: the seeded consultant has an SCN');
        $this->assertContains($consultant->id, $this->usersFound($scn));
    }

    /** SCN-00230 is a placement account with no login — it finds the person who owns it. */
    public function test_a_placement_account_scn_finds_its_owner(): void
    {
        $consultant = $this->consultantUser();
        $hiddenScn = $consultant->consultant->hiddenAccounts()->orderBy('id')->firstOrFail()->scn_code;

        $this->assertContains($consultant->id, $this->usersFound($hiddenScn));
    }

    public function test_name_and_email_search_still_work(): void
    {
        $student = $this->student('Nadeesha Unique', 'SRN-07773');

        $this->assertSame([$student->id], $this->usersFound('Nadeesha Unique'));
        $this->assertSame([$student->id], $this->usersFound($student->email));
    }

    // ----- User Management table --------------------------------------------------------

    public function test_the_table_shows_srn_for_a_student_and_scn_for_a_consultant(): void
    {
        $this->student('Tharindu Table', 'SRN-07774');

        $this->actingAs($this->superAdmin())
            ->get(route('users.index', ['q' => 'Tharindu Table']))
            ->assertOk()
            ->assertSee('SCN / SRN')
            ->assertSee('SRN-07774');

        $consultant = $this->consultantUser();

        $this->actingAs($this->superAdmin())
            ->get(route('users.index', ['q' => $consultant->email]))
            ->assertSee($consultant->consultant->scn_code);
    }

    /** ⚠️ "user roll eka updaet kraddi ekath update wenn one". */
    public function test_the_number_follows_a_role_change_both_ways(): void
    {
        $user = $this->student('Role Changer', 'SRN-07775');
        $page = fn () => $this->actingAs($this->superAdmin())->get(route('users.index', ['q' => 'Role Changer']));

        $page()->assertSee('SRN-07775');

        $this->actingAs($this->superAdmin())->patch(route('users.role', $user), ['role' => 'consultant'])->assertRedirect();

        $scn = $user->fresh()->consultant?->scn_code;
        $this->assertNotNull($scn, 'guard: promotion issued an SCN');
        $page()->assertSee($scn)->assertDontSee('SRN-07775');

        $this->actingAs($this->superAdmin())->patch(route('users.role', $user), ['role' => 'student'])->assertRedirect();

        $page()->assertSee('SRN-07775')->assertDontSee($scn);
    }

    // ----- Payment Verification search --------------------------------------------------

    /** @return array<int> payment ids the queue lists for this search */
    private function paymentsFound(User $viewer, string $q): array
    {
        return Livewire::actingAs($viewer)
            ->test(PaymentQueue::class)
            ->set('status', '')
            ->set('q', $q)
            ->viewData('payments')
            ->pluck('id')
            ->all();
    }

    public function test_a_payment_is_found_by_the_students_srn(): void
    {
        $account = $this->consultantUser()->consultant;
        $mine = $this->paymentFor($this->student('Srn Buyer', 'SRN-07776'), $account);
        $this->paymentFor($this->student('Other Buyer', 'SRN-07777'), $account);

        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $this->assertSame([$mine->id], $this->paymentsFound($accountant, 'SRN-07776'));
    }

    public function test_a_payment_is_found_by_the_scn_it_was_placed_on(): void
    {
        $owner = $this->consultantUser()->consultant;
        $placement = $owner->hiddenAccounts()->orderBy('id')->firstOrFail();

        $onPlacement = $this->paymentFor($this->student('Placed Buyer', 'SRN-07778'), $placement);

        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $this->assertContains($onPlacement->id, $this->paymentsFound($accountant, $placement->scn_code), 'by the account itself');
        $this->assertContains($onPlacement->id, $this->paymentsFound($accountant, $owner->scn_code), 'and by the owner\'s main SCN');
    }

    public function test_a_consultants_own_purchase_is_found_by_their_scn(): void
    {
        $buyer = $this->consultantUser();
        $other = User::factory()->create();
        $other->assignRole('consultant');
        $otherConsultant = Consultant::create([
            'user_id' => $other->id, 'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)), 'status' => 'active',
        ]);

        $purchase = $this->paymentFor($buyer, $otherConsultant);

        $found = $this->paymentsFound(User::where('email', 'accountant@etec.lk')->firstOrFail(), $buyer->consultant->scn_code);

        $this->assertContains($purchase->id, $found);
    }

    public function test_the_group_supporter_can_search_by_srn_and_scn_too(): void
    {
        $owner = $this->consultantUser()->consultant;
        $payment = $this->paymentFor($this->student('Supporter Find', 'SRN-07779'), $owner);

        $this->assertSame([$payment->id], $this->paymentsFound($this->supporter(), 'SRN-07779'));
        $this->assertContains($payment->id, $this->paymentsFound($this->supporter(), $owner->scn_code));
    }

    public function test_the_queue_shows_the_students_number(): void
    {
        $payment = $this->paymentFor($this->student('Shown Number', 'SRN-07780'), $this->consultantUser()->consultant);

        Livewire::actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->test(PaymentQueue::class)
            ->assertSee($payment->payment_code)
            ->assertSee('SRN-07780');
    }

    /** ⚠️ The CSV export shares the controller's search — and must keep the status filter. */
    public function test_the_csv_export_searches_by_srn_without_losing_the_status_filter(): void
    {
        $account = $this->consultantUser()->consultant;
        $pending = $this->paymentFor($this->student('Csv Pending', 'SRN-07781'), $account);
        $verified = $this->paymentFor($this->student('Csv Verified', 'SRN-07782'), $account);
        $verified->forceFill(['status' => 'verified', 'verified_at' => now()])->save();

        $body = $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->get(route('payments.index', ['q' => 'SRN-0778', 'status' => 'pending', 'export' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($pending->payment_code, $body);
        $this->assertStringNotContainsString($verified->payment_code, $body, 'a search must not escape the status filter');
    }
}
