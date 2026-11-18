<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Student;
use App\Models\User;
use App\Services\ScnNumberAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ READ-ONLY DIAGNOSTIC — "can the SCN number be the SRN number?" (2026-08-27)
 *
 * The owner asked for SRN 100 → SCN-00100. Hidden placement accounts share the
 * same unique scn_code pool as real consultants, so that number is usually
 * already taken. How many ACTUALLY collide is a live-data question and prod has
 * no CLI, so it is counted from the browser.
 *
 * The one thing that must never change about this page: it writes nothing.
 */
class ScnNumberAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_it_reports_a_consultant_whose_number_is_already_taken(): void
    {
        $main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        // This person was student SRN-00101 — a number a hidden account holds.
        Student::create([
            'user_id' => $main->user_id, 'student_code' => 'SRN-00101',
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $audit = app(ScnNumberAudit::class)->run();
        $row = collect($audit['rows'])->firstWhere('srn', 'SRN-00101');

        $this->assertSame('SCN-00101', $row['wanted']);
        $this->assertSame('taken-by-hidden', $row['status']);
        $this->assertSame(1, $audit['totals']['taken_by_hidden']);
    }

    public function test_it_reports_a_number_that_is_free(): void
    {
        $main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        // Below the SCN sequence start of 100, so nothing can hold it.
        Student::create([
            'user_id' => $main->user_id, 'student_code' => 'SRN-00042',
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $audit = app(ScnNumberAudit::class)->run();
        $row = collect($audit['rows'])->firstWhere('srn', 'SRN-00042');

        $this->assertSame('SCN-00042', $row['wanted']);
        $this->assertSame('free', $row['status']);
    }

    /** A consultant an admin created directly has no SRN to match. */
    public function test_a_consultant_who_was_never_a_student_is_reported_separately(): void
    {
        $audit = app(ScnNumberAudit::class)->run();

        $this->assertGreaterThan(0, $audit['totals']['no_srn']);
        $this->assertSame(
            'no-srn',
            collect($audit['rows'])->firstWhere('srn', null)['status']
        );
    }

    /** Hidden accounts are placement buckets, never people — they have no SRN. */
    public function test_hidden_accounts_are_not_listed(): void
    {
        $audit = app(ScnNumberAudit::class)->run();
        $hidden = Consultant::where('is_hidden', true)->pluck('scn_code');

        foreach (collect($audit['rows'])->pluck('scn') as $listed) {
            $this->assertNotContains($listed, $hidden);
        }
    }

    public function test_the_page_renders_and_changes_nothing(): void
    {
        $before = Consultant::orderBy('id')->pluck('scn_code', 'id')->toArray();

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('commission-health.scn-numbers'))
            ->assertOk()
            ->assertSee('Can each SCN number match its SRN number?', false);

        $this->assertSame($before, Consultant::orderBy('id')->pluck('scn_code', 'id')->toArray());
    }

    public function test_a_consultant_cannot_open_it(): void
    {
        $this->actingAs(User::where('email', 'consultant@etec.lk')->firstOrFail())
            ->get(route('commission-health.scn-numbers'))
            ->assertForbidden();
    }
}
