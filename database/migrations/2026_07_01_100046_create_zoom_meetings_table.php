<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoom_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('lecturer_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('agenda')->nullable();
            $table->string('meeting_id')->nullable();
            $table->string('passcode')->nullable();
            $table->string('join_url')->nullable();
            $table->string('start_url')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->enum('status', ['scheduled', 'live', 'ended', 'cancelled'])->default('scheduled')->index();
            $table->string('recording_url')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoom_meetings');
    }
};
