<?php

namespace App\Services;

use App\Models\Setting;

/**
 * ⚠️ AN EMAIL TO THE ADMINS FOR EVERY COMMISSION ADDED BY HAND (2026-10-29).
 *
 * Owner: "Meke point manual add kraddi email ekak yann one adminta eke include
 * wenn one Name / SCN / service group / Amount / Course (direct bonus only) /
 * Date / Who receives it / and point add krana date and time.
 * dimuthura187@gmail.com, dushandinzte@gmail.com me email deka thama admin
 * email".
 *
 * A hand entry creates money, so every one is reported. The recipients are a
 * setting (Settings → "Hand-entry alert emails"), defaulting to the two the
 * owner named; empty = no email. Sending never blocks or undoes the entry —
 * the commission is already recorded when this runs, and a mail failure only
 * changes the confirmation message.
 */
class ManualCommissionAlert
{
    public const SETTING = 'manual_commission_alert_emails';

    public const DEFAULT = 'dimuthura187@gmail.com, dushandinzte@gmail.com';

    /** @return list<string> the addresses to tell, valid and without repeats */
    public static function recipients(): array
    {
        $raw = (string) Setting::get(self::SETTING, self::DEFAULT);

        return collect(preg_split('/[\s,;]+/', $raw) ?: [])
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter(fn ($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $details  label => value, in the order the email shows them
     * @param  array<int, array{code: string, amount: float, leg?: int|null}>  $credited  every account the entry paid
     * @return array{sent: int, failed: int}
     */
    public static function send(array $details, array $credited, string $subject): array
    {
        $sent = 0;
        $failed = 0;

        foreach (self::recipients() as $email) {
            TransactionalMail::manualCommissionAdded($email, $subject, $details, $credited) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
