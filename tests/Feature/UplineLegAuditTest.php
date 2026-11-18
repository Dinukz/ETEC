<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\UplineLegAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Correcting the leg RULE cannot move money already written.
 *
 * A 2,500 recorded against tier 2 stays on tier 2 for ever, so after the
 * 2026-08-20 rule fix the owner still saw wrong totals — those were historical
 * rows. This audit finds them and moves them, which is the only way the
 * existing figures can come right.
 */
class UplineLegAuditTest extends TestCase
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
    }

    private function addStudent(Consultant $account, int $leg): User
    {
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

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $user;
    }

    private function promote(User $user, Consultant $sponsorAccount): Consultant
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

    /**
     * Build the owner's exact scenario, then force the row onto the wrong leg
     * the way the old rule did, and prove the audit puts it back.
     */
    public function test_it_finds_and_corrects_an_upline_row_on_the_wrong_leg(): void
    {
        $sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        $firstPlacement = $sponsor->hiddenAccounts()->orderBy('id')->first();

        // The downline sits on this account's leg 1, so every 2,500 that climbs
        // through them belongs on leg 1 of this account.
        $downlineUser = $this->addStudent($firstPlacement, 1);
        $downline = $this->promote($downlineUser, $firstPlacement);

        $this->addStudent($downline->hiddenAccounts()->first(), 1);

        // The engine now writes this correctly, so simulate the OLD rule by
        // shoving the row (and its seat) onto leg 2 — what production holds.
        $row = Commission::where('consultant_id', $firstPlacement->id)
            ->where('type', 'bonus')
            ->whereNull('released_at')
            ->latest('id')
            ->firstOrFail();

        Commission::whereKey($row->id)->update(['tier' => 2]);
        if ($row->group_member_id) {
            GroupMember::whereKey($row->group_member_id)->update(['tier' => 2]);
        }

        $audit = app(UplineLegAudit::class);

        $found = $audit->mismatches();
        $this->assertCount(1, $found, 'The wrongly-legged row is detected.');
        $this->assertSame(2, $found[0]['recorded']);
        $this->assertSame(1, $found[0]['expected'], 'SCN-00101 IS the sponsor\'s Service Group 1.');

        $result = $audit->repair();
        $this->assertSame(1, $result['corrected']);

        $this->assertSame(1, (int) $row->fresh()->tier, 'The money moved to leg 1.');

        // ⚠️ INVERTED 2026-09-01. This asserted the SEAT moved with the money,
        // on the reasoning that screens count seats and the run counts money.
        //
        // That held only while a commission's leg was always a copy of its
        // seat's tier — and that copy was the cross reported eight times. Now
        // an upline credit legitimately sits on a different leg from the seat
        // beneath it, because ONE seat can carry credits for several accounts
        // at different depths in the tree.
        //
        // Moving the seat would drag a student's original registration onto
        // another leg to satisfy an upline row that is not even their money.
        $this->assertSame(
            2,
            (int) GroupMember::find($row->group_member_id)->tier,
            'The seat stays where the student actually sits — only the money moved.',
        );

        // Amount untouched.
        $this->assertSame((float) self::SLOT, (float) $row->fresh()->amount);
    }

    /** A correctly-legged row is left completely alone. */
    public function test_it_leaves_correct_rows_untouched(): void
    {
        $sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $firstPlacement = $sponsor->hiddenAccounts()->orderBy('id')->first();

        $downlineUser = $this->addStudent($firstPlacement, 2);
        $downline = $this->promote($downlineUser, $firstPlacement);
        $this->addStudent($downline->hiddenAccounts()->first(), 1);

        $audit = app(UplineLegAudit::class);

        $this->assertCount(0, $audit->mismatches(), 'Nothing to correct once the engine writes it right.');
        $this->assertSame(0, $audit->repair()['corrected']);
    }

    /** ⚠️ Money already settled by a System Run is never re-legged. */
    public function test_it_never_touches_a_row_already_released_by_a_run(): void
    {
        $sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $firstPlacement = $sponsor->hiddenAccounts()->orderBy('id')->first();

        $downlineUser = $this->addStudent($firstPlacement, 2);
        $downline = $this->promote($downlineUser, $firstPlacement);
        $this->addStudent($downline->hiddenAccounts()->first(), 1);

        $row = Commission::where('consultant_id', $firstPlacement->id)->where('type', 'bonus')->latest('id')->firstOrFail();

        // Wrong leg AND already settled by a run.
        Commission::whereKey($row->id)->update(['tier' => 2, 'released_at' => now()]);

        $this->assertCount(
            0,
            app(UplineLegAudit::class)->mismatches(),
            'Re-legging settled money would rewrite a completed run\'s arithmetic.',
        );
    }

    /** The direct-add bonus belongs to no leg and must never be moved. */
    public function test_it_never_touches_the_direct_add_bonus(): void
    {
        $sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $account = $sponsor->hiddenAccounts()->orderBy('id')->first();

        $this->addStudent($account, 1);

        $direct = Commission::where('type', 'referral')->firstOrFail();
        Commission::whereKey($direct->id)->update(['tier' => 2]);

        $this->assertCount(0, app(UplineLegAudit::class)->mismatches());
        $this->assertSame(2, (int) $direct->fresh()->tier, 'Left exactly as it was.');
    }
}
