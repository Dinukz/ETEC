<?php

namespace App\Services;

use App\Models\Consultant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every real consultant owns two "hidden" service-group accounts. Their SCN
 * numbers follow the owner's sequentially — owner SCN-00104 → SCN-00105 /
 * SCN-00106. They are placement buckets: students can be added to them, each
 * fills its own Service Group 1 / 2, and all income rolls up to the owning
 * consultant. They never have a login (user_id is null) and are shown only in
 * the owner's "Partner Consultant" picker.
 */
class HiddenAccountService
{
    /** How many hidden accounts each real consultant gets. */
    public const PER_CONSULTANT = 2;

    /**
     * Ensure the given real consultant has its full set of hidden accounts, each
     * with a sequential SCN code. Idempotent: only creates the shortfall and
     * only fills in missing codes, never duplicates.
     */
    public function ensureFor(Consultant $consultant): \Illuminate\Support\Collection
    {
        // Hidden accounts don't own hidden accounts.
        if ($consultant->is_hidden) {
            return collect();
        }

        return DB::transaction(function () use ($consultant) {
            // ⚠️ THE WHOLE BLOCK IS CLAIMED AT ONCE (2026-08-31). The owner
            // takes N and its two hidden accounts take N+1 and N+2, so the three
            // are always consecutive — and N is the person's own SRN number
            // whenever the pool issued it. Claiming them one at a time is how a
            // student registering mid-setup could land between them.
            $block = null;

            if (! $consultant->scn_code) {
                $block = $this->numberBlockFor($consultant);
                $consultant->update(['scn_code' => NumberPool::format('SCN', $block)]);
            }

            $existing = Consultant::where('owner_consultant_id', $consultant->id)
                ->where('is_hidden', true)
                ->orderBy('id')
                ->get();

            // ⚠️ RESOLVED BEFORE THE BACKFILL BELOW (2026-09-01). This used to
            // sit after it, so a pre-scheme hidden account was given a number
            // off the head of the pool and the owner's own reserved N+1 was
            // stranded for good. The owner's number has to be known first.
            //
            // $block is null when the owner already had a code, in which case
            // it is read back off that code.
            $ownerNumber = $block ?? NumberPool::numberIn($consultant->fresh()->scn_code);

            // Backfill an SCN code for any hidden account that predates this
            // scheme — from the owner's own reserved block where that number is
            // still free, so the three stay consecutive.
            foreach ($existing as $index => $hidden) {
                if ($hidden->scn_code) {
                    continue;
                }

                $reserved = $ownerNumber === null ? null : $ownerNumber + $index + 1;

                $hidden->update([
                    'scn_code' => $reserved !== null && ! $this->codeIsTaken(NumberPool::format('SCN', $reserved))
                        ? NumberPool::format('SCN', $reserved)
                        : $this->nextScn(),
                ]);
            }

            // The two hidden accounts take the numbers reserved beside the
            // owner's, so a consultant reads SCN-00104 / 00105 / 00106 rather
            // than three numbers scattered by whoever registered in between.
            for ($i = $existing->count(); $i < self::PER_CONSULTANT; $i++) {
                $reserved = $ownerNumber === null ? null : $ownerNumber + $i + 1;

                $existing->push(Consultant::create([
                    'user_id' => null,
                    'is_hidden' => true,
                    'owner_consultant_id' => $consultant->id,
                    'scn_code' => $reserved !== null && ! $this->codeIsTaken(NumberPool::format('SCN', $reserved))
                        ? NumberPool::format('SCN', $reserved)
                        : $this->nextScn(),
                    'consultant_code' => $this->nextAccountCode($consultant),
                    'referral_code' => strtoupper(Str::random(10)),
                    'status' => 'active',
                ]));
            }

            // Every account shows its Service Group 1 / 2 from day one, before
            // any student is placed, so the Service Groups screen is never empty.
            $this->ensureGroup($consultant);
            $existing->each(fn (Consultant $hidden) => $this->ensureGroup($hidden));

            return $existing;
        });
    }

    /**
     * Open an ADDITIONAL earning account for a consultant beyond the ones they
     * were created with. Used by an admin once a consultant has used up the
     * earning slots on their existing accounts.
     */
    public function addExtraAccount(Consultant $consultant): Consultant
    {
        return DB::transaction(function () use ($consultant) {
            $owner = $consultant->is_hidden ? $consultant->owner : $consultant;

            abort_if(! $owner, 422, 'Cannot open an account for this consultant.');

            $account = Consultant::create([
                'user_id' => null,
                'is_hidden' => true,
                'owner_consultant_id' => $owner->id,
                'scn_code' => $this->nextScn(),
                'consultant_code' => $this->nextAccountCode($owner),
                'referral_code' => strtoupper(Str::random(10)),
                'status' => 'active',
            ]);

            $this->ensureGroup($account);

            return $account;
        });
    }

