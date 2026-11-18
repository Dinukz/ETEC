<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Student;
use App\Models\User;
use App\Services\HiddenAccountService;
use App\Services\NumberPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ ONE NUMBER POOL: A STUDENT'S SRN BECOMES THEIR SCN (2026-08-31).
 *
 * Owner: "Student lage hadana SRN number eka thama issrahat SCN number ekak
 * widiyta convert wenne one, e widiyt hadann. SCN number 3k issrahat gannwa
 * wage, SRN eketh number 3k gann one — mokada SCN number eka weddi eka laga
 * account 3k enn epai."
 *
 * Before this, SRN counted from 1 and SCN from 100 as two unrelated sequences,
 * so a promoted student could never keep their number and SRN-00100 was a
 * different person from SCN-00100.
 *
 * ⚠️ FORWARD ONLY. Nothing already issued is renumbered — an SRN or SCN is how
 * a person is identified on receipts and to each other. Existing pairs stay
 * mismatched; only numbers issued from now on line up. That is asserted below,
 * because renumbering history is the tempting "fix" and it would be a disaster.
 */
class SrnBecomesTheScnNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function makeStudent(): User
    {
        $user = User::factory()->create();

        Student::create([
            'user_id' => $user->id,
            'student_code' => NumberPool::format('SRN', NumberPool::take()),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function srnOf(User $user): string
    {
        return Student::where('user_id', $user->id)->value('student_code');
    }

    private function promote(User $user): Consultant
    {
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    // ---- The pool -----------------------------------------------------------

    public function test_a_student_reserves_three_numbers(): void
    {
        $first = NumberPool::take();
        $second = NumberPool::take();

        $this->assertSame(3, $second - $first, 'the next identity starts three along');
    }

    public function test_it_never_issues_a_number_already_in_use(): void
    {
        // The seeder has already created consultants, so the pool must start
        // above them rather than colliding.
        $next = NumberPool::peek();

        $this->assertFalse(
            Consultant::where('scn_code', NumberPool::format('SCN', $next))->exists(),
            'the first number the pool would hand out is free',
        );
        $this->assertGreaterThanOrEqual(NumberPool::FLOOR, $next, 'and never below the SCN floor of 100');
    }

    // ---- The promise --------------------------------------------------------

    /** ⚠️ THE HEADLINE: SRN-00xxx becomes SCN-00xxx. */
    public function test_a_promoted_student_keeps_their_number(): void
    {
        $user = $this->makeStudent();
        $srn = $this->srnOf($user);

        $consultant = $this->promote($user);

        $this->assertSame(
            NumberPool::numberIn($srn),
            NumberPool::numberIn($consultant->scn_code),
            $srn.' must become '.str_replace('SRN', 'SCN', $srn),
        );
    }

    /** ...and the two reserved numbers become their hidden accounts. */
    public function test_their_two_accounts_take_the_reserved_numbers(): void
    {
        $user = $this->makeStudent();
        $number = NumberPool::numberIn($this->srnOf($user));

        $consultant = $this->promote($user);
        $hidden = $consultant->hiddenAccounts()->orderBy('id')->get();

        $this->assertCount(2, $hidden, 'guard: a consultant is created with two');
        $this->assertSame(NumberPool::format('SCN', $number + 1), $hidden[0]->scn_code);
        $this->assertSame(NumberPool::format('SCN', $number + 2), $hidden[1]->scn_code);
    }

    /** Two students in a row do not tread on each other's reserved block. */
    public function test_two_students_promoted_in_order_get_separate_blocks(): void
    {
        $a = $this->makeStudent();
        $b = $this->makeStudent();

        $first = $this->promote($a);
        $second = $this->promote($b);

        $codes = collect([$first])->concat($first->hiddenAccounts)
            ->concat([$second])->concat($second->hiddenAccounts)
            ->pluck('scn_code');

        $this->assertSame(
            $codes->unique()->count(),
            $codes->count(),
            'six accounts, six different numbers',
        );
    }

    /** A bought set is consecutive too. */
    public function test_a_bought_set_takes_three_consecutive_numbers(): void
    {
        $consultant = $this->promote($this->makeStudent());

        $set = app(HiddenAccountService::class)->addAccountSet($consultant);

        $numbers = $set->map(fn ($a) => NumberPool::numberIn($a->scn_code))->values()->all();

        $this->assertCount(3, $numbers);
        $this->assertSame($numbers[0] + 1, $numbers[1], 'consecutive');
        $this->assertSame($numbers[0] + 2, $numbers[2]);
    }

    // ---- What must NOT change -----------------------------------------------

    /**
     * ⚠️ FORWARD ONLY. The seeded consultants predate the pool; their codes must
     * survive untouched. Renumbering them would rewrite the identity on every
     * receipt and report already issued.
     */
    public function test_existing_codes_are_never_renumbered(): void
    {
        $before = Consultant::whereNotNull('scn_code')->pluck('scn_code', 'id')->all();

        $this->promote($this->makeStudent());
        app(HiddenAccountService::class)->addAccountSet(
            Consultant::whereNotNull('scn_code')->whereNull('owner_consultant_id')->firstOrFail()
        );

        foreach ($before as $id => $code) {
            $this->assertSame($code, Consultant::find($id)->scn_code, 'account #'.$id.' kept its number');
        }
    }

    /**
     * A consultant with no student record still works — they simply take a
     * fresh block instead of keeping a number they never had.
     */
    public function test_a_consultant_with_no_student_record_still_gets_a_block(): void
    {
        $user = User::factory()->create();
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);
        $consultant = $consultant->fresh();

        $number = NumberPool::numberIn($consultant->scn_code);

        $this->assertNotNull($number);
        $this->assertSame(
            NumberPool::format('SCN', $number + 1),
            $consultant->hiddenAccounts()->orderBy('id')->first()->scn_code,
        );
    }

    // ---- What people SEE ----------------------------------------------------

    /**
     * ⚠️ "SRN number eka change wenwa SCN 100 widiyta" — once promoted, the SCN
     * is the number they are known by. The `student_code` column is untouched
     * so receipts and history still resolve; it just stops being displayed.
     */
    public function test_a_promoted_student_is_shown_their_scn_not_their_srn(): void
    {
        $user = $this->makeStudent();
        $srn = $this->srnOf($user);

        $this->assertSame($srn, $user->fresh()->displayCode(), 'as a student, the SRN');

        $consultant = $this->promote($user);

        $this->assertSame(
            $consultant->scn_code,
            $user->fresh()->displayCode(),
            'as a consultant, the SCN',
        );
        $this->assertStringStartsWith('SCN-', $user->fresh()->displayCode());
    }

    /** ⚠️ The SRN row itself is never rewritten — it is history. */
    public function test_the_student_code_column_is_left_alone(): void
    {
        $user = $this->makeStudent();
        $srn = $this->srnOf($user);

        $this->promote($user);

        $this->assertSame($srn, $this->srnOf($user), 'the stored code is untouched');
    }

    /** Someone who is only a student still shows their SRN. */
    public function test_a_plain_student_still_shows_their_srn(): void
    {
        $user = $this->makeStudent();

        $this->assertStringStartsWith('SRN-', $user->fresh()->displayCode());
    }
}
