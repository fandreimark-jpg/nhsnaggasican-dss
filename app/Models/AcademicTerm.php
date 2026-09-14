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
    protected $fillable = ['academic_year_id', 'school_year', 'term', 'is_open', 'opened_at', 'closed_at', 'start_date', 'end_date'];

    protected $casts = [
        'is_open'    => 'boolean',
        'opened_at'  => 'datetime',
        'closed_at'  => 'datetime',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    /**
     * "Multi-school-year academic history" work order, PART 3 — a term
     * belongs to exactly one AcademicYear. Term 1 of 2026-2027 and Term 1
     * of 2027-2028 are two different rows with two different parents;
     * `term` (1/2/3) alone never identifies a term — always pair it with
     * school_year / academic_year_id.
     */
    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Make sure rows for Term 1, 2, 3 exist for this school year, each
     * linked to the year's AcademicYear row (created inactive if the
     * year was never explicitly configured). A brand-new school year
     * starts with Term 1 already open.
     */
    public static function ensureExistFor(string $schoolYear): void
    {
        $year = AcademicYear::ensureFor($schoolYear);

        foreach ([1, 2, 3] as $term) {
            $row = static::firstOrCreate(
                ['school_year' => $schoolYear, 'term' => $term],
                ['is_open' => $term === 1, 'academic_year_id' => $year->id]
            );

            if ($row->academic_year_id === null) {
                $row->forceFill(['academic_year_id' => $year->id])->save();
            }
        }
    }

    /**
     * Whether an Adviser may WRITE academic history (grades, assessment
     * scores, uploads, report submissions) into this term. Two conditions,
     * both server-side: the term is open, AND its school year is the one
     * currently active. A closed term is read-only. A historical year's
     * term is read-only even if its is_open flag was left set (a year that
     * is no longer active is completed — AcademicYear::activate() closes
     * its open terms, and this check makes that hold even if it didn't).
     * Every write guard in the Adviser controllers calls this, not
     * isOpen(), so a request that names an old year's section cannot
     * reopen history from the URL.
     */
    public static function acceptsWrites(string $schoolYear, int $term): bool
    {
        return static::isOpen($schoolYear, $term)
            && $schoolYear === Section::activeSchoolYear();
    }

    /**
     * The one-line reason a write was refused, matching acceptsWrites().
     */
    public static function writeRefusalReason(string $schoolYear, int $term): string
    {
        if ($schoolYear !== Section::activeSchoolYear()) {
            return "School Year {$schoolYear} is not the active school year. Its records are historical and read-only.";
        }

        return "Term {$term} is closed. Grades and assessments can only be recorded while the term is open.";
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
     * "ECR alignment" work order, PART 6 — "expected" now comes from
     * SectionElectiveStatus, the one shared place every "how many grades
     * should exist" consumer reads from, instead of a flat
     * students-times-subjects multiply. A section whose SSHS electives
     * exist but haven't been assigned yet (SectionElectiveStatus::
     * isFullyConfigured() === false) is reported incomplete with no
     * `expected` figure at all — reporting a core-only expected count
     * here would read as "complete" the moment core grades are in, which
     * would be exactly the silent-zero bug this part exists to fix, one
     * level up.
     *
     * Returns ['complete' => bool, 'incomplete_sections' => [...]]
     */
    public static function completionStatus(string $schoolYear, int $term): array
    {
        $sections = Section::where('school_year', $schoolYear)->get();
        $incomplete = [];
        // "UI legibility pass" — distinguishes genuine 100% completion from
        // the vacuous case (zero sections, or every section with nothing
        // expected yet). Without this, $incomplete stays empty() in both
        // cases and a first-time install reads "All sections fully encoded
        // for this term" when there is nothing to encode at all.
        $hasAnythingExpected = false;
        $electiveStatus = new \App\Services\SectionElectiveStatus();

        foreach ($sections as $section) {
            $studentCount = Student::enrolledIn($section)->count();

            // Nothing to require yet (no students assigned) — skip.
            if ($studentCount === 0) continue;

            if (!$electiveStatus->isFullyConfigured($section)) {
                $hasAnythingExpected = true;
                $actual = Grade::where('section_id', $section->id)
                    ->where('grading_period', $term)
                    ->where('school_year', $schoolYear)
                    ->count();
                $incomplete[] = [
                    'section'  => $section->name . ' — Grade ' . $section->grade_level,
                    'encoded'  => $actual,
                    'expected' => null,
                    'reason'   => 'Electives not yet assigned for this section.',
                ];
                continue;
            }

            $expected = $electiveStatus->expectedGradeCount($section, $term);

            // Nothing to require yet (no subjects resolved) — skip.
            if ($expected === 0) {
                $incomplete[] = ['section' => $section->name, 'encoded' => 0, 'expected' => 0,
                    'reason' => 'No subjects configured for this term.'];
                continue;
            }

            $hasAnythingExpected = true;

            $actual = Grade::where('section_id', $section->id)
                ->where('grading_period', $term)
                ->where('school_year', $schoolYear)
                ->whereIn('student_id', Student::enrolledIn($section)->select('id'))
                ->whereIn('subject_id', $electiveStatus->expectedSubjectsForTerm($section, $term)->pluck('id'))
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
            'complete'              => $hasAnythingExpected && empty($incomplete),
            'has_anything_expected' => $hasAnythingExpected,
            'incomplete_sections'   => $incomplete,
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
            $studentCount = Student::enrolledIn($section)->count();
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
