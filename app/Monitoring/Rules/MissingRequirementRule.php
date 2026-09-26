<?php

namespace App\Monitoring\Rules;

use App\Models\AcademicConcern;
use App\Models\Assessment;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Monitoring\ConcernFinding;
use App\Monitoring\EvaluationScope;
use App\Monitoring\MonitoringRule;
use App\Services\AssessmentStatusService;

/**
 * EXPLICIT_MISSING_REQUIREMENT
 *
 * Condition: an adviser explicitly marked a learner Missing on an
 * assessment item (Phase 2A, App\Services\AssessmentStatusService).
 * Result: one For Review concern per learner per item.
 *
 * No threshold: one explicitly Missing item is the whole condition — the
 * adviser has already stated what happened. A blank score (Not Yet Given /
 * No Evidence Yet), including every blank E-Class Record cell, is NOT
 * Missing and never produces a concern. A score always wins over a marker,
 * exactly as AssessmentStatusService::resolve() says.
 */
class MissingRequirementRule implements MonitoringRule
{
    public const KEY = 'explicit_missing_requirement';

    private const COMPONENT_LABELS = [
        'written_work'     => 'Written Work',
        'performance_task' => 'Performance Task',
        'examination'      => 'Examination',
    ];

    private const EXAM_ROLE_LABELS = [
        'st1'       => 'Summative Test 1',
        'st2'       => 'Summative Test 2',
        'term_exam' => 'Term Examination',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): int
    {
        return 1;
    }

    public function label(): string
    {
        return 'Missing Requirement';
    }

    public function findings(EvaluationScope $scope): iterable
    {
        if ($scope->sectionIds === []) {
            return [];
        }

        $markers = AssessmentLearnerStatus::query()
            ->where('status', AssessmentLearnerStatus::STATUS_MISSING)
            ->whereIn('assessment_id', Assessment::query()
                ->select('id')
                ->whereIn('section_id', $scope->sectionIds)
                ->where('grading_period', $scope->gradingPeriod)
                ->where('school_year', $scope->schoolYear))
            // A score wins: a learner with a recorded score is Completed.
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('assessment_scores')
                    ->whereColumn('assessment_scores.assessment_id', 'assessment_learner_statuses.assessment_id')
                    ->whereColumn('assessment_scores.student_id', 'assessment_learner_statuses.student_id');
            })
            ->with(['assessment.subject', 'assessment.section', 'student'])
            ->orderBy('id')
            ->get();

        foreach ($markers as $marker) {
            $item = $marker->assessment;
            $student = $marker->student;

            yield new ConcernFinding(
                studentId: $student->id,
                subjectId: $item->subject_id,
                sectionId: $item->section_id,
                schoolYear: $item->school_year,
                gradingPeriod: (int) $item->grading_period,
                contextKey: 'assessment:' . $item->id,
                evidence: [
                    'rule'       => self::KEY,
                    'concern'    => $this->label(),
                    'learner'    => [
                        'id'   => $student->id,
                        'lrn'  => $student->lrn,
                        'name' => $student->last_name . ', ' . $student->first_name,
                    ],
                    'subject'    => ['id' => $item->subject_id, 'name' => $item->subject?->name],
                    'section'    => ['id' => $item->section_id, 'name' => $item->section?->name],
                    'assessment' => [
                        'id'                    => $item->id,
                        'name'                  => $item->name,
                        'component'             => $item->component,
                        'component_label'       => self::COMPONENT_LABELS[$item->component] ?? $item->component,
                        'exam_role'             => $item->exam_role,
                        'exam_role_label'       => self::EXAM_ROLE_LABELS[$item->exam_role] ?? null,
                        'max_score'             => (float) $item->max_score,
                        'is_additional_support' => (bool) $item->is_additional_support,
                    ],
                    'term'        => (int) $item->grading_period,
                    'school_year' => $item->school_year,
                    'status'       => AssessmentStatusService::MISSING,
                    'status_label' => AssessmentStatusService::label(AssessmentStatusService::MISSING),
                    'marked_by'    => $marker->recorded_by,
                    'marked_at'    => $marker->created_at?->toIso8601String(),
                ],
                summary: sprintf('%s, %s — %s: %s (%s) marked Missing',
                    $student->last_name, $student->first_name, $item->subject?->name, $item->name,
                    self::COMPONENT_LABELS[$item->component] ?? $item->component),
            );
        }
    }

    public function resolutionReason(AcademicConcern $concern): string
    {
        $assessmentId = (int) ($concern->evidence['assessment']['id'] ?? 0);
        $name = $concern->evidence['assessment']['name'] ?? 'the assessment item';

        if (!Assessment::whereKey($assessmentId)->exists()) {
            return "The assessment item \"{$name}\" no longer exists.";
        }

        $scored = AssessmentScore::where('assessment_id', $assessmentId)
            ->where('student_id', $concern->student_id)
            ->exists();

        return $scored
            ? "A score was recorded for \"{$name}\"."
            : "The Missing marker on \"{$name}\" was removed; no score has been recorded yet.";
    }
}
