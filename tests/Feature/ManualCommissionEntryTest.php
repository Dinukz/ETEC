<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Student;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\ManualCommissionEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ HAND-ENTERED COMMISSION — the fallback for when the engine misses one.
 *
 * Asked for 2026-08-27, before go-live: "student and consultant 10000k wadi
 * pramanay add wenwa, ethkot calculate wen nathi prshn awoth loku prshnyk athi
 * wenwa — eka nisa manual 2500 add wen widiytath, Service Group Management ekat
 * manual 2500 saha direct 4000, anith eka upline 2500 add wen widiyt dala denn.
 * Option dekak one: SCN eka group ehekt withrk ynn, saha upline group load krla
 * e okkotam add krnn puluwn widiyt."
 *
 * This tool creates money, so the tests below are mostly about what it REFUSES.
 */
class ManualCommissionEntryTest extends TestCase
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

    private function someStudent(): User
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function entry(): ManualCommissionEntry
    {
        return app(ManualCommissionEntry::class);
    }

    private function slotOn(Consultant $account): float
    {
        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', CommissionService::TYPE_SLOT)->sum('amount');
    }

    public function test_it_adds_2500_to_one_account(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $this->entry()->add($account, $this->someStudent(), 'slot', 1, 'account');

        $this->assertSame((float) self::SLOT, $this->slotOn($account));
    }

    public function test_it_adds_the_4000_direct_bonus(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $this->entry()->add($account, $this->someStudent(), 'direct', 1, 'account');

        $this->assertSame(
            (float) self::DIRECT,
            (float) Commission::where('consultant_id', $account->id)
                ->where('type', CommissionService::TYPE_DIRECT)->sum('amount'),
        );
    }

    /**
     * The upline option walks the same chain a verified payment does —
     * ⚠️ MAIN ACCOUNT INCLUDED SINCE 2026-09-27.
     *
     * This asserted the opposite until today: "never the main — it rolls up,
     * it does not earn". That was the rule before 2026-09-02; the engine has
     * credited the main with a row of its own ever since, and this test was
     * never updated, so it held the manual tool on the old rule. The owner
     * found it on Udara Dilshantha's screen: SCN-00113 took the hand-entered
     * 2,500, SCN-00112 (the main) did not.
     */
    public function test_the_upline_option_credits_every_account_above_it(): void
    {
        $main = $this->main();

        // A bought set, so there is a real climb to walk: 00105 -> 00103 -> 00101 -> 00100.
        $payment = \App\Models\Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $main->user_id,
            'course_id' => \App\Models\Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $main->hiddenAccounts()->orderBy('id')->first()->id,
            'requested_tier' => 1, 'amount' => 39000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);
        app(\App\Services\PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        $accounts = Consultant::with('owner')->where('owner_consultant_id', $main->id)->orderBy('id')->get();
        $deep = $accounts[4];   // SCN-00105

        Commission::query()->delete();

        $result = $this->entry()->add($deep, $this->someStudent(), 'slot', 1, 'upline');

        $codes = collect($result['created'])->pluck('code')->all();

        $this->assertContains('SCN-00105', $codes, 'the account named');
        $this->assertContains('SCN-00103', $codes, 'the set-main above it');
        $this->assertContains('SCN-00101', $codes, 'the Service Group account above that');
        $this->assertContains('SCN-00100', $codes, 'AND the main — it earns its own row, like every other account');
    }

    /**
     * ⚠️ THE OWNER'S CASE: a downline person hanging off a SPONSOR'S PLACEMENT
     * account. The hand entry has to reach that placement account AND walk the
     * sponsor's own tree up to the sponsor's main — the step this tool skipped.
     */
    public function test_a_downlines_entry_reaches_the_sponsors_main_too(): void
    {
        $sponsor = $this->main();                                              // Udara, SCN-00112
        $sponsorSg1 = $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail(); // SCN-00113

        $user = User::factory()->create(['name' => 'Lakshan Maduranga']);
        $user->syncRoles(['consultant']);

        $downline = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorSg1->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-9'.random_int(1000, 9999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($downline);

        $result = $this->entry()->add($downline->fresh(), $this->someStudent(), 'slot', 1, 'upline');

        $codes = collect($result['created'])->pluck('code')->all();

        $this->assertContains($sponsorSg1->scn_code, $codes, 'the account Lakshan hangs off');
        $this->assertContains($sponsor->scn_code, $codes, 'and the sponsor\'s MAIN — the one that was being missed');
    }

    /**
     * ⚠️ THE GUARANTEE THAT STOPS THIS DRIFTING AGAIN.
     *
     * The manual tool is a fallback for when the engine misses one. The only
     * correct behaviour is therefore "exactly what the engine would have done"
     * — the same accounts, on the same legs. This places a real student through
     * the engine, records what it credited, clears it, enters the same thing by
     * hand, and requires the two to match row for row. The next time a climb
     * rule changes in CommissionService, this is the test that says the manual
     * tool was not changed with it.
     */
    public function test_a_hand_entry_credits_exactly_what_the_engine_would(): void
    {
        $sponsor = $this->main();
        $sponsorSg1 = $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail();

        // ⚠️ THE DOWNLINE IS SEATED ON THE SPONSOR'S LEG 2 FIRST, AS A STUDENT —
        // exactly how a real downline comes to exist — and the hand entry below
        // is made on leg 1. That difference is the whole point: the sponsor hop
        // must take leg 2 (where this person sits, via sideUnderParent), NOT the
        // form's leg 1. With no seat, sideUnderParent() returns null, both sides
        // fall back to the form's leg, and a regression to "one leg for every
        // row" passes unseen — which is precisely how the first draft of this
        // test let that mutation through.
        $user = User::factory()->create(['name' => 'Parity Person']);
        $user->assignRole('student');

        \App\Models\Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $seated = \App\Models\Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => \App\Models\Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $sponsorSg1->id, 'requested_tier' => 2,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);
        app(\App\Services\PaymentService::class)->verify($seated, User::where('email', 'accountant@etec.lk')->firstOrFail());

        $user->syncRoles(['consultant']);

        $downline = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorSg1->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-9'.random_int(1000, 9999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($downline);
        $downline = $downline->fresh();
        $placed = $downline->hiddenAccounts()->orderBy('id')->firstOrFail();

        // Guard on the guard: the sponsor side must really differ from the
        // form's leg below, or this test cannot see a leg regression at all.
        $this->assertSame(
            2,
            app(CommissionService::class)->sideUnderParent($downline),
            'guard: this person really sits on the sponsor\'s leg 2',
        );

        // 1. What the ENGINE credits for a real student placed on $placed, leg 1.
        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        $payment = \App\Models\Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => \App\Models\Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $placed->id, 'requested_tier' => 1,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);
        app(\App\Services\PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        $byEngine = Commission::where('payment_id', $payment->id)
            ->where('type', CommissionService::TYPE_SLOT)
            ->get()
            ->mapWithKeys(fn ($c) => [(int) $c->consultant_id => (int) $c->tier])
            ->sortKeys()
            ->all();

        $this->assertNotEmpty($byEngine, 'guard: the engine really credited a chain');

        // 2. The same placement, by hand.
        Commission::query()->delete();

        $this->entry()->add($placed, $this->someStudent(), 'slot', 1, 'upline');

        $byHand = Commission::whereNull('payment_id')
            ->where('type', CommissionService::TYPE_SLOT)
            ->get()
            ->mapWithKeys(fn ($c) => [(int) $c->consultant_id => (int) $c->tier])
            ->sortKeys()
            ->all();

        $this->assertSame(
            $byEngine,
            $byHand,
            'a hand entry reaches the same accounts, on the same legs, as the engine',
        );
    }

    /** The direct bonus never climbs, whatever scope is asked for. */
    public function test_the_direct_bonus_never_climbs_even_on_the_upline_scope(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $result = $this->entry()->add($account, $this->someStudent(), 'direct', 1, 'upline');

        $this->assertCount(1, $result['created'], 'Uplines never get the direct bonus.');
    }

    /** ⚠️ The dangerous failure mode: clicking twice. */
    public function test_it_refuses_to_record_the_same_commission_twice(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();
        $student = $this->someStudent();

        $this->entry()->add($account, $student, 'slot', 1, 'account');

        $this->expectException(\RuntimeException::class);
        $this->entry()->add($account, $student, 'slot', 1, 'account');
    }

    /** A refusal partway up the climb must leave nothing behind. */
    public function test_a_refused_upline_entry_writes_nothing_at_all(): void
    {
        $main = $this->main();
        $accounts = $main->hiddenAccounts()->orderBy('id')->get();
        $student = $this->someStudent();

        // Give the SECOND account in the chain a row already, so the climb hits
        // a duplicate after the first one has been created inside the transaction.
        $this->entry()->add($accounts[0], $student, 'slot', 1, 'account');

        $before = Commission::count();

        try {
            $this->entry()->add($accounts[0], $student, 'slot', 1, 'upline');
            $this->fail('The duplicate should have stopped this.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before, Commission::count(), 'Nothing was written by the failed entry.');
    }

    /** A hand-entered slot row waits for a System Run, like any other. */
    public function test_a_hand_entered_slot_row_is_not_released(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $this->entry()->add($account, $this->someStudent(), 'slot', 1, 'account');

        $this->assertNull(
            Commission::where('consultant_id', $account->id)->latest('id')->first()->released_at,
            'It sits on the leg until a run settles it.'
        );
    }

    // ----- The screen -------------------------------------------------------

    public function test_a_super_admin_can_post_the_form(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->post(route('groups.manual-commission'), [
                'consultant_id' => $account->id,
                'student_user_id' => $this->someStudent()->id,
                'type' => 'slot', 'tier' => 1, 'scope' => 'account',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame((float) self::SLOT, $this->slotOn($account));
    }

    public function test_an_accountant_cannot(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->post(route('groups.manual-commission'), [
                'consultant_id' => $account->id,
                'student_user_id' => $this->someStudent()->id,
                'type' => 'slot', 'tier' => 1, 'scope' => 'account',
            ])
            ->assertForbidden();

        $this->assertSame(0.0, $this->slotOn($account));
    }

    public function test_a_duplicate_reports_an_error_rather_than_a_second_row(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();
        $student = $this->someStudent();
        $admin = User::role('super_admin')->firstOrFail();

        $payload = [
            'consultant_id' => $account->id,
            'student_user_id' => $student->id,
            'type' => 'slot', 'tier' => 1, 'scope' => 'account',
        ];

        $this->actingAs($admin)->post(route('groups.manual-commission'), $payload);
        $this->actingAs($admin)->post(route('groups.manual-commission'), $payload)->assertSessionHas('error');

        $this->assertSame((float) self::SLOT, $this->slotOn($account), 'Still exactly one 2,500.');
    }

    public function test_the_form_is_only_rendered_for_a_super_admin(): void
    {
        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('groups.index'))
            ->assertOk()
            ->assertSee('Add a commission by hand', false);

        // An accountant cannot open this page at all (route middleware), so
        // the meaningful check is an ADMIN who can — and still must not see it.
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get(route('groups.index'))
            ->assertOk()
            ->assertDontSee('Add a commission by hand', false);
    }

    // ----- No student named (2026-09-15) -------------------------------------

    /**
     * ⚠️ THE REPORT. "student kiyla ekak thiyeno eka awshya wen na" — the entry
     * works with no student, and is recorded against the account's own person.
     */
    public function test_it_adds_without_naming_a_student(): void
    {
        $main = $this->main();
        $account = $main->hiddenAccounts()->orderBy('id')->first();

        $this->entry()->add($account, null, 'slot', 1, 'account');

        $row = Commission::where('consultant_id', $account->id)->latest('id')->firstOrFail();

        $this->assertSame((float) self::SLOT, (float) $row->amount);
        $this->assertSame(
            (int) $main->user_id,
            (int) $row->user_id,
            'a placement account has no user of its own, so it falls back to the person who owns it',
        );
    }

    /** ⚠️ The placement accounts this tool targets carry a NULL user_id on live. */
    public function test_a_placement_account_really_has_no_user_of_its_own(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $this->assertNull($account->user_id, 'guard: the fallback above is the path actually taken');
    }

    /**
     * ⚠️ THE GUARD MUST NOT MISFIRE. With no student named every row is
     * attributed to the same person, so the duplicate rule would have refused
     * every entry after the first — the opposite of what this tool is for.
     */
    public function test_two_entries_without_a_student_are_both_allowed(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $this->entry()->add($account, null, 'slot', 1, 'account');
        $this->entry()->add($account, null, 'slot', 1, 'account');

        $this->assertSame(2 * (float) self::SLOT, $this->slotOn($account), 'both 2,500s were recorded');
    }

    /** ...but a NAMED student is still protected from a double entry. */
    public function test_a_named_student_is_still_refused_twice(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();
        $student = $this->someStudent();

        $this->entry()->add($account, $student, 'slot', 1, 'account');

        $this->expectException(\RuntimeException::class);
        $this->entry()->add($account, $student, 'slot', 1, 'account');
    }

    /** The 4,000 works the same way with no student. */
    public function test_the_direct_bonus_works_without_a_student(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $this->entry()->add($account, null, 'direct', 1, 'account');

        $this->assertSame(
            (float) self::DIRECT,
            (float) Commission::where('consultant_id', $account->id)
                ->where('type', CommissionService::TYPE_DIRECT)->sum('amount'),
        );
    }

    /** The form posts with no student at all. */
    public function test_the_form_posts_without_a_student(): void
    {
        $account = $this->main()->hiddenAccounts()->orderBy('id')->first();

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->post(route('groups.manual-commission'), [
                'consultant_id' => $account->id,
                'type' => 'slot', 'tier' => 1, 'scope' => 'account',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame((float) self::SLOT, $this->slotOn($account));
    }

    // ----- The screen, reworked ------------------------------------------------

    /** The account picker is searchable, and the Student dropdown is gone. */
    public function test_the_account_picker_is_searchable_and_the_student_field_is_gone(): void
    {
        $response = $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('groups.index'))
            ->assertOk();

        $response->assertSee('name="consultant_id"', false);
        $response->assertSee('Type an SCN code or a name...', false);
        $response->assertSee('Info / upline');
        $response->assertDontSee('name="student_user_id"', false);
    }

    /** The consultant table is paginated rather than rendering every account. */
    public function test_the_consultant_table_is_paginated(): void
    {
        $response = $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('groups.index'))
            ->assertOk();

        $this->assertInstanceOf(
            \Illuminate\Contracts\Pagination\LengthAwarePaginator::class,
            $response->viewData('consultantTiers'),
        );
        $this->assertLessThanOrEqual(25, $response->viewData('consultantTiers')->count());
    }
}
