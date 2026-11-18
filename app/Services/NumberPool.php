<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ ONE NUMBER POOL SHARED BY SRN AND SCN, IN BLOCKS OF THREE.
 *
 * Asked for 2026-08-31:
 *
 *   "Student lage hadana SRN number eka thama issrahat SCN number ekak widiyta
 *    convert wenne one, e widiyt hadann. SCN number 3k issrahat gannwa wage,
 *    SRN eketh number 3k gann one — mokada SCN number eka weddi eka laga
 *    account 3k enn epai."
 *   = the SRN a student is given must later become their SCN. And just as an
 *     SCN consumes three numbers, an SRN must reserve three too, because when
 *     that number becomes a consultant it brings three accounts with it.
 *
 * BEFORE: two independent sequences — SRN counted from 1, SCN from 100 — so
 * SRN-00100 and SCN-00100 were different people, and a promoted student could
 * never keep their number. ScnNumberAudit exists only to count that collision.
 *
 * NOW: one counter. A student takes N and reserves N+1 and N+2; if they are
 * ever promoted, they become SCN-N with hidden accounts SCN-(N+1) and SCN-(N+2)
 * — the same digits they have had all along.
 *
 * ⚠️ FORWARD ONLY. Nothing already issued is renumbered: an SCN or SRN is how a
 * person is identified on receipts, in reports and to each other, and rewriting
 * history would break every one of those. The counter starts ABOVE the highest
 * number either table has ever used, so a new allocation can never collide with
 * an old one. Existing SRN/SCN pairs stay mismatched; only new ones line up.
 *
 * ⚠️ THREE NUMBERS PER STUDENT IS DELIBERATE WASTE. A student who never becomes
 * a consultant leaves N+1 and N+2 unused forever. That is the price of the
 * guarantee above, and the owner asked for it knowing that.
 */
class NumberPool
{
    /** Where the counter lives. */
    public const KEY = 'shared_number_pool_next';

    /** How many numbers one identity reserves: itself plus its two accounts. */
    public const BLOCK = 3;

    /**
     * The lowest number the pool may ever issue.
     *
     * SCN has always started at 100, and consultant codes are the ones people
     * quote to each other, so the shared pool starts there too rather than at
     * the SRN sequence's 1.
     */
    public const FLOOR = 100;

    /**
     * Reserve $count consecutive numbers and return the FIRST one.
     *
     * Locked for the whole read-modify-write, because two students registering
     * in the same second would otherwise both read the same value and be issued
     * the same code — and `students.student_code` is the thing every later
     * lookup keys on.
     */
    public static function take(int $count = self::BLOCK): int
    {
        $count = max(1, $count);

        return DB::transaction(function () use ($count) {
            $row = Setting::query()->where('key', self::KEY)->lockForUpdate()->first();

            $next = $row ? (int) $row->value : self::floorFromExistingData();

            // Never hand back something already in use — belt and braces for a
            // database where codes were inserted by hand or by an old build.
            while (self::isTaken($next, $count)) {
                $next += $count;
            }

            Setting::set(self::KEY, $next + $count, 'integer', 'numbering');

            return $next;
        });
    }

    /** What the next allocation would be, without consuming it. */
    public static function peek(): int
    {
        $row = Setting::query()->where('key', self::KEY)->first();

        return $row ? (int) $row->value : self::floorFromExistingData();
    }

    /**
     * Put the counter back to the lowest number nothing is using.
     *
     * ⚠️ NEEDED AFTER DELETING IDENTITIES. The counter only ever moves forward,
     * so once it has reached (say) 229 it stays there even if every identity
     * above 100 is deleted — the next registration would still be SRN-00229.
     * Reported 2026-09-01: "kalin patn gaththe SCN 100, dn patn ganne SCN 229.
     * Meka patn ganna one SCN 100."
     *
     * ⚠️ IT CANNOT CAUSE A COLLISION. It recomputes from what the two tables
     * ACTUALLY hold right now and lands one above the highest — so on a database
     * that still has real consultants it will NOT drop to 100, and that is
     * correct. It only reclaims numbers that nothing is using any more.
     *
     * @return array{from: int, to: int} the counter before and after
     */
    public static function recompute(): array
    {
        return DB::transaction(function () {
            $before = self::peek();
            $after = self::floorFromExistingData();

            Setting::set(self::KEY, $after, 'integer', 'numbering');

            return ['from' => $before, 'to' => $after];
        });
    }

    /** "SCN-00104" from ('SCN', 104). */
    public static function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    /** The numeric part of an SRN/SCN code, or null when it is not one. */
    public static function numberIn(?string $code): ?int
    {
        if (! $code || ! preg_match('/-(\d+)$/', $code, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * The number a promoted student should keep.
     *
     * Their SRN IS their SCN — that is the whole point. Returns null when the
     * person has no student record (an admin-created consultant), or when their
     * SRN number is already held by a consultant, which is the case for every
     * identity issued before this pool existed.
     */
    public static function numberForPromotedStudent(?int $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        $number = self::numberIn(
            DB::table('students')->where('user_id', $userId)->value('student_code')
        );

        if ($number === null || $number < self::FLOOR) {
            return null;
        }

        // ⚠️ THEIR OWN SRN DOES NOT COUNT AS TAKEN. The first version of this
        // asked isTaken($number, BLOCK), which sees SRN-N — the student's own
        // code, the very thing being converted — and refused every promotion,
        // handing out a fresh block instead. So the question is narrower: is any
        // CONSULTANT already on one of these three numbers, and are the two
        // RESERVED neighbours still free?
        $consultantCodes = [];
        $neighbourSrns = [];

        for ($i = 0; $i < self::BLOCK; $i++) {
            $consultantCodes[] = self::format('SCN', $number + $i);

            if ($i > 0) {
                $neighbourSrns[] = self::format('SRN', $number + $i);
            }
        }

        if (DB::table('consultants')->whereIn('scn_code', $consultantCodes)->exists()) {
            return null;
        }

        if (DB::table('students')->whereIn('student_code', $neighbourSrns)->exists()) {
            return null;
        }

        return $number;
    }

    /** Is any number in [$from, $from + $count) already used as an SRN or SCN? */
    protected static function isTaken(int $from, int $count): bool
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = self::format('SCN', $from + $i);
            $codes[] = self::format('SRN', $from + $i);
        }

        return DB::table('consultants')->whereIn('scn_code', $codes)->exists()
            || DB::table('students')->whereIn('student_code', $codes)->exists();
    }

    /**
     * Where to start on a database that has never used the pool: above
     * everything either table already holds, so nothing is ever reissued.
     */
    protected static function floorFromExistingData(): int
    {
        $highest = self::FLOOR - 1;

        foreach ([['consultants', 'scn_code', 'SCN'], ['students', 'student_code', 'SRN']] as [$table, $column, $prefix]) {
            $codes = DB::table($table)
                ->where($column, 'like', $prefix.'-%')
                ->pluck($column);

            foreach ($codes as $code) {
                $highest = max($highest, (int) self::numberIn($code));
            }
        }

        return $highest + 1;
    }
}
