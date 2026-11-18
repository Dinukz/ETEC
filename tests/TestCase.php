<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Drop FinanceSchemaRepair's request-level memo between tests.
     *
     * That memo is a plain static, so it lives for the whole PHP process,
     * while RefreshDatabase rebuilds the schema for every test class. A class
     * that calls FinanceSchemaRepair::ensure() (which CREATES `released_at`
     * and friends — they have no migration, this host has no CLI) leaves the
     * memo saying "the flush columns exist"; the next class then starts from a
     * freshly migrated database where they do NOT, and every guarded query
     * sails past its own guard straight into "no such column".
     *
     * That produced failures which depended on the ORDER test classes ran in
     * and vanished when a class was run on its own — the worst kind to chase.
     * Production never loses a column, so this is a test-harness concern only,
     * which is why it is fixed here rather than by weakening the guards.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Services\FinanceSchemaRepair::forgetMemo();

        // Same memo, same reason, for the exam paper columns (2026-10-18): an
        // exam test left "show_answers exists" remembered and the next class
        // — on a fresh database without it — failed to save a paper.
        \App\Services\ExamSchemaRepair::forgetMemo();

        // And for a course's own half payment / direct service income columns
        // (2026-10-22).
        \App\Services\CourseSchemaRepair::forgetMemo();

        // And for the courses given to a lecturer (2026-10-25).
        \App\Services\LecturerCourses::forgetMemo();

        // And for who pays a registration on a downline's account (2026-11-03).
        \App\Services\RegistrationPayer::forgetMemo();

        // The daily clean-up stays off unless a test turns it on (2026-11-11).
        \App\Http\Middleware\RunDailyHousekeeping::$runInTests = false;

        // ⚠️ SAME CLASS OF BUG AS THE MEMO ABOVE, found 2026-08-31.
        //
        // Setting::get() and CommissionSetting::current() both cache with
        // rememberForever(), and the array cache driver lives for the whole PHP
        // process while RefreshDatabase rebuilds the database per test. So a
        // test that writes a setting leaves its VALUE cached, and the next test
        // class — whose database no longer has that row — still reads the stale
        // one. That produced a failure in SystemRunWalksEveryStageTest that
        // vanished when the file was run on its own.
        //
        // Cheap on the array driver, and it makes every test start from the
        // database rather than from whatever ran before it.
        \Illuminate\Support\Facades\Cache::flush();
    }

    /**
     * Give a consultant the optional pages (2026-09-22) — Admission Report,
     * Payment Summary Report, My Bookings, All Bookings are OFF for every
     * consultant until granted. Pass null for all four. See ConsultantPageAccess.
     *
     * @param  list<string>|null  $pages  Consultant::PAGE_ACCESS keys
     */
    protected function grantConsultantPages(User $user, ?array $pages = null): void
    {
        \App\Services\FinanceSchemaRepair::ensure();

        $user->consultant->owningPerson()->forceFill([
            'page_access' => json_encode($pages ?? array_keys(\App\Models\Consultant::PAGE_ACCESS)),
        ])->save();
    }

    /**
     * Approve every pending service income record, the way an accountant does
     * from the Service Income page.
     *
     * The engine only ever RECORDS commission as pending when a payment is
     * verified; approval is what adds it to a consultant's `total_earned`. Any
     * test asserting on a consultant's income has to go through this first.
     */
    protected function approveAllCommissions(): void
    {
        $approver = User::where('email', 'admin@etec.lk')->firstOrFail();

        $this->actingAs($approver)
            ->patch(route('commissions.approve-all'))
            ->assertRedirect();
    }
}
