<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Intervention;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Section;
use App\Models\ReportSubmission;
use App\Services\InTermStatusService;
use App\Services\SectionElectiveStatus;
use App\Services\TransmutationService;
use Illuminate\Support\Collection;

/**
 * DashboardController (Adviser)
 *
 * Handles the Adviser Dashboard.
 * Shows the adviser's section overview — total students,
 * grades encoded, pending submissions, and student in-term status.
 */
class DashboardController extends Controller
{
    public function __construct(
        private InTermStatusService $inTermStatus = new InTermStatusService(),
        private SectionElectiveStatus $electiveStatus = new SectionElectiveStatus()
    ) {
    }

    public function index()
    {
        // Get the section assigned to the logged-in adviser
        $section = Section::forAdviser(auth()->id())?->load(['track', 'specialization']);

        // If no section assigned — show empty dashboard
        $totalStudents = $section
            ? Student::enrolledIn($section)->count()
            : 0;

        // "ECR alignment" work order, PART 6 — expected grades now come
        // from SectionElectiveStatus PER TERM (an elective doesn't
        // necessarily run every term), the same shared source of truth
        // AcademicTerm::completionStatus(), ReportController, and
        // TermReadinessService all read from — not a flat students-times-
        // subjects figure reused for all three terms, which is what this
        // used to be. A section whose SSHS electives aren't assigned yet
        // reports 0 expected in every term rather than a core-only figure
        // that would read as achievable.
        $isConfigured = $section ? $this->electiveStatus->isFullyConfigured($section) : true;
        $expectedPerTerm = [];
        foreach ([1, 2, 3] as $term) {
            $expectedPerTerm[$term] = ($section && $isConfigured)
                ? $this->electiveStatus->expectedGradeCount($section, $term)
                : 0;
        }
        // Still used by the "Grades Encoded ... of N across 3 terms" card —
        // the real per-term sum, not a flat multiply-by-3.
        $totalExpectedAcrossTerms = array_sum($expectedPerTerm);

        // Count grades encoded per term — used for progress tracking
        $term1Count = $section ? Grade::where('section_id', $section->id)
            ->where('grading_period', 1)
            ->where('school_year', $section->school_year)
            ->count() : 0;

        $term2Count = $section ? Grade::where('section_id', $section->id)
            ->where('grading_period', 2)
            ->where('school_year', $section->school_year)
            ->count() : 0;

        $term3Count = $section ? Grade::where('section_id', $section->id)
            ->where('grading_period', 3)
            ->where('school_year', $section->school_year)
            ->count() : 0;

        $totalGradesEncoded = $term1Count + $term2Count + $term3Count;

        // Load submissions keyed by grading_period for easy lookup in the view
        $submissions = $section ? ReportSubmission::where('section_id', $section->id)
            ->where('school_year', $section->school_year)
            ->get()
            ->keyBy('grading_period') : collect();

        // Count terms that are complete but not yet submitted
        $pendingCount = 0;
        foreach ([1, 2, 3] as $term) {
            $termCount = match($term) {
                1 => $term1Count,
                2 => $term2Count,
                3 => $term3Count,
            };
            $isComplete  = $expectedPerTerm[$term] > 0 && $termCount >= $expectedPerTerm[$term];
            $isSubmitted = isset($submissions[$term]);
            if ($isComplete && !$isSubmitted) {
                $pendingCount++;
            }
        }

        // Load students with their latest risk results for the overview table
        $students = $section
            ? Student::enrolledIn($section)
                ->with(['riskResults' => function ($q) use ($section) {
                    $q->where('school_year', $section->school_year)
                      ->orderBy('grading_period', 'desc'); // latest term first
                }])
                ->orderBy('last_name')
                ->get()
            : collect();

        // Trend per student — compares the two most recent terms so the
        // adviser can see whether a student is improving or declining,
        // not just their current snapshot.
        $trends = $students->mapWithKeys(function ($student) {
            $history = $student->riskResults->sortBy('grading_period')->values();
            return [$student->id => $this->computeTrend($history)];
        });

        $staleRiskTerms = $section ? AcademicTerm::staleRiskTerms($section->school_year) : [];

        // TASK 2 of "close the intervention loop": the risk-level overview
        // used to show "No data — Submit report to generate" on every row
        // until a term report was submitted — useless exactly when the
        // adviser most needs it. In-Term Status works from assessment
        // evidence already on file, across every subject this section
        // takes, so it has something to say from the first upload onward.
        $openTerm = $section ? (AcademicTerm::currentOpenTerm($section->school_year) ?? 1) : 1;
        // "Student identity and term-specific subject offerings" pass — the
        // subjects evaluated are the OPEN term's offerings, not every
        // subject the section takes at some point in the year.
        $subjects = $section ? Subject::forSection($section, $openTerm)->get() : collect();
        $allInTermRows = $section
            ? $this->buildInTermRows($section, $students, $subjects, $openTerm)
            : collect();

        // TASK 5 of "clarity, progress, and visual design pass" — the
        // panel used to render every learner in the section and push
        // everything else on the dashboard off-screen. buildInTermRows()
        // already sorts worst-first (At Risk -> Needs Attention -> On
        // Track -> no evidence yet), so the top 10 are exactly the 10
        // highest-priority learners; "View all ->" still links to the
        // full, unfiltered My Students page.
        $inTermRows = $allInTermRows->take(10);
        $inTermStatusCounts = [
            'At Risk'         => $allInTermRows->filter(fn($row) => ($row['in_term_status']['status'] ?? null) === 'At Risk')->count(),
            'Needs Attention' => $allInTermRows->filter(fn($row) => ($row['in_term_status']['status'] ?? null) === 'Needs Attention')->count(),
            'On Track'        => $allInTermRows->filter(fn($row) => ($row['in_term_status']['status'] ?? null) === 'On Track')->count(),
        ];

        // TASK 1c: interventions the Principal has recorded for this
        // section's students that this adviser hasn't yet acknowledged
        // seeing — see Adviser\InterventionController.
        // "Correctness and interface pass" TASK 2a — never surface a
        // recommendation the Principal hasn't decided on yet as something
        // "awaiting your acknowledgement" (see Intervention::awaitingDecision()
        // and Adviser\InterventionController's same guard) — it isn't
        // acknowledgeable yet, so it must not appear to be.
        $unacknowledgedInterventions = $section
            ? Intervention::where('section_id', $section->id)
                ->whereNull('acknowledged_at')
                ->where('status', '!=', Intervention::STATUS_RECOMMENDED)
                ->whereNotNull('decided_by')
                ->with(['student', 'subject'])
                ->latest()
                ->get()
            : collect();

        // "Decision flow, report scoping, and dashboard pass" TASK 1c/5b
        // — the second intervention state the old "awaiting your
        // acknowledgement" panel never surfaced: seen, but not yet acted
        // on. Same section/decided scoping as $unacknowledgedInterventions.
        $acknowledgedNotDelivered = $section
            ? Intervention::where('section_id', $section->id)
                ->whereNotNull('acknowledged_at')
                ->whereNull('delivered_at')
                ->count()
            : 0;

        // TASK 5b — reuses the 'complete' flag already computed per
        // student x subject inside buildInTermRows() above (no second
        // GradingEngine pass) to report two more attention items:
        // subjects where at least one student's evidence isn't complete
        // yet, and students whose complete evidence has never been
        // turned into a verified official grade this term.
        $subjectsWithIncompleteEvidence = collect();
        $gradesComputedNotVerified = 0;

        if ($section) {
            $existingGrades = Grade::where('section_id', $section->id)
                ->where('grading_period', $openTerm)
                ->where('school_year', $section->school_year)
                ->get()
                ->keyBy(fn($g) => $g->student_id . '|' . $g->subject_id);

            foreach ($allInTermRows as $row) {
                foreach ($row['by_subject'] as $entry) {
                    if (!$entry['complete']) {
                        $subjectsWithIncompleteEvidence->push($entry['subject']->id);
                        continue;
                    }

                    $key = $row['student']->id . '|' . $entry['subject']->id;
                    $existing = $existingGrades->get($key);
                    if (!$existing || !$existing->is_verified) {
                        $gradesComputedNotVerified++;
                    }
                }
            }

            $subjectsWithIncompleteEvidence = $subjectsWithIncompleteEvidence->unique();
        }

        // TASK 1 of "unblock verification" — a persistent, non-dismissible
        // banner while this section's grade level is actually running on
        // a fallback transmutation scheme (config('dss.
        // transmutation_fallback_scheme')). null when no fallback is
        // configured, or when this section's real scheme already covers
        // 0-100 cleanly and never needs one — see
        // TransmutationService::fallbackActiveFor().
        $transmutationBanner = null;
        $fallbackScheme = config('dss.transmutation_fallback_scheme');
        if ($section && $fallbackScheme && (new TransmutationService())->fallbackActiveFor($section->grade_level, $section->school_year, $section->curriculum)) {
            $transmutationBanner = [
                'fallback_scheme' => $fallbackScheme,
                'grade_levels'    => [$section->grade_level],
            ];
        }

        return view('adviser.dashboard', compact(
            'section', 'totalStudents', 'totalGradesEncoded',
            'pendingCount', 'submissions', 'students', 'trends',
            'expectedPerTerm', 'totalExpectedAcrossTerms', 'isConfigured',
            'term1Count', 'term2Count', 'term3Count',
            'staleRiskTerms', 'openTerm', 'inTermRows', 'allInTermRows', 'inTermStatusCounts', 'unacknowledgedInterventions',
            'acknowledgedNotDelivered', 'subjectsWithIncompleteEvidence', 'gradesComputedNotVerified',
            'transmutationBanner'
        ));
    }

