<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Observers\ConsultantObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ConsultantObserver::class)]
class Consultant extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'user_id', 'parent_consultant_id', 'is_hidden', 'owner_consultant_id',
        'consultant_code', 'scn_code', 'referral_code', 'nic', 'nic_verified', 'address',
        'date_of_birth', 'gender',
        'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'swift_code', 'account_proof_path',
        'beneficiary_name', 'beneficiary_relationship', 'beneficiary_nic', 'beneficiary_date_of_birth', 'beneficiary_phone',
        'total_earned', 'total_paid', 'active_group_id', 'status',
        'approved_by', 'approved_at',
        // Weekly student-quota compliance — deliberately separate from
        // `status` above. See the 2026_08_08_100000 migration docblock for
        // why: `status` already means approval state and is read elsewhere
        // (RegisterController, CheckoutController) as a proxy for whether
        // this account's referral links still work.
        //
        // finance_period_key is NOT listed here on purpose: it advances every
        // single week for every active consultant, and the Auditable trait
        // logs every fillable change — that would be pure audit-log noise.
        // It is written via forceFill()->saveQuietly() instead.
        'finance_status', 'finance_restricted_period', 'finance_restricted_at',
        'finance_suspended_at', 'finance_reactivated_at', 'finance_reactivated_by',
        // Staged group income + the 5-year "mature consultant" track. Like
        // finance_period_key above, `mature_month_key` is deliberately NOT
        // fillable — it advances every 28 days for everyone and would be pure
        // audit-log noise; it is written via forceFill()->saveQuietly().
        'lifetime_pair_stage', 'mature_at', 'mature_months', 'suspension_count',
        // An admin's manual override of the automatic account-unlock rule.
        // Fillable (unlike the *_key columns above) precisely BECAUSE it is a
        // deliberate human decision that changes who can earn — the Auditable
        // trait logging every change is the point, not noise.
        'manual_lock_override', 'manual_lock_at', 'manual_lock_by',
        // Which leg of the SPONSOR this consultant hangs off. Set when a
        // placement targets a main account (where no ordinal exists to derive
        // it from) — see CommissionService::sideUnderParent().
        'placement_tier',
        // Idempotency marker: which payment opened this account, when it came
        // from a consultant buying another course. See PaymentService.
        'opened_by_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'total_earned' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'approved_at' => 'datetime',
            'nic_verified' => 'boolean',
            'is_hidden' => 'boolean',
            'date_of_birth' => 'date',
            'beneficiary_date_of_birth' => 'date',
            'finance_restricted_period' => 'date',
            'finance_restricted_at' => 'datetime',
            'finance_suspended_at' => 'datetime',
            'finance_reactivated_at' => 'datetime',
            'lifetime_pair_stage' => 'integer',
            'mature_at' => 'datetime',
            'mature_months' => 'integer',
            'suspension_count' => 'integer',
            'manual_lock_at' => 'datetime',
            'can_view_downline_report' => 'boolean',
            'downline_report_granted_at' => 'datetime',
            'page_access_updated_at' => 'datetime',
        ];
    }

    // ----- The admin's manual account-unlock override ----------------------

    /** Forced open by an admin, regardless of the previous account's students. */
    public const LOCK_FORCED_OPEN = 'unlocked';

    /** Forced shut by an admin, even though the automatic rule would allow it. */
    public const LOCK_FORCED_SHUT = 'locked';

    /**
     * The admin's override for this account, or null to follow the automatic
     * 12-students rule.
     *
     * Reads NULL whenever the column has not been created yet, so every caller
     * degrades to the automatic rule rather than throwing — same convention as
     * the rest of the finance schema (see FinanceSchemaRepair).
     */
    /**
     * May this consultant see the admissions of the people below them?
     *
     * ⚠️ ONE PLACE, AND IT DEFAULTS TO NO. Read this rather than the column —
     * the column does not exist on a database that has not healed yet, and
     * touching it directly there throws. Absent means NOT granted, which is the
     * safe direction: a consultant sees only their own accounts until an admin
     * deliberately turns this on from User Management.
     *
     * Resolves through owningPerson() so a hidden placement account answers for
     * the person who holds it — the grant belongs to a PERSON, not to one of
     * their SCN buckets.
     */
    public function canViewDownlineReport(): bool
    {
        if (! \App\Services\FinanceSchemaRepair::downlineReportReady()) {
            return false;
        }

        return (bool) $this->owningPerson()->can_view_downline_report;
    }

    /**
     * The optional pages an admin grants per consultant (2026-09-22) — "buy one
     * access denn saha okkom access denn". Key => menu label.
     */
    public const PAGE_ACCESS = [
        'admissions' => 'Admission Report',
        'payment_summary' => 'Payment Summary Report',
        'my_bookings' => 'My Bookings',
        'all_bookings' => 'All Bookings',
    ];

    /** @return list<string> the page keys granted to this consultant's person */
    public function grantedPages(): array
    {
        if (! \App\Services\FinanceSchemaRepair::pageAccessReady()) {
            return [];
        }

        $stored = json_decode((string) $this->owningPerson()->page_access, true);

        return array_values(array_intersect(array_keys(self::PAGE_ACCESS), is_array($stored) ? $stored : []));
    }

    /**
     * May this consultant open one of the optional pages?
     *
     * ⚠️ DEFAULT NO for everyone (the owner's choice on 2026-09-22). One
     * exception: a consultant already given the old downline switch was granted
     * the Admission Report by hand, so they keep it.
     */
    public function hasPageAccess(string $key): bool
    {
        if (in_array($key, $this->grantedPages(), true)) {
            return true;
        }

        return $key === 'admissions' && $this->canViewDownlineReport();
    }

    public function lockOverride(): ?string
    {
        if (! \App\Services\FinanceSchemaRepair::lockOverrideReady()) {
            return null;
        }

        return in_array($this->manual_lock_override, [self::LOCK_FORCED_OPEN, self::LOCK_FORCED_SHUT], true)
            ? $this->manual_lock_override
            : null;
    }

    // ----- Scopes --------------------------------------------------------

    /** Only real, login-backed consultants (excludes hidden service-group accounts). */
    public function scopeReal($query)
    {
        return $query->where('is_hidden', false);
    }

    public function scopeHidden($query)
    {
        return $query->where('is_hidden', true);
    }

    /**
     * Excludes only SUSPENDED consultants, not restricted ones — a restricted
     * consultant keeps collecting referrals (their income accrues on hold,
     * per ConsultantFinanceService::hold()); a suspended one does not. Used
     * by the ?ref= resolution in RegisterController/CheckoutController.
     *
     * Schema-safe: on a host where the finance_status column has not been
     * added yet (see FinanceSchemaRepair), a raw `WHERE finance_status ...`
     * would hard-fail with "Unknown column" — unlike a plain PHP attribute
     * read, which Eloquent tolerates. This scope is a no-op until the schema
     * is ready, matching how every other finance check in this app degrades.
     */
    public function scopeNotFinanceSuspended($query)
    {
        if (! \App\Services\FinanceSchemaRepair::isReady()) {
            return $query;
        }

        // ⚠️ THE OFF SWITCH (2026-09-15). While the weekly requirement is
        // parked nobody counts as suspended, so a suspended consultant's ?ref=
        // link must resolve again like every other gate. Without this the
        // switch would be "off" everywhere EXCEPT referral links — exactly the
        // kind of leftover nobody would think to go looking for.
        if (! \App\Support\FinanceWeek::quotaEnabled()) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('finance_status')->orWhere('finance_status', '!=', 'suspended'));
    }

    /** Name to show for this consultant — hidden accounts borrow the owner's. */
    public function getDisplayNameAttribute(): string
    {
        if ($this->user) {
            return $this->user->name;
        }

        $owner = $this->owner?->user?->name;

        return $owner ? "{$owner} (service group account)" : ($this->consultant_code ?? 'Consultant');
    }

    /** Profile completeness — mirrors the "Profile Complete" badge on My Profile. */
    public function getProfileCompleteAttribute(): bool
    {
        return filled($this->nic) && filled($this->address) && filled($this->date_of_birth)
            && filled($this->bank_account_number) && filled($this->beneficiary_name);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The sponsor / upline consultant who referred this consultant. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'parent_consultant_id');
    }

    /** Consultants directly referred by this consultant (their first level). */
    public function children(): HasMany
    {
        return $this->hasMany(Consultant::class, 'parent_consultant_id');
    }

    /** The real consultant that owns this hidden service-group account. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'owner_consultant_id');
    }

    /** This consultant's two hidden service-group accounts (SCN00101 / 102). */
    public function hiddenAccounts(): HasMany
    {
        return $this->hasMany(Consultant::class, 'owner_consultant_id')->where('is_hidden', true);
    }

    /**
     * This consultant's own id plus their hidden service-group account ids —
     * used to roll students, groups and income up to the real consultant.
     *
     * @return array<int>
     */
    public function accountIds(): array
    {
        return array_merge([$this->id], $this->hiddenAccounts()->pluck('id')->all());
    }

    /**
     * The owner's accounts in the order they unlock — own account first, then
     * each hidden placement account by creation order (which is also SCN
     * order, since HiddenAccountService issues codes sequentially).
     *
     * Distinct from accountIds() on purpose: that one only needs to be a SET
     * to roll figures up, whereas ServiceGroupStaging's unlock rule ("this
     * account opens once the PREVIOUS one has 12 students") depends on the
     * sequence being stable and explicit.
     *
     * @return array<int>
     */
    public function orderedAccountIds(): array
    {
        $owner = $this->owningPerson();

        return array_merge(
            [$owner->id],
            static::where('owner_consultant_id', $owner->id)
                ->where('is_hidden', true)
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * IDs of every consultant in this consultant's downline (all levels below),
     * excluding self. Resolved iteratively to avoid deep recursion / cycles.
     *
     * @return array<int>
     */
    public function downlineIds(): array
    {
        // Walk from ALL of this consultant's accounts, not just their own id.
        // A student registered through a placement account (SCN-00166) records
        // that hidden account as their sponsor when they are promoted, so
        // starting from the real id alone finds an empty downline.
        return static::downlineIdsBelow($this->accountIds(), [$this->id]);
    }

    /**
     * Every real consultant hanging below the GIVEN accounts, all levels down.
     *
     * Split out of downlineIds() on 2026-09-07 so the Service Groups page can
     * ask the same question about ONE placement account rather than about the
     * whole person: selecting SCN-00296 shows the branch sponsored through
     * SCN-00296, while selecting the main shows the person's whole team.
     * Owner: "SCN account ekat adalawa pennuwan widiyt hadann saha main
     * account ekatath e tika penwnn one."
     *
     * ⚠️ ONE IMPLEMENTATION, TWO CALLERS. A second copy of this walk would
     * drift from the placement-account subtlety documented above — the exact
     * class of bug this codebase has already paid for three times.
     *
     * @param  array<int>  $accountIds  where to start; any mix of real and hidden accounts
     * @param  array<int>  $exclude     ids that must never appear in the result
     * @return array<int>
     */
    public static function downlineIdsBelow(array $accountIds, array $exclude = []): array
    {
        $collected = [];
        $frontier = $accountIds;

        while ($frontier) {
            // Only real consultants are "people below you" — hidden accounts are
            // placement buckets and never carry a parent_consultant_id anyway.
            $children = static::where('is_hidden', false)
                ->whereIn('parent_consultant_id', $frontier)
                ->whereNotIn('id', array_merge($collected, $exclude))
                ->pluck('id')
                ->all();

            if (! $children) {
                break;
            }

            $collected = array_merge($collected, $children);

            // The level below those consultants may likewise have been sponsored
            // through THEIR placement accounts, so descend from all of them.
            $frontier = static::whereIn('id', $children)
                ->orWhereIn('owner_consultant_id', $children)
                ->pluck('id')
                ->all();
        }

        return $collected;
    }

    /**
     * The same walk as downlineIdsBelow(), but keeping WHICH BRANCH each person
     * came down — the fact the flat walk throws away.
     *
     * Needed because the Service Groups tabs must file every descendant under
     * Service Group 1 or 2 of the account being viewed, and a person six levels
     * down belongs to whichever of the viewer's legs their branch hangs off.
     * `parent_consultant_id` is single-valued, so the placement graph is a tree
     * and a branch cannot fork: everything below a level-1 person is wholly
     * inside that person's leg. `root_account_id` is what carries that.
     *
     * ⚠️ DELIBERATELY ADDITIVE — downlineIdsBelow() IS NOT REWRITTEN TO USE THIS.
     * That method feeds downlineIds() -> reachableAccountIds() -> the
     * $reachableIds that authorise which accounts a consultant may open. Making
     * a display feature share an implementation with the security boundary
     * would put "who am I allowed to see" on new code for no gain. The cost is
     * one duplicated walk on one page; DownlineBranchProvenanceTest asserts the
     * two never disagree about membership.
     *
     * ⚠️ BOUNDED THREE WAYS, because this runs on the hottest consultant page
     * and reads data an admin can hand-edit: a depth cap (matching
     * CommissionService::MAX_UPLINE_DEPTH), a breadth cap, and a $seen guard on
     * the FRONTIER — the sibling walk only guards $collected, which stops a
     * person being listed twice but would not stop a cycle regenerating the
     * frontier forever.
     *
     * @param  array<int>  $accountIds  branch roots; the leg map is keyed on these
     * @param  array<int>  $exclude     ids that must never appear
     * @return array<int, array{level: int, parent: ?int, parent_account_id: int, root_account_id: int, user_id: ?int}>
     *                                  keyed by consultant id
     */
    public static function downlineBranchesBelow(
        array $accountIds,
        array $exclude = [],
        int $maxDepth = 50,
        int $maxPeople = 5000,
    ): array {
        $collected = [];
        $blocked = array_map('intval', $exclude);

        // accountId => [branch root account, the PERSON holding that account].
        // The roots have no person above them — that is the selected account
        // itself, rendered as the level-0 row.
        $frontier = [];

        foreach ($accountIds as $id) {
            $frontier[(int) $id] = ['root' => (int) $id, 'person' => null];
        }

        $seenAccounts = $frontier;
        $level = 0;

        while ($frontier !== [] && $level < $maxDepth && count($collected) < $maxPeople) {
            $level++;

            // Only real consultants are "people below you" — hidden accounts are
            // placement buckets and never carry a parent_consultant_id anyway.
            // orderBy('id') so first-seen-wins is deterministic: without it a
            // cross-linked person could flip between Service Group 1 and 2
            // between two refreshes of the same page.
            $children = static::where('is_hidden', false)
                ->whereIn('parent_consultant_id', array_keys($frontier))
                ->whereNotIn('id', array_merge(array_keys($collected), $blocked))
                ->orderBy('id')
                ->get(['id', 'parent_consultant_id', 'user_id']);

            if ($children->isEmpty()) {
                break;
            }

            $next = [];

            foreach ($children as $child) {
                $under = $frontier[(int) $child->parent_consultant_id] ?? null;

                if ($under === null) {
                    continue;
                }

                $collected[(int) $child->id] = [
                    'level' => $level,
                    'parent' => $under['person'],
                    // The ACCOUNT they were actually added under — who sponsored
                    // them. Kept apart from root_account_id, which is only the
                    // branch they descend from: for anyone past level 1 the two
                    // are different accounts, and reading the root as "who they
                    // are under" is the 2026-09-22 misread (every downline row
                    // printed the subject's own account as their sponsor).
                    'parent_account_id' => (int) $child->parent_consultant_id,
                    'root_account_id' => $under['root'],
                    'user_id' => $child->user_id === null ? null : (int) $child->user_id,
                ];

                $next[(int) $child->id] = ['root' => $under['root'], 'person' => (int) $child->id];
            }

            // Their own placement accounts sponsor the level below, and anyone
            // found through them still belongs to the same branch.
            $childIds = array_keys($next);

            if ($childIds !== []) {
                foreach (static::whereIn('owner_consultant_id', $childIds)
                    ->where('is_hidden', true)
                    ->get(['id', 'owner_consultant_id']) as $account) {
                    $owner = (int) $account->owner_consultant_id;

                    $next[(int) $account->id] = ['root' => $next[$owner]['root'], 'person' => $owner];
                }
            }

            // Anything already used as a frontier key is a cycle, not a level.
            $frontier = array_diff_key($next, $seenAccounts);
            $seenAccounts += $frontier;
        }

        return $collected;
    }

    /**
     * Every consultant account this consultant can act on: their own account,
     * their hidden accounts, and the same for every consultant in their downline.
     * Used to authorize actions (like confirming a student's payment) on
     * students placed anywhere in this consultant's whole network.
     *
     * @return array<int>
     */
    /**
     * Every account at or below the GIVEN accounts: the accounts themselves,
     * every person hanging under them (all levels), and all of those people's
     * own accounts.
     *
     * Added 2026-10-01 for the Admission Report's SCN Account pick — "SCN account
     * ekak select karoth ekata yatathe inn okkom account tika load wenn one".
     * Pass a person's accountIds() for the whole person (the same set as
     * reachableAccountIds(), pinned by AdmissionReportScnPickTest), or one
     * placement account for just the branch that hangs off it — the convention
     * the Service Groups page already uses.
     *
     * ⚠️ A DISPLAY SCOPE, NOT A PERMISSION. reachableAccountIds() stays the
     * security boundary and is deliberately untouched; callers intersect this
     * with whatever the viewer may see.
     *
     * @param  array<int>  $roots
     * @return array<int>
     */
    public static function subtreeAccountIds(array $roots): array
    {
        $roots = array_values(array_unique(array_map('intval', $roots)));

        if ($roots === []) {
            return [];
        }

        // The people the roots belong to never count as "below" them.
        $rootPeople = static::whereIn('id', $roots)->get(['id', 'is_hidden', 'owner_consultant_id'])
            ->map(fn (self $c) => (int) ($c->is_hidden ? ($c->owner_consultant_id ?? $c->id) : $c->id))
            ->all();

        $people = static::downlineIdsBelow($roots, $rootPeople);

        $owned = $people === []
            ? []
            : static::whereIn('owner_consultant_id', $people)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_merge($roots, array_map('intval', $people), $owned)));
    }

    public function reachableAccountIds(): array
    {
        $consultantIds = array_merge([$this->id], $this->downlineIds());

        return static::whereIn('id', $consultantIds)
            ->orWhereIn('owner_consultant_id', $consultantIds)
            ->pluck('id')
            ->all();
    }

    public function activeGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'active_group_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function getPendingBalanceAttribute(): float
    {
        return (float) $this->total_earned - (float) $this->total_paid;
    }

    // ----- Weekly finance compliance --------------------------------------

    /** A hidden placement account is not a person — roll up to whoever owns it. */
    public function owningPerson(): self
    {
        return $this->is_hidden ? ($this->owner ?? $this) : $this;
    }

    /**
     * WHICH OF ITS OWNER'S SERVICE GROUPS this placement account IS.
     *
     * The owner's model, stated repeatedly and most precisely on 2026-08-21:
     * a placement account does not merely sit near a leg, it IS that leg —
     * "SCN 127 eke Service group 2 eka yatathe thama SCN 129 thiyenne, ethkot
     * eken dana ewa ynn one Service group 2 ekata... SCN 129 service group 1
     * dammath 2 dammath ynn one SCN 127 eke service group 2 ekata."
     *
     * So everything an account earns belongs to the owner's leg that account
     * represents, WHATEVER tier it sits on inside that account. The account's
     * own two service groups are its internal structure, not its identity.
     *
     * Accounts arrive in SETS. The opening set is the person's main plus two
     * hidden accounts (#1 = leg 1, #2 = leg 2). Every later set — opened when
     * a consultant buys another course — is the same triple, so from #3 the
     * pattern repeats every three:
     *
     *   #1 -> leg 1     #4 -> leg 1     #7 -> leg 1
     *   #2 -> leg 2     #5 -> leg 2     #8 -> leg 2
     *                   #3 -> set-main  #6 -> set-main   (represents no leg)
     *
     * Returns NULL for a main account and for a set-main — neither IS a leg.
     * This is the single source of truth for the mapping; CommissionService,
     * UplineLegAudit and the Service Groups page all read it here rather than
     * each keeping their own copy, which is how they previously drifted apart.
     */
    public function serviceGroupLeg(): ?int
    {
        if (! $this->is_hidden || ! $this->owner_consultant_id) {
            return null;
        }

        $ids = static::where('owner_consultant_id', $this->owner_consultant_id)
            ->where('is_hidden', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $position = array_search($this->id, $ids, true);

        if ($position === false) {
            return null;
        }

        return static::legForOrdinal($position + 1);
    }

    /**
     * Which Service Group the Nth of an owner's placement accounts IS — the
     * arithmetic of serviceGroupLeg() on its own (2026-09-14).
     *
     * A caller already holding the owner's placements in id order can ask this
     * for each of them without a query apiece. ServiceGroupStaging::
     * previousAccountId() called serviceGroupLeg() once per sibling, and each
     * call re-read the same sibling list — for an owner with 8 placements, 9
     * queries on every unlock check, on every account a System Run looked at.
     *
     * @param  int  $ordinal  1-based position among the owner's hidden accounts, by id
     */
    public static function legForOrdinal(int $ordinal): ?int
    {
        // The opening pair sit directly under the person's main account.
        if ($ordinal <= 2) {
            return $ordinal;
        }

        // Later sets repeat in threes; position 0 is the set's own main.
        $inSet = ($ordinal - 3) % 3;

        return $inSet === 0 ? null : $inSet;
    }

    /**
     * ⚠️ ALL THREE RESPECT THE OFF SWITCH (2026-09-15).
     *
     * When the weekly requirement is switched off in Settings, nobody counts as
     * restricted or suspended — including a consultant already carrying that
     * stamp from before it was switched off. That is what makes the switch
     * retroactive for the people it has already caught, which is the whole
     * point of it. The stored finance_status is NOT cleared, so switching it
     * back on puts everyone straight back where they were.
     *
     * See App\Support\FinanceWeek::quotaEnabled().
     */
    public function isFinanceActive(): bool
    {
        if (! \App\Support\FinanceWeek::quotaEnabled()) {
            return true;
        }

        return ($this->finance_status ?? 'active') === 'active';
    }

    public function isFinanceRestricted(): bool
    {
        return \App\Support\FinanceWeek::quotaEnabled() && $this->finance_status === 'restricted';
    }

    public function isFinanceSuspended(): bool
    {
        return \App\Support\FinanceWeek::quotaEnabled() && $this->finance_status === 'suspended';
    }

    public function financePeriods(): HasMany
    {
        return $this->hasMany(ConsultantFinancePeriod::class);
    }

    // ----- The 5-year "mature consultant" track ----------------------------

    /**
     * A mature consultant has completed 60 qualifying consultant months
     * (60 x 28 days ~ 4.6 years) and is EXEMPT from the weekly student quota
     * for good — they keep earning from their downline instead. See
     * ConsultantMaturityService for how the counter advances.
     */
    public function isMature(): bool
    {
        return $this->owningPerson()->mature_at !== null;
    }
}
