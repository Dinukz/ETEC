<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tiny outbox for transactional email.
 *
 * The System Run's announcement and completion notices go to every active
 * consultant. Sent inline that is several hundred blocking SMTP round trips
 * inside one HTTP request — the single biggest contributor to the 504s on the
 * run screen. Enqueue here, deliver from App\Console\Commands\SendPendingNotices.
 *
 * Deliberately NOT Laravel's queue: this account has no worker process, so
 * anything dispatched to a queue would sit there for ever.
 *
 * SAFE WHEN THE TABLE IS MISSING. A half-deployed server (files uploaded, SQL
 * not yet run) must not take down the System Run, so every method degrades:
 * queue() falls back to sending immediately, which is exactly the old
 * behaviour.
 */
class PendingNotice
{
    /** Cached so a run enqueueing 400 rows does not ask the schema 400 times. */
    protected static ?bool $ready = null;

    public static function tableReady(): bool
    {
        if (static::$ready === null) {
            try {
                static::$ready = Schema::hasTable('pending_notices');
            } catch (\Throwable $e) {
                static::$ready = false;
            }
        }

        return static::$ready;
    }

    /**
     * Queue one notice. Returns true when it was stored OR sent.
     *
     * $type is the TransactionalMail method to call; $payload its arguments
     * after email and name, in order.
     */
    public static function queue(string $type, string $email, string $name, array $payload = []): bool
    {
        if (! static::tableReady()) {
            return static::deliver($type, $email, $name, $payload);
        }

        try {
            DB::table('pending_notices')->insert([
                'type' => $type,
                'email' => $email,
                'name' => $name,
                'payload' => json_encode($payload),
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return static::deliver($type, $email, $name, $payload);
        }
    }

    /** How many are still waiting — drives the button's label on the run screen. */
    public static function pendingCount(): int
    {
        if (! static::tableReady()) {
            return 0;
        }

        try {
            return (int) DB::table('pending_notices')->whereNull('sent_at')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Send up to $limit queued notices.
     *
     * @return array{sent:int, failed:int, remaining:int}
     */
    public static function drain(int $limit = 100): array
    {
        if (! static::tableReady()) {
            return ['sent' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $sent = 0;
        $failed = 0;

        $rows = DB::table('pending_notices')
            ->whereNull('sent_at')
            // Five strikes and it stops being retried — a permanently bad
            // address must not block the rest of the outbox for ever.
            ->where('attempts', '<', 5)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            // Claim first. The scheduler and the manual button can both be
            // running; whoever bumps attempts first owns this row, and the
            // other one simply sends nothing extra.
            $claimed = DB::table('pending_notices')
                ->where('id', $row->id)
                ->whereNull('sent_at')
                ->where('attempts', $row->attempts)
                ->update(['attempts' => $row->attempts + 1, 'updated_at' => now()]);

            if (! $claimed) {
                continue;
            }

            $payload = json_decode($row->payload ?? '[]', true) ?: [];

            if (static::deliver($row->type, $row->email, $row->name, $payload)) {
                DB::table('pending_notices')->where('id', $row->id)->update([
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'remaining' => static::pendingCount(),
        ];
    }

    /** The actual send. TransactionalMail already catches and reports its own failures. */
    protected static function deliver(string $type, string $email, string $name, array $payload): bool
    {
        try {
            switch ($type) {
                case 'system_run_notice':
                    return TransactionalMail::systemRunNotice(
                        $email,
                        $name,
                        $payload[0] ?? '',
                        $payload[1] ?? '',
                        $payload[2] ?? '',
                    );

                case 'system_run_completed':
                    return TransactionalMail::systemRunCompleted(
                        $email,
                        $name,
                        $payload[0] ?? '',
                    );
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return false;
    }
}
