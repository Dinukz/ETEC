<?php

namespace App\Services;

use App\Support\FinanceWeek;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Guarantees the columns/table the weekly consultant-quota feature depends on
 * actually exist — the same self-healing pattern as CommissionSchemaRepair,
 * for the same reason: this host has no CLI, and `php artisan migrate` aborts
 * partway through on a ledger that has drifted from the real schema.
 *
 * Every change here is purely additive (nullable columns, one new table), so
 * unlike CommissionSchemaRepair's payment_type enum widening, none of this
 * needs a raw ALTER — `Schema::hasColumn`/`hasTable` guards are enough.
 *
 * Called from CommissionSchemaRepair::ensure(), which already runs at every
 * outermost, pre-transaction entry point this feature needs — see that
 * class's docblock for the full list and for why "pre-transaction" matters
 * (MySQL implicitly commits on DDL).
 */
class FinanceSchemaRepair
{
    /**
     * Request-level memo in front of the cache.
     *
     * Cache::remember() hits the store on EVERY call, and this app runs
     * CACHE_STORE=database — so a page that asks one of these per consultant
     * row fires one SELECT against `cache` per row. The admin Service Income
     * queue was observed doing exactly that: 24 identical
     * `finance.staging.ready` lookups in a single render. These flags cannot
     * change within a request (only ensure() alters them, and it clears this
     * too), so one lookup per request is always correct.
     *
     * @var array<string, bool>
     */
    protected static array $memo = [];

    protected static function ready(string $key, \Closure $check): bool
    {
        return self::$memo[$key] ??= Cache::remember($key, 3600, $check);
    }

    /**
     * Drop the request-level memo without touching the cache.
     *
     * The memo is a static and therefore outlives any single database, which
     * only matters where the schema can go backwards — i.e. a test suite
     * rebuilding it between classes. See Tests\TestCase::setUp().
     */
    public static function forgetMemo(): void
    {
        self::$memo = [];
    }

    /** Read-only, cheap, safe to call from a request path (e.g. middleware). Never runs DDL. */
    public static function isReady(): bool
    {
        return self::ready('finance.schema.ready', fn () => Schema::hasColumn('consultants', 'finance_status')
            && Schema::hasColumn('commissions', 'held_at'));
    }

    /**
     * The staged-income + maturity columns, checked SEPARATELY from
     * isReady().
     *
     * Deliberately not folded into isReady(): that one gates the whole weekly
     * restrict/suspend feature, so making it depend on these newer columns
     * would silently switch the finance gating OFF on any deploy where only
     * the older half of the schema exists — turning a missing column into a
     * money-safety regression rather than a missing screen.
     */
    public static function maturityReady(): bool
    {
        return self::ready('finance.maturity.ready', fn () => Schema::hasColumn('consultants', 'lifetime_pair_stage')
            && Schema::hasColumn('consultants', 'mature_months')
            && Schema::hasColumn('consultants', 'mature_at'));
    }

    /**
     * The staged-release columns specifically.
     *
     * Separate again, and checked before anything GATES a payout: when these
     * are missing ServiceGroupStaging deliberately treats every pair as
     * released, so a half-applied schema falls back to the old
     * "everything approvable" behaviour instead of silently freezing every
     * consultant's service-group income.
     */
    public static function stagingReady(): bool
    {
        return self::ready('finance.staging.ready', fn () => Schema::hasColumn('groups', 'released_depth')
            && Schema::hasColumn('consultants', 'lifetime_pair_stage'));
    }

    /**
     * The manual flush-out column (2026-08-13).
     *
     * Separate flag, same reasoning as stagingReady(): when `released_at` is
     * missing, ServiceGroupFlush reports nothing as flushable and
     * CommissionApproval falls back to the older rank-vs-depth rule, so a
     * half-applied schema degrades to the previous behaviour rather than
     * making every consultant's income unapprovable at once.
     */
    public static function flushReady(): bool
    {
        return self::ready('finance.flush.ready', fn () => Schema::hasColumn('commissions', 'released_at'));
    }

