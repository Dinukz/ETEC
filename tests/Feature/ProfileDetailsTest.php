<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProfileDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_consultant_can_save_bank_and_beneficiary_details(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail();

        // Profile is one combined form now, so the user fields go along with it.
        $response = $this->actingAs($consultant)->patch(route('profile.update'), [
            'name' => $consultant->name,
            'email' => $consultant->email,
            'phone' => '+94771234567',
            'nic' => '200108200826',
            'date_of_birth' => '2001-03-21',
            'gender' => 'male',
            'address' => '85/B Piyasewana, Haburugala',
            'bank_name' => 'Hatton National Bank',
            'bank_account_name' => 'M V U Dilshantha',
            'bank_account_number' => '109020055896',
            'bank_branch' => 'Aluthgama',
            'swift_code' => 'HNB004',
            'beneficiary_name' => 'Opathagm Vidanelage Chithra Violet',
            'beneficiary_relationship' => 'Mother',
            'beneficiary_nic' => '718582555V',
            'beneficiary_date_of_birth' => '1971-12-23',
            'beneficiary_phone' => '+94774633712',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('consultants', [
            'user_id' => $consultant->id,
            'nic' => '200108200826',
            'bank_account_number' => '109020055896',
            'swift_code' => 'HNB004',
            'beneficiary_name' => 'Opathagm Vidanelage Chithra Violet',
        ]);

        $consultant->refresh();
        $this->assertTrue($consultant->consultant->fresh()->profile_complete);
    }

    public function test_student_registration_saves_nic_owner_and_course_placement_details(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $course = Course::where('status', 'published')->firstOrFail();

        // A partner account must be one of this consultant's own placement accounts.
        $partner = $consultantUser->consultant->hiddenAccounts()->first();

        $payload = [
            'name' => 'Synnex Dinuka',
            'email' => 'synnex.dinuka.software@gmail.com',
            'phone' => '+94701234567',
            'date_of_birth' => '2002-05-10',
            'gender' => 'male',
            'address' => '12 Lake Road',
            'city' => 'Colombo',
            'district' => 'Colombo',
            'nic' => '',
            'nic_owned_by_student' => '0',
            'nic_owner_name' => 'Kamal Perera',
            'nic_owner_relationship' => 'Father',
            'course_id' => $course->id,
            'placement_tier' => 1,
            'language' => 'Sinhala',
            'class_type' => 'Online',
            'course_type' => 'Individual',
        ];

        if ($partner) {
            $payload['partner_consultant_id'] = $partner->id;
        }

        $response = $this->actingAs($consultantUser)->post(route('consultant.students.store'), $payload);

        $response->assertRedirect(route('consultant.students.index'));

        $student = Student::whereHas('user', fn ($q) => $q->where('email', 'synnex.dinuka.software@gmail.com'))->firstOrFail();
        $this->assertFalse((bool) $student->nic_owned_by_student);
        $this->assertSame('Kamal Perera', $student->nic_owner_name);
        $this->assertSame('Father', $student->nic_owner_relationship);
        $this->assertSame('Sinhala', $student->language);
        $this->assertSame('Online', $student->class_type);
        $this->assertSame('Individual', $student->course_type);
        $this->assertSame('Colombo', $student->city);
        if ($partner) {
            $this->assertSame($partner->id, $student->partner_consultant_id);
        }
    }

    public function test_partner_consultant_cannot_be_self(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;
        $course = Course::where('status', 'published')->firstOrFail();

        $response = $this->actingAs($consultantUser)->post(route('consultant.students.store'), [
            'name' => 'Self Partner Test',
            'email' => 'self-partner-test@example.com',
            'course_id' => $course->id,
            'placement_tier' => 1,
            'partner_consultant_id' => $consultant->id,
        ]);

        $response->assertSessionHasErrors('partner_consultant_id');
        $this->assertDatabaseMissing('users', ['email' => 'self-partner-test@example.com']);
    }

    public function test_no_objection_letter_upload_is_stored(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $course = Course::where('status', 'published')->firstOrFail();

        $this->actingAs($consultantUser)->post(route('consultant.students.store'), [
            'name' => 'Noc Test Student',
            'email' => 'noc-test@example.com',
            'course_id' => $course->id,
            'placement_tier' => 1,
            'nic_owned_by_student' => '0',
            'nic_owner_name' => 'Guardian Name',
            'nic_owner_relationship' => 'Guardian',
            'no_objection_letter' => UploadedFile::fake()->create('noc.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $student = Student::whereHas('user', fn ($q) => $q->where('email', 'noc-test@example.com'))->firstOrFail();
        $this->assertNotNull($student->no_objection_letter_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($student->no_objection_letter_path);
    }
}
