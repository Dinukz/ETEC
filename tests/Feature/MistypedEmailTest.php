<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\TransactionalMail;
use App\Support\EmailAddressCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ⚠️ MISTYPED EMAIL ADDRESSES (2026-11-10).
 *
 * Hostinger suspended sending from info@etecbusiness.lk after the inbox filled
 * with "Undelivered Mail Returned to Sender". The owner opened one: the site's
 * own Welcome email, to a student typed as "...@email.com" for "...@gmail.com".
 * 220 of 5,857 addresses on file were certainly wrong (gamil.com, gmali.com,
 * gmail.con ...). Now such an address is refused where it is typed, never
 * mailed, listed for correction, and a failed re-send no longer leaves the
 * student without a working password.
 */
class MistypedEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::forever('settings.mail.grouped', collect());
        config(['mail.default' => 'array']);
        Mail::purge('array');
    }

    private function sent(): int
    {
        return count(Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@etec.lk')->firstOrFail();
    }

    // ----- the check itself ------------------------------------------------------

    public static function mistyped(): array
    {
        return [
            'email.com (the bounce the owner opened)' => ['nishibhagya04@email.com', 'nishibhagya04@gmail.com'],
            'gamil.com' => ['kasun.perera@gamil.com', 'kasun.perera@gmail.com'],
            'gmali.com' => ['kasun.perera@gmali.com', 'kasun.perera@gmail.com'],
            'gmail.con' => ['kasun.perera@gmail.con', 'kasun.perera@gmail.com'],
            'gmail.come' => ['kasun.perera@gmail.come', 'kasun.perera@gmail.com'],
            'no ending at all' => ['kasun.perera@gmail', 'kasun.perera@gmail.com'],
            'digits glued on' => ['kasun.perera@136gmail.com', 'kasun.perera@gmail.com'],
            'gami.com, three off' => ['kasun.perera@gami.com', 'kasun.perera@gmail.com'],
            'icolud.com' => ['kasun.perera@icolud.com', 'kasun.perera@icloud.com'],
            'hotmial.com' => ['kasun.perera@hotmial.com', 'kasun.perera@hotmail.com'],
        ];
    }

    #[DataProvider('mistyped')]
    public function test_a_misspelt_provider_is_caught_with_the_right_suggestion(string $typed, string $meant): void
    {
        $this->assertNotNull(EmailAddressCheck::problem($typed));
        $this->assertSame($meant, EmailAddressCheck::suggestion($typed));
        $this->assertStringContainsString($meant, EmailAddressCheck::problem($typed));
    }

    public static function impossible(): array
    {
        return [
            'Gmail allows no underscore' => ['nimal_perera@gmail.com'],
            'Gmail needs 6 letters or more' => ['ab12@gmail.com'],
            'a domain with no ending' => ['nimal@gemel'],
            'only a top-level name' => ['nimal@com'],
        ];
    }

    #[DataProvider('impossible')]
    public function test_an_impossible_address_is_caught(string $typed): void
    {
        $this->assertNotNull(EmailAddressCheck::problem($typed));
    }

    public static function real(): array
    {
        return array_map(fn ($e) => [$e], [
            'nimal.perera99@gmail.com', 'First.Last+etec@Gmail.com', 'nimal@yahoo.com', 'nimal@icloud.com',
            'nimal@outlook.com', 'nimal@live.com', 'nimal@aol.com', 'nimal@mac.com', 'nimal@gmx.com',
            'nimal@ymail.com', 'nimal@sltnet.lk', 'nimal@stu.kln.ac.lk', 'nimal@yahoo.co.uk', 'nimal@company.lk',
            'nimal@life.com', 'info@etecbusiness.lk', 'consultant@etec.lk', 'someone@example.com',
        ]);
    }

    /** ⚠️ It must never refuse a real address — run over every address on file it refused none. */
    #[DataProvider('real')]
    public function test_a_real_address_passes(string $typed): void
    {
        $this->assertNull(EmailAddressCheck::problem($typed), $typed);
    }

    // ----- refused where it is typed -------------------------------------------------

    private function registrationForm(string $email): array
    {
        return [
            'name' => 'Typo Student', 'email' => $email, 'phone' => '0771234567', 'date_of_birth' => '2000-01-01',
            'gender' => 'female', 'address' => '12 Galle Road', 'city' => 'Colombo', 'district' => 'Colombo',
            'nic' => '200012345'.random_int(100, 999), 'nic_owned_by_student' => 1,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'language' => 'Sinhala', 'class_type' => 'Online', 'course_type' => 'Group', 'placement_tier' => 1,
        ];
    }

    public function test_student_registration_refuses_a_mistyped_address_and_offers_the_right_one(): void
    {
        $this->actingAs($this->consultantUser())
            ->post(route('consultant.students.store'), $this->registrationForm('nishibhagya04@email.com'))
            ->assertSessionHasErrors(['email' => 'This email address looks mistyped — did you mean nishibhagya04@gmail.com? Login details are emailed there, so please check it.']);

        $this->assertFalse(User::where('email', 'nishibhagya04@email.com')->exists(), 'nothing was saved');
        $this->assertSame(0, $this->sent(), 'and nothing was sent');
    }

    public function test_student_registration_still_takes_a_real_address(): void
    {
        $this->actingAs($this->consultantUser())
            ->post(route('consultant.students.store'), $this->registrationForm('nishibhagya04@gmail.com'))
            ->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'nishibhagya04@gmail.com')->exists());
        $this->assertSame(1, $this->sent(), 'the welcome email goes out as before');
    }

    public function test_the_public_sign_up_refuses_a_mistyped_address(): void
    {
        $this->post(route('register'), [
            'name' => 'Self Signup', 'email' => 'self.signup@gamil.com', 'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd', 'terms' => 1,
        ])->assertSessionHasErrors('email');

        $this->assertFalse(User::where('email', 'self.signup@gamil.com')->exists());
    }

    public function test_an_admin_cannot_create_a_user_with_a_mistyped_address(): void
    {
        $this->actingAs($this->superAdmin())->post(route('users.store'), [
            'name' => 'New Staff', 'email' => 'new.staff@gmail.con', 'role' => 'accountant', 'status' => 'active',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(User::where('email', 'new.staff@gmail.con')->exists());
    }

    /** Correcting is what the edit screen is for — an old typo must not block saving the rest. */
    public function test_editing_a_user_checks_only_a_new_address(): void
    {
        $user = User::factory()->create(['email' => 'old.typo@gamil.com', 'name' => 'Old Name']);

        $this->actingAs($this->superAdmin())
            ->patch(route('users.update', $user), ['name' => 'New Name', 'email' => 'old.typo@gamil.com', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertSame('New Name', $user->fresh()->name, 'the rest of the form saves, typo and all');

        $this->actingAs($this->superAdmin())
            ->patch(route('users.update', $user), ['name' => 'New Name', 'email' => 'still.wrong@gmali.com', 'status' => 'active'])
            ->assertSessionHasErrors('email');

        $this->actingAs($this->superAdmin())
            ->patch(route('users.update', $user), ['name' => 'New Name', 'email' => 'old.typo@gmail.com', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertSame('old.typo@gmail.com', $user->fresh()->email, 'and the correction saves');
    }

    // ----- never mailed -------------------------------------------------------------

    public function test_nothing_is_ever_sent_to_a_mistyped_address(): void
    {
        $this->assertFalse(TransactionalMail::welcome('kasun.perera@gamil.com', 'Kasun', 'Course', 'PYM-1', 'secret'));
        $this->assertSame(0, $this->sent());
        $this->assertStringContainsString('kasun.perera@gmail.com', (string) TransactionalMail::lastError(), 'the reason names the likely right address');

        $this->assertTrue(TransactionalMail::welcome('kasun.perera@gmail.com', 'Kasun', 'Course', 'PYM-1', 'secret'));
        $this->assertSame(1, $this->sent());
    }

    // ----- re-sending never strands a student ---------------------------------------

    private function pendingRegistration(string $email): Payment
    {
        $user = User::create([
            'name' => 'Pending Person', 'email' => $email, 'password' => Hash::make('given-by-hand'),
            'status' => 'active', 'password_must_change' => true,
        ]);

        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'consultant_id' => $this->consultantUser()->consultant->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id,
            'course_id' => Course::first()->id, 'consultant_id' => $this->consultantUser()->consultant->id,
            'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR', 'method' => 'bank_transfer', 'status' => 'pending',
        ]);
    }

    public static function resendButtons(): array
    {
        return ['the consultant\'s Re-send' => ['consultant'], 'the admin\'s Re-send' => ['admin']];
    }

    private function pressResend(string $who, Payment $payment)
    {
        return $who === 'consultant'
            ? $this->actingAs($this->consultantUser())->post(route('consultant.payments.resend-email', $payment))
            : $this->actingAs($this->superAdmin())->post(route('users.resend-registration', $payment->user));
    }

    /** A mistyped address is refused BEFORE a new password is issued — the one given by hand keeps working. */
    #[DataProvider('resendButtons')]
    public function test_a_resend_to_a_mistyped_address_changes_nothing(string $who): void
    {
        $payment = $this->pendingRegistration('pending.person@gamil.com');

        $this->pressResend($who, $payment)
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'pending.person@gmail.com'));

        $this->assertTrue(Hash::check('given-by-hand', $payment->user->fresh()->password), 'the password the student was given still works');
        $this->assertSame(0, $this->sent());
    }

    /**
     * ⚠️ When the mail server refuses (as it does while the mailbox is suspended),
     * the NEW password is already saved — so it is shown, or the student would
     * be left with no working password at all.
     */
    #[DataProvider('resendButtons')]
    public function test_a_failed_resend_shows_the_new_password(string $who): void
    {
        $payment = $this->pendingRegistration('pending.person@gmail.com');
        Mail::shouldReceive('html')->andThrow(new \RuntimeException('535 5.7.8 Error: authentication failed'));

        $response = $this->pressResend($who, $payment)->assertRedirect();

        $error = (string) session('error');
        $this->assertMatchesRegularExpression('/temporary password is now: (\S+) — give it to them yourself/', $error);
        preg_match('/temporary password is now: (\S+) —/', $error, $m);
        $this->assertTrue(Hash::check($m[1], $payment->user->fresh()->password), 'the password shown is the one that now works');
    }

    // ----- listed for correction ---------------------------------------------------

    public function test_user_management_lists_the_mistyped_addresses_with_the_likely_right_one(): void
    {
        $wrong = User::factory()->create(['email' => 'wrong.one@gamil.com', 'name' => 'Wrong Address']);
        User::factory()->create(['email' => 'right.one@gmail.com', 'name' => 'Right Address']);

        $this->actingAs($this->superAdmin())->get(route('users.index'))
            ->assertOk()
            ->assertSee('Mistyped emails (1)')
            ->assertSee('Mistyped — did you mean wrong.one@gmail.com?');

        $listed = $this->actingAs($this->superAdmin())->get(route('users.index', ['email' => 'mistyped']))->assertOk();
        $names = collect($listed->viewData('users')->items())->pluck('name')->all();

        $this->assertSame(['Wrong Address'], $names);

        $this->actingAs($this->superAdmin())->get(route('users.edit', $wrong))
            ->assertOk()
            ->assertSee('Mistyped — did you mean wrong.one@gmail.com?');
    }
}
