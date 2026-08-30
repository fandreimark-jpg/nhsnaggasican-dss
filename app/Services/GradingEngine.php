<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentComponent;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;

/**
 * Centralized 25/50/25 (Written Work / Performance Task / Examination)
 * grading calculation — the ONLY place this math should ever live, per
 * CLAUDE.md's "do not duplicate grading calculations in controllers,
 * Blade templates, JavaScript, or multiple services."
 *
 * This computes a grade FROM assessment evidence (Assessment +
 * AssessmentScore). It never reads or writes grades.grade, the official
 * final grade — that stays entirely under the existing adviser-controlled
 * workflow. grades.computed_grade (see the migration adding it) is where
 * a caller may choose to store this method's result; storing it is a
 * later phase's responsibility, not this service's.
 *
 * Per component:
 *   percentage = earned score / maximum score x 100
 *   weighted contribution = percentage x component weight (from
 *   AssessmentComponent, default 25/50/25, must sum to 100)
 *
 * Final computed grade = sum of all 3 components' contributions.
 *
 * A component with NO assessment items at all for this subject/section/
 * term, or with items but no scores yet for this particular student, is
 * "missing" — the overall grade is reported incomplete (null) rather
 * than silently computed from 2 of 3 components, which would understate
 * what the student actually needs to pass. Do not hard-code any example
 * numbers here — see GradingEngineTest for the worked examples this is
 * verified against.
 */
class GradingEngine
{
    private const COMPONENTS = ['written_work', 'performance_task', 'examination'];

    /**
     * @return array{
     *     complete: bool,
     *     components: array<string, float|null>,
     *     contributions: array<string, float>,
     *     computed_grade: float|null,
     * }
     */
    public function computeGrade(Student $student, Subject $subject, Section $section, int $gradingPeriod, string $schoolYear): array
    {
        $componentPercentages = [];
        foreach (self::COMPONENTS as $key) {
            $componentPercentages[$key] = $this->componentPercentage(
                $student, $subject, $section, $gradingPeriod, $schoolYear, $key
            );
        }

        $isComplete = !in_array(null, $componentPercentages, true);

        if (!$isComplete) {
            return [
                'complete'       => false,
                'components'     => $componentPercentages,
                'contributions'  => [],
                'computed_grade' => null,
            ];
        }

        $weights = AssessmentComponent::all()->keyBy('key');
        $contributions = [];
        $total = 0.0;

        foreach ($componentPercentages as $key => $percentage) {
            $weight = (float) ($weights[$key]->weight ?? 0);
            $contribution = round($percentage * $weight / 100, 2);
            $contributions[$key] = $contribution;
            $total += $contribution;
        }

        return [
            'complete'       => true,
            'components'     => $componentPercentages,
            'contributions'  => $contributions,
            'computed_grade' => round($total, 2),
        ];
    }

    /**
     * The student's percentage for one component, aggregated across every
     * scored item in it (e.g. Quiz 1 + Quiz 2 combined, not just one).
     * Null if there are no items for this component at all, or the
     * student has no scores yet among the items that do exist.
     */
    private function componentPercentage(
        Student $student,
        Subject $subject,
        Section $section,
        int $gradingPeriod,
        string $schoolYear,
        string $componentKey
    ): ?float {
        $assessmentIds = Assessment::where('subject_id', $subject->id)
            ->where('section_id', $section->id)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $schoolYear)
            ->where('component', $componentKey)
            ->pluck('id');

        if ($assessmentIds->isEmpty()) {
            return null;
        }

        $scores = AssessmentScore::where('student_id', $student->id)
            ->whereIn('assessment_id', $assessmentIds)
            ->with('assessment')
            ->get();

        if ($scores->isEmpty()) {
            return null;
        }

        $earned = $scores->sum(fn($s) => (float) $s->score);
        $max    = $scores->sum(fn($s) => (float) $s->assessment->max_score);

        if ($max <= 0) {
            return null;
        }

        return round(($earned / $max) * 100, 2);
    }
}