    /**
     * The hand-removal marker on a commission (2026-09-06).
     *
     * ⚠️ THIS ONLY TELLS TWO KINDS OF `cancelled` APART. A removed row and a
     * System-Run-forfeited row both end up `status = 'cancelled'`, and every
     * money query treats them identically — deliberately, because in both cases
     * the amount has left the consultant. What this column adds is WHY, so a
     * hand correction is never reported as company overflow.
     *
     * The safe fallback is therefore "column absent = nothing was removed by
     * hand", which is exactly true on a database where the button has never
     * existed. Nothing about the money reading 0 depends on this flag — that
     * comes from `status = 'cancelled'`, which needs no new column.
     */
    public static function removalReady(): bool
    {
        return self::ready('finance.removal.ready', fn () => Schema::hasColumn('commissions', 'removed_at'));
    }

    /**
     * The admin's manual account lock override (2026-08-17).
     *
     * Separate flag again, and the SAFE fallback here is "no override exists",
     * which leaves the automatic 12-students rule in sole charge — exactly the
     * behaviour before this feature. An override that silently evaluated to
     * NULL on a half-applied schema would read as "not overridden" anyway, so
     * the degraded path and the normal path agree.
     */
    public static function lockOverrideReady(): bool
    {
        return self::ready('finance.lockoverride.ready', fn () => Schema::hasColumn('consultants', 'manual_lock_override'));
    }

    /**
     * The per-consultant downline-report grant (2026-09-02).
     *
     * Safe fallback is "absent" — which reads as NOT granted, so on a database
     * that has not healed yet every consultant keeps seeing only their own
     * accounts. That is the conservative direction: a half-applied schema
     * cannot accidentally expose one consultant's admissions to another.
     */
    public static function downlineReportReady(): bool
    {
        return self::ready('finance.downlinereport.ready', fn () => Schema::hasColumn('consultants', 'can_view_downline_report'));
    }

    /**
     * The per-consultant page grants (2026-09-22). Safe fallback is "absent",
     * which reads as NOT granted — the owner's chosen default anyway.
     */
    public static function pageAccessReady(): bool
    {
        return self::ready('finance.pageaccess.ready', fn () => Schema::hasColumn('consultants', 'page_access'));
    }

    /**
     * The explicit sponsor-leg column (2026-08-19).
     *
     * Safe fallback is "absent", which leaves CommissionService::sideUnderParent()
     * on its pre-existing ordinal/seat rules — i.e. exactly the behaviour before
     * this feature, rather than a half-applied schema silently changing which
     * leg money lands on.
     */
    public static function placementTierReady(): bool
    {
        return self::ready('finance.placementtier.ready', fn () => Schema::hasColumn('consultants', 'placement_tier'));
    }

    /**
     * The date the income is FOR, as opposed to the date its row appeared
     * (2026-09-10).
     *
     * Reported by the owner: a payslip attached on the 1st and verified on the
     * 8th recorded its Service Income against the 8th — "meke ganne verify
     * krapu date eka, eka wardi". A commission row is only inserted at
     * verification, so `created_at` can be a week or more after the money
     * actually arrived.
     *
     * Safe fallback is "absent", which leaves every screen reading
     * `created_at` exactly as it did before — i.e. today's behaviour, not a
     * half-applied schema quietly moving figures between months.
     */
    public static function earnedAtReady(): bool
    {
        return self::ready('finance.earnedat.ready', fn () => Schema::hasColumn('commissions', 'earned_at'));
    }

    /**
     * The SQL expression for "the date this income is for".
     *
     * ⚠️ USE THIS, NEVER THE COLUMN NAME. On a database that has not healed
     * yet, naming `earned_at` in a query throws "unknown column" and takes out
     * every Service Income page — the same rule AccountPlacementMap follows
     * for its conditionally-present columns.
     *
     * NULL survives for hand-entered income, which has no payment to date it
     * by, so the COALESCE is part of the meaning rather than a safety net.
     */
    public static function incomeDateSql(): string
    {
        return self::earnedAtReady()
            ? 'COALESCE(commissions.earned_at, commissions.created_at)'
            : 'commissions.created_at';
    }

