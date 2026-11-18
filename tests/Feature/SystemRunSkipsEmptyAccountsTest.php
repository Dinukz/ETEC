<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\GroupMember;
use App\Models\Setting;
use App\Models\User;
use App\Services\HiddenAccountService;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The System Run page and the run skip accounts that hold nothing to settle.
 *
 * Reported 2026-09-14: "System run eka godk slow nikan e tab ekat giyath load
 * wenwa ... 504 wage error enwa ... okkom logic change kran nathuw". The live
 * site had 1,521 active accounts, 297 holding any slot row and 46 holding one on
 * both sides, and every one of the 1,521 cost a dozen queries.
 *
 * Two things are pinned: the results are IDENTICAL to walking every account
 * (the rules did not change), and empty accounts no longer cost anything.
 */
class SystemRunSkipsEmptyAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        Setting::set('stage_first_pairs', '1', 'integer', 'commission');
        Setting::set('stage_second_pairs', '2', 'integer', 'commission');
        Setting::set('stage_repeat_pairs', '3', 'integer', 'commission');
        Setting::set('account_unlock_students', '12', 'integer', 'commission');
        ServiceGroupStaging::forget();
    }

    private function owner(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** $count unreleased 2,500 slot rows on one leg of an account — positions are sequential, never random. */
    private function seedRows(Consultant $account, int $tier, int $count): void
    {
        $group = app(HiddenAccountService::class)->ensureGroup($account);
        $start = (int) GroupMember::where('group_id', $group->id)->max('position');

        for ($i = 0; $i < $count; $i++) {
            $student = User::factory()->create();

            $seat = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $student->id,
                'tier' => $tier,
                'position' => $start + $i + 1,
                'commission_amount' => 0,
                'joined_at' => now()->subDays(3),
            ]);

            Commission::create([
                'consultant_id' => $account->id,
                'user_id' => $student->id,
                'group_id' => $group->id,
                'group_member_id' => $seat->id,
                'type' => 'bonus',
                'tier' => $tier,
                'amount' => self::SLOT,
                'status' => 'approved',
            ]);
        }
    }

    /** How many empty accounts this test has made, so every SCN code is new. */
    private int $made = 0;

    private ?Consultant $stranger = null;

    /**
     * Somebody else entirely — most empty accounts on the live site belong to
     * other people, so they must not lengthen the account chain of the
     * accounts that do hold money.
     */
    private function stranger(): Consultant
    {
        if ($this->stranger) {
            return $this->stranger;
        }

        $user = User::factory()->create();
        $user->assignRole('consultant');

        $this->stranger = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-6'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        return $this->stranger;
    }

    /** Hidden accounts with a group and nothing on it — most of the live network. */
    private function emptyAccounts(int $count): void
    {
        $owner = $this->stranger();

        for ($i = 0; $i < $count; $i++) {
            $account = Consultant::create([
                'user_id' => null,
                'is_hidden' => true,
                'owner_consultant_id' => $owner->id,
                'consultant_code' => 'CON'.Str::random(8),
                'referral_code' => strtoupper(Str::random(10)),
                'scn_code' => sprintf('SCN-7%04d', $this->made++),
                'status' => 'active',
            ]);

            app(HiddenAccountService::class)->ensureGroup($account);
        }
    }

    /**
     * A network with every kind of account: one that settles, one holding a
     * single side, one locked shut with money on both sides, one at 3-to-3
     * with a surplus, and a crowd of empty ones.
     */
    private function mixedNetwork(): void
    {
        $accounts = $this->owner()->hiddenAccounts()->orderBy('id')->get()->values();

        $this->seedRows($accounts[0], 1, 1);
        $this->seedRows($accounts[0], 2, 1);

        $this->seedRows($accounts[1], 1, 2);

        $this->emptyAccounts(6);

        $locked = Consultant::where('scn_code', 'SCN-70000')->firstOrFail();
        $this->seedRows($locked, 1, 1);
        $this->seedRows($locked, 2, 1);
        $locked->forceFill(['manual_lock_override' => Consultant::LOCK_FORCED_SHUT])->save();

        $threeToThree = Consultant::where('scn_code', 'SCN-70001')->firstOrFail();
        $threeToThree->forceFill(['lifetime_pair_stage' => ServiceGroupStaging::ONE_TIME_STAGES])->save();
        $this->seedRows($threeToThree, 1, 4);
        $this->seedRows($threeToThree, 2, 3);
    }

    private function queries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** A run, measured and then undone, so the same data can be run again. */
    private function runAndUndo(ServiceGroupFlush $flush): array
    {
        DB::beginTransaction();

        try {
            $result = $flush->runAll(ServiceGroupFlush::latestSelectableCutoff());
        } finally {
            DB::rollBack();
        }

        return [
            'flushed' => $result['flushed'],
            'payout' => $result['payout'],
            'forfeit' => $result['forfeit'],
            'accounts' => collect($result['accounts'])->map(fn ($a) => [
                'code' => $a['code'],
                'released' => $a['released'],
                'forfeited' => $a['forfeited'],
                'released_ids' => $a['released_ids'],
                'forfeited_ids' => collect($a['forfeited_rows'])->pluck('id')->all(),
                'swept_ids' => $a['swept_ids'],
                'stage_before' => $a['stage_before'],
            ])->all(),
            'failures' => $result['failures'],
        ];
    }

    // ----- the rules did not change ------------------------------------------------

    /** ⚠️ THE PROOF. The preview is identical to one that walks every account. */
    public function test_the_preview_is_identical_to_walking_every_account(): void
    {
        $this->mixedNetwork();
        $cutoff = ServiceGroupFlush::latestSelectableCutoff();

        $filtered = app(ServiceGroupFlush::class)->preview($cutoff);
        $everyAccount = app(WalksEveryAccount::class)->preview($cutoff);

        $this->assertGreaterThan(0, $filtered['count'], 'guard: something settles');
        $this->assertNotEmpty($filtered['skipped'], 'guard: something is listed as holding money');
        $this->assertEquals($everyAccount, $filtered);
    }

    /** ⚠️ ...and so is the run: same accounts, same rows, same money. */
    public function test_the_run_is_identical_to_walking_every_account(): void
    {
        $this->mixedNetwork();

        $filtered = $this->runAndUndo(app(ServiceGroupFlush::class));
        $everyAccount = $this->runAndUndo(app(WalksEveryAccount::class));

        $this->assertGreaterThan(0, $filtered['flushed'], 'guard: the run really settles something');
        $this->assertGreaterThan(0.0, $filtered['forfeit'], 'guard: including a 3-to-3 surplus');
        $this->assertEquals($everyAccount, $filtered);
    }

    // ----- and it is fast ----------------------------------------------------------

    /** ⚠️ THE REPORT. Forty empty accounts must cost the page and the run almost nothing. */
    public function test_empty_accounts_cost_the_preview_and_the_run_almost_nothing(): void
    {
        $this->mixedNetwork();
        $flush = app(ServiceGroupFlush::class);
        $cutoff = ServiceGroupFlush::latestSelectableCutoff();

        $flush->preview($cutoff); // warm the settings caches

        $previewBefore = $this->queries(fn () => $flush->preview($cutoff));
        $runBefore = $this->queries(fn () => $this->runAndUndo($flush));

        $this->emptyAccounts(40);

        $previewAfter = $this->queries(fn () => $flush->preview($cutoff));
        $runAfter = $this->queries(fn () => $this->runAndUndo($flush));

        $this->assertLessThan(10, $previewAfter - $previewBefore, "40 empty accounts added ".($previewAfter - $previewBefore).' queries to the preview');
        $this->assertLessThan(10, $runAfter - $runBefore, "40 empty accounts added ".($runAfter - $runBefore).' queries to the run');
    }

    // ----- the "Holding money" list loads only when asked ---------------------------

    /** ⚠️ THE REPORT. The page opens without the holding-money list, behind a button. */
    public function test_the_holding_list_is_off_by_default_and_loads_on_demand(): void
    {
        $this->mixedNetwork();
        $cutoff = ServiceGroupFlush::latestSelectableCutoff()->toDateString();

        // Default: a count and a button, no itemised list.
        $default = app(ServiceGroupFlush::class)->preview(ServiceGroupFlush::latestSelectableCutoff(), includeSkipped: false);
        $this->assertTrue($default['skipped_hidden']);
        $this->assertGreaterThan(0, $default['holding_count']);
        $this->assertSame([], $default['skipped']);

        $this->actingAs($this->accountant())
            ->get(route('system-run.index', ['cutoff' => $cutoff]))
            ->assertOk()
            ->assertSee('Holding money but not flushing — '.number_format($default['holding_count']))
            ->assertSee('Show these accounts')
            ->assertSee('holding=1', false);

        // With the button pressed, the full list is there.
        $this->actingAs($this->accountant())
            ->get(route('system-run.index', ['cutoff' => $cutoff, 'holding' => 1]))
            ->assertOk()
            ->assertDontSee('Show these accounts')
            ->assertSee('SCN-70000'); // the locked account, holding money on both legs
    }

    /** ⚠️ The flush list is byte-for-byte the same whether or not the holding list is built. */
    public function test_the_flush_list_is_identical_with_the_holding_list_off(): void
    {
        $this->mixedNetwork();
        $cutoff = ServiceGroupFlush::latestSelectableCutoff();
        $flush = app(ServiceGroupFlush::class);

        $full = $flush->preview($cutoff, includeSkipped: true);
        $fast = $flush->preview($cutoff, includeSkipped: false);

        $this->assertGreaterThan(0, $full['count'], 'guard: something flushes');
        $this->assertSame($full['accounts'], $fast['accounts']);
        $this->assertSame($full['payout'], $fast['payout']);
        $this->assertSame($full['forfeit'], $fast['forfeit']);
        $this->assertSame($full['count'], $fast['count']);

        // ...and the holding count matches what the full list actually listed.
        $this->assertSame(count($full['skipped']), $fast['holding_count']);
    }

    /** Not asking for it must cost far less than asking. */
    public function test_the_default_page_is_cheaper_than_the_full_list(): void
    {
        $this->mixedNetwork();
        $this->emptyAccounts(20);
        $flush = app(ServiceGroupFlush::class);
        $cutoff = ServiceGroupFlush::latestSelectableCutoff();

        $flush->preview($cutoff, includeSkipped: false); // warm caches

        $fast = $this->queries(fn () => $flush->preview($cutoff, includeSkipped: false));
        $full = $this->queries(fn () => $flush->preview($cutoff, includeSkipped: true));

        $this->assertLessThan($full, $fast, "fast {$fast} vs full {$full}");
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    /**
     * The unlock check asks for the owner's accounts once, not once per sibling.
     * On the live site a money-holding account's owner has a median of 8
     * placement accounts, and this ran on every account the run looked at.
     */
    public function test_the_unlock_check_does_not_query_once_per_sibling_account(): void
    {
        $this->emptyAccounts(8);

        $last = Consultant::where('owner_consultant_id', $this->stranger()->id)->orderByDesc('id')->firstOrFail();
        $staging = app(ServiceGroupStaging::class);

        $expected = $this->previousAccountTheOldWay($last);

        $queries = $this->queries(fn () => $this->assertSame($expected, $staging->previousAccountId($last)));

        $this->assertNotNull($expected, 'guard: the last account really has one before it');
        $this->assertLessThanOrEqual(2, $queries, "the unlock chain took {$queries} queries");
    }

    /** previousAccountId() as it was before 2026-09-14, via serviceGroupLeg() per sibling. */
    private function previousAccountTheOldWay(Consultant $account): ?int
    {
        $ids = Consultant::where('owner_consultant_id', $account->owner_consultant_id)
            ->where('is_hidden', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Consultant $a) => $a->serviceGroupLeg() !== null)
            ->pluck('id')
            ->values()
            ->all();

        $position = array_search($account->id, $ids, true);

        return ($position === false || $position === 0) ? null : $ids[$position - 1];
    }

    /** At a commission rate of 0 an empty account would "qualify", so nothing is skipped then. */
    public function test_nothing_is_skipped_when_the_rate_is_zero(): void
    {
        $this->mixedNetwork();

        \App\Models\CommissionSetting::where('is_active', true)->update(['commission_per_student' => 0]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        $cutoff = ServiceGroupFlush::latestSelectableCutoff();

        $this->assertEquals(
            app(WalksEveryAccount::class)->preview($cutoff),
            app(ServiceGroupFlush::class)->preview($cutoff),
        );
    }
}

/** The engine as it was: every active account, none skipped. */
class WalksEveryAccount extends ServiceGroupFlush
{
    protected function accountsWithSlotRows(bool $bothLegs): ?array
    {
        return null;
    }
}
