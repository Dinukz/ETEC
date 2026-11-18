<?php

namespace Tests\Feature;

use App\Models\SystemRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Recent runs names the date picked on the calendar for each run (2026-11-15).
 *
 * The owner: "System run krana date and time eka penwanwa mt dn methanata
 * dagann one system run eka krann calender eken select krana date ekath add
 * krnn one recent ekata." The picked date is the run's cutoff_at; it now sits
 * beside Run at as "Date selected", and both read in Sri Lanka time — Run at
 * printed created_at in UTC, 5h30m behind.
 *
 * Step 3 also names the date the button will run, because the run posts the
 * date that was CHECKED, not whatever the calendar was changed to afterwards.
 */
class SystemRunDateSelectedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // system_runs is created by the self-healing repair, not by a migration.
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    /** A recorded run: the date picked (Sri Lanka) and the moment it was pressed (UTC, as stored). */
    private function runRecorded(string $pickedDate, string $pressedAtUtc): SystemRun
    {
        $run = SystemRun::create([
            'run_by' => $this->accountant()->id,
            'cutoff_at' => Carbon::parse($pickedDate, 'Asia/Colombo')->endOfDay(),
            'accounts_flushed' => 0,
            'total_released' => 0,
            'total_forfeited' => 0,
        ]);

        DB::table('system_runs')->where('id', $run->id)->update(['created_at' => $pressedAtUtc]);

        return $run;
    }

    private function page()
    {
        return $this->actingAs($this->accountant())->get(route('system-run.index'))->assertOk();
    }

    public function test_recent_runs_has_a_date_selected_column_beside_run_at(): void
    {
        $this->runRecorded('2026-11-12', '2026-11-13 08:36:00');

        $this->page()
            ->assertSeeInOrder(['Recent runs', 'Run at', 'Date selected', 'By', 'Accounts'])
            ->assertDontSee('<th class="px-5 py-3 font-semibold">Settled up to</th>', false)
            ->assertSee('Thu, Nov 12, 2026')
            ->assertSee('the day before the run');
    }

    /** ⚠️ Run at was UTC: 08:42 UTC is 2:12 pm in Sri Lanka. */
    public function test_run_at_is_sri_lanka_time(): void
    {
        $this->runRecorded('2026-11-14', '2026-11-14 08:42:00');

        $this->page()
            ->assertSee('Nov 14, 2026 2:12 pm')
            ->assertDontSee('Nov 14, 2026 8:42 am')
            ->assertSee('Sat, Nov 14, 2026')
            ->assertSee('same day as the run');
    }

    /**
     * After 6:30 pm Sri Lanka time the UTC clock is still on the day before. A
     * run pressed at 1:30 am on the 13th for the 12th is "the day before", not
     * "same day" — the gap is counted on Sri Lanka dates.
     */
    public function test_the_gap_is_counted_in_sri_lanka_days(): void
    {
        $this->runRecorded('2026-11-12', '2026-11-12 20:00:00');

        $this->page()
            ->assertSee('Nov 13, 2026 1:30 am')
            ->assertSee('Thu, Nov 12, 2026')
            ->assertSee('the day before the run')
            ->assertDontSee('same day as the run');
    }

    public function test_an_older_date_says_how_many_days_before(): void
    {
        $this->runRecorded('2026-11-10', '2026-11-14 08:00:00');

        $this->page()->assertSee('4 days before the run');
    }

    /** Step 3 names the date the button runs — the checked date, also posted as the hidden cutoff. */
    public function test_step_three_names_the_date_it_will_run(): void
    {
        $day = Carbon::now('Asia/Colombo')->subDay();

        $this->actingAs($this->accountant())
            ->get(route('system-run.index', ['cutoff' => $day->toDateString()]))
            ->assertOk()
            ->assertSeeInOrder(['Step 3', 'Date selected for this run:', $day->format('l, j F Y')])
            ->assertSee('data-date="'.$day->toDateString().'"', false)
            ->assertSee('<input type="hidden" name="cutoff" value="'.$day->toDateString().'">', false)
            // The hold on the button while the calendar disagrees.
            ->assertSee('id="run-date-changed" hidden', false)
            ->assertSee('id="start-system-run"', false);
    }
}
