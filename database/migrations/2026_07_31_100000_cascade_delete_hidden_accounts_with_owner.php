<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A consultant's two hidden placement accounts (SCN-00101/00102) are OWNED by
 * them, not merely referred by them — no login, no user_id, no independent
 * existence. Deleting a consultant previously left these orphaned forever
 * (owner_consultant_id just went NULL) instead of removing them. Application
 * code now explicitly deletes them when their owner is deleted (see
 * UserManagementController::destroy()) — this migration is a one-time cleanup
 * for anything already orphaned under the old behaviour before that fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('consultants')
            ->where('is_hidden', true)
            ->whereNull('owner_consultant_id')
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($orphanId) {
                DB::table('groups')->where('consultant_id', $orphanId)->delete();
                DB::table('commissions')->where('consultant_id', $orphanId)->delete();
                DB::table('consultants')->where('id', $orphanId)->delete();
            });
    }

    public function down(): void
    {
        // Data cleanup only — intentionally not reversible.
    }
};
