<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guarantees the direct-add bonus actually has a value.
 *
 * The earlier migration that introduced `direct_commission` bailed out early if
 * the column already existed, so on a database where the column was added but
 * its backfill never ran, every row was left NULL. The engine reads
 * `direct_commission ?? 0` and skips the bonus when it is zero — so the setting
 * silently paid nobody while the settings screen still displayed a value.
 *
 * Only NULLs are filled. A deliberate 0 is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commission_settings', 'direct_commission')) {
            Schema::table('commission_settings', function (Blueprint $table) {
                $table->decimal('direct_commission', 10, 2)->default(3000)->after('commission_per_student');
            });
        }

        DB::table('commission_settings')->whereNull('direct_commission')->update(['direct_commission' => 3000]);
    }

    public function down(): void
    {
        // Nothing to undo — this only fills in missing values.
    }
};
