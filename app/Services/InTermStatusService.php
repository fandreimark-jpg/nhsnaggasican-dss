<?php

namespace App\Services;

use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * A second, LIGHTER signal alongside the classifier's risk_results — see
 * the "live in-term risk + stale data guard" prompt (Problem 2).
 * Computed entirely from assessment evidence already on file for ONE
 * subject/term, fresh on every read — never written to risk_results,
 * never stored anywhere, never fed to the classifier, never a
 * substitute for one.
 *
 * This is NOT a risk level. A Risk Level answers "how is this student
 * doing across every subject, over a school year's worth of terms, per
 * the submitted term report." In-Term Status only ever answers "how
 * does the evidence collected SO FAR this term, in this one subject,
 * look" — it says nothing about trend, nothing about other subjects,
 * and it exists specifically so a principal isn't stuck waiting for the
 * term (and the student's chance to recover) to be over before seeing
 * anything at all.
 *
 * Thresholds are simple and visible, same style as
 * ReportController::applyFailingSubjectOverride() and
 * InterventionRecommender — no black box:
 *   0 components below the 75 target -> On Track
 *   1 component below target         -> Needs Attention
 *   2 or more                        -> At Risk
 */
class InTermStatusService
{
    public const STATUSES = ['On Track', 'Needs Attention', 'At Risk'];

    /**
     * Worst-first severity ordering for the "Overall In-Term Status"
     * worst-of reduction below — the single source other copies of this
     * ordering (formerly duplicated in Adviser\DashboardController and
     * DashboardAnalyticsService) must read from instead of re-declaring.
     */
    public const SEVERITY = ['At Risk' => 0, 'Needs Attention' => 1, 'On Track' => 2];

    public function __construct(private PerformanceAnalysisService $analysis = new PerformanceAnalysisService())
    {
    }

    /**
     * @return array{
     *     status: string, computed_grade: float|null, complete: bool,
     *     weakest_component: ?string, components_below_target: int,
     *     item_count: int,
     * }
     */
    public function statusFor(Student $student, Subject $subject, Section $section, int $gradingPeriod, string $schoolYear): array
    {
        $analysis = $this->analysis->analyzeStudent($student, $subject, $section, $gradingPeriod, $schoolYear);

        return $this->fromAnalysis($analysis, $student, $subject, $section, $gradingPeriod, $schoolYear);
    }

    /**
     * Same as statusFor(), but takes an analysis array the caller
     * already computed via PerformanceAnalysisService::analyzeStudent().
     * Both of this feature's consumers (the Principal Students page and
     * the Adviser Assessments page) already call that once per row for
     * Computed Grade / Focus Area — this avoids running it a second time
     * for the same student/subject/term.
     *
     * @param array{components: array, weakest_component: ?string, computed_grade: float|null, complete: bool} $analysis
     */
    public function fromAnalysis(array $analysis, Student $student, Subject $subject, Section $section, int $gradingPeriod, string $schoolYear): array
    {
        $componentsBelowTarget = collect($analysis['components'])
            ->filter(fn($c) => $c['status'] === 'Needs Attention')
            ->count();

        // How many assessment ITEMS this status was actually computed
        // from — a status from 3 of 10 planned assessments is a very
        // different claim than one from all 10, and the caller must show
        // this count next to the status, not just the status alone.
        //
        // "Performance audit" pass — PerformanceAnalysisService::analyzeStudent()
        // now carries this count ('item_count', from the evidence
        // GradingEngine already loaded for the grade). The query below is
        // kept only for a caller that hands in a hand-built analysis array
        // without it; it is the same count either way.
        $itemCount = array_key_exists('item_count', $analysis)
            ? (int) $analysis['item_count']
            : AssessmentScore::where('student_id', $student->id)
                ->whereHas('assessment', function ($q) use ($subject, $section, $gradingPeriod, $schoolYear) {
                    $q->where('subject_id', $subject->id)
                      ->where('section_id', $section->id)
                      ->where('grading_period', $gradingPeriod)
                      ->where('school_year', $schoolYear);
                })
                ->count();

        return [
            'status'                  => self::classify($componentsBelowTarget),
            'computed_grade'          => $analysis['computed_grade'],
            'complete'                => $analysis['complete'],
            'weakest_component'       => $analysis['weakest_component'],
            'components_below_target' => $componentsBelowTarget,
            'item_count'              => $itemCount,
        ];
    }

    /**
     * "In-Term Status reconciliation" work order, PART 2 — the single,
     * canonical "Overall In-Term Status" computation for a roster of
     * students against a section's subject list, for one term. Both
     * Adviser\DashboardController (per-section, full detail for its
     * drill-down) and DashboardAnalyticsService (whole-school, rolled up
     * into counts) call this; neither keeps its own copy.
     *
     * This was previously duplicated: Adviser's copy called this class's
     * fromAnalysis() (and so GradingEngine, and so respected the DO 015
     * exam-role weighting and excluded no-role additional-support items
     * from the Examination component, per GradingEngine::
     * examinationPercentage()'s documented rule) while the Principal
     * dashboard's copy summed assessment_scores directly in SQL, bypassing
     * GradingEngine entirely and getting the Examination component wrong
     * for any subject using exam roles. See CLAUDE.md's numbered design
     * decision on the missing-evidence rule this method implements.
     *
     * For each student: every subject with zero scored items so far this
     * term is EXCLUDED from the worst-case reduction — no evidence is not
     * a claim of On Track. `subjects_evaluated` vs `subjects_total` lets a
     * caller show that a status is based on fewer subjects than the full
     * load, rather than silently treating an unevaluated subject as fine.
     * Among the subjects that DO have evidence, the WORST status wins.
     *
     * @param Collection<int, Student> $students
     * @param Collection<int, Subject> $subjects
     * @return Collection<int, array{
     *     student: Student,
     *     in_term_status: ?array,
     *     focus_subject: ?Subject,
     *     transmuted_grade: float|null,
     *     subjects_evaluated: int,
     *     subjects_total: int,
     *     by_subject: array<int, array{subject: Subject, status: array, complete: bool}>,
     * }>
     */
    public function overallStatusForSection(Section $section, Collection $students, Collection $subjects, int $gradingPeriod): Collection
    {
        return $students->map(function (Student $student) use ($section, $subjects, $gradingPeriod) {
            $worst = null;
            $worstSubject = null;
            $worstTransmuted = null;
            $bySubject = [];
            $evaluated = 0;

            foreach ($subjects as $subject) {
                $analysis = $this->analysis->analyzeStudent($student, $subject, $section, $gradingPeriod, $section->school_year);
                $status = $this->fromAnalysis($analysis, $student, $subject, $section, $gradingPeriod, $section->school_year);

                // Every subject goes into the drill-down, evidence or not
                // — "no evidence yet" is itself useful information there.
                $bySubject[] = ['subject' => $subject, 'status' => $status, 'complete' => $analysis['complete']];

                if ($status['item_count'] === 0) {
                    continue; // no evidence yet for this subject — no claim either way
                }
                $evaluated++;

                if ($worst === null || self::SEVERITY[$status['status']] < self::SEVERITY[$worst['status']]) {
                    $worst = $status;
                    $worstSubject = $subject;
                    $worstTransmuted = $analysis['transmuted_grade'];
                }
            }

            return [
                'student'            => $student,
                'in_term_status'     => $worst, // null when no subject has any evidence yet
                'focus_subject'      => $worstSubject,
                'transmuted_grade'   => $worstTransmuted,
                'subjects_evaluated' => $evaluated,
                'subjects_total'     => $subjects->count(),
                'by_subject'         => $bySubject,
            ];
        });
    }

    /**
     * The 0/1/2+ components-below-target threshold, exposed as a public
     * static helper so anything that needs to bucket a school-wide count
     * (see DashboardAnalyticsService::getInTermStatusSummary(), which
     * computes this via one aggregate query rather than looping
     * analyzeStudent() per student per subject at whole-school scale)
     * reuses this exact rule instead of a second copy that could drift.
     */
    public static function classify(int $componentsBelowTarget): string
    {
        return match (true) {
            $componentsBelowTarget >= 2 => 'At Risk',
            $componentsBelowTarget === 1 => 'Needs Attention',
            default => 'On Track',
        };
    }

    /**
     * TASK 2 of "bulk dialog and intervention closure" — a SIGNAL that an
     * intervention may be ready to close, never an action. An intervention
     * only qualifies once it has actually been delivered (delivered_at —
     * a recommendation the adviser never carried out has nothing to
     * "recover" from) AND is still open in a state the Principal has
     * already advanced past mere recommendation (approved/in_progress —
     * 'recommended'/'in_review' haven't been acted on yet, and
     * 'completed'/'monitoring' are already decided) AND the student's
     * CURRENT In-Term Status for that intervention's own subject/term is
     * On Track.
     *
     * Computed fresh from evidence on every call, exactly like statusFor()
     * — nothing is stored, so this can never go stale the way a persisted
     * flag would the moment new assessment evidence arrives. See the
     * ground rule: "Never auto-close, auto-complete, or auto-create an
     * intervention" — this method only ever answers a yes/no question;
     * every caller must still require an explicit Principal action before
     * changing anything.
     */
    public function isReadyForReview(Intervention $intervention): bool
    {
        if (!in_array($intervention->status, ['approved', 'in_progress'], true)) {
            return false;
        }

        if (!$intervention->delivered_at || !$intervention->subject_id || !$intervention->grading_period) {
            return false;
        }

        $student = $intervention->student;
        $subject = $intervention->subject;
        // PART 12 — the intervention's OWN section/year context.
        $section = $intervention->contextSection();

        if (!$student || !$subject || !$section) {
            return false;
        }

        $status = $this->statusFor($student, $subject, $section, $intervention->grading_period, $section->school_year);

        return $status['status'] === 'On Track';
    }

    /**
     * School-wide count of interventions currently flagged ready for
     * review — shared by the Principal Interventions filter badge and
     * the Principal dashboard card so the two numbers can never drift
     * apart. Narrowed to the small (open + delivered) candidate set in
     * SQL first, same bounded-then-filter-in-PHP shape as
     * isReadyForReview()'s own callers.
     */
    public function readyForReviewCount(?string $schoolYear = null): int
    {
        return Intervention::whereIn('status', ['approved', 'in_progress'])
            ->whereNotNull('delivered_at')
            ->when($schoolYear, fn($q) => $q->where('school_year', $schoolYear))
            ->with(['student.section', 'section', 'subject'])
            ->get()
            ->filter(fn(Intervention $iv) => $this->isReadyForReview($iv))
            ->count();
    }

    /**
     * DepEd's formal failing mark on the REPORTED grade — 74 and below.
     *
     * This is deliberately a different question from In-Term Status. In-Term
     * Status asks "does the assessment evidence show a problem while there is
     * still time to act" and is based on the COMPUTED grade. This asks "did
     * the student formally fail" and is based on the OFFICIAL (transmuted)
     * grade, which only exists once an Adviser has verified it.
     *
     * A provisional grade is never Failing: it was produced by a fallback
     * transmutation scheme, not the subject's real one, so it is not an
     * official grade and must not be treated as one.
     */
    public const FAILING_THRESHOLD = 74.0;

    public static function isFailing(?Grade $grade): bool
    {
        return $grade
            && $grade->is_verified
            && !$grade->is_provisional
            && $grade->grade !== null
            && (float) $grade->grade <= self::FAILING_THRESHOLD;
    }

    /**
     * "The Failing layer" TASK 2d — the shared priority-sort rule (Failing
     * -> At Risk -> Needs Attention -> everyone else), used by both the
     * Principal Students page and the Adviser Assessments page so the
     * default row order can never drift between them. Failing (a formal,
     * verified determination) always outranks the in-term evidence
     * signals — a student can be both Failing and At Risk and still only
     * ever appears once, in this single highest bucket.
     */
    public const PRIORITY_FAILING = 0;
    public const PRIORITY_AT_RISK = 1;
    public const PRIORITY_NEEDS_ATTENTION = 2;
    public const PRIORITY_REST = 3;

    public static function priority(?Grade $officialGrade, string $inTermStatusValue): int
    {
        if (self::isFailing($officialGrade)) {
            return self::PRIORITY_FAILING;
        }

        return match ($inTermStatusValue) {
            'At Risk'         => self::PRIORITY_AT_RISK,
            'Needs Attention' => self::PRIORITY_NEEDS_ATTENTION,
            default           => self::PRIORITY_REST,
        };
    }
}
