<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hall bookings are not for lecturers (2026-10-24). Owner: "Lectuer gen My
 * booking and All bookings ain krla denn". Gone from the menu AND from the
 * pages, including for a lecturer who also holds the consultant role.
 */
class LecturerHasNoHallBookingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function lecturer(): User
    {
        return User::where('email', 'lecturer@etec.lk')->firstOrFail();
    }

    /** @return array<int, string> every bookings page, by URL */
    private function bookingUrls(): array
    {
        return [route('bookings.index'), route('bookings.all'), route('bookings.create'), route('bookings.availability')];
    }

    private function assertShutOut(User $user): void
    {
        foreach ($this->bookingUrls() as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }

        $this->actingAs($user)->post(route('bookings.store'), [
            'hall_id' => Hall::first()->id,
            'booking_date' => today()->addDay()->toDateString(),
            'start_time' => '09:00', 'end_time' => '10:00', 'reason' => 'Lecture',
        ])->assertForbidden();
    }

    public function test_the_lecturer_menu_has_no_bookings(): void
    {
        $labels = array_column(Navigation::for($this->lecturer()), 'label');

        $this->assertNotContains('My Bookings', $labels);
        $this->assertNotContains('All Bookings', $labels);
        $this->assertContains('Zoom Meetings', $labels, 'guard: this is the lecturer menu');
    }

    public function test_a_lecturer_cannot_open_the_booking_pages(): void
    {
        $this->assertShutOut($this->lecturer());
    }

    /** A consultant who teaches gets the lecturer menu — and no bookings, even when granted. */
    public function test_a_lecturer_who_is_also_a_consultant_is_shut_out_too(): void
    {
        $user = User::where('email', 'consultant@etec.lk')->firstOrFail();

        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('users.page-access', $user), ['pages' => ['my_bookings', 'all_bookings']])
            ->assertRedirect();
        $this->actingAs($user->fresh())->get(route('bookings.index'))->assertOk();

        $user->assignRole('lecturer');
        $user = $user->fresh();
        $this->assertSame('lecturer', $user->primaryRole(), 'guard');

        $this->assertNotContains('My Bookings', array_column(Navigation::for($user), 'label'));
        $this->assertShutOut($user);
    }

    public function test_other_staff_still_book_halls(): void
    {
        foreach (['admin@etec.lk', 'accountant@etec.lk', 'superadmin@etec.lk'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->actingAs($user)->get(route('bookings.index'))->assertOk();
            $this->actingAs($user)->get(route('bookings.all'))->assertOk();
            $this->assertContains('My Bookings', array_column(Navigation::for($user), 'label'), $email);
        }
    }
}
