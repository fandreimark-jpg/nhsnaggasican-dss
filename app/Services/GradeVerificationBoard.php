<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * The Student Performance table on Verify Grades — one row per learner of
 * the ADVISORY section for one subject and term: component percentages and
 * gaps, the computed and transmuted grade from GradingEngine, In-Term
 * Status, Overall Monitoring, Focus Area, the current official grade and
 * the row's verification state.
 *
 * It only READS. Verification itself is Adviser\GradeController::
 * verifyComputedGrade() / verifyAllRemaining(), which re-check everything
 * server-side; the state here only decides what the row OFFERS, using the
 * same conditions those methods apply (evidence complete, a transmuted
 * grade available, not provisional for the bulk action).
 */
class GradeVerificationBoard
{
    public const STATUS_FILTERS = ['On Track', 'Needs Attention', 'At Risk', InTermStatusService::NO_EVIDENCE, 'Failing'];

    private const COMPONENT_LABELS = [
        'written_work'     => 'Written Work',
        'performance_task' => 'Performance Task',
        'examination'      => 'Examination',
    ];

    public function __construct(
        private PerformanceAnalysisService $analysis = new PerformanceAnalysisService(),
        private InTermStatusService $inTermStatus = new InTermStatusService(),
    ) {
    }

    /**
     * @return array{performance: Collection, itemCount: int, statusFilter: ?string, monitoringByStudentId: Collection, counts: array<string,int>}
     */
    public function build(Section $section, Subject $subject, int $term, ?string $statusFilter): array
    {
        $students = Student::enrolledIn($section)->orderBy('last_name')->get();
        $itemCount = Assessment::where('section_id', $section->id)->where('subject_id', $subject->id)
            ->where('grading_period', $term)->where('school_year', $section->school_year)->count();

        // Only worth analyzing once at least one assessment item exists —
        // otherwise every learner would just show "incomplete" for nothing.
        $performance = collect();
        if ($itemCount > 0) {
            $officialGrades = Grade::where('section_id', $section->id)->where('subject_id', $subject->id)
                ->where('grading_period', $term)->where('school_year', $section->school_year)
                ->get()->keyBy('student_id');

            // Display only: learners with at least one SCORED additional-support item.
            $supported = AssessmentScore::whereHas('assessment', fn($q) => $q->where('subject_id', $subject->id)
                ->where('section_id', $section->id)->where('grading_period', $term)
                ->where('school_year', $section->school_year)->where('is_additional_support', true))
                ->distinct()->pluck('student_id');

            $required = $this->requiredComponents($section, $subject);
            $examRoles = $this->examRoleEvidence($section, $subject, $term);

            $performance = $students->map(function ($student) use ($subject, $section, $term, $officialGrades, $supported, $required, $examRoles) {
                $result = $this->analysis->analyzeStudent($student, $subject, $section, $term, $section->school_year);
                $inTermStatus = $this->inTermStatus->fromAnalysis($result, $student, $subject, $section, $term, $section->school_year);
                $officialGrade = $officialGrades->get($student->id);

                return array_merge([
                    'student'        => $student,
                    'official_grade' => $officialGrade,
                    'in_term_status' => $inTermStatus,
                    'is_failing'     => InTermStatusService::isFailing($officialGrade),
                    'has_additional_support' => $supported->contains($student->id),
                ], $result, $this->verificationState($result, $officialGrade, $required, $examRoles, $student->id));
            });

            // Failing -> At Risk -> Needs Attention -> everyone else (stable).
            $performance = $performance->sortBy(
                fn($row) => InTermStatusService::priority($row['official_grade'], $row['in_term_status']['status'])
            )->values();
        }

        // Counts describe the WHOLE class, before the Status filter — the
        // bulk action works on the whole class too (see verifyAllRemaining()).
        $counts = [
            'learners'   => $students->count(),
            'verified'   => $performance->whereIn('verify_state', ['verified', 'verified_changed', 'verified_stale'])->count(),
            'eligible'   => $performance->where('bulk_eligible', true)->count(),
            'incomplete' => $performance->whereIn('verify_state', ['incomplete'])->count(),
        ];

        $statusFilter = in_array($statusFilter, self::STATUS_FILTERS, true) ? $statusFilter : null;
        if ($statusFilter !== null) {
            $performance = $statusFilter === 'Failing'
                ? $performance->filter(fn($row) => $row['is_failing'])->values()
                : $performance->filter(fn($row) => ($row['in_term_status']['status'] ?? null) === $statusFilter)->values();
        }

        return [
            'performance'           => $performance,
            'itemCount'             => $itemCount,
            'statusFilter'          => $statusFilter,
            'counts'                => $counts,
            'monitoringByStudentId' => app(MonitoringClassificationService::class)
                ->forStudents($students->pluck('id'), $section->school_year, $term),
        ];
    }

