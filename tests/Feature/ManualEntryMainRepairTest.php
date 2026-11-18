<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\FinanceSchemaRepair;
use App\Services\HiddenAccountService;
use App\Services\ManualCommissionEntry;
use App\Services\ManualEntryMainRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ COMPLETING HAND ENTRIES THAT SKIPPED A MAIN ACCOUNT (2026-09-27).
 *
 * The owner's case: a 2,500 entered by hand for Lakshan Maduranga reached
 * Udara's SCN-00113 and never Udara's main, SCN-00112 — and "anith ayageth
 * mehem aththe". The tool itself is fixed (ManualCommissionEntryTest pins it
 * row-for-row against the engine). This file is about the entries made
 * BEFORE the fix: finding exactly what each is missing, and adding only that.
 *
 * The fixtures write rows the way the OLD tool did — every row of one entry
 * sharing a student and a created_at second, the sponsor's main absent.
 */
class ManualEntryMainRepairTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');
    }

    private function sponsor(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function superAdmin(): User
    {
        return User::role('super_admin')->firstOrFail();
    }

    /** Lakshan: a consultant hanging off the sponsor's Service Group 1 account. */
    private function lakshan(): Consultant
    {
        $user = User::factory()->create(['name' => 'Lakshan Maduranga']);
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $this->sponsor()->hiddenAccounts()->orderBy('id')->firstOrFail()->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-9'.random_int(1000, 9999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    /** One student for the entry. */
    private function student(): User
    {
        $user = User::factory()->create(['name' => 'Hand Entry Student']);
        $user->assignRole('student');

        return $user;
    }

    /**
     * An entry exactly as the OLD tool wrote it: rows on $accounts only, all in
     * one second, on leg $leg.
     *
     * @param  array<int, Consultant>  $accounts
     * @return array<int, Commission>
     */
    private function oldEntry(User $student, array $accounts, int $leg, Carbon $at): array
    {
        $tool = app(ManualCommissionEntry::class);
        $rows = [];

        foreach ($accounts as $account) {
            $row = $tool->writeRow($account, $student->id, CommissionService::TYPE_SLOT, $leg, self::SLOT, null);
            $row->forceFill(['created_at' => $at])->save();
            $rows[] = $row;
        }

        return $rows;
    }

    private function repair(): ManualEntryMainRepair
    {
        return app(ManualEntryMainRepair::class);
    }

    // ------------------------------------------------------------------------

    /**
     * ⚠️ THE OWNER'S CASE. Lakshan's entry reached the sponsor's Service Group
     * account and stopped; the sponsor's MAIN is the row that is missing.
     */
    public function test_an_old_entry_is_found_missing_the_sponsors_main(): void
    {
        $sponsor = $this->sponsor();
        $sponsorSg1 = $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail();
        $lakshan = $this->lakshan();

        $this->oldEntry($this->student(), [$lakshan, $sponsorSg1], 1, Carbon::parse('2026-09-26 10:00:00'));

        $preview = $this->repair()->preview();

        $this->assertCount(1, $preview['entries']);

        $codes = collect($preview['entries']->first()['missing'])->pluck('code')->all();

        $this->assertContains($sponsor->scn_code, $codes, 'the sponsor\'s main is what is missing');
        $this->assertNotContains($sponsorSg1->scn_code, $codes, 'the rows it did write are not added again');
        $this->assertNotContains($lakshan->scn_code, $codes);
    }

    /** Applying adds exactly that row, on the right leg, joined to the entry. */
    public function test_applying_adds_exactly_the_missing_row(): void
    {
        $sponsor = $this->sponsor();
        $sponsorSg1 = $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail();
        $lakshan = $this->lakshan();
        $student = $this->student();
        $at = Carbon::parse('2026-09-26 10:00:00');

        $this->oldEntry($student, [$lakshan, $sponsorSg1], 1, $at);

        $expected = collect($this->repair()->preview()['entries']->first()['missing']);
        $result = $this->repair()->apply($this->superAdmin()->id);

        $this->assertSame($expected->count(), $result['created']);

        $mainRow = Commission::where('consultant_id', $sponsor->id)
            ->where('user_id', $student->id)
            ->whereNull('payment_id')
            ->firstOrFail();

        $this->assertSame((float) self::SLOT, (float) $mainRow->amount);
        $this->assertSame(
            $expected->firstWhere('code', $sponsor->scn_code)['leg'],
            (int) $mainRow->tier,
            'on the leg the fixed walk chose — the side SCN-00113 represents',
        );
        $this->assertSame(
            $at->format('Y-m-d H:i:s'),
            $mainRow->created_at->format('Y-m-d H:i:s'),
            'joined to the original entry',
        );
    }

    /** ⚠️ IDEMPOTENT. A second press finds the entry complete. */
    public function test_a_second_press_adds_nothing(): void
    {
        $sponsor = $this->sponsor();
        $this->oldEntry(
            $this->student(),
            [$this->lakshan(), $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail()],
            1,
            Carbon::parse('2026-09-26 10:00:00'),
        );

        $this->repair()->apply($this->superAdmin()->id);

        $this->assertTrue($this->repair()->preview()['entries']->isEmpty());
        $this->assertSame(0, $this->repair()->apply($this->superAdmin()->id)['created']);
    }

    /**
     * ⚠️ A ONE-ROW ENTRY IS NEVER TOUCHED. It may have been "this account
     * only" on purpose, and this page cannot tell.
     */
    public function test_a_single_row_entry_is_left_alone(): void
    {
        $this->oldEntry($this->student(), [$this->lakshan()], 1, Carbon::parse('2026-09-26 10:00:00'));

        $preview = $this->repair()->preview();

        $this->assertTrue($preview['entries']->isEmpty());
        $this->assertSame(1, $preview['singles'], 'counted, so it cannot read as "checked and fine"');
    }

    /**
     * ⚠️ A ROW REMOVED BY HAND IS NEVER PUT BACK, and nothing is added above
     * it — the engine's own rule for removed rows.
     */
    public function test_a_row_removed_by_hand_stops_the_repair_there(): void
    {
        $sponsor = $this->sponsor();
        $sponsorSg1 = $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail();

        [, $sg1Row] = $this->oldEntry(
            $this->student(),
            [$this->lakshan(), $sponsorSg1],
            1,
            Carbon::parse('2026-09-26 10:00:00'),
        );

        // An admin struck the SCN-00113 row off.
        $sg1Row->forceFill([
            'status' => 'cancelled',
            'removed_at' => now(),
            'removed_amount' => $sg1Row->amount,
            'amount' => 0,
        ])->save();

        $this->assertTrue(
            $this->repair()->preview()['entries']->isEmpty(),
            'nothing above a removed row is added — the sponsor\'s main stays as it is',
        );
    }

    /** An entry withdrawn in full is left alone. */
    public function test_a_fully_withdrawn_entry_is_left_alone(): void
    {
        $sponsor = $this->sponsor();
        $rows = $this->oldEntry(
            $this->student(),
            [$this->lakshan(), $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail()],
            1,
            Carbon::parse('2026-09-26 10:00:00'),
        );

        foreach ($rows as $row) {
            $row->forceFill(['status' => 'cancelled', 'removed_at' => now(), 'removed_amount' => $row->amount, 'amount' => 0])->save();
        }

        $this->assertTrue($this->repair()->preview()['entries']->isEmpty());
    }

    /** An entry made with the FIXED tool is already complete. */
    public function test_a_new_entry_needs_nothing(): void
    {
        app(ManualCommissionEntry::class)->add($this->lakshan(), $this->student(), 'slot', 1, 'upline');

        $this->assertTrue($this->repair()->preview()['entries']->isEmpty());
    }

    // ----- who may use it ----------------------------------------------------

    /** ⚠️ It writes money: super admin only, on the page AND on the button. */
    public function test_only_a_super_admin_can_open_or_press_it(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $this->actingAs($admin)->get(route('commission-health.manual-mains'))->assertForbidden();
        $this->actingAs($admin)->post(route('commission-health.repair-manual-mains'))->assertForbidden();

        $this->actingAs($this->superAdmin())->get(route('commission-health.manual-mains'))->assertOk();
    }

    /** The page lists what it would add, before anything is added. */
    public function test_the_page_lists_the_rows_it_would_add(): void
    {
        $sponsor = $this->sponsor();
        $this->oldEntry(
            $this->student(),
            [$this->lakshan(), $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail()],
            1,
            Carbon::parse('2026-09-26 10:00:00'),
        );

        $before = Commission::count();

        $this->actingAs($this->superAdmin())
            ->get(route('commission-health.manual-mains'))
            ->assertOk()
            ->assertSee($sponsor->scn_code)
            ->assertSee('Hand Entry Student');

        $this->assertSame($before, Commission::count(), 'opening the page writes nothing');
    }
}
