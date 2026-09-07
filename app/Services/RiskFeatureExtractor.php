<?php

namespace App\Services;

use App\Models\AssessmentScore;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * P-12 (ML integration), the safe half: computes the expanded feature
 * set CLAUDE.md describes (average_grade, ww_mean, pt_mean, exam_mean,
 * weak_component_count, prev_term_average, trend_delta — failing_subject_count
 * is computed separately in ReportController, which already has the
 * per-subject failing grades in hand) and sends them to the Python
 * classifier.
 *
 * Deliberately NOT wired into classify.py's actual model yet. The
 * current model is trained on ONE feature (average_grade) against 90
 * purely synthetic samples that are just clean threshold boundaries —
 * its "100% cross-validation accuracy" is expected, not evidence of
 * real-world validity, and there is no real assessment data yet to
 * validate an 8-feature retrain against (the assessment layer itself
 * was only just built). Retraining now would trade a simple,
 * well-understood model for a more complex one with no way to verify
 * it's actually better. See the note atop analytics/classify.py.
 *
 * So: these features are computed and included in the JSON payload
 * (classify.py already ignores fields it doesn't read, so this is a
 * safe, no-op addition today) so that once real assessment data has
 * accumulated, retraining to actually use them is a low-risk follow-up
 * with something real to validate against — not a second big rebuild.
 *
 * Every feature here is null when there isn't enough evidence to
 * compute it (no assessment items yet, no previous term on record,
 * etc.) rather than a fabricated default — "safe fallback when
 * component features are unavailable" means "clearly absent," not
 * "silently guessed."
 */
class RiskFeatureExtractor
{
    private const TARGET = 75.0;

    /**
     * Per (section_id, grading_period, school_year) cache of every
     * student's earned/max sums per component, built once via a single
     * grouped query — see componentSumsFor(). ReportController::
     * buildPythonPayload() reuses ONE RiskFeatureExtractor instance
     * across every student in a section (array_map over $gradesData),
     * so this survives for the whole Submit Report request but never
     * crosses requests (a fresh instance is constructed per request).
     *
     * @var array<string, array<string, array<int, array{earned: float, max: float}>>>
     */
    private array $componentSumsCache = [];

    /**
     * @return array{
     *     ww_mean: float|null,
     *     pt_mean: float|null,
     *     exam_mean: float|null,
     *     weak_component_count: int|null,
     *     prev_term_average: float|null,
     *     trend_delta: float|null,
     * }
     */
    public function extract(Student $student, Section $section, int $gradingPeriod, string $schoolYear, float $averageGrade): array
    {
        $componentMeans = $this->componentMeans($student, $section, $gradingPeriod, $schoolYear);
        $prevTermAverage = $this->previousTermAverage($student, $gradingPeriod, $schoolYear);

        return [
            'ww_mean'              => $componentMeans['written_work'],
            'pt_mean'              => $componentMeans['performance_task'],
            'exam_mean'            => $componentMeans['examination'],
            'weak_component_count' => $this->weakComponentCount($componentMeans),
            'prev_term_average'    => $prevTermAverage,
            'trend_delta'          => $prevTermAverage === null ? null : round($averageGrade - $prevTermAverage, 2),
        ];
    }

    /**
     * Each component's percentage aggregated across EVERY subject the
     * student has assessment evidence for this term (not just one
     * subject) — a term-wide signal, distinct from
     * PerformanceAnalysisService's per-subject breakdown.
     */
    private function componentMeans(Student $student, Section $section, int $gradingPeriod, string $schoolYear): array
    {
        $sums = $this->componentSumsFor($section, $gradingPeriod, $schoolYear);

        $means = [];
        foreach (['written_work', 'performance_task', 'examination'] as $key) {
            $studentSum = $sums[$key][$student->id] ?? null;
            $means[$key] = ($studentSum !== null && $studentSum['max'] > 0)
                ? round(($studentSum['earned'] / $studentSum['max']) * 100, 2)
                : null;
        }

        return $means;
    }

    /**
     * Used to run 3 identical Assessment::where('section_id', ...)
     * ->pluck('id') queries PER STUDENT — section_id/grading_period/
     * school_year don't vary across students in the same section, so
     * every student re-ran the exact same lookup. This runs ONCE per
     * (section, period, school year) via a single grouped join query
     * covering all 3 components at once, cached on $this so every
     * subsequent student in the same batch reads from memory instead of
     * re-querying (see the class docblock).
     *
     * A student with no rows for a component (either no assessment
     * items exist for it at all, or items exist but this student has
     * no scores yet) simply doesn't appear in that component's array —
     * componentMeans() above treats that as null, matching the original
     * per-student implementation's "not enough evidence" behavior.
     *
     * @return array<string, array<int, array{earned: float, max: float}>>
     */
    private function componentSumsFor(Section $section, int $gradingPeriod, string $schoolYear): array
    {
        $cacheKey = $section->id . '|' . $gradingPeriod . '|' . $schoolYear;

        if (isset($this->componentSumsCache[$cacheKey])) {
            return $this->componentSumsCache[$cacheKey];
        }

        $rows = AssessmentScore::query()
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.section_id', $section->id)
            ->where('assessments.grading_period', $gradingPeriod)
            ->where('assessments.school_year', $schoolYear)
            ->select(
                'assessments.component',
                'assessment_scores.student_id',
                DB::raw('SUM(assessment_scores.score) as earned'),
                DB::raw('SUM(assessments.max_score) as max_total')
            )
            ->groupBy('assessments.component', 'assessment_scores.student_id')
            ->get();

        $sums = ['written_work' => [], 'performance_task' => [], 'examination' => []];
        foreach ($rows as $row) {
            $sums[$row->component][$row->student_id] = [
                'earned' => (float) $row->earned,
                'max'    => (float) $row->max_total,
            ];
        }

        return $this->componentSumsCache[$cacheKey] = $sums;
    }

    private function weakComponentCount(array $componentMeans): ?int
    {
        $available = array_filter($componentMeans, fn($v) => $v !== null);

        if (empty($available)) {
            return null;
        }

        return count(array_filter($available, fn($v) => $v < self::TARGET));
    }

    private function previousTermAverage(Student $student, int $gradingPeriod, string $schoolYear): ?float
    {
        if ($gradingPeriod <= 1) {
            return null;
        }

        $value = RiskResult::where('student_id', $student->id)
            ->where('school_year', $schoolYear)
            ->where('grading_period', $gradingPeriod - 1)
            ->value('average_grade');

        return $value !== null ? (float) $value : null;
    }
}
