<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a commission as HELD without touching its `status`. A held row stays
 * `pending` — every existing status-based query keeps its current meaning,
 * and the money still shows up wherever "pending" already means "recorded,
 * not yet the consultant's". held_at IS NULL = normal; NOT NULL = held.
 *
 * Deliberately not a new `status` enum value: this app has no doctrine/dbal,
 * so widening an enum needs a raw ALTER TABLE (see CommissionSchemaRepair for
 * the established pattern), and every one of the ~20 places that filter on
 * `commissions.status` would need auditing for whether a held row should
 * count. An additive nullable column changes none of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->timestamp('held_at')->nullable()->after('status');
            $table->index(['consultant_id', 'held_at'], 'commissions_consultant_held_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropIndex('commissions_consultant_held_idx');
            $table->dropColumn('held_at');
        });
    }
};
