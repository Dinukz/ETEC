<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner-requested 2026-08-13: the admin Service Income screens showed WHO
 * earned an upline credit (the ancestor) but not WHO it came FROM — the
 * downline consultant whose own registration actually climbed the sponsor
 * chain to produce it. "kagenda enne penwann one" — show who it's coming from.
 *
 * Pins Commission::sourceConsultant(), which both dashboard/commissions/index
 * and .../history now render as a "from downline: ..." / "via ..." sub-line.
 */
class UplineSourceAttributionTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        Setting::set('stage_first_pairs', '1', 'integer', 'commission');
        Setting::set('stage_second_pairs', '2', 'integer', 'commission');
        Setting::set('stage_repeat_pairs', '3', 'integer', 'commission');
        Setting::set('account_unlock_students', '12', 'integer', 'commission');
        ServiceGroupStaging::forget();
    }

    private function sponsor(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function addStudent(Consultant $account, int $leg): User
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, $accountant);

        return $user;
    }

    private function promoteToConsultant(User $user, Consultant $sponsorAccount): Consultant
    {
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    public function test_an_upline_row_reports_the_downline_account_that_actually_registered_the_student(): void
    {
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1);
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);
        $downlineAccount = $downline->hiddenAccounts()->first();

        $this->addStudent($downlineAccount, 1);

        $row = Commission::where('consultant_id', $sponsor->id)
            ->where('type', 'bonus')
            ->with(['payment.consultant.user', 'payment.consultant.owner.user'])
            ->get()
            ->first(fn ($c) => $c->isUplineCredit());

        $this->assertNotNull($row, 'The climbing row must exist.');

        $source = $row->sourceConsultant();

        $this->assertNotNull($source, 'An upline row must report a source.');
        $this->assertSame(
            $downlineAccount->id,
            $source->id,
            'The source must be the specific downline account that registered the student, not the downline person\'s other accounts.',
        );
    }

    public function test_a_consultants_own_placement_reports_no_source(): void
    {
        $account = $this->sponsor()->hiddenAccounts()->first();
        $this->addStudent($account, 1);

        $row = Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->with(['payment.consultant.user', 'payment.consultant.owner.user'])
            ->firstOrFail();

        $this->assertNull($row->sourceConsultant(), 'A consultant\'s own placement has no separate "source" — they earned it themselves.');
    }

    /** The direct-add bonus is never an upline credit — only the registrant ever gets it. */
    public function test_the_direct_bonus_never_reports_a_source(): void
    {
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1);
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);
        $downlineAccount = $downline->hiddenAccounts()->first();

        $this->addStudent($downlineAccount, 1);

        $directRows = Commission::where('type', 'referral')
            ->with(['payment.consultant.user', 'payment.consultant.owner.user'])
            ->get();

        foreach ($directRows as $row) {
            $this->assertNull($row->sourceConsultant(), 'The 4,000 direct bonus belongs only to the registrant — it can never be an upline credit.');
        }
    }

    /** The admin queue (dashboard/commissions/index) must render the attribution without a lazy-load violation. */
    public function test_the_service_income_dashboard_renders_the_source_attribution(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1);
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);
        $downlineAccount = $downline->hiddenAccounts()->first();
        $downlineCode = $downlineAccount->scn_code ?? $downlineAccount->consultant_code;

        $this->addStudent($downlineAccount, 1);

        $response = $this->actingAs($admin)->get(route('commissions.index'));

        $response->assertOk();
        $response->assertSee('via '.$downlineCode, false);
    }

    /** The "All Service Income" table (dashboard/commissions/history) must render it too. */
    public function test_the_service_income_history_page_renders_the_source_attribution(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $sponsor = $this->sponsor();
        $sponsorAccount = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($sponsorAccount, 1);
        $downline = $this->promoteToConsultant($downlineUser, $sponsorAccount);
        $downlineAccount = $downline->hiddenAccounts()->first();
        $downlineCode = $downlineAccount->scn_code ?? $downlineAccount->consultant_code;

        $this->addStudent($downlineAccount, 1);

        $response = $this->actingAs($admin)->get(route('commissions.history'));

        $response->assertOk();
        $response->assertSee('from downline:', false);
        $response->assertSee($downlineCode, false);
    }
}
