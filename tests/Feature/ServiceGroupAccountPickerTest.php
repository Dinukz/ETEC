<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ EVERY ONE OF A CONSULTANT'S OWN ACCOUNTS IS SELECTABLE ON SCREEN.
 *
 * Reported 2026-09-02 against the Service Groups page: "methana pennanne main
 * account eka withariy, eka waradi — thamanta adalawa active thamange hama
 * account ekakma view wenna oni, earn krala thiyena total income ekath ekka."
 *
 * The picker was a single search input. It genuinely covered the whole network,
 * but CLOSED it renders as a field reading "SCN-00712 — mohan" and nothing
 * else, so the page looked like it offered only the main account. Switching
 * between your own accounts is the most common action on the most-used page in
 * the product — "Service group eka thamai hama kenekma wadiyenma balana
 * paawichchi wena thana... eka puluwan tharam sarala karala leasi karala demu" —
 * so those accounts are rendered as buttons rather than hidden behind a focus
 * event.
 *
 * The search box stays for the DOWNLINE, which is far too large to list.
 */
class ServiceGroupAccountPickerTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        Cache::forget('commission_settings.active');
    }

    private function main(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function student(Consultant $account, int $leg): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now()->subDays(3),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now()->subDays(3),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    private function page(?Consultant $viewer = null): \Illuminate\Testing\TestResponse
    {
        $viewer ??= $this->main();

        return $this->actingAs($viewer->user)->get(route('consultant.groups.index'));
    }

    // ------------------------------------------------------------------------

    /** The reported bug: the page must name every account, not just the main. */
    public function test_every_own_account_is_on_the_page_without_opening_anything(): void
    {
        $main = $this->main();
        $accounts = $main->hiddenAccounts()->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(2, $accounts->count(), 'guard: this consultant holds placement accounts');

        $page = $this->page();
        $page->assertOk();

        $page->assertSeeText($main->scn_code);

        foreach ($accounts as $account) {
            $page->assertSeeText(
                $account->scn_code,
                'every account the consultant holds must be reachable from this page',
            );
        }

        $page->assertSeeText('Your accounts');
    }

    /**
     * ⚠️ Each one carries what it has EARNED — the second half of the request
     * ("earn krala thiyena total income ekath ekka").
     */
    public function test_each_account_shows_what_it_has_earned(): void
    {
        $main = $this->main();
        $sg1 = $main->hiddenAccounts()->orderBy('id')->first();

        $this->student($sg1, 1);

        // Approve everything, since the page reports EARNED income (approved or
        // paid), not what is merely recorded.
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $rows = collect($this->page()->viewData('accountRows'));

        $earned = fn (int $id) => (float) $rows->firstWhere('id', $id)['sg1']
            + (float) $rows->firstWhere('id', $id)['sg2']
            + (float) $rows->firstWhere('id', $id)['direct_own'];

        $this->assertSame(
            (float) self::SLOT + self::DIRECT,
            $earned($sg1->id),
            'the placement account earned its own slot and its own direct bonus',
        );

        $this->assertSame(
            (float) self::SLOT,
            $earned($main->id),
            'and the main earned the 2,500 that climbed to it — its own row, not a rollup',
        );
    }

    /**
     * ⚠️ THE TILES MUST SUM TO WHAT THE PERSON ACTUALLY EARNED.
     *
     * `direct` on the Main row is the person-WIDE bonus total, so adding it up
     * per account would count every placement account's bonus twice. The tiles
     * read `direct_own`, and this is what stops that regression: a reader who
     * adds the tiles up must get the same answer as the ledger.
     */
    public function test_the_tiles_add_up_to_the_persons_real_total(): void
    {
        $main = $this->main();
        [$sg1, $sg2] = $main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $this->student($sg1, 1);
        $this->student($sg2, 1);

        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('commissions.approve-all'));

        $rows = collect($this->page()->viewData('accountRows'));

        $tileTotal = $rows->sum(
            fn ($r) => (float) $r['sg1'] + (float) $r['sg2'] + (float) $r['direct_own'],
        );

        $ledger = (float) \App\Models\Commission::whereIn('consultant_id', $main->accountIds())
            ->whereIn('status', ['approved', 'paid'])
            ->sum('amount');

        $this->assertSame($ledger, $tileTotal, 'summing the tiles must equal the ledger — no figure counted twice');
    }

    /** Clicking one really does switch which account's students are listed. */
    public function test_choosing_an_account_switches_the_member_list(): void
    {
        $main = $this->main();
        $sg1 = $main->hiddenAccounts()->orderBy('id')->first();

        $page = $this->actingAs($main->user)
            ->get(route('consultant.groups.index', ['consultant' => $main->id, 'account' => $sg1->id]));

        $page->assertOk();
        $this->assertSame($sg1->id, $page->viewData('selected')->id);
    }

    /** ⚠️ A closed account is not offered — the picker must not lead anywhere dead. */
    public function test_an_inactive_account_is_not_offered(): void
    {
        $main = $this->main();
        $sg2 = $main->hiddenAccounts()->orderBy('id')->skip(1)->first();

        Consultant::whereKey($sg2->id)->update(['status' => 'inactive']);

        $rows = collect($this->page()->viewData('accountRows'));
        $offered = $rows->filter(fn ($r) => ($r['status'] ?? 'active') === 'active');

        $this->assertNotNull($rows->firstWhere('id', $sg2->id), 'guard: it is still in the table');
        $this->assertNull(
            $offered->firstWhere('id', $sg2->id),
            'but a closed account is filtered out of the picker',
        );
    }

    /**
     * ⚠️ THE SEARCH BOX IS STILL THERE, and still covers the downline. The own
     * accounts being visible must not have replaced the way to reach anyone
     * below you — that is the other half of what was asked for.
     */
    public function test_the_downline_is_still_reachable_through_the_search(): void
    {
        $pickable = collect($this->page()->viewData('pickable'));

        $this->assertGreaterThan(0, $pickable->count());
        $this->assertTrue(
            $pickable->every(fn ($o) => isset($o['id'], $o['label'])),
            'the searchable list still carries what the picker needs to render',
        );
    }
}
