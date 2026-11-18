<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonViewRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\LmsSchemaRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ THE "ASKING TO WATCH AGAIN" REQUESTS CAN BE SEARCHED (2026-11-04).
 *
 * Owner: *"Course video walat ena request search krnn option ekak dala denn"* —
 * 418 were waiting, 50 a page, and finding one student meant paging through.
 * One box finds a request by the student's name, email, phone, SRN (or SCN once
 * promoted), the lesson or the course. It rides on its own query-string name
 * (req_q), like req_page / req_per, and survives paging and the page size.
 */
class CourseVideoRequestSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        LmsSchemaRepair::ensure();
        Cache::forever('settings.mail.grouped', collect());

        $this->admin = User::role('super_admin')->firstOrFail();
        $this->course = Course::where('status', 'published')->firstOrFail();
    }

    private function lesson(string $title, ?Course $course = null): Lesson
    {
        return Lesson::create([
            'course_id' => ($course ?? $this->course)->id, 'title' => $title, 'slug' => Str::slug($title).'-'.Str::random(4),
            'status' => 'published', 'sort_order' => 99,
        ]);
    }

    private function ask(string $name, Lesson $lesson, string $status = 'pending', array $user = []): LessonViewRequest
    {
        $student = User::factory()->create(['name' => $name] + $user);

        return LessonViewRequest::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'status' => $status]);
    }

    private function page(array $query = []): string
    {
        return $this->actingAs($this->admin)->get(route('manage.course-videos.index', $query))->assertOk()->getContent();
    }

    /** The rows actually listed — the request ids, not text elsewhere on the page. */
    private function listed(string $html): array
    {
        preg_match_all('/name="ids\[\]" value="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    public function test_a_name_narrows_the_list_and_the_heading_keeps_the_full_count(): void
    {
        $day4 = $this->lesson('Day 4');
        $asuntha = $this->ask('Asuntha Lalani', $day4);
        $this->ask('Rishan Jeewantha', $day4);

        $html = $this->page(['req_q' => 'asuntha']);

        $this->assertSame([$asuntha->id], $this->listed($html));
        $this->assertStringContainsString('2 student(s) asking to watch a lesson again', $html, 'the heading still counts everyone waiting');
        $this->assertStringContainsString('1 matching', $html);
        $this->assertStringContainsString('value="asuntha"', $html, 'the box keeps what was typed');
    }

    public function test_the_lesson_or_the_course_finds_a_request(): void
    {
        $other = $this->course->replicate();
        $other->slug = 'crypto-master-guide-'.Str::lower(Str::random(4));
        $other->title = 'Crypto Master Guide';
        $other->save();

        $day4 = $this->ask('First Student', $this->lesson('Day 4'));
        $day8 = $this->ask('Second Student', $this->lesson('Day 8'));
        $crypto = $this->ask('Third Student', $this->lesson('Day 01_preview', $other));

        $this->assertSame([$day8->id], $this->listed($this->page(['req_q' => 'Day 8'])));
        $this->assertSame([$crypto->id], $this->listed($this->page(['req_q' => 'crypto master'])));
        $this->assertEqualsCanonicalizing([$day4->id, $day8->id], $this->listed($this->page(['req_q' => $this->course->title])));
    }

    public function test_email_phone_srn_and_scn_find_a_request(): void
    {
        $lesson = $this->lesson('Day 2');

        $byEmail = $this->ask('Mail Person', $lesson, user: ['email' => 'samadhi.k@example.com']);
        $byPhone = $this->ask('Phone Person', $lesson, user: ['phone' => '0771239876']);

        $bySrn = $this->ask('Srn Person', $lesson);
        Student::create(['user_id' => $bySrn->user_id, 'student_code' => 'SRN-54321', 'status' => 'active', 'enrolled_at' => now()]);

        $byScn = $this->ask('Scn Person', $lesson);
        Consultant::create([
            'user_id' => $byScn->user_id, 'consultant_code' => 'CON'.Str::random(8), 'scn_code' => 'SCN-98765',
            'referral_code' => strtoupper(Str::random(10)), 'status' => 'active',
        ]);

        $this->assertSame([$byEmail->id], $this->listed($this->page(['req_q' => 'samadhi.k@'])));
        $this->assertSame([$byPhone->id], $this->listed($this->page(['req_q' => '0771239876'])));
        $this->assertSame([$bySrn->id], $this->listed($this->page(['req_q' => 'SRN-54321'])));
        $this->assertSame([$byScn->id], $this->listed($this->page(['req_q' => 'SCN-98765'])));
    }

    /** ⚠️ ONE where() group — an orWhereHas outside it would bring decided requests back. */
    public function test_only_waiting_requests_are_ever_found(): void
    {
        $lesson = $this->lesson('Day 3');
        $waiting = $this->ask('Kasun Perera', $lesson);
        $this->ask('Kasun Allowed', $lesson, 'approved');
        $this->ask('Kasun Declined', $lesson, 'declined');

        $this->assertSame([$waiting->id], $this->listed($this->page(['req_q' => 'kasun'])));
        $this->assertSame([$waiting->id], $this->listed($this->page(['req_q' => 'Day 3'])));
    }

    public function test_no_match_keeps_the_box_with_a_way_back(): void
    {
        $this->ask('Someone', $this->lesson('Day 6'));

        $html = $this->page(['req_q' => 'nobody-like-this']);

        $this->assertSame([], $this->listed($html));
        $this->assertStringContainsString('No request matches', $html);
        $this->assertStringContainsString('name="req_q"', $html, 'the search box is still there to change');
        $this->assertStringContainsString('1 student(s) asking to watch a lesson again', $html);
        $this->assertStringContainsString('>Clear<', $html);
    }

    public function test_paging_and_the_page_size_keep_the_search(): void
    {
        $lesson = $this->lesson('Day 9');

        foreach (range(1, 60) as $i) {
            $this->ask(sprintf('Match %02d', $i), $lesson);
        }
        foreach (range(1, 5) as $i) {
            $this->ask(sprintf('Other %02d', $i), $lesson);
        }

        $first = $this->page(['req_q' => 'Match', 'req_per' => 50]);
        $this->assertCount(50, $this->listed($first));
        $this->assertStringContainsString('60 matching', $first);

        // The next-page link carries the search.
        $this->assertMatchesRegularExpression('/href="[^"]*req_q=Match[^"]*req_page=2|href="[^"]*req_page=2[^"]*req_q=Match/', html_entity_decode($first));

        $second = $this->page(['req_q' => 'Match', 'req_per' => 50, 'req_page' => 2]);
        $this->assertCount(10, $this->listed($second), 'page 2 holds the other ten matches, none of the rest');

        // The page-size picker's own form re-submits the search with it.
        $this->assertMatchesRegularExpression(
            '/<form method="GET"[^>]*#requests" class="flex items-center gap-2">(?:(?!<\/form>).)*name="req_q" value="Match"(?:(?!<\/form>).)*id="req_per"/s',
            $first,
        );
    }

    public function test_without_a_search_the_list_is_exactly_as_before(): void
    {
        $lesson = $this->lesson('Day 7');
        $a = $this->ask('Alpha', $lesson);
        $b = $this->ask('Beta', $lesson);

        $html = $this->page();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->listed($html));
        $this->assertStringNotContainsString('matching', $html);
        $this->assertStringNotContainsString('>Clear<', $html);
    }

    // ----- by the day it was asked (2026-11-04, part 2) ---------------------------

    /** A request asked at $colombo (Sri Lanka wall-clock). */
    private function askedAt(string $name, Lesson $lesson, string $colombo): LessonViewRequest
    {
        $request = $this->ask($name, $lesson);
        $at = \Illuminate\Support\Carbon::parse($colombo, 'Asia/Colombo')->utc();
        $request->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $request;
    }

    public function test_from_and_to_keep_the_days_asked_in_between(): void
    {
        $lesson = $this->lesson('Day 11');
        $this->askedAt('Before', $lesson, '2026-10-31 18:00');
        $first = $this->askedAt('First Day', $lesson, '2026-11-01 09:00');
        $last = $this->askedAt('Last Day', $lesson, '2026-11-03 22:30');
        $this->askedAt('After', $lesson, '2026-11-04 08:00');

        $html = $this->page(['req_from' => '2026-11-01', 'req_to' => '2026-11-03']);

        $this->assertEqualsCanonicalizing([$first->id, $last->id], $this->listed($html), 'both ends are inclusive');
        $this->assertStringContainsString('4 student(s) asking to watch a lesson again', $html, 'the heading still counts everyone waiting');
        $this->assertStringContainsString('2 matching', $html);
        $this->assertStringContainsString('value="2026-11-01"', $html, 'the From box keeps the day picked');
    }

    /**
     * ⚠️ SRI LANKA DAYS, NOT UTC. The app stores UTC: 1:30 AM on 4 Nov in
     * Colombo is still 3 Nov in UTC, and must count as 4 Nov here.
     */
    public function test_the_day_is_the_sri_lanka_day(): void
    {
        $lesson = $this->lesson('Day 12');
        $earlyMorning = $this->askedAt('Early Bird', $lesson, '2026-11-04 01:30');
        $lateNight = $this->askedAt('Night Owl', $lesson, '2026-11-03 23:50');

        $this->assertSame([$earlyMorning->id], $this->listed($this->page(['req_from' => '2026-11-04', 'req_to' => '2026-11-04'])));
        $this->assertSame([$lateNight->id], $this->listed($this->page(['req_from' => '2026-11-03', 'req_to' => '2026-11-03'])));
    }

    public function test_one_end_alone_works_and_swapped_ends_are_put_right(): void
    {
        $lesson = $this->lesson('Day 13');
        $old = $this->askedAt('Old One', $lesson, '2026-10-20 10:00');
        $new = $this->askedAt('New One', $lesson, '2026-11-02 10:00');

        $this->assertSame([$new->id], $this->listed($this->page(['req_from' => '2026-11-01'])));
        $this->assertSame([$old->id], $this->listed($this->page(['req_to' => '2026-10-31'])));
        $this->assertEqualsCanonicalizing([$old->id, $new->id], $this->listed($this->page(['req_from' => '2026-11-30', 'req_to' => '2026-10-01'])));
    }

    public function test_a_name_and_a_date_together(): void
    {
        $lesson = $this->lesson('Day 14');
        $this->askedAt('Asuntha Lalani', $lesson, '2026-10-25 10:00');
        $wanted = $this->askedAt('Asuntha Lalani', $lesson, '2026-11-02 10:00');
        $this->askedAt('Rishan Jeewantha', $lesson, '2026-11-02 11:00');

        $this->assertSame([$wanted->id], $this->listed($this->page(['req_q' => 'asuntha', 'req_from' => '2026-11-01', 'req_to' => '2026-11-03'])));
    }

    /** A typed-in nonsense date is ignored rather than breaking the page. */
    public function test_a_broken_date_is_ignored(): void
    {
        $lesson = $this->lesson('Day 15');
        $a = $this->askedAt('Any One', $lesson, '2026-11-02 10:00');

        $this->assertSame([$a->id], $this->listed($this->page(['req_from' => '2026-13-45', 'req_to' => 'yesterday'])));
    }

    public function test_paging_keeps_the_dates_and_each_row_shows_when_it_was_asked(): void
    {
        $lesson = $this->lesson('Day 16');

        foreach (range(1, 55) as $i) {
            $this->askedAt(sprintf('Dated %02d', $i), $lesson, '2026-11-02 10:15');
        }

        $html = $this->page(['req_from' => '2026-11-02', 'req_to' => '2026-11-02', 'req_per' => 50]);

        $this->assertCount(50, $this->listed($html));
        $this->assertMatchesRegularExpression('/href="[^"]*req_from=2026-11-02[^"]*req_page=2|href="[^"]*req_page=2[^"]*req_from=2026-11-02/', html_entity_decode($html));
        $this->assertMatchesRegularExpression(
            '/<form method="GET"[^>]*#requests" class="flex items-center gap-2">(?:(?!<\/form>).)*name="req_from" value="2026-11-02"(?:(?!<\/form>).)*id="req_per"/s',
            $html,
            'the page-size picker keeps the dates too',
        );
        $this->assertStringContainsString('Asked 2 Nov 2026, 10:15 AM', $html, 'each row says when it was asked, in Sri Lanka time');
    }

    /** Allowing from a searched list comes back to the same search. */
    public function test_allowing_returns_to_the_search(): void
    {
        $lesson = $this->lesson('Day 10');
        $request = $this->ask('Back Again', $lesson);
        $url = route('manage.course-videos.index', ['req_q' => 'Back']).'#requests';

        $this->actingAs($this->admin)->from($url)
            ->post(route('manage.course-videos.decline', $request))
            ->assertRedirect($url);
    }
}
