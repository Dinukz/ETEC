<?php

namespace App\Support;

/**
 * ⚠️ CATCH A MISTYPED EMAIL ADDRESS BEFORE ANYTHING IS SENT TO IT (2026-11-10).
 *
 * Hostinger suspended sending from info@etecbusiness.lk ("continuous suspicious
 * activity") after the inbox filled with "Undelivered Mail Returned to Sender".
 * The owner opened one: the site's own Welcome email, to a student typed as
 * "...@email.com" instead of "...@gmail.com". In the 3 Nov database copy, 220
 * of 5,857 accounts (3.8%) are certainly wrong — gamil.com 45, gamail.com 20,
 * gmali.com 19, gmil.com 14, email.com 14, gmai.com 14, "gmail" 11 ... — and
 * every Welcome, payment and password email to them bounces (or, where the
 * misspelt domain really exists, hands the login details to a stranger).
 *
 * Whether a MAILBOX exists cannot be known before sending to it; asking the
 * other server is itself what spam tools do. So this only names what is
 * certainly wrong: a near-miss of a real provider's domain, a domain with no
 * proper ending, and a Gmail name Gmail itself would never accept. No network
 * lookups — the same answer every time, instantly. Run over every address on
 * file it flagged nothing real (every gmail.com / icloud.com / outlook.com /
 * company address passed).
 */
final class EmailAddressCheck
{
    /**
     * The providers people here really use, each with how many letters a typed
     * domain may be off by and still be that provider mistyped. Gmail is 94% of
     * every address on file, so it gets one letter more when the domain starts
     * with a g ("gami.com", "gimel.com" are 3 off) — not otherwise, or real
     * providers like aol.com and mac.com would read as typos of it.
     */
    public const PROVIDERS = [
        'gmail.com' => 2, 'icloud.com' => 2, 'yahoo.com' => 2, 'hotmail.com' => 2,
        'outlook.com' => 2, 'googlemail.com' => 2,
    ];

    /**
     * Real domains that are never a typo, though some sit close to one above.
     * Short ones (live.com) are matched exactly only: one letter off live.com is
     * an ordinary word domain (life.com, love.com), not a slip.
     */
    public const ALSO_REAL = [
        'live.com', 'ymail.com', 'gmx.com', 'rocketmail.com', 'aol.com', 'mac.com', 'me.com',
        'msn.com', 'protonmail.com', 'proton.me', 'zoho.com', 'yandex.com',
    ];

    /** Why this address cannot be right, or null when nothing is certainly wrong. */
    public static function problem(?string $email): ?string
    {
        [$local, $domain] = self::split($email);

        // An address with no @ at all is the plain 'email' rule's job.
        if ($local === null) {
            return null;
        }

        if (isset(self::PROVIDERS[$domain]) || in_array($domain, self::ALSO_REAL, true)) {
            return in_array($domain, ['gmail.com', 'googlemail.com'], true) && ! self::possibleGmailName($local)
                ? 'This is not a possible Gmail address: before the @ a Gmail address has 6–30 letters, numbers or dots only. Please check it.'
                : null;
        }

        if (($suggestion = self::suggestion($email)) !== null) {
            return "This email address looks mistyped — did you mean {$suggestion}? Login details are emailed there, so please check it.";
        }

        // "gmail", "com", "gemel": no proper ending.
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,}$/', $domain)) {
            return 'The part after the @ is not a real email address. Please check it.';
        }

        return null;
    }

    /** The address it was most likely meant to be, when the domain is a near-miss of a provider. */
    public static function suggestion(?string $email): ?string
    {
        [$local, $domain] = self::split($email);

        if ($local === null || isset(self::PROVIDERS[$domain]) || in_array($domain, self::ALSO_REAL, true)) {
            return null;
        }

        // "136gmail.com", "gmail.com.0764044136.nic", "gmail.lk", "gmail.con":
        // anything that carries a Gmail spelling but is not Gmail.
        if (str_contains($domain, 'gmail') || str_contains($domain, 'gamil') || str_contains($domain, 'gmial')) {
            return $local.'@gmail.com';
        }

        if (str_contains($domain, 'icloud')) {
            return $local.'@icloud.com';
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (self::PROVIDERS as $provider => $allowed) {
            if ($provider === 'gmail.com' && str_starts_with($domain, 'g')) {
                $allowed++;
            }

            $distance = levenshtein($domain, $provider);

            if ($distance <= $allowed && $distance < $bestDistance) {
                [$best, $bestDistance] = [$provider, $distance];
            }
        }

        return $best === null ? null : $local.'@'.$best;
    }

    /** @return array{0: ?string, 1: string} the part before the @ (null when there is no usable @) and the domain */
    private static function split(?string $email): array
    {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');

        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return [null, ''];
        }

        return [substr($email, 0, $at), substr($email, $at + 1)];
    }

    /** Gmail's own rule: 6–30 letters, numbers and dots, no dot at either end or twice in a row. */
    private static function possibleGmailName(string $local): bool
    {
        $name = explode('+', $local, 2)[0];
        $letters = str_replace('.', '', $name);

        return preg_match('/^[a-z0-9.]+$/', $name) === 1
            && strlen($letters) >= 6 && strlen($letters) <= 30
            && ! str_starts_with($name, '.') && ! str_ends_with($name, '.')
            && ! str_contains($name, '..');
    }
}
