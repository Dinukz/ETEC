<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudentLine;
use App\Services\VideoStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The two switches added 2026-10-01. FTP is the only way onto the live site,
 * so the student-line rule and the video piece size can each be changed — or
 * turned off — from Settings without an upload.
 */
class SettingsSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::forever('settings.mail.grouped', collect());
    }

    public function test_both_default_to_on_and_four_megabytes(): void
    {
        $this->assertTrue(StudentLine::attachEnabled());
        $this->assertSame(4 * 1048576, VideoStream::maxSpan());

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Student line')
            ->assertSee('Video piece size (MB)');
    }

    public function test_saving_changes_what_the_code_reads(): void
    {
        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->patch(route('settings.switches'), ['student_line_attach' => 0, 'video_chunk_mb' => 2])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertFalse(StudentLine::attachEnabled());
        $this->assertSame(2 * 1048576, VideoStream::maxSpan());
    }

    public function test_a_piece_size_of_zero_turns_capping_off(): void
    {
        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->patch(route('settings.switches'), ['student_line_attach' => 1, 'video_chunk_mb' => 0])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, VideoStream::maxSpan());
    }

    public function test_nonsense_is_refused(): void
    {
        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->patch(route('settings.switches'), ['student_line_attach' => 5, 'video_chunk_mb' => -1])
            ->assertSessionHasErrors(['student_line_attach', 'video_chunk_mb']);
    }

    public function test_a_consultant_cannot_change_them(): void
    {
        $this->actingAs(User::where('email', 'consultant@etec.lk')->firstOrFail())
            ->patch(route('settings.switches'), ['student_line_attach' => 0, 'video_chunk_mb' => 0])
            ->assertForbidden();

        $this->assertTrue(StudentLine::attachEnabled());
    }
}