    /**
     * TASK 2 of "dashboard structure and upload safeguards" — the
     * AGGREGATION RULE, stated plainly so it can be reproduced by hand:
     *
     *   For each student, look at every subject this section takes.
     *   Skip any subject with zero scored items this term (no evidence
     *   yet is not a claim of "On Track" — same "missing means
     *   incomplete, not zero" principle as everywhere else in this app).
     *   Among the REMAINING subjects, take the WORST status
     *   (At Risk > Needs Attention > On Track, in that order of
     *   severity — see InTermStatusService::SEVERITY). That single worst
     *   status is the student's "Overall In-Term Status" shown on this
     *   dashboard.
     *
     * WHY worst-of, not an average or a majority vote: this is a
     * triage view — "is there ANY subject this student needs help in
     * right now" — and averaging or requiring a majority would let one
     * failing subject hide behind several passing ones, which is exactly
     * the student CLAUDE.md's DSS spec exists to surface, not obscure.
     * A single low subject is enough to flag the student.
     *
     * This is DELIBERATELY a different number than the Principal Students
     * page's In-Term Status column, which shows ONE subject at a time (the
     * one currently selected in that page's filter) — the two are not
     * expected to match, and a reader comparing them should see "Overall"
     * vs "this subject" in the labels, not two numbers that claim to be
     * the same thing. See the "Overall In-Term Status" column header and
     * its subtitle on adviser.dashboard, and the per-subject drill-down
     * this method's return value also feeds (row['by_subject']).
     *
     * Bounded by ONE section's roster x subject list, never the whole
     * school — the same cost shape as the Adviser Assessments page's
     * existing per-subject loop, just widened to every subject this
     * section takes instead of one selected subject.
     *
     * "In-Term Status reconciliation" work order, PART 2 — the actual
     * per-student computation now lives in InTermStatusService::
     * overallStatusForSection(), the single copy the Principal dashboard
     * also calls. This method only adds risk_level (a different data
     * source, RiskResult, not part of the In-Term Status computation
     * itself) and this page's own sort order on top.
     *
     * @return Collection<int, array{student: Student, in_term_status: array, focus_subject: ?Subject, risk_level: ?string, by_subject: array}>
     */
    private function buildInTermRows(Section $section, Collection $students, Collection $subjects, int $openTerm): Collection
    {
        $rows = $this->inTermStatus->overallStatusForSection($section, $students, $subjects, $openTerm)
            ->map(function (array $row) use ($openTerm) {
                $riskResult = $row['student']->riskResults->firstWhere('grading_period', $openTerm);
                $row['risk_level'] = $riskResult?->risk_level;
                return $row;
            });

        // At Risk first; rows with no evidence at all sort last regardless
        // of direction — same "missing is unknown, not worse" rule as
        // Principal\StudentController::sortRows().
        [$withStatus, $withoutStatus] = $rows->partition(fn($row) => $row['in_term_status'] !== null);

        $sorted = $withStatus->sortBy(fn($row) => InTermStatusService::SEVERITY[$row['in_term_status']['status']]);

        return $sorted->concat($withoutStatus->sortBy(fn($row) => $row['student']->last_name))->values();
    }

    /**
     * Same trend logic as the Admin dashboard — 'improving', 'declining',
     * 'stable', or null when there's fewer than 2 terms to compare.
     */
    private function computeTrend($history): ?string
    {
        if ($history->count() < 2) {
            return null;
        }

        $previous = $history[$history->count() - 2]->average_grade;
        $current  = $history[$history->count() - 1]->average_grade;
        $diff     = $current - $previous;

        if ($diff > 1) {
            return 'improving';
        }
        if ($diff < -1) {
            return 'declining';
        }
        return 'stable';
    }

}