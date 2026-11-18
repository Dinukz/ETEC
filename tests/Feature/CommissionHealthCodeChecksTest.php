<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ THE HEALTH PAGE MUST NOT ACCUSE A CORRECT BUILD (added 2026-09-07).
 *
 * Commission Health opens with a list of "is the deployed code the current
 * code?" checks (CommissionHealthController::index()'s $code map). Any false
 * entry prints "The uploaded code is out of date — re-upload the app files",
 * which is the loudest possible thing this app says — and it is printed on the
 * exact page an owner is told to press Repair from.
 *
 * ⚠️ WHY THIS FILE EXISTS. On 2026-09-07 `downlineFixPresent()` was a
 * `str_contains()` against the SOURCE TEXT of Consultant.php, looking for
 * `'$frontier = $this->accountIds();'`. Refactoring the downline walk that same
 * day moved that line into a new method — the behaviour it was checking for was
 * still there, and more correct than before — and the page immediately began
 * telling the owner their upload was broken. Nothing caught it because nothing
 * asserted these checks pass on a healthy build.
 *
 * A source-text probe asserts how code is WRITTEN; the thing worth asserting is
 * that it EXISTS. This test fails the moment any check starts lying, whichever
 * way it is implemented.
 */
class CommissionHealthCodeChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    /**
     * Every deployed-code check passes against the code in this repository.
     *
     * Asserted by NAME, so a failure says which check is lying rather than just
     * "one of them is false".
     */
    public function test_every_deployed_code_check_passes_on_this_build(): void
    {
        $response = $this->actingAs($this->admin())->get(route('commission-health.index'));

        $response->assertOk();

        $code = $response->viewData('code');

        $this->assertNotEmpty($code, 'guard: the page really does publish the checks');

        foreach ($code as $label => $ok) {
            $this->assertTrue(
                (bool) $ok,
                "The deployed-code check \"{$label}\" is reporting this build as out of date. "
                .'Either the code it asks about is genuinely missing, or the check itself has rotted '
                .'— see CommissionHealthController::downlineFixPresent() for the last time that happened.',
            );
        }
    }

    /**
     * ⚠️ THE SPECIFIC ONE THAT ROTTED, pinned on its own so the reason survives
     * even if the loop above is ever narrowed.
     */
    public function test_the_downline_walk_check_does_not_depend_on_how_the_walk_is_written(): void
    {
        $this->assertTrue(
            method_exists(\App\Models\Consultant::class, 'downlineIdsBelow'),
            'downlineIdsBelow() is what the check now asks for — it exists precisely because the walk '
            .'can be seeded from an arbitrary account set.',
        );

        $this->actingAs($this->admin())
            ->get(route('commission-health.index'))
            ->assertOk()
            ->assertDontSee('The uploaded code is out of date', false);
    }

    /** And the page's own headline agrees: nothing is wrong on a clean install. */
    public function test_a_clean_install_reports_no_stale_upload(): void
    {
        $problems = $this->actingAs($this->admin())
            ->get(route('commission-health.index'))
            ->viewData('problems');

        $stale = array_values(array_filter(
            $problems,
            fn ($p) => str_contains($p, 'uploaded code is out of date'),
        ));

        $this->assertSame([], $stale, 'A correct build must raise no stale-upload problem.');
    }
}
