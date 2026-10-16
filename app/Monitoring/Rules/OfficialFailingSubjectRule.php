<?php

namespace App\Monitoring\Rules;

use App\Models\AcademicConcern;
use App\Models\Grade;
use App\Monitoring\ConcernFinding;
use App\Monitoring\EvaluationScope;
use App\Monitoring\MonitoringRule;
use App\Services\InTermStatusService;
use Illuminate\Database\Eloquent\Builder;

/**
 * OFFICIAL_FAILING_SUBJECT_GRADE — "Subject Performance Concern".
 *
 * Condition: a learner's OFFICIAL grade in one subject for one term is
 * Failing — exactly the app's existing Failing rule (Grade::scopeFailing()
 * / InTermStatusService::isFailing(): verified, not provisional, and at or
 * below InTermStatusService::FAILING_THRESHOLD, DepEd's failing mark).
 * No new threshold is introduced here.
 *
 * SUBJECT FIRST: each subject is evaluated on its own, so a failing subject
 * opens its own concern however high the learner's other subjects or their
 * overall average are. One concern per learner per subject per term.
 *
 * Deliberately NOT a trigger: the in-term Academic Performance status
 * (On Track / Below Target — computed evidence against the 75 target) and
 * an unverified or provisional grade. Those are not official results; an
 * in-term concern trigger needs school approval (NEEDS POLICY CONFIRMATION).
 *
 * Resolves only when the evidence changes: the official grade becomes
 * passing, or stops being an official grade. An assessment-item evaluation
 * observes nothing here and reconciles against nothing.
 */
class OfficialFailingSubjectRule implements MonitoringRule
{
    public const KEY = 'official_failing_subject_grade';

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
        return 'Subject Performance Concern';
    }

    public function findings(EvaluationScope $scope): iterable
    {
        if ($scope->sectionIds === [] || $scope->assessmentIds !== null) {
            return [];
        }

        $grades = Grade::query()
            ->failing()
            ->whereIn('section_id', $scope->sectionIds)
            ->where('school_year', $scope->schoolYear)
            ->where('grading_period', $scope->gradingPeriod)
            ->with(['student', 'subject', 'section'])
            ->orderBy('id')
            ->get();

        foreach ($grades as $grade) {
            $student = $grade->student;

            yield new ConcernFinding(
                studentId: $student->id,
                subjectId: $grade->subject_id,
                sectionId: $grade->section_id,
                schoolYear: $grade->school_year,
                gradingPeriod: (int) $grade->grading_period,
                contextKey: self::contextKeyFor($grade->subject_id, (int) $grade->grading_period, $grade->school_year),
                evidence: [
                    'rule'    => self::KEY,
                    'concern' => $this->label(),
                    'learner' => [
                        'id'   => $student->id,
                        'lrn'  => $student->lrn,
                        'name' => $student->last_name . ', ' . $student->first_name,
                    ],
                    'subject' => ['id' => $grade->subject_id, 'name' => $grade->subject?->name],
                    'section' => ['id' => $grade->section_id, 'name' => $grade->section?->name],
                    'official_grade' => [
                        'grade_id'          => $grade->id,
                        'grade'             => (float) $grade->grade,
                        'result'            => 'Failed',
                        'failing_threshold' => InTermStatusService::FAILING_THRESHOLD,
                        'verified_at'       => $grade->verified_at?->toIso8601String(),
                    ],
                    'term'        => (int) $grade->grading_period,
                    'school_year' => $grade->school_year,
                ],
                summary: sprintf('%s, %s — %s: official grade %s (Failed)',
                    $student->last_name, $student->first_name, $grade->subject?->name, number_format((float) $grade->grade, 2)),
            );
        }
    }

    public function constrainOpenConcerns(Builder $query, EvaluationScope $scope): void
    {
        // An assessment-item evaluation looked at no grades.
        if ($scope->assessmentIds !== null) {
            $query->whereRaw('1 = 0');
        }
    }

    public static function contextKeyFor(int $subjectId, int $term, string $schoolYear): string
    {
        return 'subject:' . $subjectId . ':term:' . $term . ':' . $schoolYear;
    }

    public function resolutionReason(AcademicConcern $concern): string
    {
        $subject = $concern->evidence['subject']['name'] ?? 'this subject';
        $grade = Grade::where('student_id', $concern->student_id)
            ->where('subject_id', $concern->subject_id)
            ->where('grading_period', $concern->grading_period)
            ->where('school_year', $concern->school_year)
            ->first();

        if (!$grade) {
            return "The official {$subject} grade for this term is no longer recorded.";
        }
        if (!$grade->is_verified || $grade->is_provisional) {
            return "The {$subject} grade is no longer an official (verified, non-provisional) grade.";
        }

        return "The official {$subject} grade is now " . number_format((float) $grade->grade, 2) . ' (passing).';
    }
}
