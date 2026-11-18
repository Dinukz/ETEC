<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hall_id')->constrained('halls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('booking_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'cancelled'])->default('approved')->index();
            $table->timestamps();

            $table->index(['hall_id', 'booking_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_bookings');
    }
};
