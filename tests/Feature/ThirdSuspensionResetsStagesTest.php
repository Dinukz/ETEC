<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\User;
use App\Services\ConsultantMaturityService;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The 3rd suspension puts every one of the person's SCN accounts back on 1-to-1.
 *
 * Owner, 2026-09-14: a stage only moves forward — "only stage change after 3
 * time suspend only". Asked which suspension, they chose the 3rd, and every
 * one after it. The 5-year maturity counter keeps its own reset on the 4th.
 */
class ThirdSuspensionResetsStagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function person(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** Every account the person holds: their main and each placement account. */
    private function accountsOf(Consultant $person)
    {
        return Consultant::query()
            ->where(fn ($q) => $q->whereKey($person->id)->orWhere('owner_consultant_id', $person->id))
            ->orderBy('id')
            ->get();
    }

    private function putAllAtStage(Consultant $person, int $stage): void
    {
        Consultant::query()
            ->where(fn ($q) => $q->whereKey($person->id)->orWhere('owner_consultant_id', $person->id))
            ->update(['lifetime_pair_stage' => $stage]);
    }

    /** @return array<int, int> */
    private function stages(Consultant $person): array
    {
        return $this->accountsOf($person)->pluck('lifetime_pair_stage')->map(fn ($s) => (int) $s)->all();
    }

    private function suspend(Consultant $person, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            app(ConsultantMaturityService::class)->recordSuspension($person);
        }
    }

    public function test_the_first_two_suspensions_leave_every_stage_alone(): void
    {
        $person = $this->person();
        $this->putAllAtStage($person, ServiceGroupStaging::ONE_TIME_STAGES);

        $this->suspend($person, 2);

        $this->assertGreaterThan(1, count($this->stages($person)), 'guard: the person holds several accounts');
        $this->assertSame(array_fill(0, count($this->stages($person)), 2), $this->stages($person));
    }

    /** ⚠️ THE RULE. The 3rd suspension puts every one of their accounts on 1-to-1. */
    public function test_the_third_suspension_puts_every_account_back_on_1_to_1(): void
    {
        $person = $this->person();
        $this->putAllAtStage($person, ServiceGroupStaging::ONE_TIME_STAGES);

        $this->suspend($person, 3);

        $this->assertSame(array_fill(0, count($this->stages($person)), 0), $this->stages($person));
    }

    /** ...and so does every suspension after it, even once an admin has moved them forward again. */
    public function test_every_suspension_after_the_third_resets_again(): void
    {
        $person = $this->person();

        $this->suspend($person, 3);
        $this->putAllAtStage($person, 1);

        $this->suspend($person);

        $this->assertSame(array_fill(0, count($this->stages($person)), 0), $this->stages($person));
    }

    public function test_nobody_elses_accounts_are_touched(): void
    {
        $person = $this->person();

        $user = User::factory()->create();
        $user->assignRole('consultant');
        $stranger = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-6'.random_int(1000, 9999),
            'status' => 'active',
        ]);
        $this->putAllAtStage($stranger, 2);

        $this->suspend($person, 3);

        $this->assertSame(array_fill(0, count($this->stages($stranger)), 2), $this->stages($stranger));
    }

    /** A suspension reached through a placement account is the person's suspension. */
    public function test_a_suspension_recorded_on_a_placement_account_counts_for_the_person(): void
    {
        $person = $this->person();
        $placement = $person->hiddenAccounts()->orderBy('id')->firstOrFail();
        $this->putAllAtStage($person, 2);

        $this->suspend($placement, 3);

        $this->assertSame(3, (int) DB::table('consultants')->where('id', $person->id)->value('suspension_count'));
        $this->assertSame(array_fill(0, count($this->stages($person)), 0), $this->stages($person));
    }

    /** The maturity counter keeps its own rule: the 4th suspension, not the 3rd. */
    public function test_the_maturity_counter_still_resets_on_the_fourth(): void
    {
        $person = $this->person();
        DB::table('consultants')->where('id', $person->id)->update(['mature_months' => 10]);

        $this->suspend($person, 3);
        $this->assertSame(10, (int) DB::table('consultants')->where('id', $person->id)->value('mature_months'));

        $this->suspend($person);
        $this->assertSame(0, (int) DB::table('consultants')->where('id', $person->id)->value('mature_months'));
    }

    /** Written down, so a stage going back is never unexplained again. */
    public function test_the_reset_is_written_to_the_activity_log(): void
    {
        $person = $this->person();

        $this->suspend($person, 3);

        $this->assertTrue(
            DB::table('activity_log')
                ->where('subject_id', $person->id)
                ->where('description', 'Suspended 3 times — every SCN account back on 1-to-1')
                ->exists(),
        );
    }
}
