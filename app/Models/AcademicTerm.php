<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AcademicTerm
 * -------------
 * Controls which grading period (1, 2, or 3) is currently open for
 * grade encoding, system-wide, for a given school year. Only ONE
 * term can be open at a time — enforced in AcademicTermController.
 */
class AcademicTerm extends Model
{
    protected $fillable = ['school_year', 'term', 'is_open', 'opened_at', 'closed_at'];

    protected $casts = [
        'is_open'   => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Make sure rows for Term 1, 2, 3 exist for this school year.
     * A brand-new school year starts with Term 1 already open.
     */
    public static function ensureExistFor(string $schoolYear): void
    {
        foreach ([1, 2, 3] as $term) {
            static::firstOrCreate(
                ['school_year' => $schoolYear, 'term' => $term],
                ['is_open' => $term === 1]
            );
        }
    }

    /** Which term number is open right now? Null if none. */
    public static function currentOpenTerm(string $schoolYear): ?int
    {
        return static::where('school_year', $schoolYear)
            ->where('is_open', true)
            ->value('term');
    }

    public static function isOpen(string $schoolYear, int $term): bool
    {
        return static::where('school_year', $schoolYear)
            ->where('term', $term)
            ->where('is_open', true)
            ->exists();
    }

    /**
     * Is this term FULLY encoded across every section for this school year?
     * "Fully encoded" = every student in every section has a grade for
     * every subject applicable to their section, in this term.
     *
     * Returns ['complete' => bool, 'incomplete_sections' => [...]]
     */
    public static function completionStatus(string $schoolYear, int $term): array
    {
        $sections = Section::where('school_year', $schoolYear)->get();
        $incomplete = [];

        foreach ($sections as $section) {
            $studentCount = Student::where('section_id', $section->id)->count();
            $subjectCount = Subject::forSection($section)->count();
            $expected     = $studentCount * $subjectCount;

            // Nothing to require yet (no students or no subjects assigned) — skip.
            if ($expected === 0) continue;

            $actual = Grade::where('section_id', $section->id)
                ->where('grading_period', $term)
                ->where('school_year', $schoolYear)
                ->count();

            if ($actual < $expected) {
                $incomplete[] = [
                    'section'  => $section->name . ' — Grade ' . $section->grade_level,
                    'encoded'  => $actual,
                    'expected' => $expected,
                ];
            }
        }

        return [
            'complete'            => empty($incomplete),
            'incomplete_sections' => $incomplete,
        ];
    }

    /**
     * TASK 4 of "dashboard structure and upload safeguards" — the exact
     * same students x subjects arithmetic completionStatus() uses to
     * decide whether a term CAN open, but for EVERY section (not just the
     * incomplete ones) and returned as data rather than a boolean, so the
     * gap is visible on the Admin Sections and Academic Terms pages
     * BEFORE anyone clicks "Open Term N" and gets refused. Display only —
     * completionStatus() itself, and the guard in
     * Admin\AcademicTermController::open(), are both untouched; this must
     * always compute the identical expected/actual numbers so the two
     * can be checked against each other by hand.
     *
     * @return array<int, array{section: Section, subject_count: int, student_count: int, expected: int, encoded: int}>
     */
    public static function sectionCapacityBreakdown(string $schoolYear, int $term): array
    {
        $sections = Section::where('school_year', $schoolYear)
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get();

        return $sections->map(function (Section $section) use ($term, $schoolYear) {
            $studentCount = Student::where('section_id', $section->id)->count();
            $subjectCount = Subject::forSection($section)->count();

            $encoded = Grade::where('section_id', $section->id)
                ->where('grading_period', $term)
                ->where('school_year', $schoolYear)
                ->count();

            return [
                'section'       => $section,
                'subject_count' => $subjectCount,
                'student_count' => $studentCount,
                'expected'      => $studentCount * $subjectCount,
                'encoded'       => $encoded,
            ];
        })->all();
    }

    /**
     * Terms (within a school year) whose risk_results exist but whose
     * underlying grades do not — see the "live in-term risk + stale data
     * guard" prompt. This happens when grades/assessments are wiped
     * (e.g. a partial hand-written truncate) without also clearing the
     * risk_results and report_submissions that were computed from them.
     * A risk level with no surviving evidence behind it must never be
     * displayed as if nothing were wrong — every place a risk level
     * appears checks this first.
     *
     * @return array<int, int> term numbers (1-3) with this problem, empty if none
     */
    public static function staleRiskTerms(string $schoolYear): array
    {
        $stale = [];

        foreach ([1, 2, 3] as $term) {
            $hasRiskResults = RiskResult::where('school_year', $schoolYear)
                ->where('grading_period', $term)
                ->exists();

            if (!$hasRiskResults) {
                continue;
            }

            $hasGrades = Grade::where('school_year', $schoolYear)
                ->where('grading_period', $term)
                ->exists();

            if (!$hasGrades) {
                $stale[] = $term;
            }
        }

        return $stale;
    }
}