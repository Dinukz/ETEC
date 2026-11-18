<?php

namespace App\Services;

use App\Models\Consultant;
use App\Models\Student;

/**
 * ⚠️ READ-ONLY. Can the SCN number match the SRN number? (2026-08-27)
 *
 * The owner asked for a rule: when a student becomes a consultant, their SCN
 * number should be their SRN number — "SRN 100 nm SCN number ekath SCN 100
 * wenn one".
 *
 * That is not safely implementable as stated, because hidden placement accounts
 * share the SAME globally-unique scn_code namespace as real consultants:
 * HiddenAccountService::nextScn() serves the real owner AND every hidden
 * account from one sequence starting at 100, and the column is unique(). Hidden
 * accounts take roughly two of every three numbers, plus three more per course
 * purchase — so a promoted student's number is usually already spoken for. The
 * owner's own example collides: SRN 100 wants SCN-00100, which is the first
 * consultant's main account.
 *
 * How many ACTUALLY collide is a question about live data, not about code, and
 * there is no CLI on production. So this counts it from the browser, changes
 * nothing, and lets the owner decide with real numbers in front of them.
 *
 * Nothing here writes. It is safe to open on production at any time.
 */
class ScnNumberAudit
{
    /**
     * One row per real consultant who was once a student, plus totals.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function run(): array
    {
        // Real consultants only — a hidden account is a placement bucket, never
        // a person, so it was never a student and has no SRN to match.
        $consultants = Consultant::with('user')
            ->where('is_hidden', false)
            ->orderBy('id')
            ->get();

        $students = Student::whereIn('user_id', $consultants->pluck('user_id')->filter())
            ->pluck('student_code', 'user_id');

        // Every SCN in use, and whether a real person or a placement bucket
        // holds it. One query rather than a lookup per consultant.
        $holders = Consultant::whereNotNull('scn_code')
            ->get(['id', 'scn_code', 'is_hidden'])
            ->keyBy('scn_code');

        $rows = [];
        $totals = ['total' => 0, 'already_match' => 0, 'free' => 0, 'taken_by_hidden' => 0, 'taken_by_real' => 0, 'no_srn' => 0];

        foreach ($consultants as $consultant) {
            $srn = $students[$consultant->user_id] ?? null;
            $totals['total']++;

            if (! $srn) {
                // Admin-created consultant who was never enrolled as a student.
                // The rule has no input for them and they need the sequence.
                $totals['no_srn']++;

                $rows[] = [
                    'scn' => $consultant->scn_code,
                    'name' => $consultant->display_name,
                    'srn' => null,
                    'wanted' => null,
                    'status' => 'no-srn',
                    'held_by' => null,
                ];

                continue;
            }

            $digits = preg_replace('/\D/', '', $srn);
            $wanted = 'SCN-'.str_pad($digits, 5, '0', STR_PAD_LEFT);
            $holder = $holders[$wanted] ?? null;

            $status = match (true) {
                $consultant->scn_code === $wanted => 'already-match',
                $holder === null => 'free',
                (bool) $holder->is_hidden => 'taken-by-hidden',
                default => 'taken-by-real',
            };

            $totals[str_replace('-', '_', $status)]++;

            $rows[] = [
                'scn' => $consultant->scn_code,
                'name' => $consultant->display_name,
                'srn' => $srn,
                'wanted' => $wanted,
                'status' => $status,
                'held_by' => $holder && $consultant->scn_code !== $wanted
                    ? ($holder->is_hidden ? 'a hidden placement account' : 'another consultant')
                    : null,
            ];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}
