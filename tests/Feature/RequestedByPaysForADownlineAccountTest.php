<?php

namespace Tests\Feature;

use App\Livewire\PaymentQueue;
use App\Livewire\ScnAccountSummary;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Notifications\StudentPaymentConfirmed;
use App\Services\CommissionService;
use App\Services\ConsultantFinanceService;
use App\Services\EnrollmentService;
use App\Services\FinanceSchemaRepair;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use App\Services\RegistrationPayer;
use App\Support\FinanceWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ⚠️ "CONSULTANT (REQUESTED BY)" PAYS; THE PARTNER ACCOUNT EARNS (2026-11-03).
 *
 * The owner's example: SCN-112 (sub accounts 113, 114) registers a student on
 * SCN-132, an account of SCN-130 who hangs in 112's group. Picking 132 used to
 * flip Requested By to 130 — "eka wardi". Now:
 *
 *   - Requested By stays 112, and 112 PAYS: confirms the plan, attaches and
 *     re-attaches the slip, pays the balance.
 *   - The 2,500 is exactly what it was: climbing 132 -> 130 -> the 112 account
 *     130 hangs under -> 112 -> above. "aluth logic eken parana logic walata
 *     balapamak wenn ba."
 *   - ⚠️ The DIRECT bonus goes to 112 (owner, 2026-11-16: "direct bonus eka …
 *     Consultant (Requested By) add wena widiytma") — it was on 132 until then.
 *   - 130 still sees the student (it is on his account and in his weekly
 *     quota) but VIEW ONLY, marked "Paid by SCN-112" — the owner's answer.
 *
 * In this fixture: top = SCN-112 (the seeded consultant), topSg1 / topSg2 =
 * 113 / 114, holder = SCN-130 hanging under topSg1, holderSg1 / holderSg2 =
 * 131 / 132.
 */
class RequestedByPaysForADownlineAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $topUser;

    private Consultant $top;

    private Consultant $topSg1;

    private Consultant $topSg2;

    private Consultant $holder;

    private Consultant $holderSg1;

    private Consultant $holderSg2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
        // Posting the form boots the mail settings — see RegisterOnADownlineAccountTest::setUp.
        Cache::forever('settings.mail.grouped', collect());
        Storage::fake('public');

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => 2500,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');

        $this->topUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->top = $this->topUser->consultant;
        [$this->topSg1, $this->topSg2] = $this->top->hiddenAccounts()->orderBy('id')->get()->all();

        $this->holder = $this->consultantUnder($this->topSg1, 'Holder Person');
        [$this->holderSg1, $this->holderSg2] = $this->holder->hiddenAccounts()->orderBy('id')->get()->all();
    }

    // ----- helpers -------------------------------------------------------------

    private function code(Consultant $account): string
    {
        return $account->scn_code ?: $account->consultant_code;
    }

    /** A real consultant hanging under $sponsorAccount (null = nobody), with their own accounts. */
    private function consultantUnder(?Consultant $sponsorAccount, string $name): Consultant
    {
        static $n = 0;
        $n++;

        $user = User::factory()->create(['name' => $name]);
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount?->id,
            'consultant_code' => 'CON'.Str::random(8),
            // Spaced by ten: the sub accounts take the next numbers (main + 1, + 2).
            'scn_code' => 'SCN-7'.str_pad((string) ($n * 10), 5, '0', STR_PAD_LEFT),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    private function course(): Course
    {
        return Course::where('status', 'published')->orderBy('id')->firstOrFail();
    }

    /** POST the registration form with the full, valid field set (StudentLineTest). */
    private function register(User $as, array $consultantFields, string $email, int $tier = 2): TestResponse
    {
        static $n = 0;
        $n++;

        return $this->actingAs($as)->post(route('consultant.students.store'), $consultantFields + [
            'name' => 'Student '.$email,
            'email' => $email,
            'phone' => '07733'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'date_of_birth' => '2000-01-01',
            'gender' => 'male',
            'address' => '12 Galle Road',
            'city' => 'Colombo',
            'district' => 'Colombo',
            'nic' => '20033'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'nic_owned_by_student' => 1,
            'course_id' => $this->course()->id,
            'language' => 'Sinhala',
            'class_type' => 'Physical',
            'course_type' => 'Diploma',
            'placement_tier' => $tier,
        ]);
    }

    private function paymentOf(string $email): Payment
    {
        return Payment::whereHas('user', fn ($q) => $q->where('email', $email))->latest('id')->firstOrFail();
    }

    /** SCN-112 registers a student on one of 130's accounts (132 unless told otherwise). */
    private function paidByTop(string $email, ?Consultant $account = null): Payment
    {
        $this->register($this->topUser, [
            'requested_by_consultant_id' => $this->top->id,
            'partner_consultant_id' => ($account ?? $this->holderSg2)->id,
        ], $email)->assertSessionHasNoErrors()->assertRedirect();

        return $this->paymentOf($email);
    }

    /** $poster submits the form naming $requestedBy, on $partner (null = None). Names are this class's properties. */
    private function registerCombination(string $poster, string $requestedBy, ?string $partner, string $email): Payment
    {
        $fields = ['requested_by_consultant_id' => $this->{$requestedBy}->id];

        if ($partner !== null) {
            $fields['partner_consultant_id'] = $this->{$partner}->id;
        }

        $this->register($this->{$poster}->user, $fields, $email)->assertSessionHasNoErrors()->assertRedirect();

        return $this->paymentOf($email);
    }

    private function confirm(User $as, Payment $payment, string $plan = 'full'): TestResponse
    {
        return $this->actingAs($as)->patch(route('consultant.payments.confirm', $payment), [
            'payment_type' => $plan,
            'payment_date' => now()->toDateString(),
            'payment_reference_number' => 'REF-'.$payment->id,
            'payment_receipt' => UploadedFile::fake()->image('slip.jpg'),
        ]);
    }

    private function verify(Payment $payment): void
    {
        app(PaymentService::class)->verify($payment->fresh(), $this->accountant());
    }

    private function payBalance(User $as, Payment $half): TestResponse
    {
        return $this->actingAs($as)->post(route('consultant.payments.pay-balance', $half), [
            'payment_date' => now()->toDateString(),
            'payment_reference_number' => 'BAL-'.$half->id,
            'payment_receipt' => UploadedFile::fake()->image('balance.jpg'),
        ]);
    }

    private function resubmit(User $as, Payment $payment): TestResponse
    {
        return $this->actingAs($as)->post(route('consultant.payments.resubmit', $payment), [
            'payment_type' => 'full',
            'payment_date' => now()->toDateString(),
            'payment_reference_number' => 'RE-'.$payment->id,
            'payment_receipt' => UploadedFile::fake()->image('again.jpg'),
        ]);
    }

    /** Read straight from the table, so a model that never saw the column cannot hide it. */
    private function payerColumn(Payment $payment): ?int
    {
        if (! Schema::hasColumn('payments', RegistrationPayer::COLUMN)) {
            return null;
        }

        $value = DB::table('payments')->where('id', $payment->id)->value(RegistrationPayer::COLUMN);

        return $value === null ? null : (int) $value;
    }

    /** Every account holding a direct bonus row for this payment, any status. */
    private function directAccounts(Payment $payment): array
    {
        return Commission::where('payment_id', $payment->id)
            ->where('type', CommissionService::TYPE_DIRECT)
            ->pluck('consultant_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** The exact form action, so payment 5 can never match payment 52's URL. */
    private function action(string $route, Payment $payment): string
    {
        return 'action="'.route($route, $payment).'"';
    }

    // ----- the money: identical whoever registers ------------------------------

    public static function placements(): array
    {
        return [
            'SG2 account (132), full' => ['sg2', 'full'],
            'SG1 account (131), full' => ['sg1', 'full'],
            'the holder main (130), full' => ['main', 'full'],
            'SG2 account (132), half then balance' => ['sg2', 'half'],
        ];
    }

    /**
     * ⚠️ THE OWNER'S CONDITION, AS AMENDED 2026-11-16. The same student on the
     * same account, registered once by its holder (the old way) and once by
     * SCN-112 (the new way): the 2,500s, seats, company revenue, the student's
     * account and the quota are identical. The ONE money difference is the
     * direct bonus — on the account when its holder registers, on 112's main
     * when 112 does, same amount and status. Each run is rolled back, so both
     * start from the same state.
     */
    #[DataProvider('placements')]
    public function test_only_the_direct_bonus_moves_when_requested_by_registers(string $on, string $plan): void
    {
        $account = match ($on) {
            'sg1' => $this->holderSg1,
            'sg2' => $this->holderSg2,
            'main' => $this->holder,
        };

        $byHolder = $this->isolated(fn () => $this->fullFlow($this->holder, $account, $plan));
        $byTop = $this->isolated(fn () => $this->fullFlow($this->top, $account, $plan));

        $this->assertNotEmpty($byHolder['commissions'], 'guard: the flow really paid something');
        $this->assertNull($byHolder['payer'], 'the holder on their own account records no payer');
        $this->assertSame((int) $this->top->id, $byTop['payer'], 'SCN-112 is recorded as the one who pays');

        // commissions lines read role|type|amount|tier|status — see snapshot().
        $isDirect = fn (string $line) => explode('|', $line)[1] === CommissionService::TYPE_DIRECT;
        $directOf = fn (array $snap) => array_values(array_filter($snap['commissions'], $isDirect));
        $rest = function (array $snap) use ($isDirect) {
            $snap['commissions'] = array_values(array_filter($snap['commissions'], fn ($line) => ! $isDirect($line)));
            unset($snap['payer']);

            return $snap;
        };

        $this->assertCount(1, $directOf($byHolder), 'one direct bonus the old way');
        $this->assertCount(1, $directOf($byTop), 'and one the new way');

        [$oldOn, , $oldAmount, , $oldStatus] = explode('|', $directOf($byHolder)[0]);
        [$newOn, , $newAmount, , $newStatus] = explode('|', $directOf($byTop)[0]);

        $this->assertSame('holder.'.$on, $oldOn, 'the holder registering: the direct bonus is on the account, as always');
        $this->assertSame('top.main', $newOn, 'SCN-112 registering: the direct bonus is on 112\'s main');
        $this->assertSame($oldAmount, $newAmount, 'same amount');
        $this->assertSame($oldStatus, $newStatus, 'same status');

        $this->assertSame($rest($byHolder), $rest($byTop), 'everything else — every 2,500, seat, revenue, quota — is identical');
    }

    /** The owner's own words, account by account. */
    public function test_the_owners_example_climbs_from_the_account_picked(): void
    {
        $payment = $this->paidByTop('owner@example.com');
        $this->confirm($this->topUser, $payment)->assertSessionHasNoErrors();
        $this->verify($payment);

        $rows = Commission::where('payment_id', $payment->id)->whereIn('status', ['pending', 'approved', 'paid'])->get();
        $slot = $rows->where('type', '!=', 'referral');
        $direct = $rows->where('type', 'referral');

        $this->assertEqualsCanonicalizing(
            [(int) $this->holderSg2->id, (int) $this->holder->id, (int) $this->topSg1->id, (int) $this->top->id],
            $slot->pluck('consultant_id')->map(fn ($id) => (int) $id)->values()->all(),
            'one 2,500 each on 132, 130, 113 and 112, and nowhere else',
        );
        $this->assertTrue($slot->every(fn ($row) => (float) $row->amount === 2500.0));

        $this->assertSame([(int) $this->top->id], $direct->pluck('consultant_id')->map(fn ($id) => (int) $id)->values()->all(), 'the direct bonus lands once, on 112 (Requested By) — since 2026-11-16');
        $this->assertSame(4000.0, (float) $direct->first()->amount);

        $this->assertSame(2, (int) $slot->firstWhere('consultant_id', $this->holder->id)->tier, '132 is the SG2 account, so 130 earns on Service Group 2');
    }

    // ----- the direct bonus goes to Requested By (2026-11-16) -------------------

    /** The old rule, untouched: on one's own account the account earns it. */
    public function test_an_own_account_registration_still_pays_the_direct_to_that_account(): void
    {
        $own = $this->registerCombination('top', 'top', 'topSg1', 'own-direct@example.com');
        $this->confirm($this->topUser, $own)->assertSessionHasNoErrors();
        $this->verify($own);

        $this->assertSame([(int) $this->topSg1->id], $this->directAccounts($own));
    }

    /** Requested By = the holder: nobody else pays, so the account earns, as before. */
    public function test_requested_by_the_holder_still_pays_the_direct_to_the_account(): void
    {
        $payment = $this->registerCombination('top', 'holder', 'holderSg2', 'holder-requested@example.com');
        $this->confirm($this->holder->user, $payment)->assertSessionHasNoErrors();
        $this->verify($payment);

        $this->assertSame([(int) $this->holderSg2->id], $this->directAccounts($payment));
    }

    public function test_a_half_payment_pays_the_direct_to_requested_by_and_the_balance_pays_none(): void
    {
        $paid = $this->paidByTop('half-direct@example.com');
        $this->confirm($this->topUser, $paid, 'half')->assertSessionHasNoErrors();
        $this->verify($paid);

        $this->assertSame([(int) $this->top->id], $this->directAccounts($paid), 'the half payment earns 112 the direct bonus');

        $this->payBalance($this->topUser, $paid->fresh())->assertSessionHasNoErrors()->assertSessionMissing('error');
        $balance = Payment::where('user_id', $paid->user_id)->where('payment_type', 'balance')->firstOrFail();
        $this->verify($balance);

        $this->assertSame([], $this->directAccounts($balance), 'the balance earns no second one');
        $this->assertSame([(int) $this->top->id], $this->directAccounts($paid));
    }

    /** Only who receives it moves — a course's own amount (Elliott Wave 5,000) still applies. */
    public function test_a_course_with_its_own_direct_pays_that_amount_to_requested_by(): void
    {
        \App\Services\CourseSchemaRepair::ensure();
        DB::table('courses')->where('id', $this->course()->id)->update(['direct_commission' => 5000]);

        $paid = $this->paidByTop('own-amount@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
        $this->verify($paid);

        $direct = Commission::where('payment_id', $paid->id)->where('type', CommissionService::TYPE_DIRECT)->get();
        $this->assertSame([(int) $this->top->id], $direct->pluck('consultant_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(5000.0, (float) $direct->first()->amount);
    }

    public function test_a_free_card_pays_no_direct_to_anyone(): void
    {
        $paid = $this->paidByTop('free-direct@example.com');
        $this->confirm($this->topUser, $paid, 'free_card')->assertSessionHasNoErrors();
        $this->verify($paid);

        $this->assertSame([], $this->directAccounts($paid));
    }

    public static function selfBuyers(): array
    {
        return [
            'the student is Requested By' => ['top'],
            'the student is the account holder' => ['holder'],
        ];
    }

    /** Nobody is paid for bringing in themselves — the 2026-08-19 rule, for the payer and the holder alike. */
    #[DataProvider('selfBuyers')]
    public function test_a_self_purchase_earns_no_direct(string $who): void
    {
        $paid = $this->paidByTop('self-direct@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
        DB::table('payments')->where('id', $paid->id)->update(['user_id' => $this->{$who}->user_id]);
        $this->verify($paid);

        $this->assertSame([], $this->directAccounts($paid));
    }

    public static function directFallbacks(): array
    {
        return [
            'the Requested By row no longer exists' => ['dangling'],
            'the Requested By login was deleted' => ['trashed'],
            'Requested By is no longer a consultant' => ['demoted'],
            'the recorded payer is the holder themselves' => ['holder'],
        ];
    }

    /** No consultant to pay any more: the account earns it, the rule before 2026-11-16. */
    #[DataProvider('directFallbacks')]
    public function test_a_requested_by_who_is_gone_leaves_the_direct_on_the_account(string $how): void
    {
        $paid = $this->paidByTop('direct-gone@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();

        match ($how) {
            'dangling' => DB::table('payments')->where('id', $paid->id)->update([RegistrationPayer::COLUMN => 999999]),
            'trashed' => $this->topUser->delete(),
            'demoted' => $this->topUser->syncRoles(['student']),
            // store() never records this; a hand-edited row must still not move the bonus to 130's main.
            'holder' => DB::table('payments')->where('id', $paid->id)->update([RegistrationPayer::COLUMN => $this->holder->id]),
        };

        $this->verify($paid);

        $this->assertSame([(int) $this->holderSg2->id], $this->directAccounts($paid));
    }

    /** A suspension does not move money: still 112's, as a suspended holder's would be theirs. */
    public function test_a_suspended_requested_by_keeps_their_direct(): void
    {
        $paid = $this->paidByTop('direct-suspended@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
        DB::table('consultants')->where('id', $this->top->id)->update(['finance_status' => 'suspended']);

        $this->verify($paid);

        $this->assertSame([(int) $this->top->id], $this->directAccounts($paid));
    }

    /**
     * ⚠️ ONE DIRECT PER PAYMENT. Commission Health → Repair replays verified
     * payments through the engine. A payment verified under the 2026-11-03 build
     * already has its direct on 132; the replay must not add a second on 112.
     * And a payment verified under the new rule replays to nothing new.
     */
    public function test_repair_never_pays_a_second_direct(): void
    {
        $old = $this->paidByTop('repair-old@example.com');
        $this->confirm($this->topUser, $old)->assertSessionHasNoErrors();

        // Verified as the 2026-11-03 build did: no payer read, the direct on 132.
        DB::table('payments')->where('id', $old->id)->update([RegistrationPayer::COLUMN => null]);
        $this->verify($old);
        DB::table('payments')->where('id', $old->id)->update([RegistrationPayer::COLUMN => $this->top->id]);
        $this->assertSame([(int) $this->holderSg2->id], $this->directAccounts($old), 'guard: the old build paid 132');

        app(CommissionService::class)->handleVerifiedPayment($old->fresh());
        $this->assertSame([(int) $this->holderSg2->id], $this->directAccounts($old), 'the replay adds no second direct');

        $new = $this->paidByTop('repair-new@example.com');
        $this->confirm($this->topUser, $new)->assertSessionHasNoErrors();
        $this->verify($new);
        $rows = Commission::where('payment_id', $new->id)->count();

        app(CommissionService::class)->handleVerifiedPayment($new->fresh());
        $this->assertSame([(int) $this->top->id], $this->directAccounts($new));
        $this->assertSame($rows, Commission::where('payment_id', $new->id)->count(), 'nothing at all is added on a replay');
    }

    /** Commission Health → Rule Audit reads it as healthy. */
    public function test_the_rule_audit_finds_nothing_wrong_with_it(): void
    {
        $paid = $this->paidByTop('audit-direct@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
        $this->verify($paid);
        $this->assertSame([(int) $this->top->id], $this->directAccounts($paid), 'guard');

        $audit = app(\App\Services\CommissionRuleAudit::class)->run();
        $mine = collect(array_merge($audit['violations'], $audit['notices']))->where('payment_id', $paid->id)->pluck('rule')->all();

        $this->assertSame([], $mine);
    }

    private function isolated(callable $flow): array
    {
        DB::beginTransaction();

        try {
            return $flow();
        } finally {
            DB::rollBack();
            RegistrationPayer::forgetMemo();
        }
    }

    /** Register as $requestedBy on $account, confirm with a slip and verify; a half also pays and verifies the balance. */
    private function fullFlow(Consultant $requestedBy, Consultant $account, string $plan): array
    {
        $as = $requestedBy->user;
        $fields = ['requested_by_consultant_id' => $requestedBy->id];

        if ((int) $account->id !== (int) $requestedBy->id) {
            $fields['partner_consultant_id'] = $account->id;
        }

        $this->register($as, $fields, 'parity@example.com')->assertSessionHasNoErrors()->assertRedirect();
        $payment = $this->paymentOf('parity@example.com');

        $this->confirm($as, $payment, $plan)->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->verify($payment);

        if ($plan === 'half') {
            $this->payBalance($as, $payment->fresh())->assertSessionHasNoErrors()->assertSessionMissing('error');
            $this->verify(Payment::where('user_id', $payment->user_id)->where('payment_type', 'balance')->firstOrFail());
        }

        return $this->snapshot((int) $payment->user_id) + ['payer' => $this->payerColumn($payment)];
    }

    /** Everything the money touched for this student, with every account named by its role. */
    private function snapshot(int $userId): array
    {
        $roles = [
            (int) $this->top->id => 'top.main',
            (int) $this->topSg1->id => 'top.sg1',
            (int) $this->topSg2->id => 'top.sg2',
            (int) $this->holder->id => 'holder.main',
            (int) $this->holderSg1->id => 'holder.sg1',
            (int) $this->holderSg2->id => 'holder.sg2',
        ];
        $role = fn ($id) => $id === null ? 'none' : ($roles[(int) $id] ?? 'other#'.$id);

        $paymentIds = Payment::where('user_id', $userId)->pluck('id');
        $finance = app(ConsultantFinanceService::class);

        return [
            'commissions' => Commission::whereIn('payment_id', $paymentIds)->get()
                ->map(fn (Commission $c) => implode('|', [
                    $role($c->consultant_id), $c->type, number_format((float) $c->amount, 2, '.', ''),
                    (string) $c->tier, (string) $c->status,
                ]))->sort()->values()->all(),
            'payments' => Payment::whereIn('id', $paymentIds)->orderBy('id')->get()
                ->map(fn (Payment $p) => implode('|', [
                    $role($p->consultant_id), (string) $p->payment_type, $p->status,
                    (string) $p->requested_tier, number_format((float) $p->amount, 2, '.', ''),
                ]))->all(),
            'seats' => GroupMember::query()
                ->join('groups', 'groups.id', '=', 'group_members.group_id')
                ->where('group_members.user_id', $userId)
                ->get(['groups.consultant_id', 'group_members.tier'])
                ->map(fn ($seat) => $role($seat->consultant_id).'|'.$seat->tier)->sort()->values()->all(),
            'student_on' => $role(Student::where('user_id', $userId)->value('consultant_id')),
            'company_revenue' => number_format((float) CompanyRevenue::whereIn('payment_id', $paymentIds)->sum('amount'), 2, '.', ''),
            'quota' => [
                'holder' => $finance->countFor($this->holder, FinanceWeek::current()),
                'top' => $finance->countFor($this->top, FinanceWeek::current()),
            ],
        ];
    }

    // ----- what is recorded ----------------------------------------------------

    public function test_requested_by_stays_and_is_recorded_as_the_payer(): void
    {
        $payment = $this->paidByTop('recorded@example.com');
        $student = Student::where('user_id', $payment->user_id)->firstOrFail();

        $this->assertSame((int) $this->holderSg2->id, (int) $payment->consultant_id, 'the payment sits on the account picked');
        $this->assertSame((int) $this->holderSg2->id, (int) $student->consultant_id, 'so does the student — the quota and promotion read this');
        $this->assertSame((int) $this->holderSg2->id, (int) $student->partner_consultant_id);
        $this->assertSame((int) $this->top->id, $this->payerColumn($payment), 'SCN-112 is the one who pays');
    }

    public function test_the_payer_column_is_created_by_the_first_registration_that_needs_it(): void
    {
        $this->assertFalse(Schema::hasColumn('payments', RegistrationPayer::COLUMN), 'guard: a fresh database has no such column');

        $this->paidByTop('first@example.com');

        $this->assertTrue(Schema::hasColumn('payments', RegistrationPayer::COLUMN));
    }

    public static function oldCombinations(): array
    {
        return [
            'own sub account' => ['top', 'top', 'topSg1'],
            'own main (None)' => ['top', 'top', null],
            'Requested By = a downline person, None' => ['top', 'holder', null],
            'a page left open from before: Requested By already flipped to the holder' => ['top', 'holder', 'holderSg1'],
            'the holder on their own account' => ['holder', 'holder', 'holderSg2'],
        ];
    }

    /** ⚠️ Every combination that existed before keeps today's behaviour — it never even adds the column. */
    #[DataProvider('oldCombinations')]
    public function test_an_old_combination_never_creates_the_column(string $poster, string $requestedBy, ?string $partner): void
    {
        // ⚠️ TestCase::setUp() must forget what an earlier test remembered. A stale
        // "ready" here would query a column that is not there — which SQLite
        // silently reads as a string, and MySQL refuses.
        $this->assertFalse(RegistrationPayer::ready(), 'guard: nothing remembered from an earlier test');

        $payment = $this->registerCombination($poster, $requestedBy, $partner, 'old@example.com');
        $this->confirm($this->{$poster}->user, $payment)->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->verify($payment);

        foreach ([$this->topUser, $this->holder->user] as $viewer) {
            foreach (['consultant.students.create', 'consultant.students.index', 'consultant.students.pending'] as $page) {
                $this->actingAs($viewer)->get(route($page))->assertOk();
            }
        }

        $this->assertFalse(Schema::hasColumn('payments', RegistrationPayer::COLUMN), 'an old-style registration never adds the column');
    }

    #[DataProvider('oldCombinations')]
    public function test_an_old_combination_stores_no_payer_even_once_the_column_exists(string $poster, string $requestedBy, ?string $partner): void
    {
        RegistrationPayer::ensure();

        $payment = $this->registerCombination($poster, $requestedBy, $partner, 'old2@example.com');

        $this->assertTrue(Schema::hasColumn('payments', RegistrationPayer::COLUMN), 'guard: the column is there');
        $this->assertNull($this->payerColumn($payment));
    }

    /** ⚠️ Never a silent "the holder pays" — that is the exact thing the owner asked to stop. */
    public function test_a_registration_that_cannot_record_its_payer_is_refused(): void
    {
        DB::connection()->beforeExecuting(function (string $query) {
            $sql = strtolower($query);

            if (str_contains($sql, 'alter table') && str_contains($sql, RegistrationPayer::COLUMN)) {
                throw new \RuntimeException('simulated: the host refused the ALTER');
            }
        });

        $this->register($this->topUser, [
            'requested_by_consultant_id' => $this->top->id,
            'partner_consultant_id' => $this->holderSg2->id,
        ], 'refused@example.com')->assertSessionHasErrors('partner_consultant_id');

        $this->assertFalse(User::where('email', 'refused@example.com')->exists(), 'nothing was saved');
        $this->assertFalse(Schema::hasColumn('payments', RegistrationPayer::COLUMN));
    }

    // ----- which accounts may be picked ----------------------------------------

    /** "Partner = 130 (main), 131 or 132" — the MAIN account of a downline person too. */
    public function test_a_downline_persons_main_account_can_be_the_partner(): void
    {
        $payment = $this->paidByTop('main@example.com', $this->holder);

        $this->assertSame((int) $this->holder->id, (int) $payment->consultant_id);
        $this->assertSame((int) $this->top->id, $this->payerColumn($payment));
        $this->assertSame((int) $this->holder->id, (int) Student::where('user_id', $payment->user_id)->value('partner_consultant_id'));
    }

    /** Requested By = a downline person, on an account further below THEM: that person pays. */
    public function test_a_downline_requested_by_pays_for_their_own_downline(): void
    {
        $deep = $this->consultantUnder($this->holderSg1, 'Deep Person');
        $deepSg1 = $deep->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->register($this->topUser, [
            'requested_by_consultant_id' => $this->holder->id,
            'partner_consultant_id' => $deepSg1->id,
        ], 'deep-paid@example.com')->assertSessionHasNoErrors();

        $payment = $this->paymentOf('deep-paid@example.com');

        $this->assertSame((int) $deepSg1->id, (int) $payment->consultant_id);
        $this->assertSame((int) $this->holder->id, $this->payerColumn($payment));

        $this->confirm($deep->user, $payment)->assertForbidden();
        $this->confirm($this->holder->user, $payment)->assertSessionHasNoErrors()->assertRedirect();

        // Requested By earns the direct bonus, whoever posted the form.
        $this->verify($payment);
        $this->assertSame([(int) $this->holder->id], $this->directAccounts($payment), 'the direct bonus goes to 130, the Requested By');
    }

    public static function outsideTheGroup(): array
    {
        return [
            'Requested By = the holder, on a sibling of the holder' => ['holder', 'sibling'],
            'Requested By = the holder, on an account of 112 above them' => ['holder', 'topSg1'],
            'Requested By = 112, on a stranger' => ['top', 'stranger'],
        ];
    }

    #[DataProvider('outsideTheGroup')]
    public function test_an_account_outside_the_requested_by_group_is_refused(string $requestedBy, string $partner): void
    {
        $account = match ($partner) {
            'topSg1' => $this->topSg1,
            'sibling' => $this->consultantUnder($this->topSg2, 'Sibling')->hiddenAccounts()->orderBy('id')->firstOrFail(),
            'stranger' => $this->consultantUnder(null, 'Stranger')->hiddenAccounts()->orderBy('id')->firstOrFail(),
        };

        $this->register($this->topUser, [
            'requested_by_consultant_id' => $this->{$requestedBy}->id,
            'partner_consultant_id' => $account->id,
        ], 'outside@example.com')->assertSessionHasErrors('partner_consultant_id');

        $this->assertFalse(User::where('email', 'outside@example.com')->exists());
    }

    public static function ownMains(): array
    {
        return [
            '112 on its own main' => ['top'],
            'the holder on its own main' => ['holder'],
        ];
    }

    /** Their own main is "None" — never a partner. */
    #[DataProvider('ownMains')]
    public function test_the_requested_by_own_main_is_not_a_partner(string $requestedBy): void
    {
        $this->register($this->topUser, [
            'requested_by_consultant_id' => $this->{$requestedBy}->id,
            'partner_consultant_id' => $this->{$requestedBy}->id,
        ], 'ownmain@example.com')->assertSessionHasErrors('partner_consultant_id');

        $this->assertFalse(User::where('email', 'ownmain@example.com')->exists());
    }

    /** ⚠️ The browser list and the server rule must agree for every pair. */
    public function test_the_partner_list_offers_downline_mains_and_follows_requested_by(): void
    {
        $this->consultantUnder($this->holderSg1, 'Deep Person');
        $this->consultantUnder($this->topSg2, 'Sibling');

        $response = $this->actingAs($this->topUser)->get(route('consultant.students.create'))->assertOk();
        $options = collect($response->viewData('partnerOptions'));
        $chains = $response->viewData('partnerChains');
        $people = collect($response->viewData('requestedByConsultants'));

        $holderMain = $options->firstWhere('id', (int) $this->holder->id);
        $this->assertNotNull($holderMain, '130, the main account, is offered');
        $this->assertSame((int) $this->holder->id, (int) $holderMain['owner_id']);
        $this->assertTrue($holderMain['is_main']);
        $this->assertNull($options->firstWhere('id', (int) $this->top->id), "112's own main is never a partner: that is None");

        foreach (collect($response->viewData('partnerConsultants')) as $account) {
            $this->assertTrue((bool) $account->is_hidden, 'the sub-account list itself is unchanged');
        }

        $this->assertGreaterThanOrEqual(3, $people->count(), 'guard: 112, the holder and more are offered');

        foreach ($people as $rb) {
            $group = array_merge([(int) $rb->id], array_map('intval', $rb->downlineIds()));

            foreach ($options as $o) {
                $client = (int) $o['id'] !== (int) $rb->id
                    && in_array((int) $rb->id, $chains[(int) $o['owner_id']] ?? [], true);
                $server = in_array((int) $o['owner_id'], $group, true)
                    && ! ($o['is_main'] && (int) $o['owner_id'] === (int) $rb->id);

                $this->assertSame($server, $client, "Requested By {$rb->id} × option {$o['id']}");
            }
        }
    }

    public function test_picking_a_partner_never_moves_requested_by(): void
    {
        $this->actingAs($this->topUser)->get(route('consultant.students.create'))
            ->assertOk()
            ->assertDontSee('requestedBy = Number(opt.owner_id)', false)
            ->assertSee('partnerChains', false);
    }

    // ----- who sees it, who pays it --------------------------------------------

    public function test_the_payer_sees_it_on_pending_students_and_confirms_it(): void
    {
        $paid = $this->paidByTop('pay@example.com');

        $this->actingAs($this->topUser)->get(route('consultant.students.pending'))
            ->assertOk()
            ->assertSee('Student pay@example.com')
            ->assertSee($this->action('consultant.payments.confirm', $paid), false)
            ->assertSee('Paid by you');

        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $this->assertSame('full', $paid->fresh()->payment_type);
    }

    public function test_the_payer_can_resend_and_delete_their_registration(): void
    {
        $paid = $this->paidByTop('resend@example.com');

        $this->actingAs($this->topUser)->post(route('consultant.payments.resend-email', $paid))->assertRedirect();
        $this->actingAs($this->topUser)->delete(route('consultant.payments.destroy', $paid))
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertFalse(User::where('email', 'resend@example.com')->exists(), 'removed like any unpaid registration');
    }

    /** Two rows on every page, so a missing eager load fails here (lazy loading is only refused on a list). */
    public function test_the_holder_sees_it_view_only_with_no_payment_controls(): void
    {
        $holderOwn = $this->registerCombination('holder', 'holder', 'holderSg1', 'holder-own@example.com');
        $topOwn = $this->registerCombination('top', 'top', 'topSg1', 'top-own@example.com');
        $paid = $this->paidByTop('paid@example.com');

        foreach (['consultant.students.pending', 'consultant.students.index'] as $page) {
            $this->actingAs($this->holder->user)->get(route($page))
                ->assertOk()
                ->assertSee('Student paid@example.com')
                ->assertSee('Paid by '.$this->code($this->top))
                ->assertSee('View only')
                // The SCN only: 112 is above 130, outside 130's own network.
                ->assertDontSee($this->topUser->name)
                ->assertDontSee($this->action('consultant.payments.confirm', $paid), false)
                ->assertDontSee($this->action('consultant.payments.resend-email', $paid), false)
                ->assertDontSee($this->action('consultant.payments.destroy', $paid), false)
                ->assertSee($this->action('consultant.payments.confirm', $holderOwn), false);

            $this->actingAs($this->topUser)->get(route($page))
                ->assertOk()
                ->assertSee($this->action('consultant.payments.confirm', $paid), false)
                ->assertSee($this->action('consultant.payments.confirm', $topOwn), false)
                ->assertSee('Paid by you');
        }
    }

    public static function actions(): array
    {
        return [
            'confirm the plan' => ['confirm'],
            're-send the email' => ['resend'],
            'delete the registration' => ['destroy'],
            're-attach a rejected slip' => ['resubmit'],
            'pay the balance' => ['balance'],
        ];
    }

    #[DataProvider('actions')]
    public function test_the_holder_is_refused_every_payment_action(string $action): void
    {
        $paid = $this->paidByTop("refused-{$action}@example.com");
        $holder = $this->holder->user;

        if ($action === 'resubmit') {
            $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
            app(PaymentService::class)->reject($paid->fresh(), $this->accountant(), 'Blurry slip');
        }

        if ($action === 'balance') {
            $this->confirm($this->topUser, $paid, 'half')->assertSessionHasNoErrors();
            $this->verify($paid);
        }

        $before = $paid->fresh()->only(['payment_type', 'status', 'reference_number', 'receipt_path']);

        $response = match ($action) {
            'confirm' => $this->confirm($holder, $paid),
            'resend' => $this->actingAs($holder)->post(route('consultant.payments.resend-email', $paid)),
            'destroy' => $this->actingAs($holder)->delete(route('consultant.payments.destroy', $paid)),
            'resubmit' => $this->resubmit($holder, $paid),
            'balance' => $this->payBalance($holder, $paid->fresh()),
        };

        $response->assertForbidden();

        $this->assertSame($before, $paid->fresh()->only(['payment_type', 'status', 'reference_number', 'receipt_path']), 'nothing changed');
        $this->assertSame(0, Payment::where('user_id', $paid->user_id)->where('payment_type', 'balance')->count(), 'no balance was recorded');
    }

    public function test_a_consultant_between_the_payer_and_the_holder_is_refused_too(): void
    {
        $middle = $this->consultantUnder($this->topSg1, 'Middle Person');
        $deep = $this->consultantUnder($middle->hiddenAccounts()->orderBy('id')->firstOrFail(), 'Deep Person');
        $deepSg2 = $deep->hiddenAccounts()->orderBy('id')->get()[1];

        $paid = $this->paidByTop('between@example.com', $deepSg2);

        $this->confirm($middle->user, $paid)->assertForbidden();
        $this->confirm($deep->user, $paid)->assertForbidden();
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_the_payer_reattaches_a_rejected_slip(): void
    {
        $paid = $this->paidByTop('rejected@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
        app(PaymentService::class)->reject($paid->fresh(), $this->accountant(), 'Blurry slip');

        $this->actingAs($this->topUser)->get(route('consultant.students.pending'))
            ->assertOk()
            ->assertSee($this->action('consultant.payments.resubmit', $paid), false)
            ->assertSee('Blurry slip');

        $this->resubmit($this->topUser, $paid)->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $this->assertSame('pending', $paid->fresh()->status);
    }

    public function test_the_payer_pays_half_then_the_balance_and_the_balance_stays_theirs(): void
    {
        $paid = $this->paidByTop('half@example.com');
        $this->confirm($this->topUser, $paid, 'half')->assertSessionHasNoErrors();
        $this->verify($paid);

        $this->actingAs($this->topUser)->get(route('consultant.students.index'))
            ->assertOk()
            ->assertSee($this->action('consultant.payments.pay-balance', $paid), false);

        $this->actingAs($this->holder->user)->get(route('consultant.students.index'))
            ->assertOk()
            ->assertDontSee($this->action('consultant.payments.pay-balance', $paid), false)
            ->assertSee('Paid by '.$this->code($this->top));

        $this->payBalance($this->topUser, $paid->fresh())->assertSessionHasNoErrors()->assertSessionMissing('error');

        $balance = Payment::where('user_id', $paid->user_id)->where('payment_type', 'balance')->firstOrFail();

        $this->assertSame((int) $this->holderSg2->id, (int) $balance->consultant_id, 'the balance sits on the same account');
        $this->assertSame((int) $this->top->id, $this->payerColumn($balance), 'and is paid by the same consultant');

        $this->actingAs($this->holder->user)->delete(route('consultant.payments.destroy', $balance))->assertForbidden();
    }

    public function test_the_payers_row_shows_the_service_group_before_and_after_verification(): void
    {
        $paid = $this->paidByTop('tier@example.com');

        $this->actingAs($this->topUser)->get(route('consultant.students.pending'))
            ->assertOk()
            ->assertSee('GROUP 02')
            ->assertSee('Pending verification');

        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
        $this->verify($paid);

        $this->actingAs($this->topUser)->get(route('consultant.students.index'))
            ->assertOk()
            ->assertSee('GROUP 02')
            ->assertDontSee('Pending verification')
            ->assertDontSee('Not assigned');
    }

    /**
     * ⚠️ The climb seats the same student on 112's own accounts too, on the legs
     * the climb took there. Here both of those are SG1 while the student sits on
     * 132's SG2 — the row must print the group beside "CON: SCN-132".
     */
    public function test_the_payers_row_shows_the_group_on_the_account_it_was_placed_on(): void
    {
        // 130 sits on 113's Service Group 1 — the seat a real downline gets as a
        // student first — so the climb takes SG1 on 113, and 113 is 112's SG1.
        $group = Group::create([
            'group_code' => 'GRP-T'.Str::upper(Str::random(6)), 'consultant_id' => $this->topSg1->id,
            'status' => 'open', 'filled_slots' => 1, 'tier1_filled' => 1, 'opened_at' => now(),
        ]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->holder->user_id, 'position' => 1, 'tier' => 1, 'joined_at' => now()]);
        $this->assertSame(1, app(CommissionService::class)->sideUnderParent($this->holder->fresh()), 'guard: 130 really sits on 113\'s SG1');

        $paid = $this->paidByTop('placed-on@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
        $this->verify($paid);

        $ownLegs = GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->where('group_members.user_id', $paid->user_id)
            ->whereIn('groups.consultant_id', [$this->top->id, $this->topSg1->id, $this->topSg2->id])
            ->pluck('group_members.tier')->map(fn ($tier) => (int) $tier)->unique()->values()->all();
        $this->assertSame([1], $ownLegs, 'guard: on 112\'s own accounts the climb seated the student on SG1 only');

        $this->actingAs($this->topUser)->get(route('consultant.students.index'))
            ->assertOk()
            ->assertSee('GROUP 02')
            ->assertDontSee('GROUP 01')
            ->assertSee('CON: '.$this->code($this->holderSg2));
    }

    /** The cards describe the table under them (the 2026-08-22 "count 1, empty table" lesson). */
    public function test_the_payers_stats_cards_count_what_their_table_lists(): void
    {
        $this->registerCombination('top', 'top', 'topSg1', 'stats-own@example.com');
        $this->paidByTop('stats-paid@example.com');

        foreach (['consultant.students.pending', 'consultant.students.index'] as $page) {
            $this->assertSame(
                ['total' => 2, 'grouped' => 0, 'pending_payment' => 2],
                $this->actingAs($this->topUser)->get(route($page))->assertOk()->viewData('stats'),
                $page,
            );
        }
    }

    public function test_search_still_filters_both_kinds_of_row(): void
    {
        $this->registerCombination('top', 'top', 'topSg1', 'alpha-own@example.com');
        $this->paidByTop('beta-paid@example.com');

        // The rows themselves — the page also repeats the last success flash.
        $listed = fn (TestResponse $response) => collect($response->assertOk()->viewData('students')->items())
            ->map(fn ($student) => $student->user->email)->sort()->values()->all();

        foreach (['consultant.students.pending', 'consultant.students.index'] as $page) {
            $this->assertSame(['alpha-own@example.com'], $listed($this->actingAs($this->topUser)->get(route($page, ['q' => 'alpha-own']))), $page);
            $this->assertSame(['beta-paid@example.com'], $listed($this->actingAs($this->topUser)->get(route($page, ['q' => 'beta-paid']))), $page);
            $this->assertSame(['alpha-own@example.com', 'beta-paid@example.com'], $listed($this->actingAs($this->topUser)->get(route($page))), $page.' (no search)');
        }
    }

    public function test_the_payer_can_open_the_info_popup_for_that_student(): void
    {
        $paid = $this->paidByTop('info@example.com');
        $studentId = (int) Student::where('user_id', $paid->user_id)->value('id');

        Livewire::actingAs($this->topUser)
            ->test(ScnAccountSummary::class)
            ->call('open', $studentId)
            ->assertOk()
            ->assertSet('studentId', $studentId);
    }

    // ----- quota, notices, admin -----------------------------------------------

    /** The owner's answer: the weekly 2-student count stays with the holder. */
    public function test_the_weekly_quota_still_counts_for_the_holder(): void
    {
        $finance = app(ConsultantFinanceService::class);
        $week = FinanceWeek::current();
        [$holderBefore, $topBefore] = [$finance->countFor($this->holder, $week), $finance->countFor($this->top, $week)];

        $paid = $this->paidByTop('quota@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
        $this->verify($paid);

        $this->assertSame($holderBefore + 1, $finance->countFor($this->holder, $week), 'the student counts for 130');
        $this->assertSame($topBefore, $finance->countFor($this->top, $week), 'and not for 112');
    }

    public function test_the_payer_is_told_when_the_payment_is_verified(): void
    {
        Notification::fake();
        config(['mail.default' => 'array']);
        Mail::purge('array');

        $paid = $this->paidByTop('told@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();
        $this->verify($paid);

        Notification::assertSentToTimes($this->topUser, StudentPaymentConfirmed::class, 1);
        Notification::assertSentToTimes($this->holder->user, StudentPaymentConfirmed::class, 1);

        $sentTo = Mail::mailer('array')->getSymfonyTransport()->messages()
            ->map(fn ($m) => $m->getOriginalMessage())
            ->filter(fn ($email) => str_contains((string) $email->getSubject(), 'Payment Confirmed'))
            ->map(fn ($email) => $email->getTo()[0]->getAddress())
            ->countBy()
            ->all();

        $this->assertSame(1, $sentTo[$this->topUser->email] ?? 0, 'SCN-112 paid, so SCN-112 is told');
        $this->assertSame(1, $sentTo[$this->holder->user->email] ?? 0, 'and 130 is still told, as before');
    }

    public function test_an_own_registration_still_tells_its_consultant_once(): void
    {
        Notification::fake();

        $own = $this->registerCombination('top', 'top', 'topSg1', 'once@example.com');
        $this->confirm($this->topUser, $own)->assertSessionHasNoErrors();
        $this->verify($own);

        Notification::assertSentToTimes($this->topUser, StudentPaymentConfirmed::class, 1);
    }

    public function test_the_payment_queue_says_who_paid(): void
    {
        $paid = $this->paidByTop('queue@example.com');
        $this->confirm($this->topUser, $paid)->assertSessionHasNoErrors();

        Livewire::actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->test(PaymentQueue::class)
            ->assertSee('paid by '.$this->code($this->top))
            ->call('open', $paid->id)
            ->assertSee($this->code($this->top).' — '.$this->topUser->name);
    }

    public function test_the_success_message_says_who_pays_and_which_account_earns(): void
    {
        $this->register($this->topUser, [
            'requested_by_consultant_id' => $this->top->id,
            'partner_consultant_id' => $this->holderSg2->id,
        ], 'flash@example.com')->assertSessionHasNoErrors();

        $flash = (string) session('success');
        $this->assertStringContainsString('Placed on '.$this->code($this->holderSg2), $flash);
        $this->assertStringContainsString($this->code($this->top).' pays for this registration', $flash);
        $this->assertStringContainsString('the direct bonus goes to '.$this->code($this->top), $flash);

        $this->register($this->topUser, [
            'requested_by_consultant_id' => $this->top->id,
            'partner_consultant_id' => $this->topSg1->id,
        ], 'flash-own@example.com')->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('pays for this registration', (string) session('success'));
    }

    // ----- the payer can never strand or widen anything ------------------------

    public static function gonePayers(): array
    {
        return [
            'the payer row no longer exists' => ['dangling'],
            'the payer login was deleted' => ['trashed'],
            'the payer was finance-suspended' => ['suspended'],
            'the payer is no longer a consultant' => ['demoted'],
        ];
    }

    /**
     * A registration is never stranded with nobody allowed to pay it: a payer
     * who can no longer act hands it back to the holder's own rule.
     */
    #[DataProvider('gonePayers')]
    public function test_a_payer_who_is_gone_hands_the_payment_back_to_the_holder(string $how): void
    {
        $paid = $this->paidByTop('gone@example.com');

        match ($how) {
            'dangling' => DB::table('payments')->where('id', $paid->id)->update([RegistrationPayer::COLUMN => 999999]),
            'trashed' => $this->topUser->delete(),
            'suspended' => DB::table('consultants')->where('id', $this->top->id)->update(['finance_status' => 'suspended']),
            'demoted' => $this->topUser->syncRoles(['student']),
        };

        $this->actingAs($this->holder->user)->get(route('consultant.students.pending'))
            ->assertOk()
            ->assertSee($this->action('consultant.payments.confirm', $paid), false);

        $this->confirm($this->holder->user, $paid)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('full', $paid->fresh()->payment_type);
    }

    /**
     * ⚠️ Deleting a person takes ALL their payments with them (cascade). The
     * holder must not be able to take the registration 112 pays for down by
     * deleting another unpaid registration of the same student.
     */
    public function test_the_holder_cannot_take_a_paid_registration_down_with_another_one(): void
    {
        $paid = $this->paidByTop('two-courses@example.com');

        $secondCourse = $this->course()->replicate();
        $secondCourse->slug = 'second-course-'.Str::lower(Str::random(6));
        $secondCourse->title = 'Second Course';
        $secondCourse->save();

        // The same student, a second course, placed on 131 with nobody else paying.
        $own = Payment::create([
            'payment_code' => 'PYM-'.Str::upper(Str::random(8)), 'user_id' => $paid->user_id,
            'course_id' => $secondCourse->id, 'consultant_id' => $this->holderSg1->id, 'requested_tier' => 1,
            'amount' => 0, 'currency' => 'LKR', 'method' => 'bank_transfer', 'status' => 'pending',
        ]);

        $this->actingAs($this->holder->user)->delete(route('consultant.payments.destroy', $own))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'paid for by another consultant'));

        $this->assertFalse(Payment::whereKey($own->id)->exists(), 'their own registration is removed');
        $this->assertTrue(Payment::whereKey($paid->id)->exists(), 'the one 112 pays for is untouched');
        $this->assertTrue(User::whereKey($paid->user_id)->exists(), 'and so is the student');
    }

    /** Paying for one student on 132 and another on 130 never reads the first one's climb seat on 130. */
    public function test_each_paid_student_reads_the_seat_on_their_own_account(): void
    {
        $this->register($this->topUser, [
            'requested_by_consultant_id' => $this->top->id,
            'partner_consultant_id' => $this->holderSg2->id,
        ], 'on-132@example.com', 1)->assertSessionHasNoErrors();
        $on132 = $this->paymentOf('on-132@example.com');
        $on130 = $this->paidByTop('on-130@example.com', $this->holder);

        foreach ([$on132, $on130] as $payment) {
            $this->confirm($this->topUser, $payment)->assertSessionHasNoErrors();
            $this->verify($payment);
        }

        $climbSeat = GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->where('group_members.user_id', $on132->user_id)
            ->where('groups.consultant_id', $this->holder->id)
            ->value('group_members.tier');
        $this->assertSame(2, (int) $climbSeat, 'guard: the climb seated the 132 student on 130 on SG2');

        $tiers = $this->actingAs($this->topUser)->get(route('consultant.students.index'))->assertOk()->viewData('tiers');

        $this->assertSame(1, (int) $tiers[$on132->user_id], 'that student sits on SG1 of 132');
    }

    /** A remembered "ready" outliving the column (a restored backup) never 500s the lists. */
    public function test_a_stale_ready_never_breaks_the_lists(): void
    {
        $this->assertFalse(Schema::hasColumn('payments', RegistrationPayer::COLUMN), 'guard');
        (new \ReflectionProperty(RegistrationPayer::class, 'memo'))->setValue(null, true);

        // MySQL refuses a query on a missing column; SQLite would quietly read it
        // as a string — so refuse it here the way MySQL does.
        DB::connection()->beforeExecuting(function (string $query) {
            if (str_contains($query, RegistrationPayer::COLUMN) && str_starts_with(strtolower(ltrim($query)), 'select')) {
                throw new \RuntimeException('simulated: Unknown column '.RegistrationPayer::COLUMN);
            }
        });

        foreach (['consultant.students.index', 'consultant.students.pending'] as $page) {
            $this->actingAs($this->topUser)->get(route($page))->assertOk();
        }
    }

    /** ...and never sends a registration's write at a column that is not there. */
    public function test_a_stale_ready_never_breaks_a_registration(): void
    {
        (new \ReflectionProperty(RegistrationPayer::class, 'memo'))->setValue(null, true);

        $payment = $this->paidByTop('stale@example.com');

        $this->assertSame((int) $this->top->id, $this->payerColumn($payment));
    }

    /** The column only ever NARROWS who may act — today's boundary still applies first. */
    public function test_the_payer_rule_never_widens_todays_boundary(): void
    {
        $paid = $this->paidByTop('moved@example.com');
        $strangerSg1 = $this->consultantUnder(null, 'Stranger')->hiddenAccounts()->orderBy('id')->firstOrFail();

        DB::table('payments')->where('id', $paid->id)->update(['consultant_id' => $strangerSg1->id]);

        $this->confirm($this->topUser, $paid)->assertForbidden();
    }

    /** A checkout re-submit that moves the payment elsewhere takes the payer off it. */
    public function test_moving_the_payment_to_another_account_clears_its_payer(): void
    {
        $paid = $this->paidByTop('repoint@example.com');
        $strangerSg1 = $this->consultantUnder(null, 'Stranger')->hiddenAccounts()->orderBy('id')->firstOrFail();

        app(EnrollmentService::class)->enroll($paid->user, $this->course(), $strangerSg1, ['requested_tier' => 1]);

        $paid->refresh();

        $this->assertSame((int) $strangerSg1->id, (int) $paid->consultant_id, 'guard: it really moved');
        $this->assertNull($this->payerColumn($paid), 'whoever paid for 132 did not agree to pay for this account');
    }

    public function test_a_resubmit_to_the_same_account_keeps_its_payer(): void
    {
        $paid = $this->paidByTop('same@example.com');

        app(EnrollmentService::class)->enroll($paid->user, $this->course(), $this->holderSg2, ['requested_tier' => 2]);

        $this->assertSame((int) $this->top->id, $this->payerColumn($paid));
    }
}
