<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\ExamRoleShare;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;

/**
 * Centralized Written Work / Performance Task / Examination grading
 * calculation — the ONLY place this math should ever live, per
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
 *   SubjectGroupWeight, keyed by scheme + the subject's subject_group —
 *   see that model's resolve(). NEVER a single system-wide flat default
 *   any more: DO 015, s. 2026 assigns different weights to 6 different
 *   SHS subject groups, and 2 of them have NO Examination component at
 *   all — see ex_weight's nullability).
 *
 * Final computed grade = sum of every APPLICABLE component's
 * contribution (2 components for a subject group with no Examination,
 * 3 otherwise).
 *
 * A component with NO assessment items at all for this subject/section/
 * term, or with items but no scores yet for this particular student, is
 * "missing" — the overall grade is reported incomplete (null) rather
 * than silently computed from fewer components than the subject's group
 * actually requires. A component the subject's group was never supposed
 * to have (ex_weight null) is NOT "missing" — it's not applicable, and
 * never blocks completeness. Do not hard-code any example numbers here
 * — see GradingEngineTest for the worked examples this is verified
 * against.
 *
 * computed_grade is the raw weighted percentage above — evidence, and
 * what the component analysis / risk classifier read. It is NOT what
 * DepEd expects on a report card; that's transmuted_grade, produced by
 * TransmutationService and carried alongside computed_grade, never
 * replacing it.
 */
class GradingEngine
{
    private const COMPONENTS = ['written_work', 'performance_task', 'examination'];

    /** The 3 roles the Examination component can split into under a scheme that defines exam_role_shares — see ExamRoleShare. */
    private const EXAM_ROLES = ['st1', 'st2', 'term_exam'];

    public function __construct(private TransmutationService $transmutation = new TransmutationService())
    {
    }

