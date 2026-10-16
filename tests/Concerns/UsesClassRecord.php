<?php

namespace Tests\Concerns;

use App\Models\Assessment;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassRecordService;
use Illuminate\Testing\TestResponse;

/**
 * Test helpers for the final assessment workflow ("Class Record
 * refinements", 2026-09-28): an assessment is DEFINED on
 * teacher.assessments.store (details only), and scores / Missing marks are
 * entered ONLY through the Class Record batch save. These helpers post the
 * same requests the pages do; they grant no permission — the acting user
 * still needs a teaching assignment.
 */
trait UsesClassRecord
{
    /** POST the details-only Add Assessment form. */
    protected function addAssessment(User $user, Section $section, Subject $subject, array $details, int $term = 1): TestResponse
    {
        return $this->actingAs($user)->post(route('teacher.assessments.store'), array_merge([
            'section_id' => $section->id, 'subject_id' => $subject->id, 'grading_period' => $term,
            'component' => 'written_work', 'max_score' => 20,
        ], $details));
    }

    /** PUT the details-only Edit Assessment form, opened just now. */
    protected function editAssessment(User $user, Assessment $assessment, array $details): TestResponse
    {
        return $this->actingAs($user)->put(route('teacher.assessments.update', $assessment->id), array_merge([
            'max_score' => ClassRecordService::fmt((float) $assessment->max_score),
            'component' => $assessment->component,
            'exam_role' => $assessment->exam_role,
            'loaded_fingerprint' => ClassRecordService::definitionFingerprint($assessment),
        ], $details));
    }

    /**
     * Save Class Record cells for one item, the way the grid does: each value
     * is a score string ('0' included), 'M' for Missing, or '' / null for
     * empty (No Evidence Yet); the base is the value stored right now.
     *
     * @param array<int, string|int|float|null> $values student id => value
     */
    protected function saveCells(User $user, Assessment $assessment, array $values): TestResponse
    {
        $cells = [];
        foreach ($values as $studentId => $value) {
            $value = $value === null ? '' : trim((string) $value);
            $stored = AssessmentScore::where('assessment_id', $assessment->id)->where('student_id', $studentId)->value('score');
            $marked = AssessmentLearnerStatus::where('assessment_id', $assessment->id)->where('student_id', $studentId)->exists();
            $cells[] = [
                'assessment_id' => $assessment->id,
                'student_id'    => (int) $studentId,
                'score'         => in_array(strtolower($value), ['', 'm'], true) ? null : $value,
                'missing'       => strtolower($value) === 'm',
                'base_score'    => $stored === null ? null : ClassRecordService::fmt((float) $stored),
                'base_missing'  => $marked,
            ];
        }

        return $this->actingAs($user)->postJson(route('teacher.class-record.save'), [
            'section_id' => $assessment->section_id, 'subject_id' => $assessment->subject_id,
            'period' => (int) $assessment->grading_period, 'cells' => $cells,
        ]);
    }
}
