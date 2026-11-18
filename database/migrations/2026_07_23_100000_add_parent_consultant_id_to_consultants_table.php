<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultants', function (Blueprint $table) {
            // The consultant who referred this consultant (their sponsor / upline).
            // Set from the ref link used when they first registered as a student.
            $table->foreignId('parent_consultant_id')->nullable()->after('user_id')
                ->constrained('consultants')->nullOnDelete();
        });

        // Backfill: a consultant's sponsor is whoever referred them when they were a
        // student (students.consultant_id for their own user account).
        // Written with the query builder rather than UPDATE..JOIN so it runs on
        // SQLite too (the test database); the result is identical on MySQL.
        DB::table('students')
            ->whereNotNull('consultant_id')
            ->select('user_id', 'consultant_id')
            ->orderBy('user_id')
            ->chunk(500, function ($students) {
                foreach ($students as $student) {
                    DB::table('consultants')
                        ->where('user_id', $student->user_id)
                        ->where('id', '<>', $student->consultant_id)
                        ->update(['parent_consultant_id' => $student->consultant_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('consultants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_consultant_id');
        });
    }
};
