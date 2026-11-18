<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ THE SAME PERSON MUST NOT BE REGISTERED TWICE (asked for 2026-09-07).
 *
 * Owner: "Meke ekama Email, NIC / Passport eka duplicate wenwa, kalin damm ekak
 * aye dann puluwn — meka hadal denn, ekaparak withrk dan puluwn widiyt. Eka
 * kalin add krla thiyeno nm 'already have' kiyla pop up ekak penwann."
 *
 * Neither field had any uniqueness rule, and store() deliberately REUSES a user
 * found by email — so a second registration of the same person silently
 * succeeded and attached them to whoever typed it in second.
 *
 * ⚠️ SCOPED TO THIS ONE SCREEN, deliberately: "Student Register weddi withrk
 * check krala". The public checkout and the payment-resubmit paths still reuse
 * an existing login on purpose, and `test_the_public_checkout_is_not_affected`
 * is what stops this guard leaking into them.
 */
class DuplicateStudentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $consultantUser;

    private Consultant $consultant;

    protected function setUp(): void
    {
        parent::setUp();
        // Checkout now requires a slip; keep it out of the real disk.
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        $this->consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->consultant = $this->consultantUser->consultant;
    }

    private function course(): Course
    {
        return Course::where('status', 'published')->firstOrFail();
    }

    /**
     * A complete, valid registration payload — every field the form requires
     * since the go-live validation pass.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nimal Silva',
            'email' => 'nimal@example.com',
            'phone' => '0771234567',
            'date_of_birth' => '2000-01-01',
            'gender' => 'male',
            'address' => '12 Galle Road',
            'city' => 'Colombo',
            'district' => 'Colombo',
            'nic' => '200012345678',
            'nic_owned_by_student' => 1,
            'course_id' => $this->course()->id,
            'language' => 'Sinhala',
            'class_type' => 'Physical',
            'course_type' => 'Diploma',
            'placement_tier' => 1,
            'partner_consultant_id' => $this->consultant->hiddenAccounts()->orderBy('id')->value('id'),
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        // from() so back() returns to the form rather than to "/" — every error
        // path in this controller redirects back, and without a referer the test
        // would be asserting against the home page.
        return $this->actingAs($this->consultantUser)
            ->from(route('consultant.students.create'))
            ->post(route('consultant.students.store'), $this->payload($overrides));
    }

    // ----- the first one works ----------------------------------------------

    public function test_a_new_student_registers_normally(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'nimal@example.com']);
        $this->assertSame(1, Student::whereRelation('user', 'email', 'nimal@example.com')->count());
    }

    // ----- and the second one is stopped ------------------------------------

    /** ⚠️ THE REPORTED BUG: the same email straight back in. */
    public function test_the_same_email_is_refused(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $before = Payment::count();

        $this->register(['nic' => '199999999999'])   // different NIC, same email
            ->assertSessionHasErrors('duplicate');

        $this->assertSame($before, Payment::count(), 'nothing was created the second time');
        $this->assertSame(1, User::where('email', 'nimal@example.com')->count());
    }

    /** The same NIC under a different email is the same person too. */
    public function test_the_same_nic_is_refused(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $before = Payment::count();

        $this->register(['email' => 'someone.else@example.com'])
            ->assertSessionHasErrors('duplicate');

        $this->assertSame($before, Payment::count());
        $this->assertDatabaseMissing('users', ['email' => 'someone.else@example.com']);
    }

    /**
     * ⚠️ A NIC IS TYPED BY HAND, so spacing and case are not a different person.
     * Without this, "V123 45678" walks straight past a stored "v12345678".
     */
    public function test_a_nic_typed_with_different_spacing_or_case_is_still_the_same_person(): void
    {
        $this->register(['nic' => 'V1234567 8'])->assertSessionHasNoErrors();

        $this->register(['email' => 'other@example.com', 'nic' => 'v12345678'])
            ->assertSessionHasErrors('duplicate');
    }

    /** The message has to be usable — who it is, and where to find them. */
    public function test_the_message_names_the_existing_student(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $code = Student::whereRelation('user', 'email', 'nimal@example.com')->value('student_code');

        $this->register(['name' => 'Typed Again'])->assertSessionHasErrors('duplicate');

        $message = session('errors')->first('duplicate');

        $this->assertStringContainsString('Nimal Silva', $message, 'it names who already holds the record');
        $this->assertStringContainsString($code, $message, 'and their student code, so they can be looked up');
    }

    /** And it reaches the screen as a popup, not a line in the error list. */
    public function test_the_page_shows_the_already_registered_popup(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $this->actingAs($this->consultantUser)
            ->from(route('consultant.students.create'))
            ->followingRedirects()
            ->post(route('consultant.students.store'), $this->payload())
            ->assertOk()
            ->assertSee('This student already has an account')
            ->assertSee('already registered to');
    }

    /** A consultant must not be able to re-register somebody else's student either. */
    public function test_a_student_registered_by_another_consultant_is_also_refused(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $otherUser = User::factory()->create();
        $otherUser->assignRole('consultant');
        $other = Consultant::create([
            'user_id' => $otherUser->id,
            'consultant_code' => 'CON'.\Illuminate\Support\Str::random(8),
            'referral_code' => strtoupper(\Illuminate\Support\Str::random(10)),
            'status' => 'active',
        ]);
        app(\App\Services\HiddenAccountService::class)->ensureFor($other);

        $this->actingAs($otherUser)
            ->post(route('consultant.students.store'), array_merge($this->payload(), [
                'partner_consultant_id' => $other->fresh()->hiddenAccounts()->orderBy('id')->value('id'),
            ]))
            ->assertSessionHasErrors('duplicate');
    }

    // ----- what it must NOT stop --------------------------------------------

    /** A genuinely different person with their own details is unaffected. */
    public function test_a_different_person_still_registers(): void
    {
        // Counted as a delta — the seeder ships demo students of its own.
        $before = Student::count();

        $this->register()->assertSessionHasNoErrors();

        $this->register([
            'name' => 'Sunil Fernando',
            'email' => 'sunil@example.com',
            'nic' => '198887776665',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before + 2, Student::count());
    }

    /**
     * ⚠️ THE BOUNDARY. The owner asked for this check "Student Register weddi
     * withrk" — at student registration only. The public checkout reuses an
     * existing login on purpose, which is how a returning student buys another
     * course, and this guard must not have leaked into it.
     */
    public function test_the_public_checkout_is_not_affected(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $student = User::where('email', 'nimal@example.com')->firstOrFail();

        $other = Course::where('status', 'published')->where('id', '!=', $this->course()->id)->first()
            ?? Course::create([
                'title' => 'Second Course',
                'slug' => 'second-course-'.\Illuminate\Support\Str::random(6),
                'description' => 'x',
                'price' => $this->course()->price,
                'currency' => 'LKR',
                'status' => 'published',
                'lecturer_id' => $this->course()->lecturer_id,
                'published_at' => now(),
            ]);

        $this->actingAs($student)
            ->post(route('checkout.store', $other), ['method' => 'bank_transfer', 'reference_number' => 'TXN-TEST', 'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf')])
            ->assertSessionHasNoErrors();
    }
}
