<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a consultant explicitly place a student into Service Group 1 or 2 at
 * registration, instead of the engine always deriving the tier from sequential
 * fill order. Groups now track each tier's fill count independently so a tier
 * can be chosen/filled without waiting for the other tier to complete first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedInteger('tier1_filled')->default(0)->after('filled_slots');
            $table->unsignedInteger('tier2_filled')->default(0)->after('tier1_filled');
        });

        Schema::table('group_members', function (Blueprint $table) {
            $table->unsignedTinyInteger('tier')->nullable()->after('position');
        });

        Schema::table('payments', function (Blueprint $table) {
            // Which service group the consultant explicitly chose at registration.
            // Null = let the engine decide automatically (legacy / self-checkout).
            $table->unsignedTinyInteger('requested_tier')->nullable()->after('consultant_id');
        });

        // Backfill group_members.tier from the commission row already recorded
        // for it. Query-builder form so this also runs on SQLite (tests);
        // identical result to the previous UPDATE..JOIN on MySQL.
        DB::table('commissions')
            ->whereNotNull('group_member_id')
            ->whereNotNull('tier')
            ->select('group_member_id', 'tier')
            ->orderBy('group_member_id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('group_members')
                        ->where('id', $row->group_member_id)
                        ->whereNull('tier')
                        ->update(['tier' => $row->tier]);
                }
            });

        // Backfill each group's per-tier fill counts from its existing members.
        DB::table('groups')->select('id')->orderBy('id')->chunk(500, function ($groups) {
            foreach ($groups as $group) {
                DB::table('groups')->where('id', $group->id)->update([
                    'tier1_filled' => DB::table('group_members')->where('group_id', $group->id)->where('tier', 1)->count(),
                    'tier2_filled' => DB::table('group_members')->where('group_id', $group->id)->where('tier', 2)->count(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('requested_tier');
        });

        Schema::table('group_members', function (Blueprint $table) {
            $table->dropColumn('tier');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['tier1_filled', 'tier2_filled']);
        });
    }
};
