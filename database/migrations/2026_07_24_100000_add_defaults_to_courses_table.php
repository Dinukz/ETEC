<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-course defaults for the consultant "Add Student" form — Language, Class
 * Type, Course Type. Selecting a course auto-fills these instead of the
 * consultant picking them blank every time; still overridable per student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('default_language')->nullable()->after('duration_text');
            $table->string('default_class_type')->nullable()->after('default_language');
            $table->string('default_course_type')->nullable()->after('default_class_type');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['default_language', 'default_class_type', 'default_course_type']);
        });
    }
};
