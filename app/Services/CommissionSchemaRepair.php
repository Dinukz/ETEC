<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guarantees the columns the commission engine depends on actually exist.
 *
 * This database's `migrations` ledger has been found out of sync with its real
 * schema (a table existed without ever being recorded, which makes a blanket
 * `php artisan migrate` abort on "table already exists" and silently strand
 * every migration after it — see CommissionHealthController). Two of those
 * stranded migrations are exactly the columns this engine reads every time it
 * runs: `commission_settings.direct_commission` and `commissions.slots`. When
 * either is missing, nothing crashes — a read of a non-existent column simply
 * comes back `null`, which the engine happily treats as "rate is 0" and skips
 * awarding anything. That silent-zero is indistinguishable from correctly
 * configured settings until you inspect the schema directly.
 *
 * Each check mirrors its original migration's own logic exactly, so this is
 * safe to call as often as needed (every commission settings save, and from
 * the Commission Health "Run Repair" action) without depending on `migrate`
 * ever running cleanly.
 */
class CommissionSchemaRepair
{
    /** @return array<int, string> what (if anything) was changed */
    public static function ensure(): array
    {
        $log = [];

        if (! Schema::hasColumn('commission_settings', 'direct_commission')) {
            Schema::table('commission_settings', function (Blueprint $table) {
                $table->decimal('direct_commission', 10, 2)->default(3000)->after('commission_per_student');
            });
            $log[] = 'commission_settings: added the missing direct_commission column.';
        }

        $filledSettings = DB::table('commission_settings')->whereNull('direct_commission')->update(['direct_commission' => 3000]);

        if ($filledSettings) {
            $log[] = "commission_settings: filled in direct_commission on {$filledSettings} row(s) that had no value.";
        }

        if (! Schema::hasColumn('commissions', 'slots')) {
            Schema::table('commissions', function (Blueprint $table) {
                $table->unsignedInteger('slots')->default(1)->after('amount');
            });
            DB::table('commissions')->update(['slots' => 1]);
            $log[] = 'commissions: added the missing slots column.';
        }

        // 'balance' (the second Payment row created when a half payer settles
        // their remaining course balance) must never be treated by the engine
        // as a fresh registration — see CommissionService::handleVerifiedPayment.
        // Widening a plain enum needs raw SQL: Schema::table()->change() needs
        // doctrine/dbal, which this app does not have installed. This runs on
        // EVERY verify() (see the call sites), so it checks the live column
        // definition first rather than re-running the ALTER unconditionally.
        if (DB::connection()->getDriverName() === 'mysql') {
            $columnType = DB::selectOne(
                "SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_type'"
            )?->type ?? '';

            if (! str_contains($columnType, "'balance'")) {
                DB::statement("ALTER TABLE payments MODIFY payment_type ENUM('full','half','free_card','balance') NULL");
                $log[] = "payments: widened payment_type to allow 'balance'.";
            }
        }

        // Adding a column does nothing for a CommissionSetting instance already
        // sitting in the cache: it was serialised without that attribute, so it
        // keeps reading back as null (a silent zero rate) for the rest of the
        // cache's hour. Drop it so the very next read rebuilds from the real
        // row — without this, repairing the schema still left the direct-add
        // bonus paying nothing.
        Cache::forget('commission_settings.active');

        if ($log) {
            $log[] = 'Cleared the cached commission settings so the new columns are read.';
        }

        // Same self-healing discipline, for the weekly consultant-quota
        // feature's own columns/table. One call site here means every
        // existing entry point that already calls ensure() — PaymentService,
        // BackfillCommissions, SettingController, the Commission Health
        // repair button — automatically covers this feature too, with no
        // risk of a call site being missed.
        $log = array_merge($log, FinanceSchemaRepair::ensure());

        return $log;
    }
}
