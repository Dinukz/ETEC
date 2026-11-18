<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CommissionSetting;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    public function index()
    {
        $general = Setting::whereIn('group', ['general', 'theme', 'payment'])->get()->keyBy('key');
        $commission = CommissionSetting::current();
        $mail = Setting::where('group', 'mail')->get()->keyBy('key');

        // The weekly-quota rules live in the key/value store, so they are read
        // separately from the versioned commission row. Defaults are supplied
        // here because SettingsSeeder never runs on production.
        $financeWeekStartDay = (int) Setting::get('finance_week_start_day', 2); // Tuesday
        $financeWeekQuota = (int) Setting::get('finance_week_quota', 2);
        $financeGraceWeeks = (int) Setting::get('finance_grace_weeks', 5);
        // The whole requirement can be parked — see FinanceWeek::quotaEnabled().
        $financeQuotaEnabled = (int) Setting::get(\App\Support\FinanceWeek::ENABLED_KEY, 1);

        // Staged service-group release. Read through the same defaults the
        // engine itself clamps to, so the form can never display a value the
        // engine would silently override.
        $staging = \App\Services\ServiceGroupStaging::config();
        $stageFirstPairs = $staging['first'];
        $stageSecondPairs = $staging['second'];
        $stageRepeatPairs = $staging['repeat'];
        $accountUnlockStudents = $staging['unlock'];

        // Two switches added 2026-10-01 — FTP is the only way onto the live
        // site, so anything new that could need turning off gets a switch here.
        $studentLineAttach = \App\Services\StudentLine::attachEnabled() ? 1 : 0;
        $videoChunkMb = \App\Services\VideoStream::maxSpan() / 1048576;
        $manualCommissionAlertEmails = implode(', ', \App\Services\ManualCommissionAlert::recipients());

        return view('dashboard.settings.index', compact(
            'general', 'commission', 'mail',
            'financeWeekStartDay', 'financeWeekQuota', 'financeGraceWeeks', 'financeQuotaEnabled',
            'stageFirstPairs', 'stageSecondPairs', 'stageRepeatPairs', 'accountUnlockStudents',
            'studentLineAttach', 'videoChunkMb', 'manualCommissionAlertEmails',
        ));
    }

    /**
     * The student-line rule and the video piece size (2026-10-01).
     *
     * Kept out of updateCommission() on purpose: that one writes a new
     * versioned CommissionSetting row, and neither of these is a money rate.
     */
    public function updateSwitches(Request $request)
    {
        $data = $request->validate([
            'student_line_attach' => ['required', 'integer', 'in:0,1'],
            'video_chunk_mb' => ['required', 'numeric', 'min:0', 'max:64'],
            // Who is emailed when a commission is added by hand (2026-10-29).
            // Optional in the request, so a form without it never wipes it.
            'manual_commission_alert_emails' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if ($request->exists('manual_commission_alert_emails')) {
            $emails = preg_split('/[\s,;]+/', (string) ($data['manual_commission_alert_emails'] ?? '')) ?: [];
            $bad = array_values(array_filter($emails, fn ($e) => $e !== '' && ! filter_var($e, FILTER_VALIDATE_EMAIL)));

            if ($bad !== []) {
                return back()->withInput()->withErrors(['manual_commission_alert_emails' => 'Not an email address: '.implode(', ', $bad)]);
            }

            Setting::set(\App\Services\ManualCommissionAlert::SETTING, implode(', ', array_filter($emails)), 'string', 'commission');
        }

        Setting::set(\App\Services\StudentLine::ATTACH_KEY, (string) $data['student_line_attach'], 'string', 'network');
        Setting::set(\App\Services\VideoStream::SPAN_SETTING, (string) (0 + $data['video_chunk_mb']), 'string', 'lms');

        return back()->with('success', 'Saved.');
    }

    /** Update general / theme / payment settings. */
    public function updateGeneral(Request $request)
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:120'],
            'site_tagline' => ['nullable', 'string', 'max:200'],
            'contact_email' => ['required', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:60'],
            'contact_address' => ['nullable', 'string', 'max:255'],
            'default_currency' => ['required', 'string', 'max:10'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:60'],
        ]);

        $groups = [
            'site_name' => 'general', 'site_tagline' => 'general', 'contact_email' => 'general',
            'contact_phone' => 'general', 'contact_address' => 'general', 'default_currency' => 'general',
            'bank_name' => 'payment', 'bank_account_name' => 'payment', 'bank_account_number' => 'payment',
        ];

        foreach ($data as $key => $value) {
            Setting::set($key, $value ?? '', 'string', $groups[$key]);
        }

        return back()->with('success', 'System settings updated successfully.');
    }

    /**
     * Update the commission configuration. Writes a new active CommissionSetting
     * version and keeps the mirrored Setting rows in sync. No hardcoded values —
     * the engine reads exclusively from here.
     */
    public function updateCommission(Request $request)
    {
        $data = $request->validate([
            'course_price' => ['required', 'numeric', 'min:0'],
            'half_payment_amount' => ['required', 'numeric', 'min:0'],
            'commission_per_student' => ['required', 'numeric', 'min:0'],
            'direct_commission' => ['required', 'numeric', 'min:0'],
            'max_commission' => ['required', 'numeric', 'min:0'],
            'group_size' => ['required', 'integer', 'min:2', 'max:50'],
            'first_tier_students' => ['required', 'integer', 'min:1'],
            'first_tier_amount' => ['required', 'numeric', 'min:0'],
            'second_tier_students' => ['required', 'integer', 'min:0'],
            'second_tier_amount' => ['required', 'numeric', 'min:0'],

            // Weekly student quota that keeps a consultant in good standing.
            // 1 = Monday … 7 = Sunday (ISO), so the business default of Tuesday is 2.
            'finance_week_start_day' => ['required', 'integer', 'min:1', 'max:7'],
            'finance_week_quota' => ['required', 'integer', 'min:0', 'max:100'],
            'finance_grace_weeks' => ['required', 'integer', 'min:0', 'max:52'],
            // The temporary off switch for the whole requirement — 1 on, 0 off.
            'finance_quota_enabled' => ['required', 'integer', 'in:0,1'],

            // Staged service-group release. min:1 on every step is load-bearing,
            // not cosmetic: a step of 0 would make "release when matched >=
            // paidDepth + step" true forever and spin ServiceGroupStaging's
            // release loop.
            'stage_first_pairs' => ['required', 'integer', 'min:1', 'max:50'],
            'stage_second_pairs' => ['required', 'integer', 'min:1', 'max:50'],
            'stage_repeat_pairs' => ['required', 'integer', 'min:1', 'max:50'],
            'account_unlock_students' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        // Sanity: tier student counts should not exceed group size.
        if ($data['first_tier_students'] + $data['second_tier_students'] > $data['group_size']) {
            return back()
                ->withInput()
                ->with('error', 'Tier student counts cannot exceed the group size.');
        }

        if ($data['half_payment_amount'] > $data['course_price']) {
            return back()
                ->withInput()
                ->with('error', 'The instalment first payment cannot be more than the full course price.');
        }

        // Self-heal before writing: this database's migrations ledger has been
        // found out of sync with its real schema, which has silently left
        // columns like direct_commission missing on production before with no
        // error anywhere — until an admin tried to save this exact form and hit
        // "Unknown column". Guaranteeing the schema here means that specific
        // failure can never happen again, with or without a trip to Commission
        // Health first.
        try {
            \App\Services\CommissionSchemaRepair::ensure();
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not save: the database schema needs repairing first. Visit Commission Health and run Repair, then try saving again.');
        }

        // Lives in the key/value store, not in the versioned CommissionSetting
        // row — it prices the payment plan, it is not a commission rate.
        $halfPaymentAmount = $data['half_payment_amount'];
        unset($data['half_payment_amount']);

        // Same again for the weekly-quota rules: they govern a consultant's
        // standing, not what a student is charged, so they are not columns on
        // commission_settings. They MUST be unset before the create() below —
        // the app runs with preventSilentlyDiscardingAttributes(), so an
        // unknown key would throw rather than being ignored.
        $finance = [
            'finance_week_start_day' => $data['finance_week_start_day'],
            'finance_week_quota' => $data['finance_week_quota'],
            'finance_grace_weeks' => $data['finance_grace_weeks'],
            'finance_quota_enabled' => $data['finance_quota_enabled'],
        ];

        // Same again for the staged-release settings: they govern WHEN group
        // income is released, not what a student is charged.
        $staging = [
            'stage_first_pairs' => $data['stage_first_pairs'],
            'stage_second_pairs' => $data['stage_second_pairs'],
            'stage_repeat_pairs' => $data['stage_repeat_pairs'],
            'account_unlock_students' => $data['account_unlock_students'],
        ];

        foreach ([...array_keys($finance), ...array_keys($staging)] as $key) {
            unset($data[$key]);
        }

        DB::transaction(function () use ($data, $halfPaymentAmount, $finance, $staging) {
            // Retire the current active version, create a new one.
            CommissionSetting::where('is_active', true)->update(['is_active' => false]);

            CommissionSetting::create([
                ...$data,
                'is_active' => true,
                'effective_from' => now(),
            ]);

            // Mirror the headline values into the key/value settings store.
            Setting::set('half_payment_amount', (string) $halfPaymentAmount, 'integer', 'commission');
            Setting::set('course_price', (string) $data['course_price'], 'integer', 'commission');
            Setting::set('commission_per_student', (string) $data['commission_per_student'], 'integer', 'commission');
            Setting::set('direct_commission', (string) $data['direct_commission'], 'integer', 'commission');
            Setting::set('max_commission', (string) $data['max_commission'], 'integer', 'commission');
            Setting::set('group_size', (string) $data['group_size'], 'integer', 'commission');

            // Typed as integer so Setting::get() casts them back to int rather
            // than to the string the general handler would store.
            foreach ($finance as $key => $value) {
                Setting::set($key, (string) $value, 'integer', 'finance');
            }

            // Group 'commission' rather than 'finance' — these describe how
            // income is released, and sit alongside half_payment_amount.
            foreach ($staging as $key => $value) {
                Setting::set($key, (string) $value, 'integer', 'commission');
            }
        });

        // FinanceWeek::config() reads these through ONE grouped cache entry
        // (to keep the middleware's per-request cost near zero) — must be
        // cleared here or the old quota/grace/start-day keeps silently
        // applying, exactly like updateMail()'s 'settings.mail.grouped' below.
        \App\Support\FinanceWeek::forget();
        \App\Services\ServiceGroupStaging::forget();

        return back()->with('success', 'Commission settings updated. New enrollments will use these values.');
    }

    /**
     * Update outgoing mail (SMTP) settings. Stored in the database — not .env —
     * so an admin can switch email providers without a developer/redeploy.
     */
    public function updateMail(Request $request)
    {
        $data = $request->validate([
            'mail_mailer' => ['required', 'in:smtp,log'],
            'mail_host' => ['required_if:mail_mailer,smtp', 'nullable', 'string', 'max:180'],
            'mail_port' => ['required_if:mail_mailer,smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['nullable', 'in:tls,ssl'],
            'mail_username' => ['nullable', 'string', 'max:180'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['required', 'email', 'max:180'],
            'mail_from_name' => ['required', 'string', 'max:120'],
        ]);

        Setting::set('mail_mailer', $data['mail_mailer'], 'string', 'mail');
        Setting::set('mail_host', $data['mail_host'] ?? '', 'string', 'mail');
        Setting::set('mail_port', (string) ($data['mail_port'] ?? ''), 'string', 'mail');
        Setting::set('mail_encryption', $data['mail_encryption'] ?? '', 'string', 'mail');
        Setting::set('mail_username', $data['mail_username'] ?? '', 'string', 'mail');

        // Only overwrite the stored password if a new one was entered — leave
        // the existing one intact when the field is left blank on a later edit.
        if (filled($data['mail_password'] ?? null)) {
            Setting::set('mail_password', encrypt($data['mail_password']), 'string', 'mail');
        }

        Setting::set('mail_from_address', $data['mail_from_address'], 'string', 'mail');
        Setting::set('mail_from_name', $data['mail_from_name'], 'string', 'mail');

        // AppServiceProvider reads all mail settings via this single grouped
        // cache entry (to keep it to one query per request) — must be cleared
        // whenever any mail setting changes, or the old values keep applying.
        Cache::forget('settings.mail.grouped');

        return back()->with('success', 'Email settings updated.');
    }

    /** Send a one-off test email using the settings just saved, so an admin can confirm it actually works. */
    public function testMail(Request $request)
    {
        $data = $request->validate(['test_email' => ['required', 'email']]);

        // Sent through the SAME path (Mail::html) as every real transactional
        // email, so a passing test guarantees registration/payment/password
        // emails will send too.
        $sent = \App\Services\TransactionalMail::test($data['test_email']);

        if ($sent) {
            return back()->with('success', "Test email sent to {$data['test_email']}. Check the inbox (and spam folder).");
        }

        // ⚠️ SAY WHAT ACTUALLY WENT WRONG (2026-09-07).
        //
        // This used to be a fixed sentence telling the admin to check the
        // host/port/username/password — the same words whether the password was
        // wrong, port 587 was blocked by the host, the settings in the database
        // never reached the mailer, or Gmail had refused an ordinary account
        // password because it requires an App Password. An owner with no server
        // access cannot read storage/logs/laravel.log, so the one sentence that
        // would have ended it was the one place they could not reach.
        //
        // The settings actually IN EFFECT are printed alongside it, because a
        // mismatch between what the form shows and what the mailer is using is
        // its own distinct fault (see AppServiceProvider::applyMailSettingsFromDatabase()
        // — an undecryptable password used to abort the whole block, leaving
        // the .env values silently in charge).
        $reason = \App\Services\TransactionalMail::lastError();

        $inEffect = sprintf(
            'Currently sending as: %s — host %s, port %s, encryption %s, username %s.',
            config('mail.default') ?: 'not set',
            config('mail.mailers.smtp.host') ?: 'not set',
            config('mail.mailers.smtp.port') ?: 'not set',
            config('mail.mailers.smtp.encryption') ?: 'none',
            config('mail.mailers.smtp.username') ?: 'not set',
        );

        return back()->with('error', trim(sprintf(
            'Test email failed. %s %s',
            $reason ? 'The mail server said: "'.$reason.'"' : 'No reason was reported by the mail server.',
            $inEffect,
        )));
    }
}
