<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\DepedSubjectCatalog;
use App\Models\ExamRoleShare;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\Track;
use Illuminate\Support\Facades\Log;

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

    /**
     * "Performance audit" pass — EVIDENCE CACHE.
     *
     * computeGrade() used to run ~7 queries per call (assessment ids per
     * component, this student's scores per component, the weight row, the
     * exam-role shares, the transmutation band), and every dashboard calls
     * it once per (student, subject, term): the Principal dashboard alone
     * made 1,076 calls and 7,715 queries per page load. The arithmetic
     * below is byte-for-byte what it was; only WHERE the rows come from
     * changed. Each (section, term, school year) scope is now read ONCE —
     * every assessment item in it and every score against those items —
     * and each call filters that in memory using the exact same
     * predicates the per-call queries used.
     *
     * Staleness is guarded by a process-wide GENERATION counter: every
     * Eloquent save/delete on the evidence and reference tables bumps it
     * (see AppServiceProvider::boot()), and a cache entry built under an
     * older generation is discarded on its next read. The one non-Eloquent
     * write in the app (a query-builder delete in
     * Adviser\AssessmentController::updateItem()) calls invalidateEvidence()
     * itself. A new engine instance always starts empty, so nothing here
     * crosses requests — the cache lives on the instance, only the
     * generation is static.
     *
     * The numeric values are cast ONCE when the evidence is loaded: every
     * (float) $score->score / (float) $item->max_score the arithmetic
     * used to do per read goes through Eloquent's decimal:2 cast, which
     * costs ~7us each — thousands of times per dashboard. The same cast
     * produces the same float; it just runs once per row now.
     *
     * @var array<string, array{
     *     generation: int,
     *     by_subject_component: array<int, array<string, array<int, Assessment>>>,
     *     max: array<int, float>,
     *     roles: array<int, ?string>,
     *     scores: array<int, array<int, AssessmentScore>>,
     *     values: array<int, array<int, float>>,
     * }>
     */
    private array $evidence = [];

    /** @var array<int|string, mixed> per-instance memo of reference rows (catalog, weights, exam-role shares), generation-checked */
    private array $referenceMemo = [];
    private int $referenceGeneration = -1;

    private static int $generation = 0;

    /**
     * Marks every evidence/reference cache stale. Called automatically from
     * model events (AppServiceProvider); call it by hand after any write to
     * assessments / assessment_scores / the grading reference tables that
     * bypasses Eloquent model events (query-builder update/delete/insert).
     */
    public static function invalidateEvidence(): void
    {
        self::$generation++;
    }

    public static function evidenceGeneration(): int
    {
        return self::$generation;
    }

    public function __construct(private TransmutationService $transmutation = new TransmutationService())
    {
    }

    /**
     * How many assessment ITEMS this student has a recorded score for in
     * this subject/section/term/year — any component. The same count
     * InTermStatusService::fromAnalysis() used to run as its own query
     * per row (AssessmentScore where student_id ... whereHas assessment),
     * now answered from the evidence already loaded for the grade.
     */
    public function scoredItemCount(Student $student, Subject $subject, Section $section, int $gradingPeriod, string $schoolYear): int
    {
        $evidence = $this->evidenceFor($section, $gradingPeriod, $schoolYear);
        $studentScores = $evidence['scores'][$student->id] ?? [];

        if ($studentScores === []) {
            return 0;
        }

        $count = 0;
        foreach ($evidence['by_subject_component'][$subject->id] ?? [] as $items) {
            foreach ($items as $assessmentId => $item) {
                if (isset($studentScores[$assessmentId])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * This student's scored items for ONE component of one subject/
     * section/term/year, in assessment-id order, optionally only the
     * scores recorded on or before $asOf (assessment_scores.created_at —
     * when the score was entered, not when the item was defined). The
     * rows ProgressMonitoringService::componentPercentageAsOf() used to
     * query per call, now read from the evidence already loaded.
     *
     * The cutoff is compared on the same 'Y-m-d H:i:s' representation the
     * query binding used, so a score with no created_at is excluded
     * exactly as SQL's `NULL <= x` excluded it.
     *
     * @return array<int, array{score: AssessmentScore, assessment: Assessment}>
     */
    public function scoredItems(Student $student, Subject $subject, Section $section, int $gradingPeriod, string $schoolYear, string $componentKey, ?\DateTimeInterface $asOf = null): array
    {
        $evidence = $this->evidenceFor($section, $gradingPeriod, $schoolYear);
        $items = $evidence['by_subject_component'][$subject->id][$componentKey] ?? [];
        $studentScores = $evidence['scores'][$student->id] ?? [];
        $cutoff = $asOf?->format('Y-m-d H:i:s');

        $rows = [];
        foreach ($items as $assessmentId => $item) {
            $score = $studentScores[$assessmentId] ?? null;
            if ($score === null) {
                continue;
            }
            if ($cutoff !== null && ($score->created_at === null || $score->created_at->format('Y-m-d H:i:s') > $cutoff)) {
                continue;
            }
            $rows[] = ['score' => $score, 'assessment' => $item];
        }

        return $rows;
    }

    /**
     * Loads (once per section/term/year per instance) every assessment
     * item in scope and every score against those items. Two queries,
     * regardless of how many students or subjects are then computed.
     *
     * @return array{generation: int, by_subject_component: array, scores: array}
     */
    private function evidenceFor(Section $section, int $gradingPeriod, string $schoolYear): array
    {
        $key = $section->id . '|' . $gradingPeriod . '|' . $schoolYear;

        if (isset($this->evidence[$key]) && $this->evidence[$key]['generation'] === self::$generation) {
            return $this->evidence[$key];
        }

        $generation = self::$generation;

        $items = Assessment::where('section_id', $section->id)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $schoolYear)
            ->orderBy('id')
            ->get();

        $bySubjectComponent = [];
        $max = [];
        $roles = [];
        foreach ($items as $item) {
            $bySubjectComponent[$item->subject_id][$item->component][$item->id] = $item;
            $max[$item->id] = (float) $item->max_score;
            $roles[$item->id] = $item->exam_role;
        }

        $scores = [];
        $values = [];
        if ($items->isNotEmpty()) {
            // No ORDER BY on purpose: every read below iterates the ITEMS
            // (loaded in id order) and looks scores up by key, so row order
            // here is irrelevant — and without it MySQL uses the
            // (assessment_id, student_id) unique index instead of walking
            // the primary key.
            $rows = AssessmentScore::whereIn('assessment_id', $items->pluck('id'))
                ->get();
            foreach ($rows as $row) {
                $scores[$row->student_id][$row->assessment_id] = $row;
                $values[$row->student_id][$row->assessment_id] = (float) $row->score;
            }
        }

        return $this->evidence[$key] = [
            'generation'           => $generation,
            'by_subject_component' => $bySubjectComponent,
            'max'                  => $max,
            'roles'                => $roles,
            'scores'               => $scores,
            'values'               => $values,
        ];
    }

    /** Generation-checked per-instance memo for reference rows that never change within a request. */
    private function memo(string $key, callable $load): mixed
    {
        if ($this->referenceGeneration !== self::$generation) {
            $this->referenceMemo = [];
            $this->referenceGeneration = self::$generation;
        }

        if (!array_key_exists($key, $this->referenceMemo)) {
            $this->referenceMemo[$key] = $load();
        }

        return $this->referenceMemo[$key];
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
        // Which published table applies is decided by the SECTION's
        // curriculum when it's set ("ECR alignment" work order, PART 3a),
        // falling back to the grade-level/year inference otherwise — see
        // TransmutationService::schemeFor(). The SAME scheme also picks
        // which subject_group_weights row applies below — one scheme
        // concept, resolved once, driving both.
        $scheme = $this->transmutation->schemeFor($section->grade_level, $schoolYear, $section->curriculum);

        // "ECR alignment" work order, PART 2 — resolution order is: a
        // linked catalog row (the official per-subject DepEd weights) if
        // this subject actually has one, THEN the existing subject_group_
        // weights lookup exactly as before. A subject with no catalog_id
        // (every subject that predates this, or one genuinely outside the
        // Strengthened SHS catalog — see CLAUDE.md's "rule on conflicting
        // evidence") sees no behavior change at all.
        $profile  = $this->resolveWeightProfile($section, $subject, $scheme);
        $catalog  = $profile['catalog'];
        $wwWeight = $profile['ww_weight'];
        $ptWeight = $profile['pt_weight'];
        $exWeight = $profile['ex_weight'];

        $componentPercentages = [
            'written_work'     => $this->componentPercentage($student, $subject, $section, $gradingPeriod, $schoolYear, 'written_work'),
            'performance_task' => $this->componentPercentage($student, $subject, $section, $gradingPeriod, $schoolYear, 'performance_task'),
            'examination'      => $exWeight !== null
                ? $this->examinationPercentage($student, $subject, $section, $gradingPeriod, $schoolYear, $scheme, $catalog)
                : null,
        ];

        // A component this subject's group was never supposed to have
        // (ex_weight null) is not "required" — only components the
        // group's weights actually name must have evidence for the
        // grade to be complete.
        $weightMap = [
            'written_work'     => $wwWeight,
            'performance_task' => $ptWeight,
            'examination'      => $exWeight,
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
        // Same predicates as the per-call query this replaced (subject,
        // section, period, year, component) — read from the evidence
        // loaded once for this section/term, see evidenceFor().
        $evidence = $this->evidenceFor($section, $gradingPeriod, $schoolYear);
        $items = $evidence['by_subject_component'][$subject->id][$componentKey] ?? [];

        if ($items === []) {
            return null;
        }

        $studentValues = $evidence['values'][$student->id] ?? [];
        $maxByItem = $evidence['max'];

        $earned = 0.0;
        $max    = 0.0;
        $any    = false;
        foreach ($items as $assessmentId => $item) {
            if (!isset($studentValues[$assessmentId])) {
                continue;
            }
            $any = true;
            $earned += $studentValues[$assessmentId];
            $max    += $maxByItem[$assessmentId];
        }

        if (!$any) {
            return null;
        }

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
        string $scheme,
        ?DepedSubjectCatalog $catalog = null
    ): ?float {
        $evidence = $this->evidenceFor($section, $gradingPeriod, $schoolYear);
        // Item ids in id order; the student's pre-cast score per item id.
        $itemIds = array_keys($evidence['by_subject_component'][$subject->id]['examination'] ?? []);

        if ($itemIds === []) {
            return null;
        }

        $studentValues = $evidence['values'][$student->id] ?? [];
        $maxByItem = $evidence['max'];
        $roleByItem = $evidence['roles'];

        $scoredItemIds = array_values(array_filter($itemIds, fn($id) => isset($studentValues[$id])));

        if ($scoredItemIds === []) {
            return null;
        }

        $hasAnyRole = false;
        foreach ($itemIds as $id) {
            if ($roleByItem[$id] !== null) {
                $hasAnyRole = true;
                break;
            }
        }

        if (!$hasAnyRole) {
            $earned = 0.0;
            $max    = 0.0;
            foreach ($scoredItemIds as $id) {
                $earned += $studentValues[$id];
                $max    += $maxByItem[$id];
            }

            return $max > 0 ? round(($earned / $max) * 100, 2) : null;
        }

        // role => [item ids], items with no role excluded, id order kept.
        $itemIdsByRole = [];
        foreach ($itemIds as $id) {
            if ($roleByItem[$id] !== null) {
                $itemIdsByRole[$roleByItem[$id]][] = $id;
            }
        }

        // "ECR alignment" work order, PART 2c — a linked catalog row's own
        // st1/st2/te shares win when it has any (including a TE-only row,
        // where st1/st2 are simply absent from the array and fall through
        // to the equal-split fallback below exactly like an unknown role
        // always has). Only when there is no catalog row, or its shares are
        // entirely null, does this fall back to the scheme-wide table.
        $catalogShares = $catalog?->examRoleShares() ?? [];
        $shareRows = !empty($catalogShares)
            ? $catalogShares
            : $this->memo('exam_role_shares|' . $scheme, fn() => ExamRoleShare::where('scheme', $scheme)->pluck('share', 'exam_role'));

        $presentRoles = array_values(array_intersect(self::EXAM_ROLES, array_keys($itemIdsByRole)));
        $equalShare = 100 / count($presentRoles);

        $weightedSum = 0.0;
        $totalShare = 0.0;

        foreach ($presentRoles as $role) {
            $earned = 0.0;
            $max = 0.0;
            foreach ($itemIdsByRole[$role] as $itemId) {
                if (isset($studentValues[$itemId])) {
                    $earned += $studentValues[$itemId];
                    $max += $maxByItem[$itemId];
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

    /**
     * "ECR alignment" work order, PART 2d — DO 8, s. 2015 weights by TRACK,
     * unlike DO 015's subject_group axis, so it needs its own resolution
     * path rather than reading $subject->subject_group directly. The
     * mapping, written down in full per the work order's own requirement:
     *
     *  1. Section's track code ACAD -> the Academic branch; TECHPRO or TVL
     *     -> the non-Academic (TVL/Sports/Arts and Design) branch — DO 8's
     *     table has no separate Tech-Pro row, it groups TVL, Sports, and
     *     Arts and Design into one weighting bucket, and this codebase's
     *     "TechPro Track" is DO 015-era vocabulary for what DO 8 calls TVL.
     *  2. Within a branch: a core subject (type 'core') always gets that
     *     branch's *_core slug — but only the Academic branch HAS one; DO 8's
     *     table defines Core Subjects once, not per track, so a hypothetical
     *     non-Academic core subject falls through to that branch's *_other
     *     slug instead.
     *  3. An elective subject is checked by name against a short, explicit
     *     keyword list for "Work Immersion / Research / Business Enterprise
     *     Simulation" (Academic) or "Work Immersion / Research / Exhibit /
     *     Performance" (non-Academic). A match routes to that branch's
     *     *_work_immersion slug and is logged (subject id, name, matched
     *     keyword) — see CheckIntegrityCommand's DO 8 listing, which surfaces
     *     every such match for a human to confirm rather than trusting the
     *     name silently. No match -> that branch's *_other slug.
     *
     * Name-based matching is a stopgap: no equivalent DO 8/2013-curriculum
     * catalog exists (unlike DO 015's 141-row extraction), and zero Grade 12
     * subjects exist in this database as of this work order (Part 7 is
     * blocked on the school's answer to Q2) — it is deliberately built to
     * look like a stopgap rather than a silent decision.
     */
    /**
     * The weight profile computeGrade() grades a (section, subject) pair
     * under — extracted so Admin > Sections > Subjects can DISPLAY the
     * resolved profile on the assignment form without duplicating the
     * resolution order (catalog row first, then subject_group_weights —
     * see computeGrade()). Display only: nothing an Admin types can
     * change these figures, which is the whole point of showing them
     * read-only ("Student identity and term-specific subject offerings"
     * pass, STEP F).
     *
     * @return array{
     *     scheme: string,
     *     source: string,
     *     source_label: string,
     *     group_key: ?string,
     *     ww_weight: float,
     *     pt_weight: float,
     *     ex_weight: ?float,
     *     catalog: ?DepedSubjectCatalog,
     * }
     */
    public function resolveWeightProfile(Section $section, Subject $subject, ?string $scheme = null): array
    {
        $scheme ??= $this->transmutation->schemeFor($section->grade_level, $section->school_year, $section->curriculum);

        $catalog = $subject->catalog_id
            ? $this->memo('catalog|' . $subject->catalog_id, fn() => DepedSubjectCatalog::find($subject->catalog_id))
            : null;

        if ($catalog) {
            return [
                'scheme'       => $scheme,
                'source'       => 'catalog',
                'source_label' => 'DepEd SSHS subject catalog (' . $catalog->cluster . ')',
                'group_key'    => null,
                'ww_weight'    => (float) $catalog->ww_weight,
                'pt_weight'    => (float) $catalog->pt_weight,
                'ex_weight'    => $catalog->ex_weight !== null ? (float) $catalog->ex_weight : null,
                'catalog'      => $catalog,
            ];
        }

        $groupKey = $scheme === 'do8_2015'
            ? $this->resolveDo8GroupKey($section, $subject)
            : $subject->subject_group;
        // A missing row throws (see SubjectGroupWeight::resolve()); only a
        // found row is memoised, so the exception behaviour is unchanged.
        $weights = $this->memo('weights|' . $scheme . '|' . ($groupKey ?? ''), fn() => SubjectGroupWeight::resolve($scheme, $groupKey));

        return [
            'scheme'       => $scheme,
            'source'       => 'subject_group',
            'source_label' => ($scheme === 'do8_2015' ? 'DO 8, s. 2015 — ' : 'DO 015, s. 2026 — ') . $weights->subject_group,
            'group_key'    => $weights->subject_group,
            'ww_weight'    => (float) $weights->ww_weight,
            'pt_weight'    => (float) $weights->pt_weight,
            'ex_weight'    => $weights->ex_weight !== null ? (float) $weights->ex_weight : null,
            'catalog'      => null,
        ];
    }

    /**
     * The profile a SUBJECT grades under, asked without a section — for
     * Admin > Subjects, which lists subjects, not (section, subject) pairs
     * ("Grading policy display" pass, 2026-09-20).
     *
     * This is NOT a second resolver. It calls resolveWeightProfile() — the
     * one computeGrade() uses — once per section context the subject could
     * be graded in, and reports whether every context agrees:
     *
     *  - The scheme comes from the section's curriculum (explicit, or the
     *    school-year inference in TransmutationService::schemeFor()); the
     *    curricula in use at this grade level are each a context.
     *  - DO 015 never reads the section beyond its scheme: one context.
     *  - DO 8 (an explicit k12_2013 section) reads the SECTION's track
     *    (resolveDo8GroupKey()).
     *    An elective carries its own track_id and only ever applies to
     *    sections of that track (SubjectApplicabilityService's strand
     *    mechanism), so that track is the only context. A core subject has
     *    no track, so every track in the system is a context; if they all
     *    resolve to the same figures the answer is settled, otherwise the
     *    honest answer is "resolved by section context" and the caller
     *    shows the per-track variants rather than picking one.
     *
     * The pseudo-sections are never saved. The scheme is inferred exactly
     * as it is for a real section with no explicit curriculum
     * (TransmutationService::schemeFor()).
     *
     * @return array{
     *     resolved: bool,
     *     profile: ?array,
     *     variants: array<int, array{track: ?string, curriculum: ?string, profile: array}>,
     * }
     */
    public function resolveSubjectProfile(Subject $subject, ?string $schoolYear = null): array
    {
        $schoolYear ??= Section::activeSchoolYear();

        $tracks = $subject->track_id
            ? collect([$subject->track ?? Track::find($subject->track_id)])->filter()
            : Track::orderBy('code')->get();

        // Curriculum contexts: the DISTINCT curricula of the sections that
        // actually exist at this grade level in this school year (null =
        // "inferred by TransmutationService::schemeFor()"), or just the
        // inferred one when no section exists yet. A legacy k12_2013 section
        // therefore surfaces as a second variant only when one is real.
        $curricula = Section::where('grade_level', (int) $subject->grade_level)
            ->where('school_year', $schoolYear)
            ->pluck('curriculum')->map(fn($c) => $c ?: null)->unique()->values();
        if ($curricula->isEmpty()) {
            $curricula = collect([null]);
        }

        $trackContexts = $tracks->isEmpty() ? collect([null]) : $tracks;

        $variants = collect();
        foreach ($curricula as $curriculum) {
            foreach ($trackContexts as $track) {
                $section = new Section([
                    'grade_level' => (int) $subject->grade_level,
                    'school_year' => $schoolYear,
                    'track_id'    => $track?->id,
                    'curriculum'  => $curriculum,
                ]);
                if ($track) {
                    $section->setRelation('track', $track);
                }

                $variants->push([
                    'track'      => $track?->code,
                    'curriculum' => $curriculum,
                    'profile'    => $this->resolveWeightProfile($section, $subject),
                ]);
            }
        }
        $variants = $variants->values();

        $signature = fn(array $p) => $p['ww_weight'] . '|' . $p['pt_weight'] . '|' . ($p['ex_weight'] ?? 'null') . '|' . ($p['group_key'] ?? '');
        $agree = $variants->map(fn($v) => $signature($v['profile']))->unique()->count() === 1;

        return [
            'resolved' => $agree,
            'profile'  => $agree ? $variants->first()['profile'] : null,
            'variants' => $variants->all(),
        ];
    }

    public function resolveDo8GroupKey(Section $section, Subject $subject): string
    {
        $trackCode = $section->track?->code;

        // No track recorded on the section at all is NOT the same as "not
        // Academic" — every pre-existing Grade 12 subject/test relied on
        // the scheme's universal 'all' fallback (25/50/25) precisely
        // because nothing here used to read track. Guessing non-Academic
        // for an unset track would silently mis-weight every such subject
        // at 20/60/20 instead. Only an ACTUAL track answers this question.
        if ($trackCode === null) {
            return 'all';
        }

        $isAcademic = $trackCode === 'ACAD';
        $branch = $isAcademic ? 'do8_academic' : 'do8_tvl_sports_arts';

        if ($isAcademic && $subject->type === 'core') {
            return 'do8_core';
        }

        $keywords = $isAcademic
            ? ['work immersion', 'research', 'business enterprise simulation']
            : ['work immersion', 'research', 'exhibit', 'performance'];

        $name = strtolower($subject->name);
        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword)) {
                Log::info('GradingEngine::resolveDo8GroupKey routed by name keyword match — review, not an error', [
                    'subject_id'      => $subject->id,
                    'subject_name'    => $subject->name,
                    'matched_keyword' => $keyword,
                    'slug'            => $branch . '_work_immersion',
                ]);

                return $branch . '_work_immersion';
            }
        }

        return $branch . '_other';
    }
}
