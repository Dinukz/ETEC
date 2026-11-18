<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many matched pairs on this account have been RELEASED for payout.
 *
 * Service-group income is RECORDED per student the moment a payment is
 * verified — the consultant sees their 2,500 straight away — but it only
 * becomes approvable once both legs balance a whole stage (1-to-1, then
 * 2-to-2, then 3-to-3 repeating). This counter is how far that has got.
 *
 * Monotonic and idempotent: ServiceGroupStaging::advance() only ever moves it
 * forward, and recomputes it on demand, so a group that balanced BEFORE this
 * column existed catches up the first time anything reads it rather than
 * having its already-earned income stranded at zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('released_depth')->default(0)->after('tier2_filled');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('released_depth');
        });
    }
};
