<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('course_price', 10, 2)->default(28000);
            $table->decimal('commission_per_student', 10, 2)->default(2500);
            $table->decimal('max_commission', 10, 2)->default(15000);
            $table->unsignedInteger('group_size')->default(6);
            $table->unsignedInteger('first_tier_students')->default(3);
            $table->decimal('first_tier_amount', 10, 2)->default(7500);
            $table->unsignedInteger('second_tier_students')->default(3);
            $table->decimal('second_tier_amount', 10, 2)->default(7500);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('effective_from')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settings');
    }
};
