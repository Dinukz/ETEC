<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\User;
use App\Services\HiddenAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ `{OWNER}-SG{n}` codes must never be re-issued.
 *
 * consultant_code is UNIQUE, and every creation path used to build the suffix
 * from a live count() of the owner's hidden accounts. Delete one account and
 * the next allocation points back at a suffix still in use — the insert throws
 * inside the payment-verification transaction, so an admin gets a 500 and the
 * payment can never be verified. A cosmetic label taking down the money path.
 */
class AccountCodeAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function owner(): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $owner = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($owner);

        return $owner->fresh();
    }

    /**
     * A GAP below the highest suffix is what actually breaks: delete -SG1 and
     * count() says 1, so the old allocator hands out -SG2 — which is still
     * alive. UNIQUE(consultant_code) rejects the insert and the enclosing
     * verification transaction 500s.
     */
    public function test_a_gap_in_the_codes_does_not_collide_with_a_live_account(): void
    {
        $service = app(HiddenAccountService::class);
        $owner = $this->owner();

        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();
        $this->assertCount(2, $accounts);

        $survivor = $accounts[1]->consultant_code;
        $accounts[0]->delete();

        $fresh = $service->addExtraAccount($owner->fresh());

        $this->assertNotSame($survivor, $fresh->consultant_code, 'Must not reuse a LIVE account\'s code.');
        $this->assertSame($owner->consultant_code.'-SG3', $fresh->consultant_code, 'It climbs past the highest suffix issued.');
    }

    public function test_a_whole_set_gets_three_distinct_codes_after_a_deletion(): void
    {
        $service = app(HiddenAccountService::class);
        $owner = $this->owner();

        $owner->hiddenAccounts()->orderBy('id')->first()->delete();

        $set = $service->addAccountSet($owner->fresh());

        $this->assertCount(3, $set);

        $all = Consultant::where('owner_consultant_id', $owner->id)->pluck('consultant_code');
        $this->assertSame(
            $all->count(),
            $all->unique()->count(),
            'Every one of the owner\'s account codes stays distinct.',
        );
    }

    /** Repeated allocations keep climbing rather than stalling on a taken code. */
    public function test_codes_keep_climbing_across_many_allocations(): void
    {
        $service = app(HiddenAccountService::class);
        $owner = $this->owner();

        for ($i = 0; $i < 4; $i++) {
            $service->addAccountSet($owner->fresh());
        }

        $codes = Consultant::where('owner_consultant_id', $owner->id)->pluck('consultant_code');

        $this->assertCount(14, $codes, '2 original + 4 sets of 3.');
        $this->assertSame($codes->count(), $codes->unique()->count());
    }
}
