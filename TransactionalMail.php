<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;

/**
 * Sends transactional emails through Mail::html() — the SAME low-level path as
 * the working "test email" (Mail::raw). It sends a self-contained HTML string
 * with inline styles and does NOT touch Laravel's markdown-mail pipeline
 * (Blade mail components + CSS inliner), which is unreliable on this host and
 * was the reason notification emails silently failed while the test email sent.
 *
 * Every call is guarded: a mail failure is reported but never bubbles up to
 * break registration or payment verification.
 */
class TransactionalMail
{
    /**
     * Why the last send failed, for the one caller that has a human waiting.
     *
     * ⚠️ ADDED 2026-09-07 BECAUSE THE REAL REASON WAS UNREACHABLE. dispatch()
     * catches everything and returns false, so Settings → Email could only say
     * "Test email failed to send. Check the SMTP host/port/username/password" —
     * which is a guess, not a diagnosis, and is the same sentence whether the
     * password is wrong, the port is blocked, the host is unreachable or Gmail
     * has refused an ordinary account password. The admin was left re-typing
     * correct settings. The real message was going to storage/logs/laravel.log,
     * which is exactly where an owner on shared hosting cannot look.
     *
     * Deliberately NOT surfaced anywhere else: every other caller is a
     * background send during registration or payment verification, where a mail
     * failure must stay silent and never break the transaction.
     */
    protected static ?string $lastError = null;

    /** The exception message from the most recent failed send, if any. */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /** Diagnostic — sends through the exact same path as every real email. */
    public static function test(string $email): bool
    {
        $body = self::paragraph('This is a test email from ETEC College.')
            .self::paragraph('If you can read this, your email settings are working and registration / payment / password emails will send the same way.');

        return self::dispatch($email, 'ETEC Test', 'ETEC College — Test Email', 'Test Email', $body);
    }

    /** Password reset link — replaces Laravel's ResetPassword notification (broken mail pipeline). */
    public static function passwordReset(string $email, string $name, string $resetUrl): bool
    {
        $body = self::paragraph('Hi <strong>'.e($name).'</strong>,')
            .self::paragraph('You are receiving this email because a password reset was requested for your ETEC College account.')
            .self::button('Reset Password', $resetUrl)
            .self::paragraph('This link will expire in 60 minutes. If you did not request a password reset, you can safely ignore this email.');

        return self::dispatch($email, $name, 'Reset Your ETEC College Password', 'Reset Your Password', $body);
    }

    /** Welcome + login credentials for a newly registered student. */
    public static function welcome(string $email, string $name, string $courseTitle, string $username, string $password): bool
    {
        $portal = route('login');

        $body = self::paragraph('Hi <strong>'.e($name).'</strong>,')
            .self::paragraph("We are happy to have you as a new student. You have joined <strong>".e($courseTitle)."</strong>, and we are ready to help you learn step by step.")
            .self::paragraph('You can log in using either your <strong>Username</strong> or <strong>Email</strong>. Below are your login credentials:')
            .self::box([
                'Username' => $username,
                'Email' => $email,
                'Password' => $password,
            ])
            .self::paragraph('For your security, you will be asked to set a new password the first time you log in.')
            .self::paragraph('Your consultant will confirm your payment plan shortly. Please note: if this is not confirmed within 14 days, this registration will expire and you will need to register again.')
            .self::button('Access Student Portal', $portal);

        return self::dispatch($email, $name, 'Welcome to ETEC College!', 'Welcome to ETEC College!', $body);
    }

