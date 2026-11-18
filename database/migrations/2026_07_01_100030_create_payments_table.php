<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_code')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            // Set once the enrollment is created (no FK to avoid circular dependency with enrollments).
            $table->unsignedBigInteger('enrollment_id')->nullable()->index();
            $table->foreignId('consultant_id')->nullable()->constrained('consultants')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('LKR');
            $table->enum('method', ['bank_transfer', 'online', 'cash'])->default('bank_transfer');
            $table->string('reference_number')->nullable();
            $table->string('receipt_path')->nullable();
            $table->enum('status', ['pending', 'verified', 'rejected', 'refunded'])->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
