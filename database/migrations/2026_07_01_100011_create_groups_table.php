<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('group_code')->unique();
            $table->foreignId('consultant_id')->constrained('consultants')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->unsignedInteger('capacity')->default(6);
            $table->unsignedInteger('filled_slots')->default(0);
            $table->enum('status', ['open', 'completed', 'closed'])->default('open');
            $table->decimal('total_commission_earned', 12, 2)->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['consultant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
