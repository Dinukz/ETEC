<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Self-healing schema for the course-video viewing rules.
 *
 * Same pattern, and the same reason, as FinanceSchemaRepair: production has no
 * CLI, so a migration can never be run there. The columns are created by the
 * first request that needs them, guarded by hasColumn/hasTable, and the answer
 * is memoised so the check costs nothing afterwards.
 *
 * ⚠️ NEVER CALL ensure() INSIDE AN OPEN TRANSACTION — an ALTER/CREATE
 * auto-commits under MySQL and would silently commit whatever the caller had
 * open.
 */
class LmsSchemaRepair
{
    private static ?bool $memo = null;

    public static function forgetMemo(): void
    {
        self::$memo = null;
        Cache::forget('lms.video.ready');
    }

    /** True once the viewing-window columns and the requests table exist. */
    public static function ready(): bool
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            return self::$memo = Cache::remember(
                'lms.video.ready',
                now()->addMinutes(10),
                fn () => Schema::hasTable('lesson_view_requests')
                    && Schema::hasColumn('lesson_progress', 'window_expires_at'),
            );
        } catch (\Throwable $e) {
            return self::$memo = false;
        }
    }

    /** @return array<int, string> a log line per change */
    public static function ensure(): array
    {
        $log = [];

        try {
            if (Schema::hasTable('lesson_progress') && ! Schema::hasColumn('lesson_progress', 'window_expires_at')) {
                Schema::table('lesson_progress', function (Blueprint $table) {
                    // How many separate viewing windows this learner has opened
                    // on this lesson. The first play opens window 1.
                    $table->unsignedInteger('view_count')->default(0);

                    // While this is in the future the lesson plays freely —
                    // pause, seek, rewatch. Once it passes, another window has
                    // to be granted.
                    $table->timestamp('window_expires_at')->nullable();

                    // Extra windows an admin has granted on request, on top of
                    // the one everybody gets.
                    $table->unsignedSmallInteger('extra_views_granted')->default(0);

                    $table->timestamp('last_viewed_at')->nullable();
                });

                $log[] = 'lesson_progress: added the viewing-window columns.';
            }

            if (! Schema::hasTable('lesson_view_requests')) {
                Schema::create('lesson_view_requests', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                    $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
                    $table->string('status', 20)->default('pending');
                    $table->text('reason')->nullable();
                    $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->timestamp('decided_at')->nullable();
                    $table->timestamps();

                    // One open request per person per lesson — the screen asks
                    // "have they already asked?" on every page load.
                    $table->index(['lesson_id', 'user_id', 'status'], 'lesson_view_requests_open_idx');
                    $table->index('status');
                });

                $log[] = 'lesson_view_requests: created the missing table.';
            }
        } catch (\Throwable $e) {
            report($e);
            $log[] = 'lesson video schema: could not be repaired — '.$e->getMessage();
        }

        self::forgetMemo();

        return $log;
    }
}
