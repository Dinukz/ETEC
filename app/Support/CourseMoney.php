<?php

namespace App\Support;

use App\Models\CommissionSetting;
use App\Models\Course;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * A course's own half payment and direct service income — or the standard
 * (2026-10-22).
 *
 * The owner's new course "MASTER IN ELLIOTT WAVE": price 39,000, first
 * instalment 20,000 (balance 19,000), and 5,000 direct to the consultant who
 * registers a student. Every other course keeps the standard 18,000 / 4,000.
 * ONE place decides which amount applies, used by billing, the commission
 * engine and the hand-entry tool alike.
 *
 * ⚠️ WHAT DOES NOT CHANGE: the 2,500 service income, the upline climb, the
 * Service Groups, the stages and the System Run never look at a course at all
 * (they work by account and leg). A student of this course is seated and
 * settled exactly like any other; only the direct amount and the half differ.
 *
 * ⚠️ READ STRAIGHT OFF THE ROW, NEVER BEHIND A CACHED "COLUMN EXISTS?" CHECK.
 * The engine runs inside the verification transaction (and the Repair replay
 * inside its own), so nothing here may alter the schema — and a stale "not
 * ready" cache must never pay the standard 4,000 for a 5,000 course, because
 * Repair never rewrites a row that exists. getAttribute() simply returns null
 * when the column is not there yet, and the standard applies — today's
 * behaviour exactly.
 *
 * ⚠️ A page that loads only SOME course columns (e.g. ->get(['id', 'title']))
 * gets the standard here. Load the whole course before asking.
 */
class CourseMoney
{
    /** The standard first instalment (Settings → Half Payment). */
    public static function standardHalf(): float
    {
        return (float) Setting::get('half_payment_amount', 18000);
    }

    /** The first instalment of the half plan for this course. */
    public static function halfPayment(Course $course): float
    {
        $own = (float) ($course->getAttribute('half_payment_amount') ?? 0);
        $half = $own > 0 ? $own : self::standardHalf();

        // Capped at the course price so a cheaper course can never be billed
        // more than it costs — unchanged from before.
        return round(min($half, (float) $course->effective_price), 2);
    }

    /**
     * The direct service income for registering a student on this course.
     * Soft-deleted courses still count: a payment taken before the course was
     * removed must be paid what that course paid.
     */
    public static function directCommission(?int $courseId, CommissionSetting $settings): float
    {
        $own = 0.0;

        if ($courseId) {
            $course = Course::withTrashed()->find($courseId);
            $own = (float) ($course?->getAttribute('direct_commission') ?? 0);
        }

        return $own > 0 ? $own : (float) ($settings->direct_commission ?? 0);
    }

    /**
     * Courses that pay their own direct amount, for labels and the hand-entry
     * tool: [course_id => ['title' => …, 'price' => …, 'direct' => …]].
     *
     * @return Collection<int, array{title: string, price: float, direct: float}>
     */
    public static function coursesWithOwnDirect(): Collection
    {
        try {
            return Course::query()
                ->orderBy('title')
                ->get()
                ->filter(fn (Course $c) => (float) ($c->getAttribute('direct_commission') ?? 0) > 0)
                ->mapWithKeys(fn (Course $c) => [(int) $c->id => [
                    'title' => (string) $c->title,
                    'price' => (float) $c->effective_price,
                    'direct' => (float) $c->getAttribute('direct_commission'),
                ]]);
        } catch (\Throwable) {
            return collect();
        }
    }
}
