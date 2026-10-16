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
 * WORKBOOK PARITY (D1–D3, implementation decision 2026-10-04 — a project
 * requirement, not a recorded school approval): every figure follows the
 * client's SSHS E-Class Record, sheet "Term 1" row 17 (N/O/P, AA/AB/AC,
 * AG:AI, AJ, AK, AL) — see workbookComponent() / workbookExamination() and
 * tests/Feature/WorkbookParityTest (compared with the workbook's own
 * formulas). On top of the workbook, a RUNNING grade is shown while
 * evidence is partial (display only), and verification keeps requiring a
 * score in every Examination Type that was given.
 *
 * Per component:
 *   percentage = earned score / HPS of every item in the component x 100
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

    /** @var array<string, array<int, array<int, ?float>>> preview-only score overrides per scope key — see overlayScores() */
    private array $overlays = [];

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
        // The workbook's ST 1 / ST 2 / TE columns per subject (one item each).
        $examColumns = [];
        foreach ($bySubjectComponent as $subjectId => $components) {
            $examColumns[$subjectId] = ExaminationRoles::workbookColumns($components['examination'] ?? []);
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

        foreach ($this->overlays[$key] ?? [] as $assessmentId => $byStudent) {
            if (!isset($max[$assessmentId])) {
                continue;
            }
            foreach ($byStudent as $studentId => $value) {
                if ($value === null) {
                    unset($scores[$studentId][$assessmentId], $values[$studentId][$assessmentId]);
                    continue;
                }
                // An unsaved, in-memory row: never persisted (preview only).
                $scores[$studentId][$assessmentId] = (new AssessmentScore())->forceFill([
                    'assessment_id' => $assessmentId, 'student_id' => $studentId, 'score' => $value,
                ]);
                $values[$studentId][$assessmentId] = (float) $value;
            }
        }

        return $this->evidence[$key] = [
            'generation'           => $generation,
            'by_subject_component' => $bySubjectComponent,
            'max'                  => $max,
            'roles'                => $roles,
            'exam_columns'         => $examColumns,
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

        $evidence = $this->evidenceFor($section, $gradingPeriod, $schoolYear);
        $weightMap = [
            'written_work'     => $wwWeight,
            'performance_task' => $ptWeight,
            'examination'      => $exWeight,
        ];

        // A profile that cannot be graded (e.g. a teacher-supplied catalog row
        // with no configured weights) yields NO figure at all — never a silent
        // fallback to another profile, never a 0 weight.
        if ($profile['configuration_error'] !== null) {
            return [
                'components'                    => array_fill_keys(self::COMPONENTS, null),
                'component_detail'              => array_fill_keys(self::COMPONENTS, self::NO_EXAMINATION),
                'partial_contributions'         => [],
                'running_grade'                 => null,
                'workbook_complete'             => false,
                'exam_roles'                    => [],
                'blockers'                      => [$profile['configuration_error']],
                'transmutation_scheme'          => $scheme,
                'complete'                      => false,
                'contributions'                 => [],
                'computed_grade'                => null,
                'transmuted_grade'              => null,
                'transmutation_available'       => false,
                'transmutation_provisional'     => false,
                'transmutation_fallback_scheme' => null,
            ];
        }

        $detail = [
            'written_work'     => $this->workbookComponent($evidence, $student, $subject, 'written_work', $wwWeight),
            'performance_task' => $this->workbookComponent($evidence, $student, $subject, 'performance_task', $ptWeight),
            'examination'      => $exWeight !== null
                ? $this->workbookExamination($evidence, $student, $subject, $scheme, $catalog, $exWeight)
                : self::NO_EXAMINATION,
        ];
        $componentPercentages = array_map(fn($d) => $d['percentage'], $detail);

        // A component this subject's group was never supposed to have
        // (weight null) is not required — workbook AL17 sums P17+AC17 alone
        // when $AD$12 = 0.
        $requiredKeys = array_keys(array_filter($weightMap, fn($w) => $w !== null));

        // Each WS exactly as the workbook rounds it (P17, AC17, AK17).
        $partialContributions = [];
        $runningCents = 0;
        foreach ($requiredKeys as $key) {
            if ($detail[$key]['ws'] !== null) {
                $partialContributions[$key] = $detail[$key]['ws'];
                $runningCents += self::cents($detail[$key]['ws']);
            }
        }
        // RUNNING grade (Class Record display only): the WS present so far,
        // added in exact hundredths — weights NOT renormalised, so a partial
        // term reads partial. With every required WS present it is the
        // workbook's AL17 = SUM(P17, AC17, AK17) of 2-dp values, exactly.
        $runningGrade = $partialContributions === [] ? null : $runningCents / 100.0;

        // Two separate questions:
        //  - workbook_complete: AL17 would be filled (every required WS exists);
        //  - complete: the grade may become computed_grade and be verified.
        //    On top of the above, every Examination Type that HAS an item in
        //    the formula needs a numeric score — the pre-existing verification
        //    requirement, kept: a computable workbook number never relaxes it.
        $workbookComplete = count($partialContributions) === count($requiredKeys);
        $isComplete = $workbookComplete && $detail['examination']['unscored_roles'] === [];

        $base = [
            'components'            => $componentPercentages,
            'component_detail'      => $detail,
            'partial_contributions' => $partialContributions,
            'running_grade'         => $runningGrade,
            'workbook_complete'     => $workbookComplete,
            'exam_roles'            => $detail['examination']['roles'],
            'blockers'              => $detail['examination']['blockers'] ?? [],
            'transmutation_scheme'  => $scheme,
        ];

        if (!$isComplete) {
            return $base + [
                'complete'                      => false,
                'contributions'                 => [],
                'computed_grade'                => null,
                'transmuted_grade'              => null,
                'transmutation_available'       => false,
                'transmutation_provisional'     => false,
                'transmutation_fallback_scheme' => null,
            ];
        }

        $computedGrade = $runningGrade;

        // An incomplete scheme (e.g. do015_2026 — see
        // TransmutationRangesSeeder's TODO) must never silently fall back
        // to a different scheme's number; the caller gets no transmuted
        // grade at all, plus the flag to show an explicit note, rather
        // than a value that looks real but isn't.
        $transmutation = $this->transmutation->transmuteWithAvailability($computedGrade, $scheme);

        return $base + [
            'complete'                      => true,
            'contributions'                 => $partialContributions,
            'computed_grade'                => $computedGrade,
            'transmuted_grade'              => $transmutation['available'] ? round($transmutation['value'], 2) : null,
            'transmutation_available'       => $transmutation['available'],
            // TASK 1 of "unblock verification" — true only when the scheme
            // actually used (transmutation_fallback_scheme) differs from
            // the subject's real scheme above; see TransmutationService::
            // resolve() and config('dss.transmutation_fallback_scheme').
            'transmutation_provisional'     => $transmutation['provisional'],
            'transmutation_fallback_scheme' => $transmutation['provisional'] ? $transmutation['scheme_used'] : null,
        ];
    }

    private const NO_EXAMINATION = ['earned' => null, 'possible' => null, 'percentage' => null, 'ws' => null, 'scored' => 0,
        'roles' => [], 'method' => 'none', 'unscored_roles' => [], 'unmapped' => [], 'blockers' => []];

    /**
     * DECIMAL-SAFE arithmetic. Every input here is a decimal with at most two
     * places (scores, HPS: decimal(…,2); weights and shares: whole or 2-dp
     * percentages), so each is an exact integer number of hundredths, and
     * every workbook step is an exact rational of those integers. ROUND(x, 2)
     * is applied to that exact rational, half away from zero — Excel's ROUND
     * on these decimals — instead of to a binary double that may sit a
     * hair below or above the .5 tie. No epsilon anywhere.
     */
    private static function cents(float $value): int
    {
        return (int) round($value * 100);
    }

    /** round(numerator / denominator) for non-negative integers, ties up. */
    private static function divRound(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /**
     * WW / PT exactly as the client workbook (Term 1; D1, decided for the
     * implementation 2026-10-04):
     *   N14 = SUM(D14:M14)                       every assessment's HPS
     *   N17 = IF(COUNT(D17:M17)=0,"",SUM(...))   numeric scores only; blank / "M" add 0
     *   O17 = ROUND(N17/N$14*100, 2)             IFERROR -> "" (no positive HPS)
     *   P17 = ROUND(O17*P$14, 2)                 P14 = weight / 100.0
     * A blank or Missing entry earns 0 while its HPS stays in the
     * denominator; a component with no numeric score at all has no PS
     * (never a fabricated 0). An unused template slot is not an assessment
     * and adds nothing. A recorded 0 is a numeric score.
     *
     * Applicability: every REGULAR item of the class/term applies to every
     * enrolled learner (the system has no per-learner exemption model). An
     * ADDITIONAL-SUPPORT item applies only to the learners it was given to,
     * i.e. those with a numeric score on it, and for them it is additive —
     * its points to earned AND its HPS to the denominator (HANDOFF.md
     * "within-term additional support is uncapped and additive"). For anyone
     * else it is absent: adding one can never change their grade.
     *
     * @return array{earned: ?float, possible: ?float, percentage: ?float, ws: ?float, scored: int}
     */
    private function workbookComponent(array $evidence, Student $student, Subject $subject, string $componentKey, ?float $weight): array
    {
        $items = $evidence['by_subject_component'][$subject->id][$componentKey] ?? [];
        $values = $evidence['values'][$student->id] ?? [];

        $earned = 0;   // hundredths
        $possible = 0; // hundredths
        $counted = 0;
        $scored = 0;
        foreach ($items as $id => $item) {
            $has = isset($values[$id]);
            if ($item->is_additional_support && !$has) {
                continue;
            }
            $counted++;
            $possible += self::cents($evidence['max'][$id]);
            if ($has) {
                $earned += self::cents($values[$id]);
                $scored++;
            }
        }

        // O17 in hundredths of a percent: earned / possible × 100 × 100.
        $psCents = $scored > 0 && $possible > 0 ? self::divRound($earned * 10000, $possible) : null;

        return [
            'earned'     => $scored > 0 ? $earned / 100.0 : null,
            'possible'   => $counted > 0 ? $possible / 100.0 : null,
            'percentage' => $psCents === null ? null : $psCents / 100.0,
            // P17 = ROUND(O17 × weight/100, 2): psCents/100 × W/10000, in hundredths.
            'ws'         => $psCents !== null && $weight !== null ? self::divRound($psCents * self::cents($weight), 10000) / 100.0 : null,
            'scored'     => $scored,
        ];
    }

    /**
     * Examination exactly as the client workbook (Term 1; D2/D3, decided for
     * the implementation 2026-10-04), over the ST 1 / ST 2 / TE columns
     * (ExaminationRoles::workbookColumns()) and this subject's CONFIGURED
     * shares (catalog row, else the scheme's exam_role_shares — HELPER!AA:AC):
     *   AG17 = IFERROR(IF(AD17="","",ROUND(AD17/$AD$14*$AG$14, 2)),"")   per type
     *   AJ17 = IF(OR(AG17<>"",AH17<>"",AI17<>""),
     *             SUM(AG17:AI17)/SUM($AG$14:$AI$14)*100, "")             NOT rounded
     *   AK17 = IFERROR(ROUND(AJ17*$AK$14, 2),"")                          AK14 = weight / 100.0
     * The denominator is the sum of every configured share whether or not
     * that exam was given — the available types are never rescaled to 100.
     * A type with no item, no numeric score (blank or Missing) or no
     * positive HPS contributes nothing ("" in the workbook). The exam PS
     * shown is AJ17 to 2 dp; the WS is rounded from the EXACT AJ17.
     *
     * A type's cell (AD17) = its REGULAR item, plus — for a learner scored on
     * them — that type's additional-support items, additively (points and HPS).
     *
     * BLOCKED (no Examination figure for the class; listed in `blockers` with
     * the correction to make): two REGULAR items of one type (ambiguous —
     * the workbook has one cell; never "first wins"), or a typed item whose
     * type this subject's profile gives no share. Untyped items while typed
     * ones exist are not counted (`unmapped`; warned on the Class Record).
     *
     * No configured shares at all (a DO 8 / k12_2013 profile, outside this
     * workbook): the pre-existing equal split among the types that have an
     * item (`method` = equal_split). No typed item at all (legacy data): the
     * pre-existing POOLED Examination percentage over the Examination items,
     * with the WW/PT rule (`method` = pooled).
     *
     * `unscored_roles`: types with a regular item but no numeric score for
     * this learner. The workbook computes without them; the system shows
     * that running figure but keeps the grade out of verification.
     */
    private function workbookExamination(array $evidence, Student $student, Subject $subject, string $scheme, ?DepedSubjectCatalog $catalog, float $weight): array
    {
        $items = $evidence['by_subject_component'][$subject->id]['examination'] ?? [];
        if ($items === []) {
            return ['method' => 'workbook'] + self::NO_EXAMINATION;
        }

        $values = $evidence['values'][$student->id] ?? [];
        $columns = $evidence['exam_columns'][$subject->id];
        $typedRoles = array_keys(array_filter($columns['roles']) + $columns['support']);
        if ($typedRoles === []) {
            return $this->workbookComponent($evidence, $student, $subject, 'examination', $weight)
                + ['roles' => [], 'method' => 'pooled', 'unscored_roles' => [], 'unmapped' => [], 'blockers' => []];
        }

        $shareRows = $this->examRoleShareRows($scheme, $catalog);
        $shares = [];
        foreach (self::EXAM_ROLES as $role) {
            if (isset($shareRows[$role]) && $shareRows[$role] !== null) {
                $shares[$role] = (float) $shareRows[$role];
            }
        }
        $method = 'workbook';
        if (array_sum($shares) <= 0) {
            // No shares configured for this profile (DO 8 / k12_2013 — outside
            // this workbook): the pre-existing equal split among the types that
            // have an item, through the same columns and rounding.
            $shares = array_fill_keys($typedRoles, 100.0); // equal weight, exact
            $method = 'equal_split';
        }

        $blockers = [];
        foreach ($columns['duplicates'] as $role => $extra) {
            $names = array_map(fn($i) => '"' . $i->name . '"', array_merge([$columns['roles'][$role]], $extra));
            $blockers[] = 'More than one regular ' . ExaminationRoles::label($role) . ' item (' . implode(', ', $names) . '). The E-Class Record has one '
                . ExaminationRoles::label($role) . ' column, so no Examination figure is computed until only one remains: '
                . 'Edit the extra item to its correct Examination Type (Edit Assessment).';
        }
        foreach ($typedRoles as $role) {
            if (!isset($shares[$role])) {
                $blockers[] = 'An ' . ExaminationRoles::label($role) . ' item exists, but this subject\'s grading profile gives '
                    . ExaminationRoles::label($role) . ' no share. Edit the item to an Examination Type the profile uses.';
            }
        }
        $unmapped = array_map(fn($i) => $i->id, $columns['untyped']);
        if ($blockers !== []) {
            return ['earned' => null, 'possible' => null, 'percentage' => null, 'ws' => null, 'scored' => 0,
                'roles' => [], 'method' => 'blocked', 'unscored_roles' => [], 'unmapped' => $unmapped, 'blockers' => $blockers];
        }

        $shareTotal = 0;
        foreach ($shares as $share) {
            $shareTotal += self::cents($share);
        }
        $roles = [];
        $sumAg = 0; // hundredths
        $any = false;
        $unscored = [];
        foreach (self::EXAM_ROLES as $role) {
            if (!isset($shares[$role])) {
                continue;
            }
            $share = $shares[$role];
            $regular = $columns['roles'][$role];
            $score = null; // hundredths
            $hps = 0;      // hundredths
            if ($regular !== null) {
                $hps += self::cents($evidence['max'][$regular->id]);
                if (isset($values[$regular->id])) {
                    $score = self::cents($values[$regular->id]);
                }
            }
            foreach ($columns['support'][$role] ?? [] as $support) {
                if (isset($values[$support->id])) {
                    $hps += self::cents($evidence['max'][$support->id]);
                    $score = ($score ?? 0) + self::cents($values[$support->id]);
                }
            }
            if ($regular === null && $score === null) {
                $roles[$role] = ['percentage' => null, 'share' => $share, 'weighted' => null, 'item_id' => null];
                continue;
            }
            if ($score === null) {
                $unscored[] = $role;
            }
            // AG17 = ROUND(AD17 / AD14 × share, 2), in hundredths: score × shareC / hps.
            $weighted = $score !== null && $hps > 0 ? self::divRound($score * self::cents($share), $hps) : null;
            if ($weighted !== null) {
                $sumAg += $weighted;
                $any = true;
            }
            $roles[$role] = [
                'percentage' => $score !== null && $hps > 0 ? self::divRound($score * 10000, $hps) / 100.0 : null,
                'share'      => $share,
                'weighted'   => $weighted === null ? null : $weighted / 100.0,
                'item_id'    => $regular?->id,
            ];
        }

        // AJ17 = ΣAG / Σshares × 100 (exact rational); shown to 2 dp.
        // AK17 = ROUND(AJ17 × weight/100, 2) = ΣAGc × W / ΣsharesC, in hundredths.
        return [
            'earned'         => null,
            'possible'       => null,
            'percentage'     => $any ? self::divRound($sumAg * 10000, $shareTotal) / 100.0 : null,
            'ws'             => $any ? self::divRound($sumAg * self::cents($weight), $shareTotal) / 100.0 : null,
            'scored'         => count(array_filter($roles, fn($r) => $r['weighted'] !== null)),
            'roles'          => $roles,
            'method'         => $method,
            'unscored_roles' => $unscored,
            'unmapped'       => $unmapped,
            'blockers'       => [],
        ];
    }

    /** role => configured share: the catalog row's own shares when it has any, else the scheme's exam_role_shares. */
    private function examRoleShareRows(string $scheme, ?DepedSubjectCatalog $catalog): mixed
    {
        $catalogShares = $catalog?->examRoleShares() ?? [];

        return !empty($catalogShares)
            ? $catalogShares
            : $this->memo('exam_role_shares|' . $scheme, fn() => ExamRoleShare::where('scheme', $scheme)->pluck('share', 'exam_role'));
    }

    /**
     * The configured share of each Examination Type for this class — the
     * Class Record's HPS row under "WS ST1 / WS ST2 / WS TE" (workbook
     * HELPER!AA:AC). A type with no configured share is absent (the engine
     * then splits equally among the types present; nothing is invented).
     *
     * @return array<string, float> st1|st2|term_exam => share
     */
    public function examRoleShares(Section $section, Subject $subject): array
    {
        $profile = $this->resolveWeightProfile($section, $subject);
        $rows = $this->examRoleShareRows($profile['scheme'], $profile['catalog']);
        $shares = [];
        foreach (self::EXAM_ROLES as $role) {
            if (isset($rows[$role]) && $rows[$role] !== null) {
                $shares[$role] = (float) $rows[$role];
            }
        }

        return $shares;
    }

    /**
     * Class Record LIVE PREVIEW (2026-10-04): replaces, in THIS instance's
     * in-memory evidence only, the scores of the given cells — [assessment
     * id][student id] => score, null = no score (blank, which a save turns
     * into Missing; the engine never reads Missing either way). Nothing is
     * written; a fresh engine instance is unaffected. Cells of items outside
     * the loaded scope are ignored. Re-applied if the evidence reloads.
     *
     * @param array<int, array<int, ?float>> $cells
     */
    public function overlayScores(Section $section, int $gradingPeriod, string $schoolYear, array $cells): static
    {
        $key = $section->id . '|' . $gradingPeriod . '|' . $schoolYear;
        $this->overlays[$key] = $cells;
        unset($this->evidence[$key]);

        return $this;
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

        if ($catalog && $catalog->teacher_supplied) {
            // "OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM" (HELPER rows 93 / 147):
            // the workbook reads this row's weights and shares from what the
            // teacher types in INPUT DATA!F43:F48. The system stores no such
            // per-subject figures and never takes a teacher-typed weight, so
            // this profile is UNCONFIGURED: no figure, no official grade —
            // never a 0 weight or another profile's weights.
            return [
                'scheme'              => $scheme,
                'source'              => 'unconfigured',
                'source_label'        => 'Not configured — DepEd "Other elective / special curricular program" (weights supplied per subject)',
                'group_key'           => null,
                'ww_weight'           => null,
                'pt_weight'           => null,
                'ex_weight'           => null,
                'catalog'             => $catalog,
                'configuration_error' => $subject->name . ' is linked to the DepEd "Other elective / special curricular program" catalog row, whose weights and '
                    . 'Examination shares are supplied per subject. The system has no configured weights for it, so no grade is computed and none can be verified. '
                    . 'Ask the Admin to link this subject to its own DepEd catalog subject, or to record the school\'s decision on its weights.',
            ];
        }

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
                'configuration_error' => null,
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
            'configuration_error' => null,
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