    /**
     * One-off carry-over, run once when `released_at` is first created.
     *
     * The old rule: a slot commission was payable when its member's rank on
     * its own leg (oldest first) was within `groups.released_depth`. The new
     * rule reads `commissions.released_at` instead, so without this every
     * already-released-but-still-pending row would become unapprovable the
     * instant the column appeared — money that an accountant was about to pay
     * out, silently frozen.
     *
     * Deliberately stamps ONLY rows the old rule already considered released.
     * Anything beyond `released_depth` stays NULL and now waits for a manual
     * flush, which is the intended new behaviour.
     *
     * Runs as plain query-builder writes (no models) so it cannot fire model
     * events, and outside any transaction — it is called from ensure(), which
     * only ever runs pre-transaction (see the class docblock).
     *
     * @return int how many rows were carried over
     */
    protected static function backfillReleasedAt(): int
    {
        if (! Schema::hasTable('groups') || ! Schema::hasColumn('groups', 'released_depth')) {
            return 0;
        }

        $stamped = 0;

        // Only groups that actually released something under the old rule.
        $groups = \Illuminate\Support\Facades\DB::table('groups')
            ->where('released_depth', '>', 0)
            ->pluck('released_depth', 'id');

        foreach ($groups as $groupId => $depth) {
            foreach ([1, 2] as $leg) {
                // The first $depth members on this leg, oldest first — exactly
                // what rankOnLeg() <= released_depth resolved to.
                $memberIds = \Illuminate\Support\Facades\DB::table('group_members')
                    ->where('group_id', $groupId)
                    ->where('tier', $leg)
                    ->orderBy('id')
                    ->limit((int) $depth)
                    ->pluck('id');

                if ($memberIds->isEmpty()) {
                    continue;
                }

                $stamped += \Illuminate\Support\Facades\DB::table('commissions')
                    ->whereIn('group_member_id', $memberIds)
                    ->where('type', CommissionService::TYPE_SLOT)
                    ->whereNull('released_at')
                    ->update(['released_at' => now()]);
            }
        }

        // ⚠️⚠️ 'approved' REMOVED FROM THIS LIST (2026-08-31). It read
        // whereIn(['approved', 'paid']).
        //
        // That was written while approval was a human step, so an approved row
        // genuinely meant "an accountant has actioned this". Since 2026-08-25
        // EVERY slot row is auto-approved the instant its payment is verified —
        // so this line would now stamp released_at on EVERY commission in the
        // database, taking every rupee off every leg at once, with no System Run
        // and no way to tell it had happened.
        //
        // It has not fired on the live site because it only runs when
        // `released_at` is first created and that column already exists there.
        // But it WOULD fire on any fresh environment — a staging copy, a
        // restored backup, a new install — and empty the whole network on the
        // first request. Found while hunting a report of accounts flushing
        // without being balanced; it was not that cause, but it is the same
        // kind of accident waiting to happen.
        //
        // PAID is the only safe test: that money really has left, so it must
        // not sit on a leg and be forfeited a second time. An approved row is
        // ordinary income still waiting for its pair.
        $stamped += \Illuminate\Support\Facades\DB::table('commissions')
            ->where('status', 'paid')
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        return $stamped;
    }

