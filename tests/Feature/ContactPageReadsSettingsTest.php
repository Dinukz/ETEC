<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ THE CONTACT PAGE SHOWS WHAT SETTINGS SAYS (2026-09-26).
 *
 * The owner changed the address under Settings -> General and the public page
 * went on printing "Colombo, Sri Lanka". Nothing was broken in the saving: the
 * page had never read the setting at all, and the email and phone beside it
 * were literals too.
 *
 * Setting::get() caches forever and Setting::set() forgets that key, so the
 * cache is not what was stale — this test would have caught the real cause.
 */
class ContactPageReadsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prints_the_saved_address_email_and_phone(): void
    {
        Setting::set('contact_address', 'No. 30A/ 01, Galle Road, Molligoda, Wadduwa');
        Setting::set('contact_email', 'info@etecbusiness.lk');
        Setting::set('contact_phone', '+94 77 123 4567');

        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('No. 30A/ 01, Galle Road, Molligoda, Wadduwa')
            ->assertSee('info@etecbusiness.lk')
            ->assertSee('+94 77 123 4567')
            ->assertDontSee('Colombo, Sri Lanka');
    }

    /** ⚠️ A tel: link may only carry digits and a leading plus. */
    public function test_the_phone_link_is_stripped_of_spaces(): void
    {
        Setting::set('contact_phone', '+94 77 123 4567');

        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('tel:+94771234567', false);
    }

    /**
     * ⚠️ THE ADDRESS CARD OPENS A MAP (2026-09-27). Its link was '#', so it
     * looked clickable and did nothing but add a fragment to the URL.
     */
    public function test_the_address_links_to_a_map(): void
    {
        Setting::set('contact_address', 'No. 30A/ 01, Galle Road, Molligoda, Wadduwa');

        $this->get(route('contact'))
            ->assertOk()
            // &amp; because Blade escapes the attribute — correct HTML, and
            // the browser sends a plain & to Maps.
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query='.urlencode('No. 30A/ 01, Galle Road, Molligoda, Wadduwa'), false)
            // and the card itself no longer points at a bare fragment.
            ->assertDontSee('<a href="#" class="card', false);
    }

    /** The other two hand off to an app, so they stay in the same tab. */
    public function test_the_email_and_phone_still_link_out(): void
    {
        Setting::set('contact_email', 'info@etecbusiness.lk');
        Setting::set('contact_phone', '+94 11 234 5678');

        $body = $this->get(route('contact'))->assertOk()->getContent();

        $this->assertStringContainsString('mailto:info@etecbusiness.lk', $body);
        $this->assertStringContainsString('tel:+94112345678', $body);

        // Only the MAP leaves the site. Since 2026-11-02 the page also embeds the
        // map (footer + a "Find us" section), each with an "Open in Google Maps"
        // link — so every new-tab link must be a Google Maps one, and the email
        // and phone cards never open a tab.
        preg_match_all('/<a\s[^>]*href="([^"]+)"[^>]*target="_blank"/', $body, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $href) {
            $this->assertStringStartsWith('https://www.google.com/maps/', html_entity_decode($href), 'only the map opens a new tab');
        }
    }

    /** An unset setting falls back rather than printing an empty row. */
    public function test_an_empty_setting_falls_back(): void
    {
        Setting::set('contact_address', '');

        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('Colombo, Sri Lanka');
    }
}
