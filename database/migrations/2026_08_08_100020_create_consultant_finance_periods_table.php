<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One frozen row per consultant per CLOSED finance week — the audit trail and
 * report source for the weekly student-quota rule.
 *
 * `students_required` is a SNAPSHOT of the setting at the moment the period
 * was evaluated, not a live read. Without this, raising the quota from 2 to 3
 * would retroactively make an already-passed week look like it was missed.
 *
 * The live (open, not-yet-closed) period is never stored here — it is always
 * computed on the fly by ConsultantFinanceService, since it can still change.
 *
 * This table is never read by the restrict/suspend state machine itself
 * (that lives entirely in consultants.finance_*), only by the Finance Report
 * and the admin audit trail — so a missing table can never break gating, only
 * make the report temporarily show live-computed figures until the next
 * evaluation writes it. See FinanceSchemaRepair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultant_finance_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_id')->constrained('consultants')->cascadeOnDelete();
            $table->date('period_start'); // local (Asia/Colombo) date
            $table->date('period_end');   // local date, inclusive (the last calendar day of the period)
            $table->unsignedSmallInteger('students_required');
            $table->unsignedSmallInteger('students_completed')->default(0);
            $table->string('result', 10);   // 'met' | 'missed'
            $table->string('outcome', 20);  // consultant's finance_status immediately after this period was applied
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['consultant_id', 'period_start'], 'cfp_consultant_period_unique');
            $table->index('period_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_finance_periods');
    }
};
