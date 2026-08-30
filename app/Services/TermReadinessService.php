<?php

namespace App\Services;

use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;

/**
 * CLAUDE.md's "Term Readiness" check, extended to also cover assessment
 * evidence — the pre-existing readiness check (see
 * ReportController::show()'s $termStatus) only ever verified OFFICIAL
 * GRADE encoding completeness. This adds the missing half: "Final grade
 * calculation available" per CLAUDE.md's checklist, i.e. does every
 * student have a COMPLETE (all 3 components scored) computed grade for
 * every subject, via the same GradingEngine used everywhere else.
 *
 * Deliberately informational, NOT a submission blocker: assessment
 * upload is an additive evidence layer (see P-4/P-5), and requiring it
 * before submission would break the existing grade-only workflow for
 * any section that hasn't started using it yet. This shows the adviser
 * (and by extension the admin) where things stand — same "45 students,
 * 43 complete, 2 incomplete" reporting shape CLAUDE.md's example uses —
 * without changing what's actually required to submit.
 */
class TermReadinessService
{
    public function __construct(private GradingEngine $gradingEngine = new GradingEngine())
    {
    }

    /**
     * @return array{expected: int, complete: int, incomplete: int, ready: bool, has_any_evidence: bool}
     */
    public function assessmentEvidenceStatus(Section $section, int $gradingPeriod): array
    {
        $subjects = Subject::forSection($section)->get();
        $students = Student::where('section_id', $section->id)->get();

        $expected = $students->count() * $subjects->count();
        $complete = 0;
        $hasAnyEvidence = false;

        foreach ($students as $student) {
            foreach ($subjects as $subject) {
                $result = $this->gradingEngine->computeGrade($student, $subject, $section, $gradingPeriod, $section->school_year);

                if ($result['complete']) {
                    $complete++;
                }

                if (!$hasAnyEvidence && collect($result['components'])->contains(fn($p) => $p !== null)) {
                    $hasAnyEvidence = true;
                }
            }
        }

        return [
            'expected'         => $expected,
            'complete'         => $complete,
            'incomplete'       => $expected - $complete,
            'ready'            => $expected > 0 && $complete >= $expected,
            'has_any_evidence' => $hasAnyEvidence,
        ];
    }
}
