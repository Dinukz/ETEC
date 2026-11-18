<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Course thumbnails and blog images must render through MediaController, not
 * through asset('storage/...').
 *
 * Production is shared hosting with no CLI, so the public/storage symlink is
 * missing — which is the entire reason MediaController exists (see its
 * docblock). Course thumbnails uploaded from Course Management were still
 * rendered with asset('storage/...') and so showed as broken images on the
 * public site (reported 2026-08-19, homepage "Popular Courses").
 */
class PublicMediaUrlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    public function test_an_uploaded_course_thumbnail_is_served_by_the_media_route(): void
    {
        $path = UploadedFile::fake()->image('thumb.jpg')->store('courses', 'public');

        $course = Course::first();
        $course->update(['thumbnail' => $path]);

        $this->assertSame(route('media.show', $path), $course->fresh()->thumbnail_url);

        // ...and that route actually serves it, rather than 404ing the way the
        // symlink path does on production.
        $this->get(route('media.show', $path))->assertOk();
    }

    public function test_an_uploaded_blog_image_is_served_by_the_media_route(): void
    {
        $path = UploadedFile::fake()->image('post.jpg')->store('blog', 'public');

        $blog = Blog::create([
            'author_id' => User::first()->id,
            'title' => 'Media Test Post',
            'slug' => 'media-test-post',
            'content' => 'Body',
            'featured_image' => $path,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->assertSame(route('media.show', $path), $blog->featured_image_url);
        $this->get(route('media.show', $path))->assertOk();
    }

    /** A remote URL is passed through untouched rather than wrapped in the media route. */
    public function test_an_absolute_thumbnail_url_is_left_alone(): void
    {
        $course = Course::first();
        $course->update(['thumbnail' => 'https://cdn.example.com/x.jpg']);

        $this->assertSame('https://cdn.example.com/x.jpg', $course->fresh()->thumbnail_url);
    }

    public function test_no_thumbnail_yields_null_rather_than_a_broken_url(): void
    {
        $course = Course::first();
        $course->update(['thumbnail' => null]);

        $this->assertNull($course->fresh()->thumbnail_url);
    }

    /**
     * ⚠️ The allowlist is a security boundary: `path` is a `.*` route param, so
     * anything permitted here is world-readable. Receipts and payment slips
     * must stay behind the authenticated payments.receipt route.
     */
    public function test_the_media_route_refuses_paths_outside_the_allowlist(): void
    {
        $secret = UploadedFile::fake()->create('slip.pdf', 10)->store('receipts', 'public');

        $this->get(route('media.show', $secret))->assertNotFound();
    }

    public function test_the_public_course_page_renders_the_media_url(): void
    {
        $path = UploadedFile::fake()->image('thumb.jpg')->store('courses', 'public');

        $course = Course::where('status', 'published')->firstOrFail();
        $course->update(['thumbnail' => $path]);

        $this->get(route('courses.show', $course))
            ->assertOk()
            ->assertSee(route('media.show', $path), false)
            ->assertDontSee(asset('storage/'.$path), false);
    }
}
