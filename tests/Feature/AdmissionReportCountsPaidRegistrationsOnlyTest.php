<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A FREE CARD IS NOT AN ADMISSION (2026-09-23).
 *
 * *"admission report eke free card kattiya penwann nathuw thiyenn, half or
 * full payment krla thiyan ayaw withrk penwann."*
 *
 * A free card is handed out rather than bought. It still seats the person and
 * still opens their set — that part of the engine is untouched — but nobody
 * paid for it, so counting it in a marketer's admissions made the report say
 * something the money never said. On the 21 Sep live data free cards are the
 * majority of payment rows, so this is the difference between a report that
 * can be checked against the bank and one that cannot.
 *
 * The count and the listed rows are asserted together on purpose: filtering
 * one and not the other is the failure this would most plausibly ship as.
 */
class AdmissionReportCountsPaidRegistrationsOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Consultant $consultant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();

        $this->consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function staff(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    /** A student of this consultant whose registration was paid for in $type. */
    private function studentPaying(string $name, ?string $type): Student
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('student');

        $student = Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN'.Str::random(8),
            'consultant_id' => $this->consultant->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        if ($type === null) {
            return $student; // registered, never paid anything at all
        }

        $course = Course::where('status', 'published')->firstOrFail();

        $enrollment = Enrollment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $this->consultant->id,
            'amount' => $type === 'free_card' ? 0 : 39000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => $type,
            'status' => 'pending', 'paid_at' => now(),
        ]);

        return $student;
    }

    /** The consultant's row on the report, as the screen builds it. */
    private function reportRow(): ?object
    {
        $response = $this->actingAs($this->staff())->get(route('reports.admissions'));

        $response->assertOk();

        return collect($response->viewData('rows'))->firstWhere('id', $this->consultant->id);
    }

    // ------------------------------------------------------------------------

    /**
     * ⚠️ The row itself stays — a real consultant is always listed, with or
     * without admissions (that rule is older than this change). What must be
     * zero is the count.
     */
    public function test_a_free_card_registration_is_not_counted(): void
    {
        $this->studentPaying('Free Card Person', 'free_card');

        $row = $this->reportRow();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->student_count, 'a free card is not an admission');
        $this->assertTrue($row->students->isEmpty(), 'and it is not listed either');
    }

    public function test_a_full_payment_is_counted(): void
    {
        $this->studentPaying('Full Payment Person', 'full');

        $this->assertSame(1, (int) $this->reportRow()?->student_count);
    }

    public function test_a_half_payment_is_counted(): void
    {
        $this->studentPaying('Half Payment Person', 'half');

        $this->assertSame(1, (int) $this->reportRow()?->student_count);
    }

    /**
     * ⚠️ THE ONE THAT CATCHES A HALF-APPLIED FILTER: the count and the listed
     * students must be filtered by the same rule.
     */
    public function test_the_count_and_the_listed_students_agree(): void
    {
        $this->studentPaying('Paid One', 'full');
        $this->studentPaying('Paid Two', 'half');
        $this->studentPaying('Free Card Person', 'free_card');
        $this->studentPaying('Never Paid', null);

        $row = $this->reportRow();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->student_count, 'only the two paid registrations');
        $this->assertSame(2, $row->students->count(), 'the list agrees with the count');

        $names = $row->students->map(fn ($s) => $s->user?->name)->all();

        sort($names);
        $this->assertSame(['Paid One', 'Paid Two'], $names);
    }

    /** A student with no payment at all never paid either. */
    public function test_a_registration_with_no_payment_is_not_counted(): void
    {
        $this->studentPaying('Never Paid', null);

        $this->assertSame(0, (int) $this->reportRow()?->student_count);
    }

    /** The screen says so, because every number on it just got smaller. */
    public function test_the_page_says_free_cards_are_left_out(): void
    {
        $this->actingAs($this->staff())
            ->get(route('reports.admissions'))
            ->assertOk()
            ->assertSee('Free cards are not included');
    }
}