    /**
     * A nudge for a student whose registration is still unpaid — re-sent by
     * their consultant from Pending Students, usually because the original
     * welcome email was never seen.
     *
     * $password is null when the student has already set a password of their
     * own; we must not reveal or reset it, so the email points at Forgot
     * Password instead of listing credentials.
     */
    public static function registrationReminder(string $email, string $name, string $courseTitle, string $paymentCode, string $username, ?string $password, string $expiresOn): bool
    {
        $credentials = $password === null
            ? self::box([
                'Username' => $username,
                'Email' => $email,
                'Payment Code' => $paymentCode,
                'Course' => $courseTitle,
            ]).self::paragraph('Use the password you already set. If you have forgotten it, choose <strong>Forgot Password</strong> on the login page.')
            : self::box([
                'Username' => $username,
                'Email' => $email,
                'Password' => $password,
                'Payment Code' => $paymentCode,
                'Course' => $courseTitle,
            ]).self::paragraph('This is a new temporary password and it replaces any earlier one. You will be asked to set your own the first time you log in.');

        $body = self::paragraph('Hi <strong>'.e($name).'</strong>,')
            .self::paragraph('This is a reminder that your registration for <strong>'.e($courseTitle).'</strong> at ETEC College is still waiting for payment.')
            .$credentials
            .self::paragraph('Please complete your payment on or before <strong>'.e($expiresOn).'</strong>. After that date this registration expires automatically and you would need to register again.')
            .self::button('Access Student Portal', route('login'))
            .self::paragraph('If you have already paid, you can ignore this message — your consultant will confirm it shortly.');

        return self::dispatch($email, $name, 'Reminder — Complete Your ETEC College Registration', 'Your registration is waiting for payment', $body);
    }

    /**
     * An admin created this account directly (User Management → Create User) —
     * welcome + login credentials, for any role. Distinct from welcome() above,
     * which is specifically the course-registration flow and always mentions a
     * course; an admin-created Consultant/Accountant/Lecturer/Admin account has
     * no course to reference.
     */
    public static function accountCreated(string $email, string $name, string $roleLabel, string $password): bool
    {
        $portal = route('login');

        $body = self::paragraph('Hello '.e($name).',')
            .self::paragraph('An administrator has created an account for you at ETEC College, as a <strong>'.e($roleLabel).'</strong>.')
            .self::paragraph('Below are your login credentials:')
            .self::box([
                'Email' => $email,
                'Password' => $password,
                'Role' => $roleLabel,
            ])
            .self::paragraph('For your security, please change your password after you log in.')
            .self::button('Log In', $portal);

        return self::dispatch($email, $name, 'Your ETEC College account has been created', 'Account Created', $body);
    }

    /** Payment verified — confirmation to the student, course now active. */
    public static function paymentVerifiedToStudent(string $email, string $name, string $courseTitle, string $paymentCode, string $statusLabel, float $amount, ?string $studentCode): bool
    {
        $loginLine = $studentCode
            ? "You can log in with your <strong>email</strong> ({$email}) or your student number <strong>{$studentCode}</strong>."
            : "You can log in with your <strong>email</strong> ({$email}).";

        $body = self::paragraph('Hi <strong>'.e($name).'</strong>,')
            .self::paragraph('Good news — your payment has been verified and your enrolment is now active at ETEC College.')
            .self::box([
                'Course' => $courseTitle,
                'Payment Code' => $paymentCode,
                'Payment Status' => $statusLabel,
                'Amount' => 'LKR '.number_format($amount, 2),
            ])
            .self::paragraph($loginLine)
            .self::button('Go to Student Portal', route('login'));

        return self::dispatch($email, $name, 'Payment Verified — Your Course is Now Active at ETEC College', 'Payment Verified', $body);
    }

    /** Payment confirmed — student details to the referring consultant. */
    public static function paymentConfirmedToConsultant(string $email, string $consultantName, array $details): bool
    {
        $body = self::paragraph('Hello Consultant,')
            .self::paragraph('A new student has successfully registered and completed payment for a course at ETEC College. Below are the student details for your reference.')
            .self::box($details)
            .self::button('View in Dashboard', route('consultant.students.index'));

        return self::dispatch($email, $consultantName, 'New Student Registration — Payment Confirmed at ETEC College', 'New Student Registration', $body);
    }

    /**
     * The pre-System-Run notice to a consultant.
     *
     * Deliberately worded like the message the accounts unit already sends by
     * hand, because consultants recognise that format. The run permanently
     * forfeits unmatched service-group income, so the whole point of this email
     * is the window it gives them to fix things first.
     */
    public static function systemRunNotice(string $email, string $name, string $runAtLabel, string $activationsDate, string $errorsDate): bool
    {
        $body = self::paragraph('Good day,')
            .self::paragraph('<strong>'.e($activationsDate).' activations are done.</strong>')
            .self::paragraph('The <strong>System Run</strong> will be done at <strong>'.e($runAtLabel).'</strong> today.')
            .self::paragraph('Before then, please check your pending students, collect any outstanding payments, and report anything wrong with <strong>'.e($errorsDate).'</strong> in the consultant group.')
            .self::paragraph('Once the System Run has been done, each service group is settled: a matched pair is released for payout and any unmatched amount left on the heavier side is closed off. Anything you have not sorted out by then cannot be changed afterwards.')
            .self::button('Check Pending Students', route('consultant.students.pending'))
            .self::paragraph('Thank you,<br>Accounts Unit');

        return self::dispatch($email, $name, 'System Run today at '.$runAtLabel, 'System Run Notice', $body);
    }

