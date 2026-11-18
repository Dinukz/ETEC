<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page in the public nav must actually render for a signed-out visitor.
 *
 * ⚠️ THE POINT IS THE NAV LINKS. The navbar builds its URLs with route(), so a
 * page whose route is missing does not 404 — it throws while rendering the
 * HEADER, which takes down every public page at once, including the homepage.
 * One broken link is a whole-site outage here, so each one is walked.
 *
 * Services and Careers were added 2026-09-02 and carry sample copy; News &
 * Events is the existing blog route relabelled.
 */
class PublicSitePagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public static function publicPages(): array
    {
        return [
            'home' => ['home'],
            'about' => ['about'],
            'courses' => ['courses.index'],
            'services' => ['services'],
            'news and events' => ['blog.index'],
            'careers' => ['careers'],
            'contact' => ['contact'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicPages')]
    public function test_a_visitor_can_open_every_page_in_the_nav(string $routeName): void
    {
        $this->get(route($routeName))->assertOk();
    }

    /** And every nav link points somewhere that exists. */
    public function test_every_nav_link_resolves(): void
    {
        $page = $this->get(route('home'))->assertOk();

        foreach (['About Us', 'Courses', 'Services', 'News &amp; Events', 'Careers', 'Contact Us'] as $label) {
            $page->assertSee($label, false);
        }
    }

    /**
     * ⚠️ THE PHOTOS ARE NOT IN THE REPO YET, and the site must not look broken
     * while that is true. <x-site-image> paints a themed block instead of
     * emitting an <img> that 404s.
     */
    public function test_a_missing_site_photo_does_not_render_a_broken_image(): void
    {
        // ⚠️ The hero photo itself was added on 2026-11-02, so this now checks the
        // component directly with a photo that is NOT there: a themed block, no
        // <img> pointing at a missing file (which shows as broken alt text).
        $html = \Illuminate\Support\Facades\Blade::render(
            '<x-site-image path="images/not-uploaded-yet.jpg" alt="Missing photo" />',
        );

        $this->assertStringNotContainsString('images/not-uploaded-yet.jpg', $html);
        $this->assertStringContainsString('role="img"', $html);
    }

    /**
     * ⚠️ The owner uploaded the About photos as .jpeg while the page asked for
     * .jpg (2026-11-02) — the same name with another photo ending is used.
     */
    public function test_a_photo_with_another_ending_is_still_found(): void
    {
        $dir = public_path('images/test-ending');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/campus.jpeg', 'x');

        try {
            $html = \Illuminate\Support\Facades\Blade::render(
                '<x-site-image path="images/test-ending/campus.jpg" alt="Campus" />',
            );

            $this->assertStringContainsString('images/test-ending/campus.jpeg', $html);
            $this->assertStringNotContainsString('role="img"', $html);
        } finally {
            @unlink($dir.'/campus.jpeg');
            @rmdir($dir);
        }
    }

    /** Once the file IS there, it is used. */
    public function test_the_hero_photo_is_used_when_present(): void
    {
        $dir = public_path('images');
        $file = $dir.'/hero-students.jpg';

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // ⚠️ The real photo lives here since 2026-11-02 — keep it and put it back,
        // never delete it (this test deleted it once).
        $original = is_file($file) ? file_get_contents($file) : null;

        // A 1x1 JPEG is enough — the component only asks whether the file exists.
        file_put_contents($file, base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'.
            'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'.
            'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
        ));

        try {
            $this->get(route('home'))
                ->assertOk()
                ->assertSee('images/hero-students.jpg', false);
        } finally {
            $original !== null ? file_put_contents($file, $original) : @unlink($file);
        }
    }
}
