<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;

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
        $means = [];

        foreach (['written_work', 'performance_task', 'examination'] as $key) {
            $assessmentIds = Assessment::where('section_id', $section->id)
                ->where('grading_period', $gradingPeriod)
                ->where('school_year', $schoolYear)
                ->where('component', $key)
                ->pluck('id');

            if ($assessmentIds->isEmpty()) {
                $means[$key] = null;
                continue;
            }

            $scores = AssessmentScore::where('student_id', $student->id)
                ->whereIn('assessment_id', $assessmentIds)
                ->with('assessment')
                ->get();

            if ($scores->isEmpty()) {
                $means[$key] = null;
                continue;
            }

            $earned = $scores->sum(fn($s) => (float) $s->score);
            $max    = $scores->sum(fn($s) => (float) $s->assessment->max_score);

            $means[$key] = $max > 0 ? round(($earned / $max) * 100, 2) : null;
        }

        return $means;
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
