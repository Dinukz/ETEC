<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens `payments.payment_type` to add 'balance' — the second Payment row
 * created when a half-paying student's remaining course balance is settled.
 *
 * A plain enum column can't gain a new allowed value through Schema::table()
 * ->change() without doctrine/dbal, which this app does not have installed,
 * so this modifies the column directly. Safe to run more than once — MySQL
 * accepts re-applying the same enum definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments MODIFY payment_type ENUM('full','half','free_card','balance') NULL");

            return;
        }

        // ⚠️ SQLITE WAS LEFT OUT, AND IT COST REAL COVERAGE (2026-08-23).
        //
        // The whole method was wrapped in the mysql check, so on sqlite the
        // column kept the original CHECK constraint listing only
        // full/half/free_card. Every test that records a balance payment died
        // with "CHECK constraint failed: payment_type" — which is why the
        // balance path, one of the money paths, had NO working test at all
        // (BalancePaymentTest sat red for weeks and was written off as
        // "sqlite-only"). That stopped being tolerable the moment the balance
        // payment started earning slot income (2026-08-23).
        //
        // sqlite cannot alter a CHECK in place; ->change() rebuilds the table,
        // which is fine because sqlite stores it as TEXT regardless. Production
        // is MySQL and takes the branch above, so its enum is untouched.
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_type', 20)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments MODIFY payment_type ENUM('full','half','free_card') NULL");
        }
    }
};
