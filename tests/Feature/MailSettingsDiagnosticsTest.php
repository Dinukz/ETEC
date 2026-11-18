<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\TransactionalMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ⚠️ WHY THE TEST EMAIL COULD NOT BE DEBUGGED (2026-09-07).
 *
 * Reported: "email yn na... test email ekak yawwata passe 'Test email failed to
 * send. Check the SMTP host/port/username/password' kiyla enwa. Mn password add
 * kra code eken. Meka balann kohend?"
 *
 * Two faults, and the second is why the first mattered:
 *
 *  1. The failure message was a FIXED SENTENCE naming four settings. It said the
 *     same thing whether the password was wrong, the port was blocked, or the
 *     saved settings never reached the mailer at all. The real reason went to
 *     storage/logs/laravel.log — unreachable for an owner on shared hosting.
 *
 *  2. A password that could not be decrypted disabled SMTP ENTIRELY.
 *     `config(['mail.default' => 'smtp'])` is set BEFORE the credentials block,
 *     so a decrypt() failure aborted the method with the mailer switched to smtp
 *     and the host/username/password still holding .env values. Every email then
 *     failed while the settings screen showed correct values — and the error
 *     message blamed exactly those correct values.
 *
 * A password written straight into the `settings` table (by a script, or by
 * hand) is plain text and hits fault 2 immediately. That is what "mn password
 * add kra code eken" describes.
 */
class MailSettingsDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::forget('settings.mail.grouped');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    /** Re-run the provider's mail wiring against whatever is in `settings`. */
    private function applyMailSettings(): void
    {
        Cache::forget('settings.mail.grouped');

        $provider = new AppServiceProvider($this->app);
        $method = (new \ReflectionClass($provider))->getMethod('applyMailSettingsFromDatabase');
        $method->setAccessible(true);
        $method->invoke($provider);
    }

    private function saveSmtpSettings(string $password, bool $encrypted): void
    {
        Setting::set('mail_mailer', 'smtp', 'string', 'mail');
        Setting::set('mail_host', 'smtp.gmail.com', 'string', 'mail');
        Setting::set('mail_port', '587', 'string', 'mail');
        Setting::set('mail_encryption', 'tls', 'string', 'mail');
        Setting::set('mail_username', 'etecbusinesscollage@gmail.com', 'string', 'mail');
        Setting::set('mail_password', $encrypted ? encrypt($password) : $password, 'string', 'mail');
        Setting::set('mail_from_address', 'etecbusinesscollage@gmail.com', 'string', 'mail');
        Setting::set('mail_from_name', 'ETEC College', 'string', 'mail');
    }

    // ----- fault 2: the settings must actually reach the mailer -------------

    /** The ordinary path: saved through the form, so encrypted. */
    public function test_an_encrypted_password_reaches_the_mailer(): void
    {
        $this->saveSmtpSettings('super-secret', encrypted: true);
        $this->applyMailSettings();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.gmail.com', config('mail.mailers.smtp.host'));
        $this->assertSame('super-secret', config('mail.mailers.smtp.password'));
    }

    /**
     * ⚠️ THE REPORTED CASE. A password put into the settings table by a script
     * is plain text; decrypt() throws on it.
     *
     * Before the fix this aborted the whole block, leaving mail.default = smtp
     * with the host, username and password from .env — so mail failed and the
     * screen showed settings that were never used.
     */
    public function test_a_plain_text_password_no_longer_disables_smtp(): void
    {
        config(['mail.mailers.smtp.host' => 'left-over-from-env.example', 'mail.mailers.smtp.username' => 'env@example.com']);

        $this->saveSmtpSettings('typed-in-by-hand', encrypted: false);
        $this->applyMailSettings();

        $this->assertSame(
            'smtp.gmail.com',
            config('mail.mailers.smtp.host'),
            'the saved host must reach the mailer even when the password cannot be decrypted',
        );
        $this->assertSame('etecbusinesscollage@gmail.com', config('mail.mailers.smtp.username'));
        $this->assertSame('typed-in-by-hand', config('mail.mailers.smtp.password'), 'used exactly as stored');
    }

    /** An empty password is null, not an empty string pretending to be one. */
    public function test_no_saved_password_is_null(): void
    {
        $this->saveSmtpSettings('', encrypted: false);
        $this->applyMailSettings();

        $this->assertNull(config('mail.mailers.smtp.password'));
    }

    // ----- fault 1: the failure has to say what went wrong ------------------

    /** The real reason is captured, not only written to a log nobody can read. */
    public function test_the_reason_for_a_failure_is_available(): void
    {
        Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('Connection could not be established with host smtp.gmail.com'));

        $this->assertFalse(TransactionalMail::test('someone@example.com'));

        $this->assertStringContainsString(
            'Connection could not be established',
            TransactionalMail::lastError() ?? '',
        );
    }

    /** ⚠️ And it reaches the admin's screen, which is the whole point. */
    public function test_the_settings_page_shows_the_real_reason(): void
    {
        Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('535 Username and Password not accepted'));

        $this->saveSmtpSettings('whatever', encrypted: true);
        $this->applyMailSettings();

        $response = $this->actingAs($this->admin())
            ->from(route('settings.index'))
            ->post(route('settings.mail.test'), ['test_email' => 'owner@example.com']);

        $error = session('error');

        $this->assertStringContainsString('535 Username and Password not accepted', $error);
        $this->assertStringContainsString('smtp.gmail.com', $error, 'and what was actually in use when it failed');
    }

    /** A successful send still reports success and clears the last error. */
    public function test_a_successful_send_reports_success(): void
    {
        Mail::shouldReceive('html')->once();

        $this->actingAs($this->admin())
            ->from(route('settings.index'))
            ->post(route('settings.mail.test'), ['test_email' => 'owner@example.com'])
            ->assertSessionHas('success');

        $this->assertNull(TransactionalMail::lastError());
    }
}