    /**
     * What the row can offer, from the same conditions the verify endpoints
     * apply. States: verified | verified_changed | verified_stale | eligible | provisional |
     * no_transmutation | incomplete. `bulk_eligible` mirrors
     * GradeController::classifyForVerifyAll() exactly (not yet verified,
     * complete, transmuted, not provisional).
     */
    private function verificationState(array $result, ?Grade $official, array $required, array $examRoles, int $studentId): array
    {
        $verified = $official && $official->is_verified;

        if (!$result['complete']) {
            // A verified grade whose evidence is no longer complete (a score
            // removed, or a grading-rule change) is stale, never "verified"
            // as if current; it cannot be re-verified until complete again.
            return $verified
                ? ['verify_state' => 'verified_stale', 'bulk_eligible' => false,
                    'verify_reason' => 'Verified earlier, but the current evidence is not complete: ' . $this->incompleteReason($result, $required, $examRoles, $studentId)]
                : ['verify_state' => 'incomplete', 'bulk_eligible' => false,
                    'verify_reason' => $this->incompleteReason($result, $required, $examRoles, $studentId)];
        }
        if ($result['transmuted_grade'] === null) {
            return ['verify_state' => $verified ? 'verified' : 'no_transmutation', 'bulk_eligible' => false,
                'verify_reason' => 'No transmuted grade is available for this computed grade in the ' . $result['transmutation_scheme'] . ' table.'];
        }
        if ($verified) {
            $same = round((float) $official->grade, 2) === round((float) $result['transmuted_grade'], 2)
                && round((float) $official->computed_grade, 2) === round((float) $result['computed_grade'], 2);

            // Say WHICH side moved: the evidence (computed grade differs from
            // the one stored at verification) or the official value itself
            // (same evidence, but the grade was typed/imported over).
            $evidenceChanged = $official->computed_grade === null
                || round((float) $official->computed_grade, 2) !== round((float) $result['computed_grade'], 2);
            $reason = $same ? null : ($evidenceChanged
                ? 'The assessment evidence changed after this grade was verified.'
                : 'The official value was changed after verification (typed or imported on Encode Grades); the evidence still gives '
                    . number_format((float) $result['transmuted_grade'], 2) . '.');

            return ['verify_state' => $same ? 'verified' : 'verified_changed', 'bulk_eligible' => false, 'verify_reason' => $reason];
        }
        if ($result['transmutation_provisional']) {
            return ['verify_state' => 'provisional', 'bulk_eligible' => false,
                'verify_reason' => 'Only a provisional transmuted grade is available (fallback table), so it is left out of the bulk action.'];
        }

        return ['verify_state' => 'eligible', 'bulk_eligible' => true, 'verify_reason' => null];
    }

    private function incompleteReason(array $result, array $required, array $examRoles, int $studentId): string
    {
        if (!empty($result['blockers'])) {
            return 'Not eligible — ' . implode(' ', $result['blockers']);
        }
        $missing = [];
        foreach ($required as $key) {
            $hasPercentage = ($result['components'][$key]['percentage'] ?? null) !== null;
            $unscored = $key === 'examination' && $examRoles !== []
                ? array_keys(array_filter($examRoles, fn($scored) => !isset($scored[$studentId])))
                : [];
            // The workbook computes an Examination PS from any scored type;
            // verification still needs a score in every type that was given.
            if ($hasPercentage && $unscored === []) {
                continue;
            }
            $label = self::COMPONENT_LABELS[$key];
            if ($unscored !== [] && ($hasPercentage || count($unscored) < count($examRoles))) {
                $label .= ' (no score yet for ' . implode(', ', array_map(fn($r) => ExaminationRoles::LABELS[$r] ?? $r, $unscored)) . ')';
            }
            $missing[] = $label;
        }

        return $missing === []
            ? 'Assessment evidence is not complete yet.'
            : 'Not eligible — no computable result yet for ' . implode(', ', $missing) . '.';
    }

    /** @return list<string> component keys this subject's grading profile weights. */
    private function requiredComponents(Section $section, Subject $subject): array
    {
        $profile = (new GradingEngine())->resolveWeightProfile($section, $subject);

        return array_keys(array_filter([
            'written_work'     => $profile['ww_weight'] ?? null,
            'performance_task' => $profile['pt_weight'] ?? null,
            'examination'      => $profile['ex_weight'] ?? null,
        ], fn($w) => $w !== null));
    }

    /** @return array<string, array<int, true>> exam role => learners with a score in any item of that role. */
    private function examRoleEvidence(Section $section, Subject $subject, int $term): array
    {
        $items = Assessment::where('section_id', $section->id)->where('subject_id', $subject->id)
            ->where('grading_period', $term)->where('school_year', $section->school_year)
            ->where('component', 'examination')->whereNotNull('exam_role')->get(['id', 'exam_role']);
        if ($items->isEmpty()) {
            return [];
        }
        $scored = AssessmentScore::whereIn('assessment_id', $items->pluck('id'))->get(['assessment_id', 'student_id'])
            ->groupBy('assessment_id');

        $byRole = [];
        foreach ($items as $item) {
            $byRole[$item->exam_role] ??= [];
            foreach ($scored->get($item->id, collect()) as $s) {
                $byRole[$item->exam_role][$s->student_id] = true;
            }
        }

        return $byRole;
    }
}
