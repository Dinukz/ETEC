<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hall CRUD is admin-only (this was previously missing entirely — there was no
 * way to add a hall except a seeder, so "No halls" showed up with an empty DB).
 * Cancelling someone else's booking is also now admin-only, not any staff role.
 */
class HallManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_create_a_hall(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $this->actingAs($admin)->post(route('halls.store'), [
            'name' => 'New Hall', 'location' => 'Block C', 'capacity' => 40,
        ])->assertRedirect();

        $this->assertDatabaseHas('halls', ['name' => 'New Hall', 'is_active' => true]);
    }

    public function test_consultant_cannot_manage_halls(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail();

        $this->actingAs($consultant)->get(route('halls.index'))->assertForbidden();
        $this->actingAs($consultant)->post(route('halls.store'), ['name' => 'Sneaky Hall'])->assertForbidden();
    }

    public function test_admin_can_toggle_a_hall_inactive(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $hall = Hall::first();

        $this->actingAs($admin)->patch(route('halls.toggle', $hall))->assertRedirect();
        $this->assertFalse($hall->fresh()->is_active);
    }

    // ⚠️ These booked as the LECTURER until 2026-10-24, when hall bookings were
    // taken away from lecturers (LecturerHasNoHallBookingsTest). Same checks,
    // made by staff who still book halls.
    public function test_a_deactivated_hall_no_longer_appears_for_booking(): void
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hall = Hall::first();
        $hall->update(['is_active' => false]);

        $this->actingAs($accountant)
            ->get(route('bookings.create'))
            ->assertOk()
            ->assertDontSee($hall->name);
    }

    public function test_only_admin_can_cancel_someone_elses_booking(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('accountant');
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $hall = Hall::first();

        $booking = HallBooking::create([
            'hall_id' => $hall->id,
            'user_id' => $owner->id,
            'booking_date' => today()->addDay(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => 'approved',
        ]);

        // An accountant (staff, but not admin) may not cancel another user's booking.
        $this->actingAs($accountant)->patch(route('bookings.cancel', $booking))->assertForbidden();
        $this->assertSame('approved', $booking->fresh()->status);

        // The owner may cancel their own.
        $this->actingAs($owner)->patch(route('bookings.cancel', $booking))->assertRedirect();
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_admin_can_cancel_any_booking(): void
    {
        $lecturer = User::where('email', 'lecturer@etec.lk')->firstOrFail();
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $hall = Hall::first();

        $booking = HallBooking::create([
            'hall_id' => $hall->id,
            'user_id' => $lecturer->id,
            'booking_date' => today()->addDay(),
            'start_time' => '11:00',
            'end_time' => '12:00',
            'status' => 'approved',
        ]);

        $this->actingAs($admin)->patch(route('bookings.cancel', $booking))->assertRedirect();
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_active_bookings_calendar_renders_with_click_to_view_data(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $hall = Hall::first();

        HallBooking::create([
            'hall_id' => $hall->id,
            'user_id' => $admin->id,
            'booking_date' => today(),
            'start_time' => '14:00',
            'end_time' => '15:00',
            'reason' => 'Staff meeting',
            'status' => 'approved',
        ]);

        $this->actingAs($admin)
            ->get(route('bookings.all', ['hall_id' => $hall->id]))
            ->assertOk()
            ->assertSee('Staff meeting')
            ->assertSee('cancel_url');
    }
}
