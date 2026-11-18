<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many service-group student slots a commission row settled. A "1-to-1"
 * pair settles 2 at once and a filled leg settles 3, so the engine can no
 * longer assume one row = one student. Counting settled slots is also what
 * makes re-running the engine idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('commissions', 'slots')) {
            return;
        }

        Schema::table('commissions', function (Blueprint $table) {
            $table->unsignedInteger('slots')->default(1)->after('amount');
        });

        // Existing rows were one-student-per-row under the old engine.
        DB::table('commissions')->update(['slots' => 1]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('commissions', 'slots')) {
            return;
        }

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn('slots');
        });
    }
};
