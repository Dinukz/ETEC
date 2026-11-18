<?php

namespace Tests\Feature;

use App\Livewire\ScnAccountSummary;
use App\Livewire\UserAccountSummary;
use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\CommissionRuleAudit;
use App\Services\CommissionService;
use App\Services\PaymentService;
use App\Services\StudentLine;
use App\Services\UplineContinuity;
use App\Services\UplineLegAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ⚠️ THE OWNER'S EXAMPLE, LITERALLY (2026-10-01).
 *
 * *"SCN 112 account eken add kranawa student la 10k … 5 weniya inne consultant
 * … 6 saha 7 student la 8 weniya consultant una 9 saha 10 student la. dn 8
 * weniyta inn consultant student lawa add kraddi ethan idn upline ekat add wenn
 * one. 6 saha 7 add wen na eya student la nisa 5 weniyta 2500 calculate wenwa
 * ita passe 1,2,3,4 ewa student la nisa ewata calculate wen na ita passe
 * udatama SCN 112 ekata 2500 add wenwa."*
 *
 * A = the seeded consultant (the owner's SCN-112). His Service Group 1 account
 * (the owner's SCN-113) registers ten students on Service Group 1 — one line.
 * The rule: a student promoted later hangs under the nearest consultant ABOVE
 * them on that line, so #8's students pay #5 and then A, exactly as before.
 *
 * Owner's answers: each Service Group is its own line; no spillover (A's own
 * later students pay #5/#8 nothing); existing people are DISPLAY ONLY — nobody
 * already promoted is ever moved. And: "parana logic tikata mukuth nokara" —
 * A and everyone above must be credited exactly as before.
 */
class StudentLineTest extends TestCase
{
    use RefreshDatabase;

    private User $aUser;

    private Consultant $a;

    /** A's Service Group 1 account — the account the line belongs to. */
    private Consultant $lineAccount;

    /** @var array<int, User> the ten students, 1-based */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
        Cache::forever('settings.mail.grouped', collect());
        \Illuminate\Support\Facades\Storage::fake('public');

        $this->aUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->a = $this->aUser->consultant;
        $this->lineAccount = $this->a->hiddenAccounts()->orderBy('id')->firstOrFail();
    }

    // ----- helpers -------------------------------------------------------------

    /** Register a student through $by's $partner account on $tier, confirm and verify. */
    private function addStudent(User $by, Consultant $partner, int $tier, string $email): User
    {
        static $n = 0;
        $n++;

        $this->actingAs($by)->post(route('consultant.students.store'), [
            'name' => 'Student '.$email,
            'email' => $email,
            'phone' => '07712'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'date_of_birth' => '2000-01-01',
            'gender' => 'male',
            'address' => '12 Galle Road',
            'city' => 'Colombo',
            'district' => 'Colombo',
            'nic' => '20001'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'nic_owned_by_student' => 1,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'language' => 'Sinhala',
            'class_type' => 'Physical',
            'course_type' => 'Diploma',
            'placement_tier' => $tier,
            'partner_consultant_id' => $partner->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $payment = Payment::whereHas('user', fn ($q) => $q->where('email', $email))->latest('id')->firstOrFail();

        $this->actingAs($by)
            ->patch(route('consultant.payments.confirm', $payment), [
                'payment_type' => 'full',
                'payment_date' => now()->toDateString(),
                'payment_reference_number' => 'REF-'.$n,
                'payment_receipt' => \Illuminate\Http\UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error')
            ->assertRedirect();

        app(PaymentService::class)->verify($payment->fresh(), User::where('email', 'accountant@etec.lk')->firstOrFail());

        return User::where('email', $email)->firstOrFail();
    }

    /** The owner's line: ten students on A's Service Group 1 account, Service Group 1. */
    private function tenOnTheLine(): void
    {
        foreach (range(1, 10) as $n) {
            $this->s[$n] = $this->addStudent($this->aUser, $this->lineAccount, 1, "line{$n}@example.com");
        }
    }

    private function promote(User $student): Consultant
    {
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('users.role', $student), ['role' => 'consultant'])
            ->assertRedirect();

        $student->forceFill(['password_must_change' => false])->save();

        return $student->fresh()->consultant;
    }

    private function sg1(Consultant $person): Consultant
    {
        return $person->hiddenAccounts()->orderBy('id')->firstOrFail();
    }

    /** consultant_id => tier of every live 2,500 a payment wrote. */
    private function slotsFor(User $student): array
    {
        $payment = Payment::where('user_id', $student->id)->orderBy('id')->firstOrFail();

        return Commission::where('payment_id', $payment->id)
            ->where('type', CommissionService::TYPE_SLOT)
            ->whereNull('removed_at')
            ->where('amount', '>', 0)
            ->get()
            ->mapWithKeys(fn ($c) => [(int) $c->consultant_id => (int) $c->tier])
            ->all();
    }

    private function accountIdsOf(Consultant $person): array
    {
        return array_map('intval', $person->accountIds());
    }

    // ----- the owner's example -------------------------------------------------

    public function test_eight_hangs_under_five_and_the_money_climbs_8_5_then_112(): void
    {
        $this->tenOnTheLine();
        $five = $this->promote($this->s[5]);
        $eight = $this->promote($this->s[8]);

        $this->assertSame((int) $this->lineAccount->id, (int) $five->parent_consultant_id, '#5 has nobody above on the line — as before');
        $this->assertSame((int) $five->id, (int) $eight->parent_consultant_id, '#8 hangs under #5\'s main');
        $this->assertSame(1, (int) $eight->placement_tier, 'on #5\'s Service Group 1 — the side the line is on');

        $x = $this->addStudent($this->s[8], $this->sg1($eight), 1, 'eights-student@example.com');
        $slots = $this->slotsFor($x);

        $this->assertSame(1, $slots[(int) $five->id] ?? null, '#5 earns one 2,500, on Service Group 1');
        $this->assertSame(1, $slots[(int) $this->lineAccount->id] ?? null, 'the line\'s account (SCN-113) still earns');
        $this->assertSame(1, $slots[(int) $this->a->id] ?? null, 'and SCN-112 at the top still earns');

        foreach ([6, 7, 1, 2, 3, 4] as $n) {
            $this->assertNull($this->s[$n]->fresh()->consultant, "#{$n} is a student — no account, nothing to credit");
        }
    }

    /**
     * ⚠️ "PARANA LOGIC" — A's side must be exactly what it was. Same scenario
     * with the rule switched off: A's accounts get the same rows on the same legs.
     */
    public function test_the_account_above_is_credited_exactly_as_without_the_rule(): void
    {
        Setting::set(StudentLine::ATTACH_KEY, '0');
        $this->tenOnTheLine();
        $this->promote($this->s[5]);
        $eight = $this->promote($this->s[8]);

        $this->assertSame((int) $this->lineAccount->id, (int) $eight->parent_consultant_id, 'switched off: as before');

        $x = $this->addStudent($this->s[8], $this->sg1($eight), 1, 'eights-student@example.com');
        $slots = $this->slotsFor($x);

        $this->assertSame(1, $slots[(int) $this->lineAccount->id] ?? null);
        $this->assertSame(1, $slots[(int) $this->a->id] ?? null);
        $this->assertArrayNotHasKey((int) $this->s[5]->fresh()->consultant->id, $slots, 'without the rule #5 is skipped — the bug');
    }

    /** No spillover: A's OWN later student pays #5 and #8 nothing. */
    public function test_a_student_the_account_adds_itself_pays_nobody_on_the_line(): void
    {
        $this->tenOnTheLine();
        $five = $this->promote($this->s[5]);
        $eight = $this->promote($this->s[8]);

        $eleven = $this->addStudent($this->aUser, $this->lineAccount, 1, 'line11@example.com');
        $slots = $this->slotsFor($eleven);

        $this->assertEmpty(array_intersect(array_keys($slots), array_merge($this->accountIdsOf($five), $this->accountIdsOf($eight))));
        $this->assertArrayHasKey((int) $this->lineAccount->id, $slots);
    }

    public function test_three_five_and_eight_chain_up_the_line(): void
    {
        $this->tenOnTheLine();
        $three = $this->promote($this->s[3]);
        $five = $this->promote($this->s[5]);
        $eight = $this->promote($this->s[8]);

        $this->assertSame((int) $three->id, (int) $five->parent_consultant_id);
        $this->assertSame((int) $five->id, (int) $eight->parent_consultant_id);

        $slots = $this->slotsFor($this->addStudent($this->s[8], $this->sg1($eight), 1, 'eights-student@example.com'));

        foreach ([$five, $three, $this->lineAccount, $this->a] as $earner) {
            $this->assertSame(1, $slots[(int) $earner->id] ?? null, ($earner->scn_code ?? $earner->id).' earns on Service Group 1');
        }
    }

    // ----- nothing existing moves ---------------------------------------------

    public function test_promoting_someone_above_never_moves_a_consultant_already_promoted(): void
    {
        $this->tenOnTheLine();
        $eight = $this->promote($this->s[8]);
        $this->promote($this->s[5]);

        $this->assertSame((int) $this->lineAccount->id, (int) $eight->fresh()->parent_consultant_id, '#8 came first and stays where it was');
    }

    public function test_the_other_service_group_is_another_line(): void
    {
        $this->tenOnTheLine();
        $this->promote($this->s[5]);

        $other = $this->addStudent($this->aUser, $this->lineAccount, 2, 'other-side@example.com');
        $promoted = $this->promote($other);

        $this->assertSame((int) $this->lineAccount->id, (int) $promoted->parent_consultant_id);
    }

    public function test_with_no_consultant_above_nothing_changes(): void
    {
        $this->tenOnTheLine();
        $eight = $this->promote($this->s[8]);

        $this->assertSame((int) $this->lineAccount->id, (int) $eight->parent_consultant_id);
    }

    // ----- the guards: anything unproven keeps the old path --------------------

    public function test_a_line_consultant_moved_elsewhere_is_not_used(): void
    {
        $this->tenOnTheLine();
        $five = $this->promote($this->s[5]);
        $five->update(['parent_consultant_id' => $this->a->id]); // moved by hand, off the line account

        $eight = $this->promote($this->s[8]);

        $this->assertSame((int) $this->lineAccount->id, (int) $eight->parent_consultant_id);
    }

    public function test_a_line_consultant_whose_seat_is_on_the_other_side_is_not_used(): void
    {
        $this->tenOnTheLine();
        $five = $this->promote($this->s[5]);

        // #5's money would reach SCN-113 on side 2, #8's on side 1 — attaching
        // would move #8's money to the other side of A's account.
        GroupMember::where('user_id', $five->user_id)
            ->whereIn('group_id', $this->lineAccount->groups()->pluck('id'))
            ->update(['tier' => 2]);

        $eight = $this->promote($this->s[8]);

        $this->assertSame((int) $this->lineAccount->id, (int) $eight->parent_consultant_id);
    }

    public function test_a_chain_too_close_to_the_depth_limit_is_not_lengthened(): void
    {
        $this->tenOnTheLine();
        $this->promote($this->s[5]);

        // Hang A under a chain of 45 people: #5 is then ~46 hops from the top.
        $above = null;
        foreach (range(1, 45) as $i) {
            $user = User::factory()->create();
            $person = Consultant::create([
                'user_id' => $user->id, 'parent_consultant_id' => $above?->id,
                'consultant_code' => 'CHAIN'.$i, 'referral_code' => 'CHAINREF'.$i, 'status' => 'active',
            ]);
            $above = $person;
        }
        $this->a->update(['parent_consultant_id' => $above->id]);

        $eight = $this->promote($this->s[8]);

        $this->assertSame((int) $this->lineAccount->id, (int) $eight->parent_consultant_id);
    }

    public function test_the_switch_turns_the_rule_off(): void
    {
        Setting::set(StudentLine::ATTACH_KEY, '0');
        $this->tenOnTheLine();
        $this->promote($this->s[5]);

        $this->assertSame((int) $this->lineAccount->id, (int) $this->promote($this->s[8])->parent_consultant_id);
    }

    /** The account's own holder buying on it is never "on the line". */
    public function test_the_accounts_own_holder_is_not_a_line_consultant(): void
    {
        Payment::create([
            'payment_code' => 'PYM-SELF1', 'user_id' => $this->aUser->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $this->lineAccount->id, 'requested_tier' => 1,
            'amount' => 0, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'free_card', 'status' => 'pending',
        ]);
        $this->tenOnTheLine();

        $eight = $this->promote($this->s[8]);

        $this->assertSame((int) $this->lineAccount->id, (int) $eight->parent_consultant_id);

        // ...and they are not shown standing on it either.
        $line = $this->staffLine($this->s[1]);
        $this->assertSame(1, $line['position'], 'the first student is first — the holder is not ahead of them');
        $this->assertSame(10, $line['total']);
    }

    /** #5 bought a course on their own account — their student row moved, the line did not. */
    public function test_a_line_consultant_who_bought_a_course_is_still_found(): void
    {
        $this->tenOnTheLine();
        $five = $this->promote($this->s[5]);
        Student::where('user_id', $five->user_id)->update(['consultant_id' => $this->sg1($five)->id]);

        $this->assertSame((int) $five->id, (int) $this->promote($this->s[8])->parent_consultant_id);
    }

    // ----- everything else agrees with the new place ---------------------------

    public function test_the_audits_find_nothing_wrong_afterwards(): void
    {
        $this->tenOnTheLine();
        $this->promote($this->s[5]);
        $eight = $this->promote($this->s[8]);
        $this->addStudent($this->s[8], $this->sg1($eight), 1, 'eights-student@example.com');

        $this->assertCount(0, app(UplineLegAudit::class)->mismatches());

        $violations = collect(app(CommissionRuleAudit::class)->run()['violations'])
            ->filter(fn ($v) => str_starts_with((string) ($v['rule'] ?? ''), 'upline'));
        $this->assertCount(0, $violations, $violations->toJson());
    }

    public function test_deleting_five_lifts_eight_back_onto_the_line_account_on_the_same_side(): void
    {
        $this->tenOnTheLine();
        $five = $this->promote($this->s[5]);
        $eight = $this->promote($this->s[8]);

        UplineContinuity::liftDownlineOf($five);

        $eight->refresh();
        $this->assertSame((int) $this->lineAccount->id, (int) $eight->parent_consultant_id);
        $this->assertSame(1, app(CommissionService::class)->sideUnderParent($eight));
    }

    // ----- the Line block in the popup -------------------------------------------

    private function staffLine(User $subject): array
    {
        return Livewire::actingAs(User::role('super_admin')->firstOrFail())
            ->test(UserAccountSummary::class)
            ->call('openUser', $subject->id)
            ->call('loadDeep')
            ->get('deep')['line'];
    }

    public function test_staff_see_the_whole_line_with_students_and_consultants(): void
    {
        $this->tenOnTheLine();
        $this->promote($this->s[5]);
        $this->promote($this->s[8]);

        $line = $this->staffLine($this->s[5]);

        $this->assertSame((int) $this->lineAccount->id, $line['account_id']);
        $this->assertSame(1, $line['leg']);
        $this->assertSame(5, $line['position']);
        $this->assertSame(10, $line['total']);

        $this->assertSame(['Student line4@example.com', 'Student line3@example.com', 'Student line2@example.com', 'Student line1@example.com'],
            array_column($line['above'], 'name'), 'nearest first');
        $this->assertSame([false, false, true, false, false], array_column($line['below'], 'is_consultant'), '6,7 students · 8 consultant · 9,10 students');
        $this->assertSame('Student line8@example.com', $line['below'][2]['name']);
    }

    public function test_a_plain_student_sees_their_place_on_the_line_too(): void
    {
        $this->tenOnTheLine();

        $line = $this->staffLine($this->s[7]);

        $this->assertSame(7, $line['position']);
        $this->assertCount(6, $line['above']);
        $this->assertCount(3, $line['below']);
    }

    /**
     * ⚠️ NO NAMES FROM OUTSIDE THE VIEWER'S NETWORK. Y joined on A's line, then
     * bought a course placed on #5's account — so #5 may open Y's popup, but
     * A's other students are not #5's to read.
     */
    public function test_a_consultant_sees_no_names_from_a_line_outside_their_network(): void
    {
        $this->tenOnTheLine();
        $five = $this->promote($this->s[5]);

        Payment::create([
            'payment_code' => 'PYM-LATER', 'user_id' => $this->s[9]->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $this->sg1($five)->id, 'requested_tier' => 1,
            'amount' => 0, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'free_card', 'status' => 'pending',
        ]);

        $deep = Livewire::actingAs($five->user)
            ->test(ScnAccountSummary::class)
            ->call('open', Student::where('user_id', $this->s[9]->id)->value('id'))
            ->call('loadDeep')
            ->get('deep');

        $line = $deep['line'];
        $this->assertSame(9, $line['position']);

        $visibleNames = collect($line['above'])->merge($line['below'])->where('visible', true)->pluck('name')->all();
        $this->assertSame([], array_values(array_diff($visibleNames, ['Student line5@example.com'])), 'only #5 — the viewer — is theirs to name');
        $this->assertNotContains('Student line4@example.com', collect($line['above'])->pluck('name')->filter()->all());
    }

    public function test_the_line_loads_only_with_the_heavy_part(): void
    {
        $this->tenOnTheLine();

        $component = Livewire::actingAs(User::role('super_admin')->firstOrFail())
            ->test(UserAccountSummary::class)
            ->call('openUser', $this->s[5]->id);

        $this->assertArrayNotHasKey('line', (array) $component->get('deep'));
    }
}
