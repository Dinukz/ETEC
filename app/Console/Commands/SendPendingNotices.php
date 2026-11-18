<?php

namespace App\Console\Commands;

use App\Services\PendingNotice;
use Illuminate\Console\Command;

/**
 * Drains the pending_notices table a batch at a time.
 *
 * The System Run used to send its network-wide email INLINE — one blocking SMTP
 * round trip per active consultant, several hundred of them, inside the HTTP
 * request that was also moving the money. That alone was enough to hit the
 * proxy's 60s ceiling and return 504, leaving the run half-finished with no
 * way to tell how far it got.
 *
 * Now the run only writes rows; delivery happens here.
 *
 * TWO TRIGGERS ON PURPOSE. routes/console.php schedules this every minute, but
 * its own comment admits the server's cron may not be running `schedule:run` at
 * all — and a notice queue that silently never drains is worse than the slow
 * version it replaced. So the System Run screen also has a "Send pending
 * emails" button that calls the same service. Whichever fires first wins;
 * PendingNotice::claim() makes a double fire harmless.
 */
class SendPendingNotices extends Command
{
    protected $signature = 'notices:send {--limit=100 : How many to attempt in this pass}';

    protected $description = 'Send queued transactional notices (System Run announcements and completions).';

    public function handle(): int
    {
        if (! PendingNotice::tableReady()) {
            $this->warn('pending_notices table is not present — nothing to do.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $result = PendingNotice::drain($limit);

        $this->info("Sent {$result['sent']}, failed {$result['failed']}, remaining {$result['remaining']}.");

        return self::SUCCESS;
    }
}