    /** @return array<int, string> what (if anything) was changed */
    public static function ensure(): array
    {
        $log = [];

        $consultantColumns = [
            'finance_status' => fn (Blueprint $t) => $t->string('finance_status', 20)->default('active')->index(),
            'finance_period_key' => fn (Blueprint $t) => $t->string('finance_period_key', 10)->nullable(),
            'finance_restricted_period' => fn (Blueprint $t) => $t->date('finance_restricted_period')->nullable(),
            'finance_restricted_at' => fn (Blueprint $t) => $t->timestamp('finance_restricted_at')->nullable(),
            'finance_suspended_at' => fn (Blueprint $t) => $t->timestamp('finance_suspended_at')->nullable(),
            'finance_reactivated_at' => fn (Blueprint $t) => $t->timestamp('finance_reactivated_at')->nullable(),
            'finance_reactivated_by' => fn (Blueprint $t) => $t->unsignedBigInteger('finance_reactivated_by')->nullable(),

            // Staged group income (ServiceGroupStaging) — how many of the two
            // one-time stages (1-to-1, 2-to-2) this PERSON has consumed.
            'lifetime_pair_stage' => fn (Blueprint $t) => $t->unsignedTinyInteger('lifetime_pair_stage')->default(0),

            // The 5-year maturity track (ConsultantMaturityService).
            'mature_months' => fn (Blueprint $t) => $t->unsignedSmallInteger('mature_months')->default(0),
            'mature_month_key' => fn (Blueprint $t) => $t->string('mature_month_key', 10)->nullable(),
            'mature_at' => fn (Blueprint $t) => $t->timestamp('mature_at')->nullable(),
            'suspension_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('suspension_count')->default(0),

            // An admin's manual override of the 12-students account unlock
            // (2026-08-17). NULL — the default and the only value any existing
            // row gets — means "follow the automatic rule", so adding this
            // column changes nothing until somebody deliberately clicks.
            'manual_lock_override' => fn (Blueprint $t) => $t->string('manual_lock_override', 10)->nullable(),
            'manual_lock_at' => fn (Blueprint $t) => $t->timestamp('manual_lock_at')->nullable(),
            'manual_lock_by' => fn (Blueprint $t) => $t->unsignedBigInteger('manual_lock_by')->nullable(),

            // ⚠️ MAY THIS CONSULTANT SEE THEIR DOWNLINE'S ADMISSIONS? (2026-09-02)
            //
            // Default FALSE, and that is the whole point: a consultant who signs
            // up tomorrow gets it off, the Admission Report keeps showing them
            // only their own accounts, and the downline controls are not
            // rendered at all. An admin turns it on per person from User
            // Management. Owner: "aluthin ena consultant lata me function eka
            // block krnn one nisa, user management eken access denn".
            //
            // A COLUMN rather than a Spatie permission on purpose. Permissions
            // are seeded, and this host has no CLI to run a seeder — a
            // permission that does not exist in the table makes every check
            // throw. The rest of this app gates on roles and self-healing
            // columns for exactly that reason; this follows it rather than
            // introducing a second mechanism that only works locally.
            'can_view_downline_report' => fn (Blueprint $t) => $t->boolean('can_view_downline_report')->default(false),
            'downline_report_granted_at' => fn (Blueprint $t) => $t->timestamp('downline_report_granted_at')->nullable(),
            'downline_report_granted_by' => fn (Blueprint $t) => $t->unsignedBigInteger('downline_report_granted_by')->nullable(),

            // ⚠️ WHICH OPTIONAL PAGES THIS CONSULTANT MAY OPEN (2026-09-22).
            //
            // A JSON list of Consultant::PAGE_ACCESS keys. NULL = none, which is
            // the owner's chosen default for everyone: "access eken dena widiyt
            // dala denn one buy one access denn saha okkom access denn". A column,
            // for the same no-CLI reason as can_view_downline_report above.
            'page_access' => fn (Blueprint $t) => $t->text('page_access')->nullable(),
            'page_access_updated_at' => fn (Blueprint $t) => $t->timestamp('page_access_updated_at')->nullable(),
            'page_access_updated_by' => fn (Blueprint $t) => $t->unsignedBigInteger('page_access_updated_by')->nullable(),

            // WHICH LEG of the sponsor this consultant hangs off (2026-08-19).
            //
            // Needed because a placement may now target the sponsor's MAIN
            // account, not only a hidden one. The 2026-08-18 rule derives the
            // leg from the placement account's ORDINAL (1st hidden -> leg 1,
            // 2nd -> leg 2), which says nothing about a main account — there
            // sideUnderParent() fell through to a seat lookup that returns null
            // for a consultant who was never seated as a student there, and the
            // leg then defaulted to fill order. Owner's instruction: "main ho
            // hiden account walata place kragann puluwn widiyta hadann, main
            // ekata select karoth Service group 1 or 2 walata 2500 yana widiyta
            // hadann."
            //
            // NULL keeps the existing ordinal/seat behaviour untouched, so
            // adding this changes nothing for any consultant already placed.
            'placement_tier' => fn (Blueprint $t) => $t->unsignedTinyInteger('placement_tier')->nullable(),

            // Which PAYMENT opened this account, when it came from a
            // consultant buying another course (2026-08-20). Purely an
            // idempotency marker: verification can be re-run, and without it a
            // second run would open a second set of three SCN accounts.
            // NULL for every account created any other way.
            'opened_by_payment_id' => fn (Blueprint $t) => $t->unsignedBigInteger('opened_by_payment_id')->nullable()->index(),
            // ⚠️ AN EXPLICIT PLACEMENT OVERRIDE (2026-08-31).
            //
            // A bought set's position is normally DERIVED — opened_by_payment_id
            // -> payments.consultant_id, the account chosen at checkout. When
            // nothing was chosen (or the choice was lost), that resolves to the
            // main or to nothing, the climb has no parent to walk to, and the
            // upline 2,500 never reaches the account above. Reported for
            // SCN-00122: "5th account eke upline ekedi 2nd account ekat
            // calculate wela na, main account ekat gihilla cross wela."
            //
            // Correcting it by rewriting payments.consultant_id would rewrite
            // WHO EARNED that purchase, which is real money and not ours to
            // move. So the correction lives here instead: when set, this wins
            // over the derived placement and nothing about the payment changes.
            'placed_under_consultant_id' => fn (Blueprint $t) => $t->unsignedBigInteger('placed_under_consultant_id')->nullable()->index(),
        ];

        foreach ($consultantColumns as $column => $define) {
            if (! Schema::hasColumn('consultants', $column)) {
                Schema::table('consultants', fn (Blueprint $t) => $define($t));
                $log[] = "consultants: added the missing {$column} column.";
            }
        }

        if (! Schema::hasColumn('commissions', 'held_at')) {
            Schema::table('commissions', function (Blueprint $table) {
                $table->timestamp('held_at')->nullable();
                $table->index(['consultant_id', 'held_at'], 'commissions_consultant_held_idx');
            });
            $log[] = 'commissions: added the missing held_at column.';
        }

        // Who removed this commission by hand, and when (2026-09-06).
        //
        // `removed_at` / `removed_by` answer a question `status = 'cancelled'`
        // cannot: a System Run forfeit and an accountant's correction both land
        // on that same status, and without these the Commission Health screens
        // would report a correction as company overflow.
        //
        // ⚠️ THEY ALSO GATE THE ZEROING. ManagesServiceIncome::removeOne() only
        // sets `amount` to 0 once `removed_amount` exists to hold the original
        // — until then a removal is still a full cancellation, it just keeps
        // its printed figure. So this block is what "SCN ekat adala hama
        // thaninm 0 wenn one" actually waits on, which is why the Service
        // Income page runs the repair itself.
        //
        // Nullable with no backfill: every existing row was NOT removed by
        // hand, which is what NULL already says.
        if (! Schema::hasColumn('commissions', 'removed_at')) {
            Schema::table('commissions', function (Blueprint $table) {
                $table->timestamp('removed_at')->nullable();
                $table->unsignedBigInteger('removed_by')->nullable();
                // What the row was worth before it was zeroed.
                //
                // ⚠️ REMOVAL SETS `amount` TO 0, and that is the real guarantee
                // behind "hama thaninm 0 wenn one". Filtering `cancelled` out of
                // every SUM would work only for as long as nobody adds a
                // fourteenth one; zeroing makes it a property of the DATA.
                // The filters are still applied — a COUNT(*) does not care that
                // the amount is 0 — but they are the second lock, not the first.
                $table->decimal('removed_amount', 10, 2)->nullable();
                $table->index(['consultant_id', 'removed_at'], 'commissions_consultant_removed_idx');
            });
            $log[] = 'commissions: added the missing removed_at / removed_by / removed_amount columns.';
        }

        // How many matched pairs this account has had RELEASED for payout.
        // Monotonic; see ServiceGroupStaging::advance().
        if (Schema::hasTable('groups') && ! Schema::hasColumn('groups', 'released_depth')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->unsignedSmallInteger('released_depth')->default(0);
            });
            $log[] = 'groups: added the missing released_depth column.';
        }

        // When a manual flush-out unlocked this slot commission for payout
        // (ServiceGroupFlush). NULL = still sitting on its leg, counting
        // towards that leg's flush balance.
        if (! Schema::hasColumn('commissions', 'released_at')) {
            Schema::table('commissions', function (Blueprint $table) {
                $table->timestamp('released_at')->nullable();
                $table->index(['consultant_id', 'released_at'], 'commissions_consultant_released_idx');
            });
            $log[] = 'commissions: added the missing released_at column.';

            // Carry the OLD rule's already-released money across, or every
            // consultant's approvable income would silently become
            // unapprovable the moment this column appears. Under the previous
            // model a slot row was payable when its rank on its leg was within
            // groups.released_depth; those rows must stay payable.
            $log[] = self::backfillReleasedAt().' commission(s) carried over from the old released_depth rule.';
        }

        // The day the money arrived, as opposed to the day an accountant got
        // round to verifying it. See earnedAtReady() for the report behind it.
        //
        // ⚠️ ADDED EMPTY, ON PURPOSE. Every existing row stays NULL and falls
        // back to created_at, so uploading this changes nothing on screen until
        // the owner presses Commission Health → Repair. That was their choice:
        // a schema change that silently re-dated a year of income the moment it
        // deployed is not something anyone can review.
        if (! Schema::hasColumn('commissions', 'earned_at')) {
            Schema::table('commissions', function (Blueprint $table) {
                $table->timestamp('earned_at')->nullable();
                $table->index(['consultant_id', 'earned_at'], 'commissions_consultant_earned_idx');
            });
            $log[] = 'commissions: added the missing earned_at column. Run Repair to date existing income by its payslip.';
        }

        // Audit trail for the daily System Run. A run permanently forfeits
        // money across every account at once, so there must be a record of
        // who ran it, when, against which cutoff, and what it moved.
        if (! Schema::hasTable('system_runs')) {
            Schema::create('system_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cutoff_at');
                $table->unsignedInteger('accounts_flushed')->default(0);
                $table->decimal('total_released', 12, 2)->default(0);
                $table->decimal('total_forfeited', 12, 2)->default(0);
                // Which accounts actually moved, as JSON. A run reports "1
                // account" and that is useless without saying WHICH one —
                // asked for 2026-08-15.
                $table->text('accounts')->nullable();
                $table->text('failures')->nullable();
                $table->timestamps();

                $table->index('created_at');
            });
            $log[] = 'system_runs: created the missing table.';
        }

        // Added after the table itself, so an install that already has
        // system_runs still picks it up.
        if (Schema::hasTable('system_runs') && ! Schema::hasColumn('system_runs', 'accounts')) {
            Schema::table('system_runs', function (Blueprint $table) {
                $table->text('accounts')->nullable();
            });
            $log[] = 'system_runs: added the missing accounts column.';
        }

        if (! Schema::hasTable('consultant_finance_periods')) {
            Schema::create('consultant_finance_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('consultant_id')->constrained('consultants')->cascadeOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->unsignedSmallInteger('students_required');
                $table->unsignedSmallInteger('students_completed')->default(0);
                $table->string('result', 10);
                $table->string('outcome', 20);
                $table->timestamp('evaluated_at')->nullable();
                $table->timestamps();

                $table->unique(['consultant_id', 'period_start'], 'cfp_consultant_period_unique');
                $table->index('period_start');
            });
            $log[] = 'consultant_finance_periods: created the missing table.';
        }

        // Both layers, or the request that just repaired the schema would keep
        // reading its own stale "not ready" memo for the rest of its life.
        self::$memo = [];
        Cache::forget('finance.schema.ready');
        Cache::forget('finance.maturity.ready');
        Cache::forget('finance.staging.ready');
        Cache::forget('finance.flush.ready');
        Cache::forget('finance.lockoverride.ready');
        Cache::forget('finance.placementtier.ready');
        Cache::forget('finance.removal.ready');
        // ⚠️ WAS MISSING (found 2026-09-06). Without this, an ensure() that
        // creates can_view_downline_report leaves the "not ready" answer cached
        // for an hour, so the feature stays invisible on a host that has just
        // healed itself. Same class of bug the memo docblock at the top warns
        // about — the flag and the schema disagreeing.
        Cache::forget('finance.downlinereport.ready');
        Cache::forget('finance.pageaccess.ready');
        Cache::forget(FinanceWeek::CACHE_KEY);
        \App\Services\ServiceGroupStaging::forget();

        return $log;
    }
}
