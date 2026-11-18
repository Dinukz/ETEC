<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⚠️ THE DAILY JOBS, WITHOUT A CRON (2026-11-11).
 *
 * students:purge-unpaid (a new registration still unpaid after 14 days is
 * removed) was only ever on the scheduler, and Hostinger runs no cron for it —
 * so it never ran: "kalinuth meka haduwa api eth wada krn na". The owner has no
 * command line to run it by hand either.
 *
 * So the first page anyone opens on a new Sri Lanka day starts it, AFTER that
 * page has been sent (terminate) — the visitor never waits for it. Cache::add()
 * lets exactly one request a day through; a second run would find nothing to
 * do anyway. Never redirects, never blocks a request, and a failure is only
 * reported.
 */
class RunDailyHousekeeping
{
    /** Tests switch this on to exercise it; otherwise every test request would run the purge. */
    public static bool $runInTests = false;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (app()->runningUnitTests() && ! self::$runInTests) {
            return;
        }

        try {
            $today = now('Asia/Colombo')->toDateString();

            if (! Cache::add('housekeeping.daily.'.$today, true, now()->addHours(26))) {
                return;
            }

            Artisan::call('students:purge-unpaid');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