    /**
     * @return array{
     *     complete: bool,
     *     components: array<string, float|null>,
     *     contributions: array<string, float>,
     *     computed_grade: float|null,
     *     transmuted_grade: float|null,
     *     transmutation_scheme: string,
     *     transmutation_available: bool,
     *     transmutation_provisional: bool,
     *     transmutation_fallback_scheme: string|null,
     * }
     */
    public function computeGrade(Student $student, Subject $subject, Section $section, int $gradingPeriod, string $schoolYear): array
    {
        // Which published table applies depends on the SECTION's grade
        // level and school year, not a fixed default — see
        // TransmutationService::schemeFor(). The SAME scheme also picks
        // which subject_group_weights row applies below — one scheme
        // concept, resolved once, driving both.
        $scheme = $this->transmutation->schemeFor($section->grade_level, $schoolYear);
        $weights = SubjectGroupWeight::resolve($scheme, $subject->subject_group);

        $componentPercentages = [
            'written_work'     => $this->componentPercentage($student, $subject, $section, $gradingPeriod, $schoolYear, 'written_work'),
            'performance_task' => $this->componentPercentage($student, $subject, $section, $gradingPeriod, $schoolYear, 'performance_task'),
            'examination'      => $weights->ex_weight !== null
                ? $this->examinationPercentage($student, $subject, $section, $gradingPeriod, $schoolYear, $scheme)
                : null,
        ];

        // A component this subject's group was never supposed to have
        // (ex_weight null) is not "required" — only components the
        // group's weights actually name must have evidence for the
        // grade to be complete.
        $weightMap = [
            'written_work'     => (float) $weights->ww_weight,
            'performance_task' => (float) $weights->pt_weight,
            'examination'      => $weights->ex_weight !== null ? (float) $weights->ex_weight : null,
        ];
        $requiredKeys = array_keys(array_filter($weightMap, fn($w) => $w !== null));

        $isComplete = !in_array(null, array_intersect_key($componentPercentages, array_flip($requiredKeys)), true);

        if (!$isComplete) {
            return [
                'complete'                       => false,
                'components'                     => $componentPercentages,
                'contributions'                  => [],
                'computed_grade'                 => null,
                'transmuted_grade'               => null,
                'transmutation_scheme'           => $scheme,
                'transmutation_available'        => false,
                'transmutation_provisional'      => false,
                'transmutation_fallback_scheme'  => null,
            ];
        }

        $contributions = [];
        $total = 0.0;

        foreach ($requiredKeys as $key) {
            $contribution = round($componentPercentages[$key] * $weightMap[$key] / 100, 2);
            $contributions[$key] = $contribution;
            $total += $contribution;
        }

        $computedGrade = round($total, 2);

        // An incomplete scheme (e.g. do015_2026 — see
        // TransmutationRangesSeeder's TODO) must never silently fall back
        // to a different scheme's number; the caller gets no transmuted
        // grade at all, plus the flag to show an explicit note, rather
        // than a value that looks real but isn't.
        $transmutation = $this->transmutation->transmuteWithAvailability($computedGrade, $scheme);

        return [
            'complete'                      => true,
            'components'                    => $componentPercentages,
            'contributions'                 => $contributions,
            'computed_grade'                => $computedGrade,
            'transmuted_grade'              => $transmutation['available'] ? round($transmutation['value'], 2) : null,
            'transmutation_scheme'          => $scheme,
            'transmutation_available'       => $transmutation['available'],
            // TASK 1 of "unblock verification" — true only when the scheme
            // actually used (transmutation_fallback_scheme) differs from
            // the subject's real scheme above; see TransmutationService::
            // resolve() and config('dss.transmutation_fallback_scheme').
            'transmutation_provisional'     => $transmutation['provisional'],
            'transmutation_fallback_scheme' => $transmutation['provisional'] ? $transmutation['scheme_used'] : null,
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

    /**
     * The Examination component's percentage — same "missing means
     * incomplete" contract as componentPercentage() above, but role-aware
     * (see ExamRoleShare and the assessments.exam_role column):
     *
     *  - No Examination items at all -> null (missing, same as before).
     *  - Items exist but NONE has a role set -> the legacy flat
     *    aggregate (sum earned / sum max across every item), exactly as
     *    if roles didn't exist — every pre-existing subject keeps
     *    computing exactly as it always has.
     *  - At least one item has a role -> group by role (an item with no
     *    role in this case is excluded from the calculation below — a
     *    documented edge case, not expected in practice: a subject
     *    either adopts roles for all its Examination items or none).
     *    Each role's percentage is earned/max summed across every item
     *    under that role (so two items claiming the same role combine
     *    naturally, "equally," exactly like multiple items in any other
     *    component — see AssessmentController's duplicate-role warning
     *    for surfacing that to the adviser). A role with an item that
     *    exists but this student has no score for yet makes the whole
     *    component missing for this student (same "items exist, no
     *    score yet" rule as any other component) — a role with NO ITEM
     *    AT ALL yet (the exam hasn't happened this term) is different:
     *    it's simply excluded and the remaining roles' shares are
     *    renormalised to sum to 100, so a term in progress never reads
     *    as though the student scored zero on an exam that hasn't
     *    happened. A role missing from ExamRoleShare for this scheme
     *    falls back to an equal split among the roles actually present.
     */
    private function examinationPercentage(
        Student $student,
        Subject $subject,
        Section $section,
        int $gradingPeriod,
        string $schoolYear,
        string $scheme
    ): ?float {
        $items = Assessment::where('subject_id', $subject->id)
            ->where('section_id', $section->id)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $schoolYear)
            ->where('component', 'examination')
            ->get(['id', 'exam_role', 'max_score']);

        if ($items->isEmpty()) {
            return null;
        }

        $scores = AssessmentScore::where('student_id', $student->id)
            ->whereIn('assessment_id', $items->pluck('id'))
            ->get();

        if ($scores->isEmpty()) {
            return null;
        }

        $hasAnyRole = $items->contains(fn($item) => $item->exam_role !== null);

        if (!$hasAnyRole) {
            $itemsById = $items->keyBy('id');
            $earned = $scores->sum(fn($s) => (float) $s->score);
            $max    = $scores->sum(fn($s) => (float) $itemsById[$s->assessment_id]->max_score);

            return $max > 0 ? round(($earned / $max) * 100, 2) : null;
        }

        $itemsByRole = $items->filter(fn($item) => $item->exam_role !== null)->groupBy('exam_role');
        $scoresByAssessmentId = $scores->keyBy('assessment_id');
        $shareRows = ExamRoleShare::where('scheme', $scheme)->pluck('share', 'exam_role');

        $presentRoles = array_values(array_intersect(self::EXAM_ROLES, $itemsByRole->keys()->all()));
        $equalShare = 100 / count($presentRoles);

        $weightedSum = 0.0;
        $totalShare = 0.0;

        foreach ($presentRoles as $role) {
            $roleItemIds = $itemsByRole[$role]->pluck('id');
            $roleItemsById = $itemsByRole[$role]->keyBy('id');

            $earned = 0.0;
            $max = 0.0;
            foreach ($roleItemIds as $itemId) {
                $score = $scoresByAssessmentId->get($itemId);
                if ($score) {
                    $earned += (float) $score->score;
                    $max += (float) $roleItemsById[$itemId]->max_score;
                }
            }

            if ($max <= 0) {
                // This role's exam exists but the student has no score
                // for it yet — missing for this student, not renormalised
                // away (that's only for a role with no item at all).
                return null;
            }

            $rolePercentage = ($earned / $max) * 100;
            $share = (float) ($shareRows[$role] ?? $equalShare);

            $weightedSum += $rolePercentage * $share;
            $totalShare += $share;
        }

        return $totalShare > 0 ? round($weightedSum / $totalShare, 2) : null;
    }
}
