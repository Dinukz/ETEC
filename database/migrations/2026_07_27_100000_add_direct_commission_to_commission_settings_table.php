<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The consultant who personally registers a student earns a "direct" bonus on
 * top of the service-group slot amount (commission_per_student). Uplines only
 * ever earn the slot amount, never this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('commission_settings', 'direct_commission')) {
            return;
        }

        Schema::table('commission_settings', function (Blueprint $table) {
            $table->decimal('direct_commission', 10, 2)->default(3000)->after('commission_per_student');
        });

        DB::table('commission_settings')->update(['direct_commission' => 3000]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('commission_settings', 'direct_commission')) {
            return;
        }

        Schema::table('commission_settings', function (Blueprint $table) {
            $table->dropColumn('direct_commission');
        });
    }
};
