<?php

namespace App\Console\Commands;

use App\Services\TransactionalMail;
use Illuminate\Console\Command;

/**
 * Exercises every real transactional-email path so we can confirm the code
 * renders and dispatches without error. Pass --send to actually deliver via
 * the configured SMTP; otherwise it renders through the "array" mailer so no
 * real email is sent (proves the code path without spamming an inbox).
 */
class MailSelfTest extends Command
{
    protected $signature = 'mail:selftest {email=test@example.com} {--send}';

    protected $description = 'Send/render each transactional email to verify the mail code paths work.';

    public function handle(): int
    {
        if (! $this->option('send')) {
            config(['mail.default' => 'array']);
            $this->warn('Dry run (no real email sent). Use --send to deliver for real.');
        }

        $email = $this->argument('email');

        $cases = [
            'welcome' => fn () => TransactionalMail::welcome($email, 'Test Student', 'Diploma in Business Management', 'PYM-00001', 'Secret123'),
            'paymentVerifiedToStudent' => fn () => TransactionalMail::paymentVerifiedToStudent($email, 'Test Student', 'Diploma in Business Management', 'PYM-00001', 'Full Paid', 28000.0, 'SRN-00001'),
            'paymentConfirmedToConsultant' => fn () => TransactionalMail::paymentConfirmedToConsultant($email, 'Test Consultant', [
                'Full Name' => 'Test Student', 'Username' => 'SRN-00001', 'Email' => $email,
                'Course Name' => 'Diploma in Business Management', 'Payment Status' => 'Full Paid', 'Registration Year' => '2026',
            ]),
            'passwordReset' => fn () => TransactionalMail::passwordReset($email, 'Test User', 'https://etec.synnexcloud.lk/reset-password/testtoken?email='.urlencode($email)),
            'test' => fn () => TransactionalMail::test($email),
        ];

        $allOk = true;

        foreach ($cases as $name => $case) {
            try {
                $ok = $case();
                $ok ? $this->info("✓ {$name}: rendered & dispatched") : $this->error("✗ {$name}: dispatch returned false");
                $allOk = $allOk && $ok;
            } catch (\Throwable $e) {
                $allOk = false;
                $this->error("✗ {$name}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->line('Mail default mailer: '.config('mail.default'));
        $this->line('SMTP host: '.config('mail.mailers.smtp.host').' | from: '.config('mail.from.address'));

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
