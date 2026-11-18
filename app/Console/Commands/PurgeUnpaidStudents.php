<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes student registrations that were never followed up with a confirmed
 * payment plan within 14 days. The consultant's welcome email tells the
 * student to expect this — after the window closes they must register again.
 *
 * Only ever deletes a user whose EVERY payment (across all courses) is still
 * unconfirmed (payment_type null); anyone with even one confirmed payment —
 * full, half or free card — is left alone, so this can't touch a genuine
 * paying student's account.
 *
 * ⚠️ IT NEVER RAN ON LIVE (found 2026-11-11). It was only scheduled
 * (routes/console.php), and Hostinger runs no cron for the scheduler — so
 * nothing was ever removed. App\Http\Middleware\RunDailyHousekeeping now starts
 * it once a day from ordinary site traffic.
 *
 * ⚠️ NEW STUDENTS ONLY — accounts created from NEW_FROM on. The owner: "mn me
 * kiyanne new student lata". On the 3 Nov database copy a first run with no
 * such limit would have deleted 876 September/October registrations at once.
 */
class PurgeUnpaidStudents extends Command
{
    /** Sri Lanka time. Accounts created before this are never removed by this command. */
    public const NEW_FROM = '2026-11-11 00:00:00';

    protected $signature = 'students:purge-unpaid {--days=14 : Grace period in days before an unconfirmed registration is removed}';

    protected $description = 'Permanently delete new student accounts whose payment plan was never confirmed within the grace period';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);
        $newFrom = CarbonImmutable::parse(self::NEW_FROM, 'Asia/Colombo')->utc();

        $candidateUserIds = DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->whereNull('payments.payment_type')
            ->where('payments.status', 'pending')
            ->where('payments.created_at', '<=', $cutoff)
            ->where('users.created_at', '>=', $newFrom)
            ->distinct()
            ->pluck('payments.user_id');

        if ($candidateUserIds->isEmpty()) {
            $this->info('No unpaid registrations older than '.$days.' days.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($candidateUserIds as $userId) {
            // Safety net: never touch an account that has confirmed a payment
            // for ANY course — only fully-unconfirmed registrations are purged.
            $hasConfirmedPayment = DB::table('payments')
                ->where('user_id', $userId)
                ->whereNotNull('payment_type')
                ->exists();

            if ($hasConfirmedPayment) {
                continue;
            }

            $user = User::withTrashed()->find($userId);

            if (! $user || $user->hasAnyRole(['super_admin', 'admin', 'accountant', 'lecturer', 'group_supporter', 'consultant'])) {
                continue;
            }

            Log::info('Purging unpaid student registration', [
                'user_id' => $user->id,
                'email' => $user->email,
                'username' => $user->username,
                'created_at' => $user->created_at,
            ]);

            $user->forceDelete();
            $deleted++;
        }

        $this->info("Purged {$deleted} unpaid student registration(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
