<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Only takes effect if the server's cron actually runs `schedule:run` every
// minute. If that isn't set up, add a direct daily cron job for
// `php artisan students:purge-unpaid` instead — see deployment notes.
Schedule::command('students:purge-unpaid')->daily();

// Drains the transactional-email outbox (App\Services\PendingNotice). The
// System Run only writes rows now — sending several hundred emails inline was
// the biggest single cause of its 504s.
//
// ⚠️ Same caveat as above: this is only reached if cron really runs
// `schedule:run`. Because that is not certain here, the System Run screen also
// carries a "Send pending emails" button that drains the same outbox by hand,
// so the queue can never silently stall.
Schedule::command('notices:send')->everyMinute()->withoutOverlapping();
