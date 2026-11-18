<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonViewRequest;
use App\Models\User;
use App\Services\LmsSchemaRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ "497 student(s) asking to watch a lesson again" (2026-10-10).
 *
 * *"e section eka withrk 50 request load krnn, ithuru tika page widiyt balann
 * denn, filter eke 50, 100, 200, 300, 400 widiyt denn, e wagem select all dila
 * allow krnn puluwn widiyt hadl denn."*
 *
 * Every request used to load on every visit. Now 50 a page by default, the
 * admin can pick 100–400, and ticked requests are allowed (or declined) in one
 * go — each through the same rule as its own button.
 */
class CourseVideoRequestsPagingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        LmsSchemaRepair::ensure();
        Cache::forever('settings.mail.grouped', collect());

        $this->admin = User::role('super_admin')->firstOrFail();
        $this->course = Course::where('status', 'published')->firstOrFail();
        $this->lesson = Lesson::create([
            'course_id' => $this->course->id, 'title' => 'Day 05', 'slug' => 'day-05-'.Str::random(4),
            'status' => 'published', 'sort_order' => 99,
        ]);
    }

    /**
     * $count students who watched the lesson once and are asking again.
     *
     * @return array<int, LessonViewRequest>
     */
    private function requests(int $count, bool $enrolled = true): array
    {
        $made = [];

        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->create(['name' => sprintf('Asker %03d', $i)]);

            if ($enrolled) {
                $enrollment = Enrollment::create([
                    'user_id' => $user->id, 'course_id' => $this->course->id, 'status' => 'active', 'enrolled_at' => now(),
                ]);

                LessonProgress::create([
                    'enrollment_id' => $enrollment->id, 'lesson_id' => $this->lesson->id,
                    'view_count' => 1, 'window_expires_at' => now()->subDay(), 'extra_views_granted' => 0,
                ]);
            }

            $made[] = LessonViewRequest::create(['user_id' => $user->id, 'lesson_id' => $this->lesson->id, 'status' => 'pending']);
        }

        return $made;
    }

    private function page(array $query = []): string
    {
        return $this->actingAs($this->admin)->get(route('manage.course-videos.index', $query))->assertOk()->getContent();
    }

    private function rowsOn(string $html): int
    {
        return substr_count($html, 'name="ids[]"');
    }

    // ----- paging -----------------------------------------------------------------

    public function test_fifty_a_page_by_default_with_the_full_count_in_the_heading(): void
    {
        $this->requests(120);

        $html = $this->page();

        $this->assertSame(50, $this->rowsOn($html));
        $this->assertStringContainsString('120 student(s) asking to watch a lesson again', $html);
        $this->assertStringContainsString('Showing 1–50 of 120', $html);
    }

    public function test_the_admin_can_choose_a_larger_page(): void
    {
        $this->requests(120);

        $this->assertSame(100, $this->rowsOn($this->page(['req_per' => 100])));
        $this->assertSame(120, $this->rowsOn($this->page(['req_per' => 200])));
    }

    public function test_only_the_offered_sizes_are_accepted(): void
    {
        $this->requests(60);

        $this->assertSame(50, $this->rowsOn($this->page(['req_per' => 7])));
        $this->assertSame(50, $this->rowsOn($this->page(['req_per' => 100000])));
    }

    public function test_the_rest_are_on_the_next_pages(): void
    {
        $this->requests(120);

        $html = $this->page(['req_page' => 3]);

        $this->assertSame(20, $this->rowsOn($html));
        $this->assertStringContainsString('Showing 101–120 of 120', $html);
    }

    public function test_every_size_is_offered(): void
    {
        $this->requests(1);

        $html = $this->page();

        foreach ([50, 100, 200, 300, 400] as $size) {
            $this->assertStringContainsString("{$size} a page", $html);
        }
    }

    /** Paging the requests must not lose the folder being looked at. */
    public function test_page_links_keep_the_rest_of_the_screen(): void
    {
        $this->requests(60);

        $html = $this->page(['folder' => 'Crypto Master Guide', 'req_per' => 50]);

        $this->assertMatchesRegularExpression('/href="[^"]*folder=Crypto(%20|\+)Master(%20|\+)Guide[^"]*req_page=2[^"]*#requests"|href="[^"]*req_page=2[^"]*folder=Crypto[^"]*#requests"/', html_entity_decode($html));
    }

    // ----- allow / decline selected ------------------------------------------------------

    public function test_allowing_the_selected_requests_grants_each_one_more_viewing(): void
    {
        $made = $this->requests(5);
        $picked = array_slice($made, 0, 3);

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.requests.bulk'), ['action' => 'approve', 'ids' => array_map(fn ($r) => $r->id, $picked)])
            ->assertSessionHas('success', '3 requests allowed to watch again.');

        foreach ($picked as $request) {
            $this->assertSame('approved', $request->fresh()->status);
            $this->assertSame($this->admin->id, $request->fresh()->decided_by);
        }

        $this->assertSame(3, (int) LessonProgress::where('lesson_id', $this->lesson->id)->sum('extra_views_granted'), 'exactly one each');
        $this->assertSame(2, LessonViewRequest::pending()->count(), 'the unticked two still wait');
    }

    public function test_anything_already_dealt_with_or_no_longer_enrolled_is_skipped_and_said(): void
    {
        $made = $this->requests(2);
        $made[1]->update(['status' => 'declined']);
        $gone = $this->requests(1, enrolled: false)[0];

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.requests.bulk'), ['action' => 'approve', 'ids' => [$made[0]->id, $made[1]->id, $gone->id]])
            ->assertSessionHas('success', '1 request allowed to watch again. 2 skipped — already dealt with, no longer there, or the student is no longer enrolled.');

        $this->assertSame('declined', $made[1]->fresh()->status, 'a decision already taken is not overturned');
        $this->assertSame('pending', $gone->fresh()->status);
    }

    /** Allowing twice must not grant two viewings. */
    public function test_allowing_the_same_requests_twice_grants_nothing_more(): void
    {
        $ids = array_map(fn ($r) => $r->id, $this->requests(2));

        foreach ([1, 2] as $ignored) {
            $this->actingAs($this->admin)->post(route('manage.course-videos.requests.bulk'), ['action' => 'approve', 'ids' => $ids]);
        }

        $this->assertSame(2, (int) LessonProgress::where('lesson_id', $this->lesson->id)->sum('extra_views_granted'));
    }

    public function test_declining_the_selected_requests(): void
    {
        $made = $this->requests(3);

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.requests.bulk'), ['action' => 'decline', 'ids' => [$made[0]->id, $made[2]->id]])
            ->assertSessionHas('success', '2 requests declined.');

        $this->assertSame(['declined', 'pending', 'declined'], array_map(fn ($r) => $r->fresh()->status, $made));
        $this->assertSame(0, (int) LessonProgress::where('lesson_id', $this->lesson->id)->sum('extra_views_granted'));
    }

    public function test_more_than_a_page_or_an_unknown_action_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.requests.bulk'), ['action' => 'approve', 'ids' => range(1, 401)])
            ->assertSessionHasErrors('ids');

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.requests.bulk'), ['action' => 'delete', 'ids' => [1]])
            ->assertSessionHasErrors('action');
    }

    public function test_a_consultant_cannot_allow_requests(): void
    {
        $made = $this->requests(1);

        $this->actingAs(User::where('email', 'consultant@etec.lk')->firstOrFail())
            ->post(route('manage.course-videos.requests.bulk'), ['action' => 'approve', 'ids' => [$made[0]->id]])
            ->assertForbidden();

        $this->assertSame('pending', $made[0]->fresh()->status);
    }

    // ----- found by the review (2026-10-10) ------------------------------------------

    /** Allowing everything on the last page must not leave the admin on an empty page. */
    public function test_a_page_past_the_end_goes_to_the_last_real_page(): void
    {
        $this->requests(60);

        $this->actingAs($this->admin)
            ->get(route('manage.course-videos.index', ['req_page' => 2, 'req_per' => 50]))
            ->assertOk();

        LessonViewRequest::pending()->orderByDesc('id')->limit(60)->get()->slice(50)->each->update(['status' => 'approved']);

        $response = $this->actingAs($this->admin)->get(route('manage.course-videos.index', ['req_page' => 2, 'req_per' => 50]));

        $response->assertRedirect();
        $this->assertStringContainsString('req_page=1', $response->headers->get('Location'));
        $this->assertStringContainsString('#requests', $response->headers->get('Location'));
    }

    public function test_ticked_requests_that_no_longer_exist_are_counted_as_skipped(): void
    {
        $made = $this->requests(1);

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.requests.bulk'), ['action' => 'approve', 'ids' => [$made[0]->id, 999998, 999999]])
            ->assertSessionHas('success', '1 request allowed to watch again. 2 skipped — already dealt with, no longer there, or the student is no longer enrolled.');
    }

    /** Switching folder or lesson-order course keeps the chosen page size. */
    public function test_the_page_size_survives_the_rest_of_the_screen(): void
    {
        $this->requests(1);

        $html = html_entity_decode($this->page(['req_per' => 200]));

        $this->assertMatchesRegularExpression('/href="[^"]*folder=[^"]*req_per=200/', $html);
        $this->assertStringContainsString('<input type="hidden" name="req_per" value="200">', $html);
    }

    /** The single buttons still work exactly as before. */
    public function test_the_single_allow_button_is_unchanged(): void
    {
        $made = $this->requests(1);

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.approve', $made[0]))
            ->assertSessionHas('success');

        $this->assertSame('approved', $made[0]->fresh()->status);
        $this->assertSame(1, (int) LessonProgress::where('lesson_id', $this->lesson->id)->sum('extra_views_granted'));
    }
}
