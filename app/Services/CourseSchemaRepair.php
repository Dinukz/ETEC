<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Self-healing schema for a course's OWN money values (2026-10-22).
 *
 * The owner's new course "MASTER IN ELLIOTT WAVE" is 39,000 with a 20,000
 * first instalment and pays the registering consultant 5,000 direct, where
 * every other course uses the standard 18,000 / 4,000. So a course may now
 * carry its own half-payment amount and its own direct service income; blank
 * means "use the standard" (Settings), which is what every existing course has.
 *
 * Same pattern and reason as ExamSchemaRepair: production has no CLI, so the
 * columns are added by the first course-screen request that needs them. No
 * migration file — Commission Health reports a migration the live ledger has
 * never run as "genuinely not applied", forever.
 *
 * ⚠️ NEVER CALL ensure() INSIDE AN OPEN TRANSACTION — ALTER auto-commits under
 * MySQL. The money code does not need it at all: App\Support\CourseMoney reads
 * the values straight off the row and falls back to the standard when absent.
 */
class CourseSchemaRepair
{
    private static ?bool $memo = null;

    public static function forgetMemo(): void
    {
        self::$memo = null;
        Cache::forget('courses.schema.ready');
    }

    public static function ready(): bool
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            return self::$memo = (bool) Cache::remember(
                'courses.schema.ready',
                now()->addMinutes(10),
                fn () => Schema::hasColumn('courses', 'half_payment_amount')
                    && Schema::hasColumn('courses', 'direct_commission'),
            );
        } catch (\Throwable) {
            return self::$memo = false;
        }
    }

    /** The cheap call for the course screens: repair only when not known to be ready. */
    public static function heal(): void
    {
        if (! self::ready()) {
            self::ensure();
        }
    }

    /**
     * Always looks at the real columns (never trusts the memo), then resets it.
     *
     * @return array<int, string> one line per change
     */
    public static function ensure(): array
    {
        $log = [];

        try {
            if (Schema::hasTable('courses') && ! Schema::hasColumn('courses', 'half_payment_amount')) {
                Schema::table('courses', function (Blueprint $table) {
                    // The first instalment of the half plan for THIS course;
                    // null = the standard (Settings → Half Payment).
                    $table->decimal('half_payment_amount', 10, 2)->nullable();
                });

                $log[] = 'courses: added half_payment_amount.';
            }

            if (Schema::hasTable('courses') && ! Schema::hasColumn('courses', 'direct_commission')) {
                Schema::table('courses', function (Blueprint $table) {
                    // The direct service income for registering a student on
                    // THIS course; null = the standard (Settings → Direct Add Bonus).
                    $table->decimal('direct_commission', 10, 2)->nullable();
                });

                $log[] = 'courses: added direct_commission.';
            }
        } catch (\Throwable $e) {
            report($e);
            $log[] = 'course schema: could not be repaired — '.$e->getMessage();
        }

        self::forgetMemo();

        return $log;
    }
}
