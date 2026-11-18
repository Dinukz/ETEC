<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The Service Groups screen: renders, and its SCN search only reaches own network. */
class ServiceGroupsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function makeConsultant(?Consultant $parent = null): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        return Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $parent?->id,
            'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    public function test_service_groups_page_renders_with_both_legs(): void
    {
        $c = $this->makeConsultant();

        $this->actingAs($c->user)
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertSee('All Students')
            ->assertSee('Service Group 1')
            ->assertSee('Service Group 2')
            ->assertSee($c->scn_code);
    }

    /**
     * The "Left/Right Members" two-card layout was replaced with a single
     * card and three tabs (All Students / Service Group 1 / Service Group 2),
     * per the owner's screenshot annotations (2026-08-06). "All Students" is
     * the union of both legs, tagged with which group each member is on.
     */
    public function test_the_all_students_tab_merges_both_service_groups(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;
        $hidden = $consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $course = \App\Models\Course::where('status', 'published')->firstOrFail();

        foreach ([1 => 'leg-a', 2 => 'leg-b'] as $tier => $slug) {
            $email = "{$slug}@example.com";
            $this->actingAs($consultantUser)->post(route('consultant.students.store'), [
                'name' => 'Student '.$slug,
                'email' => $email,
                'course_id' => $course->id,
                'placement_tier' => $tier,
                'partner_consultant_id' => $hidden->id,
            ])->assertSessionHasNoErrors();

            $payment = \App\Models\Payment::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
            $this->actingAs($consultantUser)->patch(route('consultant.payments.confirm', $payment), ['payment_type' => 'full', 'payment_date' => now()->toDateString()]);
            app(\App\Services\PaymentService::class)->verify($payment->fresh(), $accountant);
        }

        $response = $this->actingAs($consultantUser)->get(route('consultant.groups.index'));
        $response->assertOk();

        $response->assertSeeInOrder(['All Students', 'Service Group 1', 'Service Group 2']);
        $response->assertSee('Student leg-a');
        $response->assertSee('Student leg-b');
        $response->assertDontSee('Earning slots used');
        $response->assertDontSee('Left Members');
        $response->assertDontSee('Right Members');
    }

    public function test_a_consultant_can_switch_to_a_downline_consultants_service_groups(): void
    {
        $mine = $this->makeConsultant();
        $below = $this->makeConsultant($mine);

        $response = $this->actingAs($mine->user)
            ->get(route('consultant.groups.index', ['consultant' => $below->id]));

        $response->assertOk();
        $this->assertSame($below->id, $response->viewData('subject')->id, 'The page must show the downline consultant.');
        $response->assertSee('from your network');
    }

    public function test_a_consultant_cannot_view_someone_outside_their_network(): void
    {
        $mine = $this->makeConsultant();
        $stranger = $this->makeConsultant();

        $response = $this->actingAs($mine->user)
            ->get(route('consultant.groups.index', ['consultant' => $stranger->id]));

        $response->assertOk();
        $this->assertSame($mine->id, $response->viewData('subject')->id, 'An out-of-network id must fall back to the viewer.');
    }

    public function test_exact_scn_search_finds_an_account_in_the_network(): void
    {
        $c = $this->makeConsultant();
        $hidden = $c->hiddenAccounts()->first();

        // Bare digits should resolve to the padded SCN code.
        $digits = (int) preg_replace('/\D/', '', $hidden->scn_code);

        $this->actingAs($c->user)
            ->get(route('consultant.groups.index', ['scn' => $digits]))
            ->assertOk()
            ->assertSee($hidden->scn_code);
    }

    public function test_search_cannot_reach_an_account_outside_the_network(): void
    {
        $mine = $this->makeConsultant();
        $stranger = $this->makeConsultant();

        $this->actingAs($mine->user)
            ->get(route('consultant.groups.index', ['scn' => $stranger->scn_code]))
            ->assertOk()
            ->assertSee('No account in your network matches');
    }

    // ----- the stage label and forfeit line are super-admin only (2026-09-13) -----

    /**
     * Owner: "3to3 eka saha company ekat yana gana hide krla denn okkom
     * consultant lagen sampuran ain krla denn me deka super adminge ewa wala
     * thiynn" — the internal stage name ("3-to-3 ready") and the amount a run
     * would forfeit to the company must disappear for every ordinary
     * consultant, and survive only for someone who also holds super_admin
     * (the owner's own account, viewed as a consultant).
     *
     * Reaches the repeating (3-to-3) stage the same way ServiceGroupFlushTest
     * does: lifetime_pair_stage set past the first two one-time stages, one
     * leg at the threshold and the other over it, so the preview has both a
     * releasable amount and a forfeit to report.
     */
    private function readyToFlushWithForfeit(): array
    {
        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => 2500,
            'direct_commission' => 4000,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        \App\Models\Setting::set('stage_first_pairs', '1', 'integer', 'commission');
        \App\Models\Setting::set('stage_second_pairs', '2', 'integer', 'commission');
        \App\Models\Setting::set('stage_repeat_pairs', '3', 'integer', 'commission');
        \App\Models\Setting::set('account_unlock_students', '12', 'integer', 'commission');
        \App\Services\ServiceGroupStaging::forget();

        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $account = $consultantUser->consultant->hiddenAccounts()->first();
        $account->forceFill(['lifetime_pair_stage' => 2])->save();

        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $course = \App\Models\Course::where('status', 'published')->firstOrFail();

        $addStudent = function (int $leg) use ($account, $accountant, $course) {
            $user = User::factory()->create();
            \App\Models\Student::create([
                'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
                'status' => 'active', 'enrolled_at' => now(),
            ]);

            $payment = \App\Models\Payment::create([
                'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => $course->id,
                'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 28000, 'currency' => 'LKR',
                'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
            ]);

            app(\App\Services\PaymentService::class)->verify($payment, $accountant);
        };

        // Threshold at step 3 is 3 x 2,500 = 7,500 on each side. Leg 1 exactly
        // meets it; leg 2 goes one student over, which is what the repeating
        // stage forfeits to the company instead of leaving on the leg.
        for ($i = 0; $i < 3; $i++) {
            $addStudent(1);
        }

        for ($i = 0; $i < 4; $i++) {
            $addStudent(2);
        }

        return [$consultantUser, $account];
    }

    public function test_a_plain_consultant_does_not_see_the_stage_or_forfeit_lines(): void
    {
        [$consultantUser, $account] = $this->readyToFlushWithForfeit();

        $response = $this->actingAs($consultantUser)
            ->get(route('consultant.groups.index'))
            ->assertOk();

        // Guard: the fixture really did reach a flushable, forfeiting state —
        // otherwise the two assertDontSee calls below would pass for the wrong
        // reason (nothing to hide, rather than something hidden).
        $flusher = app(\App\Services\ServiceGroupFlush::class);
        $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
        $state = $flusher->state($account, $group, \App\Services\ServiceGroupFlush::defaultCutoff());
        $this->assertTrue($state['can_flush'], 'guard: the fixture reached a flushable stage');
        $this->assertGreaterThan(0, $state['forfeit'], 'guard: the fixture produced a real forfeit');

        $response->assertDontSee('3-to-3 ready')
            ->assertDontSee('at the next run')
            ->assertDontSee('would go to the company');
    }

    /** The same screen, for the same account, with the viewer also holding super_admin. */
    public function test_a_super_admin_still_sees_the_stage_and_forfeit_lines_on_their_own_consultant_screen(): void
    {
        [$consultantUser] = $this->readyToFlushWithForfeit();
        $consultantUser->assignRole('super_admin');

        $this->actingAs($consultantUser)
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertSee('3-to-3 ready')
            ->assertSee('would go to the company');
    }

    /** The "already approved before this run" line is money news, not a stage/forfeit detail — everyone keeps it. */
    public function test_the_already_approved_line_stays_visible_to_a_plain_consultant(): void
    {
        [$consultantUser, $account] = $this->readyToFlushWithForfeit();

        // Mark one of leg 1's rows PAID before the run — 'already_paid' is
        // built from legPlan()'s 'swept' bucket, which is status = 'paid'
        // specifically. A slot commission is auto-approved the moment it is
        // recorded (CommissionService::autoApprove(), 2026-08-25), so the row
        // to pick is 'approved', never 'pending' — nothing on this leg is
        // ever left pending by the time a payment has been verified.
        //
        // ⚠️ ->first()->update(), not ->limit(1)->update(): UPDATE ... LIMIT
        // is not supported by a default SQLite build, and the syntax error it
        // throws mid-transaction leaves the connection unable to see ANY
        // table for the rest of the test (surfaces as an unrelated "no such
        // table: settings" on the next request).
        \App\Models\Commission::where('consultant_id', $account->id)
            ->where('tier', 1)->where('status', 'approved')
            ->firstOrFail()->update(['status' => 'paid']);

        $this->actingAs($consultantUser)
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertSee('already approved before this run');
    }
}
