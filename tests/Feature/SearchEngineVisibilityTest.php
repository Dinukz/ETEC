<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ WHAT GOOGLE IS GIVEN (2026-09-30).
 *
 * Reported: searching "etecbusiness" found nothing, and the site only opened at
 * https://etecbusiness.lk/public/. Checked from outside: the bare domain
 * answered 403, so there was no homepage to index at all. The root .htaccess
 * moves the site to the clean address (that part is the host, and is checked
 * on the live site after upload, not here). This file pins what the
 * application itself hands a search engine: a sitemap of the public pages, a
 * canonical address on every page, and on the homepage the structured data
 * that ties the brand name to the site.
 */
class SearchEngineVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // See RegisterOnADownlineAccountTest for why this is primed.
        Cache::forever('settings.mail.grouped', collect());
    }

    public function test_the_sitemap_lists_the_public_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));

        $xml = $response->getContent();

        $this->assertNotFalse(simplexml_load_string($xml), 'well-formed XML');

        foreach (['home', 'courses.index', 'about', 'contact', 'blog.index'] as $name) {
            $this->assertStringContainsString('<loc>'.e(route($name)).'</loc>', $xml, "{$name} is listed");
        }
    }

    /** Every published course is listed; a draft never is. */
    public function test_the_sitemap_lists_published_courses_only(): void
    {
        $template = Course::where('status', 'published')->firstOrFail();

        $draft = Course::create([
            'title' => 'Unreleased Course',
            'slug' => 'unreleased-course-'.Str::random(6),
            'description' => 'draft',
            'price' => $template->price,
            'currency' => $template->currency,
            'status' => 'draft',
            'lecturer_id' => $template->lecturer_id,
        ]);

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString(e(route('courses.show', $template)), $xml);
        $this->assertStringNotContainsString($draft->slug, $xml, 'a draft is not advertised to Google');
    }

    /** ⚠️ Nothing behind a login — a crawler only gets a redirect there. */
    public function test_the_sitemap_names_no_login_only_page(): void
    {
        $xml = $this->get('/sitemap.xml')->getContent();

        foreach (['/dashboard', '/consultant/', '/login', '/register', '/users'] as $private) {
            $this->assertStringNotContainsString($private, $xml, "{$private} is not in the sitemap");
        }
    }

    /** Every page names its own address as THE address. */
    public function test_pages_carry_a_canonical_address(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('about').'">', false);
    }

    /**
     * ⚠️ THE ICON GOOGLE SHOWS BESIDE THE RESULT (2026-10-02). It showed a grey
     * globe: the page named a 512×512 JPEG (Google only takes multiples of 48 px)
     * and /favicon.ico — where Google looks next — was an empty file.
     */
    public function test_the_homepage_names_an_icon_google_accepts(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="icon" href="'.asset('favicon.ico').'" sizes="48x48">', $html);
        $this->assertStringContainsString('href="'.asset('favicon-192x192.png').'"', $html);
        $this->assertStringContainsString('<link rel="apple-touch-icon"', $html);
        $this->assertStringNotContainsString('rel="icon" href="'.asset('images/etec-logo.jpeg'), $html);

        // The files themselves: real images, square, in sizes Google accepts.
        foreach (['favicon-48x48.png' => 48, 'favicon-96x96.png' => 96, 'favicon-192x192.png' => 192] as $file => $size) {
            [$w, $h] = getimagesize(public_path($file));
            $this->assertSame([$size, $size], [$w, $h], $file);
            $this->assertSame(0, $size % 48, "{$file} is a multiple of 48 px");
        }

        $ico = file_get_contents(public_path('favicon.ico'));
        $this->assertGreaterThan(1000, strlen($ico), 'favicon.ico is not the empty placeholder');
        $this->assertSame("\0\0\1\0", substr($ico, 0, 4), 'and is a real .ico');

        $entries = unpack('v', substr($ico, 4, 2))[1];
        $widths = [];
        for ($i = 0; $i < $entries; $i++) {
            $widths[] = ord($ico[6 + 16 * $i]);
        }
        $this->assertContains(48, $widths, 'it holds a 48 px icon');
    }

    /** The homepage tells Google this site IS the brand being searched for. */
    public function test_the_homepage_carries_the_brand_structured_data(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $this->assertNotEmpty($m, 'structured data is present');

        $data = json_decode($m[1], true);

        $this->assertIsArray($data, 'and is valid JSON');
        $this->assertSame('ETEC Business College', $data[0]['name']);
        $this->assertContains('etecbusiness', $data[0]['alternateName'], 'the word people actually type');
        $this->assertSame(route('home'), $data[1]['url']);
    }

    /** Only the homepage carries it — it describes the organisation, not a page. */
    public function test_other_pages_do_not_repeat_the_structured_data(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertDontSee('application/ld+json', false);
    }

    /**
     * ⚠️ The address is admin-typed text inside a <script> tag. A value that
     * contains "</script>" must never be able to close it.
     */
    public function test_an_admin_typed_address_cannot_break_out_of_the_script(): void
    {
        Setting::set('contact_address', 'Galle Road</script><script>alert(1)</script>');

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $data = json_decode($m[1] ?? '', true);

        $this->assertSame(
            'Galle Road</script><script>alert(1)</script>',
            $data[1]['address']['streetAddress'] ?? null,
            'kept intact as data, escaped as markup',
        );
    }
}