    /**
     * Open a WHOLE NEW SET of accounts because a consultant bought another
     * course (owner-confirmed 2026-08-20).
     *
     * Deliberately SEPARATE from addExtraAccount(), which is the admin's
     * one-at-a-time "+ New account" button and stays exactly as it was — the
     * owner asked for the two to be kept apart ("parana logic eka thibbe admin
     * gihilla new SCN account open kran widiyta, mt e logic eka wen
     * karagannn one").
     *
     * A set is PER_CONSULTANT + 1 accounts — the same three a consultant
     * receives on day one ("SCN account 3k hambenawa... plaweniyta hambena
     * account tika wagem"). In this data model every one of those three is a
     * hidden account carrying its own Service Group 1 and 2, so the set simply
     * extends the owner's account chain: a person who had SCN-00106/107/108
     * ends up with three more, each earning independently.
     *
     * Codes come from the same sequential allocator as every other SCN, so a
     * set opened when the counter is at 114 becomes SCN-00115/116/117.
     *
     * @return \Illuminate\Support\Collection<int, Consultant>
     */
    public function addAccountSet(Consultant $consultant): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($consultant) {
            $owner = $consultant->is_hidden ? $consultant->owner : $consultant;

            abort_if(! $owner, 422, 'Cannot open an account set for this consultant.');

            // The owner's own SCN must exist first, or the set would take codes
            // ahead of the person they belong to.
            if (! $owner->scn_code) {
                $owner->update(['scn_code' => $this->nextScn()]);
            }

            $created = collect();

            $block = NumberPool::take(self::PER_CONSULTANT + 1);

            for ($i = 0; $i <= self::PER_CONSULTANT; $i++) {
                $account = Consultant::create([
                    'user_id' => null,
                    'is_hidden' => true,
                    'owner_consultant_id' => $owner->id,
                    // Consecutive, from the block claimed above — so a bought
                    // set reads SCN-00115/116/117 rather than three numbers
                    // interleaved with whatever else was created meanwhile.
                    'scn_code' => NumberPool::format('SCN', $block + $i),
                    'consultant_code' => $this->nextAccountCode($owner),
                    'referral_code' => strtoupper(Str::random(10)),
                    'status' => 'active',
                ]);

                $this->ensureGroup($account);
                $created->push($account);
            }

            return $created;
        });
    }

    /**
     * The next free `{OWNER}-SG{n}` code for one of this owner's accounts.
     *
     * ⚠️ NEVER DERIVE THE SUFFIX FROM count() (2026-08-22).
     *
     * All three creation paths used `count() + 1`. Delete a single hidden
     * account — or let two payment verifications run at once — and the count
     * points back at a suffix that is still in use, so the insert hits the
     * UNIQUE index on consultant_code. That throws inside the verification
     * transaction, which means a QueryException 500 and a payment that cannot
     * be verified at all: an admin-facing dead end caused entirely by a
     * cosmetic label.
     *
     * So: start past the highest suffix ever ISSUED (not the number alive),
     * then step forward until the code is genuinely free.
     */
    protected function nextAccountCode(Consultant $owner): string
    {
        $accounts = Consultant::where('owner_consultant_id', $owner->id)
            ->where('is_hidden', true)
            ->pluck('consultant_code');

        $highest = $accounts
            ->map(fn ($code) => (int) Str::afterLast((string) $code, '-SG'))
            ->max();

        $next = max((int) $highest, $accounts->count()) + 1;

        while (Consultant::where('consultant_code', $owner->consultant_code.'-SG'.$next)->exists()) {
            $next++;
        }

        return $owner->consultant_code.'-SG'.$next;
    }

    /** Give an account its single service group if it doesn't have one yet. */
    public function ensureGroup(Consultant $account): \App\Models\Group
    {
        $existing = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->first();

        if ($existing) {
            return $existing;
        }

        $settings = \App\Models\CommissionSetting::current();

        $group = \App\Models\Group::create([
            'group_code' => 'GRP-'.strtoupper(Str::random(6)),
            'consultant_id' => $account->id,
            'name' => 'Service Group',
            'capacity' => (int) $settings->group_size,
            'filled_slots' => 0,
            'tier1_filled' => 0,
            'tier2_filled' => 0,
            'status' => 'open',
            'total_commission_earned' => 0,
            'opened_at' => now(),
        ]);

        $account->update(['active_group_id' => $group->id]);

        return $group;
    }

    /** Is this SCN code already on a consultant? */
    protected function codeIsTaken(string $code): bool
    {
        return Consultant::where('scn_code', $code)->exists();
    }

    /**
     * One SCN code from the shared pool.
     *
     * ⚠️ Used for the ODD ONES — an admin's single extra account, or a code
     * being backfilled onto an account that predates this scheme. A consultant
     * being SET UP goes through numberBlockFor() instead, so their three
     * accounts are consecutive and start at their own SRN number.
     */
    protected function nextScn(): string
    {
        return NumberPool::format('SCN', NumberPool::take(1));
    }

    /**
     * The three consecutive numbers this consultant's accounts should use.
     *
     * ⚠️ THEIR SRN IS THEIR SCN (2026-08-31). A student registered under the
     * shared pool holds SRN-N and already has N+1 and N+2 reserved, so on
     * promotion they keep the same digits: "SRN number eka thama issrahat SCN
     * number ekak widiyta convert wenne one."
     *
     * Falls back to a fresh block when they have no student record, or when
     * their SRN predates the pool and its neighbours are already taken — which
     * is every identity issued before today. Those keep working exactly as
     * before; only new ones line up.
     */
    protected function numberBlockFor(Consultant $consultant): int
    {
        return NumberPool::numberForPromotedStudent($consultant->user_id)
            ?? NumberPool::take(NumberPool::BLOCK);
    }
}
