<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The start/end date filters on the Payments screen and the Service Income
 * Summary. Both ends are INCLUSIVE — the classic bug here is comparing a plain
 * date against a timestamp column, which silently drops everything recorded
 * after midnight on the final day.
 */
class DateRangeFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    /** A verified payment stamped as paid on a specific day + time. */
    private function paymentOn(string $paidAt): Payment
    {
        $user = User::factory()->create();
        Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'verified',
            'paid_at' => $paidAt,
            'verified_at' => $paidAt,
        ]);
    }

    public function test_payments_are_filtered_by_an_inclusive_paid_date_range(): void
    {
        $before = $this->paymentOn('2026-03-09 10:00:00');
        $onFrom = $this->paymentOn('2026-03-10 00:30:00');
        // Late in the day on the final date — this is the one a naive
        // `<= 2026-03-12` comparison would wrongly exclude.
        $onTo = $this->paymentOn('2026-03-12 23:45:00');
        $after = $this->paymentOn('2026-03-13 08:00:00');

        $response = $this->actingAs($this->admin())->get(route('payments.index', [
            'status' => 'verified',
            'from' => '2026-03-10',
            'to' => '2026-03-12',
        ]));

        $response->assertOk();

        $codes = collect($response->viewData('payments')->items())->pluck('payment_code');

        $this->assertContains($onFrom->payment_code, $codes, 'The first day of the range is included.');
        $this->assertContains($onTo->payment_code, $codes, 'The last day is included, right up to midnight.');
        $this->assertNotContains($before->payment_code, $codes);
        $this->assertNotContains($after->payment_code, $codes);
    }

    public function test_the_payment_cards_follow_the_same_date_range_as_the_table(): void
    {
        $this->paymentOn('2026-03-10 09:00:00');
        $this->paymentOn('2026-03-11 09:00:00');
        $this->paymentOn('2026-06-01 09:00:00'); // outside the range

        $response = $this->actingAs($this->admin())->get(route('payments.index', [
            'status' => 'verified',
            'from' => '2026-03-10',
            'to' => '2026-03-11',
        ]));

        $stats = $response->viewData('stats');

        $this->assertSame(2, $stats['verified'], 'The cards must describe the filtered range, not all time.');
        $this->assertSame(56000.0, $stats['total_verified']);
    }

    public function test_a_backwards_range_is_swapped_rather_than_returning_nothing(): void
    {
        $inside = $this->paymentOn('2026-03-11 12:00:00');

        // from/to entered the wrong way round — always a typo, so it is
        // corrected instead of showing a confusing empty table.
        $response = $this->actingAs($this->admin())->get(route('payments.index', [
            'status' => 'verified',
            'from' => '2026-03-12',
            'to' => '2026-03-10',
        ]));

        $codes = collect($response->viewData('payments')->items())->pluck('payment_code');

        $this->assertContains($inside->payment_code, $codes);
    }

    public function test_an_unparseable_date_is_ignored_instead_of_erroring(): void
    {
        $this->paymentOn('2026-03-11 12:00:00');

        $response = $this->actingAs($this->admin())->get(route('payments.index', [
            'status' => 'verified',
            'from' => 'not-a-date',
        ]));

        $response->assertOk();
        $this->assertCount(1, $response->viewData('payments')->items(), 'A junk value falls back to no filter.');
    }

    public function test_service_income_summary_is_filtered_by_recorded_date(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        $make = function (string $createdAt, float $amount) use ($consultant) {
            $c = Commission::create([
                'consultant_id' => $consultant->id,
                'user_id' => User::factory()->create()->id,
                'amount' => $amount,
                'slots' => 1,
                'tier' => 1,
                'type' => 'bonus',
                'status' => 'pending',
            ]);

            // created_at is set by the model, so stamp it explicitly.
            $c->forceFill(['created_at' => $createdAt])->save();

            return $c;
        };

        $make('2026-03-09 10:00:00', 1000);
        $make('2026-03-10 08:00:00', 2500);
        $make('2026-03-12 22:30:00', 2500);
        $make('2026-03-15 10:00:00', 9999);

        $response = $this->actingAs($this->admin())->get(route('commissions.history', [
            'from' => '2026-03-10',
            'to' => '2026-03-12',
        ]));

        $response->assertOk();

        $totals = $response->viewData('totals');

        $this->assertSame(2, $totals['count'], 'Only the two rows inside the range.');
        $this->assertSame(5000.0, $totals['amount'], 'And the total describes exactly those rows.');
    }
}