    /**
     * The post-System-Run confirmation to a consultant.
     *
     * Deliberately terse — matches the one-line WhatsApp confirmation the
     * accounts unit already sends ("13th of August System Run Has Been
     * Done ✅"), not the longer pre-run notice. Consultants who want the
     * figures still have Service Groups / Service Income to check.
     */
    public static function systemRunCompleted(string $email, string $name, string $settledDateLabel): bool
    {
        $body = self::paragraph('Good Afternoon,')
            .self::paragraph('<strong>'.e($settledDateLabel).' System Run Has Been Done ✅</strong>')
            .self::button('View Service Groups', route('consultant.groups.index'))
            .self::paragraph('Thank you,<br>Accounts Unit<br>ETEC College');

        return self::dispatch($email, $name, $settledDateLabel.' System Run Has Been Done', 'System Run Complete', $body);
    }

    /**
     * An admin changed this user's role. Sent to the user themselves so a
     * change to what they can do in the system is never silent.
     */
    public static function roleChanged(string $email, string $name, string $roleLabel, ?string $previousLabel = null): bool
    {
        $body = self::paragraph('Hello '.e($name).',')
            .self::paragraph('Your account role at ETEC College has been updated by an administrator.')
            .self::box(array_filter([
                'Previous role' => $previousLabel,
                'New role' => $roleLabel,
            ]))
            .self::paragraph('Your dashboard and the actions available to you may look different the next time you sign in. If you were not expecting this change, please contact the administrator.')
            .self::button('Go to Dashboard', route('dashboard'));

        return self::dispatch($email, $name, 'Your ETEC College account role has changed', 'Account role updated', $body);
    }

    // ----- Rendering helpers ------------------------------------------------

    protected static function dispatch(string $email, string $name, string $subject, string $heading, string $bodyHtml): bool
    {
        try {
            Mail::html(self::shell($heading, $bodyHtml), function ($message) use ($email, $name, $subject) {
                $message->to($email, $name)->subject($subject);
            });

            self::$lastError = null;

            return true;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();

            report($e);

            return false;
        }
    }

    protected static function paragraph(string $html): string
    {
        return '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">'.$html.'</p>';
    }

    /** A highlighted "label: value" details block. Values are escaped. */
    protected static function box(array $rows): string
    {
        $inner = '';
        foreach ($rows as $label => $value) {
            $inner .= '<tr>'
                .'<td style="padding:6px 0;font-size:14px;color:#64748b;white-space:nowrap;vertical-align:top;">'.e($label).':&nbsp;&nbsp;</td>'
                .'<td style="padding:6px 0;font-size:14px;font-weight:700;color:#0f172a;">'.e($value).'</td>'
                .'</tr>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:8px 0 18px;padding:16px 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">'.$inner.'</table>';
    }

    protected static function button(string $text, string $url): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0;"><tr>'
            .'<td style="border-radius:8px;background:#2563eb;">'
            .'<a href="'.e($url).'" style="display:inline-block;padding:12px 26px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">'.e($text).'</a>'
            .'</td></tr></table>';
    }

    protected static function shell(string $heading, string $bodyHtml): string
    {
        $appName = e(config('app.name', 'ETEC College'));

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            .'<body style="margin:0;background:#f1f5f9;padding:24px;font-family:Arial,Helvetica,sans-serif;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">'
            .'<tr><td style="background:#0f172a;padding:22px 32px;text-align:center;"><span style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:1px;">'.$appName.'</span></td></tr>'
            .'<tr><td style="padding:32px;">'
            .'<h1 style="margin:0 0 18px;font-size:20px;color:#0f172a;">'.e($heading).'</h1>'
            .$bodyHtml
            .'</td></tr>'
            .'<tr><td style="padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center;">This is an automated message from '.$appName.'. Please do not reply to this email.</td></tr>'
            .'</table></td></tr></table></body></html>';
    }
}
