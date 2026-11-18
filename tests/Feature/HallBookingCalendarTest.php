<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "All Bookings" calendar hung ("stuck") when opened. Reproduces the page
 * load with real bookings so a hang / fatal shows up here instead of in a
 * browser tab on production.
 */
class HallBookingCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function hallWithBooking(User $owner): Hall
    {
        $hall = Hall::create(['name' => 'Main Hall', 'location' => 'Colombo', 'capacity' => 50, 'is_active' => true]);

        HallBooking::create([
            'hall_id' => $hall->id,
            'user_id' => $owner->id,
            'booking_date' => now()->startOfMonth()->addDays(9)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '11:00',
            'reason' => 'Lecture',
            'status' => 'approved',
        ]);

        return $hall;
    }

    public function test_all_bookings_calendar_loads_for_an_admin(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $this->hallWithBooking($admin);

        $response = $this->actingAs($admin)->get(route('bookings.all'));

        $response->assertOk();
        $response->assertSee('Active Bookings');
        $response->assertSee('Main Hall');
    }

    public function test_all_bookings_calendar_loads_for_a_consultant(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->hallWithBooking($admin);

        // All Bookings is a per-consultant grant since 2026-09-22.
        $this->grantConsultantPages($consultant, ['all_bookings']);

        $this->actingAs($consultant)->get(route('bookings.all'))->assertOk();
    }

    public function test_all_bookings_calendar_loads_with_an_explicit_month_filter(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $hall = $this->hallWithBooking($admin);

        $this->actingAs($admin)
            ->get(route('bookings.all', ['hall_id' => $hall->id, 'month' => now()->format('Y-m')]))
            ->assertOk();
    }

    public function test_all_bookings_calendar_loads_when_no_halls_exist(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $this->actingAs($admin)->get(route('bookings.all'))->assertOk();
    }

    /**
     * The page rendered fine while still being unusable: the day modal sat
     * OUTSIDE the calendar's x-data, so `open` never resolved, Alpine stripped
     * x-cloak anyway, and the modal's full-screen overlay stayed up blocking
     * every click. A plain assertOk() can't see that — this checks the nesting.
     */
    public function test_the_day_modal_lives_inside_the_calendars_alpine_scope(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $this->hallWithBooking($admin);

        $html = $this->actingAs($admin)->get(route('bookings.all'))->assertOk()->getContent();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $overlays = (new \DOMXPath($dom))->query('//*[@x-show="open"]');
        $this->assertGreaterThan(0, $overlays->length, 'The day modal overlay should be on the page.');

        $nested = (new \DOMXPath($dom))->query('//*[contains(@x-data, "open:")]//*[@x-show="open"]');
        $this->assertSame(
            $overlays->length,
            $nested->length,
            'Every x-show="open" element must be inside the x-data that declares `open`, or its overlay stays visible and blocks the whole page.',
        );
    }
}
