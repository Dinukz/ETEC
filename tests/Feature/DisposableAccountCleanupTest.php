<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\DisposableAccountAudit;
use App\Services\HiddenAccountService;
use App\Services\NumberPool;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ REMOVING TEST IDENTITIES FROM A DATABASE THAT ALSO HOLDS REAL ONES.
 *
 * Asked for 2026-09-01: the numbering had reached SCN-00229 because roughly a
 * hundred test identities were holding numbers — "kalin patn gaththe SCN 100,
 * dn patn ganne SCN 229. Meka patn ganna one SCN 100."
 *
 * ⚠️⚠️ THE LIVE SITE SHARES THIS DATABASE. The owner confirmed it. So nothing
 * here decides what is "test data" — it cannot know, and a wrong guess deletes
 * a real consultant along with their earnings. It reports the FINANCIAL
 * FOOTPRINT and a person chooses.
 *
 * Almost every test below is therefore about what is REFUSED.
 */
class DisposableAccountCleanupTest extends TestCase
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
        return User::role('super_admin')->firstOrFail();
    }

    private function audit(): array
    {
        return app(DisposableAccountAudit::class)->run($this->admin()->id);
    }

    /** @return array<int, string> the emails offered for deletion */
    private function disposableEmails(): array
    {
        return collect($this->audit()['disposable'])->pluck('email')->all();
    }

    private function keptReasonFor(string $email): ?string
    {
        return collect($this->audit()['kept'])->firstWhere('email', $email)['reason'] ?? null;
    }

    private function plainStudent(): User
    {
        $user = User::factory()->create();
        $user->assignRole('student');

        Student::create([
            'user_id' => $user->id,
            'student_code' => NumberPool::format('SRN', NumberPool::take()),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        return $user;
    }

    // ---- What it offers -----------------------------------------------------

    public function test_a_student_with_no_payment_is_offered(): void
    {
        $user = $this->plainStudent();

        $this->assertContains($user->email, $this->disposableEmails());
    }

    // ---- ...and everything it refuses ---------------------------------------

    /** ⚠️ The one that would lose real money. */
    public function test_an_account_with_a_verified_payment_is_kept(): void
    {
        $user = $this->plainStudent();

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);
        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        $this->assertNotContains($user->email, $this->disposableEmails());
        $this->assertSame('Has a verified payment.', $this->keptReasonFor($user->email));
    }

    /**
     * ⚠️ Commission can sit on a consultant's HIDDEN accounts, not on the
     * person. Checking only the person would offer a consultant whose earnings
     * are all on SCN-00101 — and deleting them takes those accounts with them.
     */
    public function test_a_consultant_earning_only_on_a_hidden_account_is_kept(): void
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);
        app(HiddenAccountService::class)->ensureFor($consultant);

        $hidden = $consultant->fresh()->hiddenAccounts()->firstOrFail();

        Commission::create([
            'consultant_id' => $hidden->id,
            'group_id' => \App\Models\Group::where('consultant_id', $hidden->id)->value('id'),
            'user_id' => $user->id,
            'tier' => 1, 'amount' => 2500, 'slots' => 1,
            'type' => 'bonus', 'status' => 'approved',
        ]);

        $this->assertNotContains($user->email, $this->disposableEmails());
        $this->assertStringContainsString('commission', (string) $this->keptReasonFor($user->email));
    }

    public function test_staff_are_never_offered(): void
    {
        $emails = $this->disposableEmails();

        foreach (['accountant@etec.lk', 'admin@etec.lk'] as $email) {
            $this->assertNotContains($email, $emails, $email.' must never be offered');
        }
    }

    public function test_you_are_never_offered_to_yourself(): void
    {
        $this->assertSame('This is you.', $this->keptReasonFor($this->admin()->email));
    }

    // ---- The numbering ------------------------------------------------------

    /**
     * ⚠️ The counter only moves forward on its own, so deleting accounts does
     * NOT lower it until it is recalculated. That surprise is the whole reason
     * the button exists.
     */
    public function test_deleting_alone_does_not_lower_the_next_number(): void
    {
        $user = $this->plainStudent();
        $before = NumberPool::peek();

        $user->forceDelete();

        $this->assertSame($before, NumberPool::peek(), 'the counter has not moved');
    }

    public function test_recalculating_reclaims_the_freed_numbers(): void
    {
        $user = $this->plainStudent();
        $high = NumberPool::peek();

        $user->forceDelete();
        $result = NumberPool::recompute();

        $this->assertSame($high, $result['from']);
        $this->assertLessThan($high, $result['to'], 'the freed number is reclaimed');
        $this->assertSame($result['to'], NumberPool::peek());
    }

    /** ⚠️ It must never hand back a number something still holds. */
    public function test_recalculating_never_reuses_a_live_number(): void
    {
        $kept = $this->plainStudent();
        $going = $this->plainStudent();

        $going->forceDelete();
        NumberPool::recompute();

        $keptNumber = NumberPool::numberIn(Student::where('user_id', $kept->id)->value('student_code'));

        $this->assertGreaterThan(
            $keptNumber,
            NumberPool::peek(),
            'the next number is above every code still in use',
        );
    }

    /** On a database with real consultants it will NOT drop to 100. */
    public function test_it_does_not_drop_below_what_real_accounts_hold(): void
    {
        NumberPool::recompute();

        $highest = Consultant::whereNotNull('scn_code')->get()
            ->map(fn ($c) => NumberPool::numberIn($c->scn_code))
            ->filter()
            ->max();

        $this->assertGreaterThan((int) $highest, NumberPool::peek());
    }

    // ---- The screen ---------------------------------------------------------

    public function test_a_super_admin_can_open_it_and_it_deletes_nothing(): void
    {
        $this->plainStudent();
        $before = User::count();

        $this->actingAs($this->admin())
            ->get(route('users.cleanup'))
            ->assertOk()
            ->assertSee('Which accounts could be removed?', false)
            ->assertSee('Kept — and why', false);

        $this->assertSame($before, User::count(), 'opening the page removes nothing');
    }

    public function test_an_admin_cannot(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get(route('users.cleanup'))
            ->assertForbidden();
    }

    public function test_the_recalculate_button_is_super_admin_only(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->post(route('users.recompute-numbering'))
            ->assertForbidden();
    }
}
