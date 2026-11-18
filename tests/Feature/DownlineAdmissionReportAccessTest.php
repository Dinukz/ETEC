<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ WHO MAY SEE WHOSE ADMISSIONS.
 *
 * Requested 2026-09-02: a consultant should be able to see the admissions of
 * the consultants below them — "eya yatathe inn consultant lage detail balann
 * puluwn wenn one" — but ONLY when an admin has switched it on for them:
 *
 *   "meka permission ekak widiyt admin user management eke enable krnn puluwn
 *    widiyt hadann, mokada aluthin ena consultant lata me function eka block
 *    krnn one nisa — user management eken access denn, naththan meka hide krann
 *    one."
 *
 * So the default is OFF and the feature is HIDDEN, not merely refused. This file
 * is the security boundary for that: everything here is about a consultant
 * being unable to read admissions that are not theirs.
 */
class DownlineAdmissionReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function sponsor(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    /** A consultant hanging under $parent, with one registered student. */
    private function downlineWithAStudent(Consultant $parent, string $email): Consultant
    {
        $user = User::factory()->create(['email' => $email]);
        $user->syncRoles(['consultant']);

        $downline = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $parent->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-'.random_int(20000, 29999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($downline);

        $this->registerStudent($downline->fresh()->hiddenAccounts()->orderBy('id')->first());

        return $downline->fresh();
    }

    /** An UNRELATED consultant — nobody's downline. */
    private function strangerWithAStudent(): Consultant
    {
        $user = User::factory()->create(['email' => 'stranger@example.com']);
        $user->syncRoles(['consultant']);

        $stranger = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-99123',
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($stranger);
        $this->registerStudent($stranger->fresh()->hiddenAccounts()->orderBy('id')->first());

        return $stranger->fresh();
    }

    private function registerStudent(Consultant $account): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'consultant_id' => $account->id,
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => 1,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    private function report(Consultant $viewer, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($viewer->user)->get(route('reports.admissions', $query));
    }

    private function grant(Consultant $consultant, bool $on = true): void
    {
        $this->actingAs($this->admin())
            ->patch(route('users.downline-report', $consultant->user), ['enabled' => $on ? 1 : 0])
            ->assertRedirect();
    }

    // ------------------------------------------------------------------------

    /** ⚠️ THE DEFAULT. A new consultant gets nothing new. */
    public function test_a_consultant_starts_with_no_downline_access(): void
    {
        $sponsor = $this->sponsor();

        $this->assertFalse(
            $sponsor->canViewDownlineReport(),
            'off until an admin turns it on — a consultant who signs up tomorrow must be blocked',
        );
    }

    /**
     * ⚠️ REVISED 2026-09-22. Without the grant the Admission Report is not
     * shown-with-own-rows any more — the page itself is closed (the owner made
     * it a per-consultant grant, OFF for everyone). Stricter than before, so the
     * boundary this file exists for only got tighter.
     */
    public function test_the_downline_controls_are_not_rendered_without_the_grant(): void
    {
        $sponsor = $this->sponsor();
        $this->downlineWithAStudent($sponsor, 'hidden-controls@example.com');

        $this->report($sponsor)->assertForbidden();
    }

    /** ⚠️ THE DEFAULT, IN MONEY TERMS: nobody's students are shown at all. */
    public function test_without_the_grant_the_report_shows_only_their_own(): void
    {
        $sponsor = $this->sponsor();
        $this->downlineWithAStudent($sponsor, 'not-counted@example.com');

        $this->report($sponsor)->assertForbidden();
    }

    /** The grant is what opens it — the reported requirement. */
    public function test_once_granted_the_downlines_admissions_appear(): void
    {
        $sponsor = $this->sponsor();
        $downline = $this->downlineWithAStudent($sponsor, 'counted@example.com');

        $this->grant($sponsor);

        $page = $this->report($sponsor->fresh());
        $rows = $page->viewData('rows');

        $this->assertTrue($page->viewData('seesDownline'));
        $this->assertNotNull(
            $rows->firstWhere('id', $downline->hiddenAccounts()->first()->id),
            "the account the downline's student was registered on is now in the report",
        );
    }

    /**
     * ⚠️⚠️ THE BOUNDARY. Granted or not, a consultant must never see somebody
     * who is not below them.
     */
    public function test_a_granted_consultant_still_cannot_see_a_stranger(): void
    {
        $sponsor = $this->sponsor();
        $stranger = $this->strangerWithAStudent();

        $this->grant($sponsor);

        $rows = $this->report($sponsor->fresh())->viewData('rows');

        foreach ($stranger->accountIds() as $id) {
            $this->assertNull(
                $rows->firstWhere('id', $id),
                'a consultant outside the network must never appear, grant or no grant',
            );
        }
    }

    /**
     * ⚠️ AND NOT BY GUESSING AN ID EITHER. The marketer filter narrows within
     * what the viewer may already see; it must never substitute for it.
     */
    public function test_the_marketer_filter_cannot_reach_outside_the_network(): void
    {
        $sponsor = $this->sponsor();
        $stranger = $this->strangerWithAStudent();

        $this->grant($sponsor);

        $rows = $this->report($sponsor->fresh(), ['marketer' => $stranger->id])->viewData('rows');

        $this->assertCount(
            0,
            $rows,
            'asking for a stranger by id returns nothing at all — never their students',
        );
    }

    /** Revoking puts it straight back. */
    public function test_the_grant_can_be_taken_away_again(): void
    {
        $sponsor = $this->sponsor();
        $this->downlineWithAStudent($sponsor, 'revoked@example.com');

        $this->grant($sponsor);
        $this->assertTrue($sponsor->fresh()->canViewDownlineReport());

        $this->grant($sponsor, false);
        $this->assertFalse($sponsor->fresh()->canViewDownlineReport());
        // Since 2026-09-22 the old switch IS the Admission Report grant.
        $this->report($sponsor->fresh())->assertForbidden();
    }

    /** ⚠️ Only staff may grant it — a consultant cannot grant it to themselves. */
    public function test_a_consultant_cannot_grant_it_to_themselves(): void
    {
        $sponsor = $this->sponsor();

        $this->actingAs($sponsor->user)
            ->patch(route('users.downline-report', $sponsor->user), ['enabled' => 1])
            ->assertForbidden();

        $this->assertFalse($sponsor->fresh()->canViewDownlineReport());
    }

    /**
     * ⚠️ THE ADMIN MUST BE ABLE TO FIND THE SWITCH (2026-09-02).
     *
     * Reported the day it shipped: "role access denn giyama ethama ehem ekak
     * penwan na". Two causes, both fixed — it was rendered far from Role &
     * permissions where an admin goes looking, and it was wrapped in a
     * schema-ready check that made it disappear completely on a database that
     * had not healed yet, which is the normal state right after a deploy.
     */
    /**
     * ⚠️ REVISED 2026-09-22: the single switch became the "Page access" panel
     * (Admission Report = this grant, plus three other pages). Still under Role
     * & permissions, still shown before the schema heals.
     */
    public function test_the_admin_can_find_the_switch_on_the_edit_user_page(): void
    {
        $sponsor = $this->sponsor();

        $page = $this->actingAs($this->admin())->get(route('users.edit', $sponsor->user));

        $page->assertOk();
        $page->assertSeeText('Page access');
        $page->assertSeeText('Admission Report');
        $page->assertSeeText('Save page access');
    }

    /** ...and a consultant granted by the old switch reads as having it. */
    public function test_the_switch_reads_enabled_after_granting(): void
    {
        $sponsor = $this->sponsor();
        $this->grant($sponsor);

        $this->actingAs($this->admin())
            ->get(route('users.edit', $sponsor->user))
            ->assertOk()
            ->assertSeeText('on from the old downline switch');
    }

    /** A non-consultant has no network, so the switch is genuinely absent. */
    public function test_a_non_consultant_gets_no_switch(): void
    {
        $student = User::role('student')->first() ?? User::factory()->create();

        if (! $student->hasRole('student')) {
            $student->syncRoles(['student']);
        }

        $this->actingAs($this->admin())
            ->get(route('users.edit', $student))
            ->assertOk()
            ->assertDontSeeText('Save page access');
    }

    /** Staff are unaffected — they still see the whole company. */
    public function test_staff_still_see_everyone(): void
    {
        $sponsor = $this->sponsor();
        $stranger = $this->strangerWithAStudent();

        $page = $this->actingAs($this->admin())->get(route('reports.admissions'));
        $rows = $page->viewData('rows');

        $this->assertNull($page->viewData('viewerConsultant'));
        $this->assertNotNull($rows->firstWhere('id', $stranger->hiddenAccounts()->first()->id));
        $this->assertNotNull($rows->firstWhere('id', $sponsor->id));
    }
}
