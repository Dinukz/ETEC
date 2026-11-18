<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super Admin implicitly passes every authorization check.
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('super_admin') ? true : null;
        });

        // Catch N+1 / lazy-loading and mass-assignment mistakes during development.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Tailwind-styled pagination across the app.
        Paginator::defaultView('vendor.pagination.tailwind');
        Paginator::defaultSimpleView('vendor.pagination.simple-tailwind');

        $this->applyMailSettingsFromDatabase();
    }

    /**
     * Mail credentials are admin-editable (Settings → Email) rather than fixed
     * in .env, so a non-technical admin can update them without a redeploy.
     * Falls back to the .env-configured mailer if nothing has been saved yet,
     * or if the settings table isn't reachable (e.g. before first migration).
     *
     * This runs on EVERY request (service providers boot every time), so it
     * must cost at most one query — never one query per field — and must never
     * throw, or it would take the whole site down on a transient DB/cache hiccup.
     */
    protected function applyMailSettingsFromDatabase(): void
    {
        try {
            $mail = Cache::rememberForever(
                'settings.mail.grouped',
                fn () => Setting::where('group', 'mail')->pluck('value', 'key'),
            );

            $mailer = $mail->get('mail_mailer');

            if (! $mailer) {
                return;
            }

            config(['mail.default' => $mailer]);

            if ($mailer === 'smtp') {
                config([
                    'mail.mailers.smtp.host' => $mail->get('mail_host'),
                    'mail.mailers.smtp.port' => (int) ($mail->get('mail_port') ?: 587),
                    'mail.mailers.smtp.encryption' => $mail->get('mail_encryption') ?: null,
                    'mail.mailers.smtp.username' => $mail->get('mail_username'),
                    'mail.mailers.smtp.password' => self::storedMailPassword($mail->get('mail_password')),
                ]);
            }

            config([
                'mail.from.address' => $mail->get('mail_from_address') ?: config('mail.from.address'),
                'mail.from.name' => $mail->get('mail_from_name') ?: config('mail.from.name'),
            ]);
        } catch (\Throwable $e) {
            // DB unreachable or settings table missing — never let mail config
            // break page rendering site-wide.
            report($e);
        }
    }

    /**
     * The SMTP password as it should be handed to the mailer.
     *
     * ⚠️ AN UNDECRYPTABLE PASSWORD USED TO DISABLE SMTP ENTIRELY (fixed 2026-09-07).
     *
     * Settings → Email stores this with encrypt(), so the normal path is a
     * decrypt(). But `config(['mail.default' => $mailer])` runs BEFORE this
     * block, so when decrypt() threw, the catch above swallowed it and the
     * method returned with the mailer switched to smtp and the host, port,
     * username and password still holding whatever .env had. The mailer then
     * authenticated with the wrong credentials and every email failed —
     * including the test email, whose error message then blamed the settings
     * the admin was looking at, which were correct.
     *
     * decrypt() fails for two real reasons, and neither should cost the whole
     * SMTP block:
     *   - the value was written straight into the `settings` table by a script
     *     or by hand, so it is PLAIN TEXT and was never encrypted;
     *   - APP_KEY has changed since it was saved, so the ciphertext is no
     *     longer readable by this application.
     *
     * Using the stored value as-is fixes the first outright and turns the
     * second into an honest authentication failure the test email can now
     * report, instead of a silent fallback to unrelated credentials. Re-saving
     * the password in Settings → Email restores proper encryption either way,
     * which is what the reported warning says to do.
     */
    protected static function storedMailPassword(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        try {
            return decrypt($stored);
        } catch (\Throwable $e) {
            report(new \RuntimeException(
                'The saved SMTP password could not be decrypted, so it is being used exactly as stored. '
                .'Re-save it in Settings → Email to store it encrypted again.',
                0,
                $e,
            ));

            return $stored;
        }
    }
}
