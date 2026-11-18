<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two related additions for the 2026-08-09 commission rework:
 *
 * 1. `lifetime_pair_stage` — how many of the two ONE-TIME staged releases
 *    (1-to-1, then 2-to-2) this PERSON has already consumed. It lives on the
 *    consultant rather than the account precisely because the owner confirmed
 *    those two stages are once-per-consultant-lifetime, which is what makes a
 *    second account start straight at 3-to-3. See ServiceGroupStaging.
 *
 * 2. The 5-year maturity track — 60 qualifying 28-day "consultant months"
 *    makes a consultant permanently exempt from the weekly student quota.
 *    `suspension_count` is lifetime and non-consecutive; the 4th suspension
 *    resets `mature_months` to zero. See ConsultantMaturityService.
 *
 * All columns are additive with safe defaults, so this applies cleanly on top
 * of whatever state this database's migrations ledger is actually in — and
 * FinanceSchemaRepair adds exactly the same set idempotently for the (normal,
 * on this host) case where migrations never run at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultants', function (Blueprint $table) {
            $table->unsignedTinyInteger('lifetime_pair_stage')->default(0)->after('total_paid');
            $table->unsignedSmallInteger('mature_months')->default(0)->after('lifetime_pair_stage');
            $table->string('mature_month_key', 10)->nullable()->after('mature_months');
            $table->timestamp('mature_at')->nullable()->after('mature_month_key');
            $table->unsignedSmallInteger('suspension_count')->default(0)->after('mature_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultants', function (Blueprint $table) {
            $table->dropColumn([
                'lifetime_pair_stage', 'mature_months', 'mature_month_key', 'mature_at', 'suspension_count',
            ]);
        });
    }
};
