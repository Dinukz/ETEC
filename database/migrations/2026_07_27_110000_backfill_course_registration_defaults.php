<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "auto-fill Language/Class Type/Course Type on Add Student" feature reads
 * each course's own default_language / default_class_type / default_course_type.
 * No course ever had these set (not seeded, and admins hadn't filled them in),
 * so selecting a course on the registration form silently filled in nothing.
 * Backfills the common baseline (Sinhala / Online / Group) for any course still
 * missing a default — admins can still override per course afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('courses')->where(fn ($q) => $q->whereNull('default_language')->orWhere('default_language', ''))
            ->update(['default_language' => 'Sinhala']);

        DB::table('courses')->where(fn ($q) => $q->whereNull('default_class_type')->orWhere('default_class_type', ''))
            ->update(['default_class_type' => 'Online']);

        DB::table('courses')->where(fn ($q) => $q->whereNull('default_course_type')->orWhere('default_course_type', ''))
            ->update(['default_course_type' => 'Group']);
    }

    public function down(): void
    {
        // Data backfill only — intentionally not reversible.
    }
};
