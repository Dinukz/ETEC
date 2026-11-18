<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly student-quota compliance state, kept entirely separate from
 * `consultants.status`. That column already means "approval state" and is
 * read elsewhere as a proxy for "should this account's referral links still
 * work" (RegisterController, CheckoutController both check
 * status != 'suspended') — overloading it for finance compliance would
 * silently orphan in-flight referrals as a side effect of a missed quota.
 *
 * All columns are additive/nullable, so this migration is safe to apply on
 * top of whatever state this database's migrations ledger is actually in —
 * see FinanceSchemaRepair for why that matters on this host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultants', function (Blueprint $table) {
            $table->string('finance_status', 20)->default('active')->index()->after('status');
            // Local (Asia/Colombo) date key of the last CLOSED period this
            // consultant has been evaluated through, e.g. '2026-08-04'.
            $table->string('finance_period_key', 10)->nullable()->after('finance_status');
            // Which period's miss started the current restriction — the grace
            // clock counts forward from this.
            $table->date('finance_restricted_period')->nullable()->after('finance_period_key');
            $table->timestamp('finance_restricted_at')->nullable()->after('finance_restricted_period');
            $table->timestamp('finance_suspended_at')->nullable()->after('finance_restricted_at');
            $table->timestamp('finance_reactivated_at')->nullable()->after('finance_suspended_at');
            $table->unsignedBigInteger('finance_reactivated_by')->nullable()->after('finance_reactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultants', function (Blueprint $table) {
            $table->dropColumn([
                'finance_status', 'finance_period_key', 'finance_restricted_period',
                'finance_restricted_at', 'finance_suspended_at', 'finance_reactivated_at', 'finance_reactivated_by',
            ]);
        });
    }
};
