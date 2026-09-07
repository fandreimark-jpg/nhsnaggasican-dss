<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\DashboardAnalyticsService;
use App\Services\InTermStatusService;
use App\Services\InterventionRecommender;
use App\Services\PerformanceAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * StudentController (Principal) — read-only.
 *
 * The drill-down destination CLAUDE.md describes: Dashboard -> Student
 * -> Subject -> Assessment Component -> Evidence -> DSS Analysis. Every
 * at-risk student row across the Principal dashboard and Interventions
 * page links here. For each subject the student takes, shows the same
 * PerformanceAnalysisService breakdown used elsewhere (so it can never
 * disagree with the DSS/Interventions pages) PLUS the actual individual
 * assessment items and scores behind it — the "Evidence" step, which
 * nothing before this showed at the individual-item level.
 */
class StudentController extends Controller
{
    private const PER_PAGE = 25;

    private const SORTABLE = ['name', 'written_work', 'performance_task', 'examination', 'computed_grade', 'transmuted_grade', 'in_term_status'];

    /**
     * "The Failing layer" TASK 2e — the status filter's valid values.
     * Grouped visually in the Blade (In-Term Status vs. Official Grade),
     * but both live in the same flat query param since a row can only
     * ever be filtered to one bucket at a time.
     */
    private const STATUS_FILTER_OPTIONS = ['On Track', 'Needs Attention', 'At Risk', 'Failing'];

    private const COMPONENT_LABELS = [
        'written_work'     => 'Written Work',
        'performance_task' => 'Performance Task',
        'examination'      => 'Examination',
    ];

    /**
     * TASK 4 of "close the intervention loop" — the Focus Area -> suggested
     * type mapping for the "Record for all At Risk" bulk dialog. A
     * starting point the Principal reviews and can change per row before
     * confirming, never applied without review.
     */
    private const FOCUS_TO_TYPE = [
        'written_work'     => 'additional_learning_activity',
        'performance_task' => 'additional_performance_task',
        'examination'      => 'remediation',
    ];

    public function __construct(
        private PerformanceAnalysisService $analysis = new PerformanceAnalysisService(),
        private DashboardAnalyticsService $dashboardAnalytics = new DashboardAnalyticsService(),
        private InterventionRecommender $recommender = new InterventionRecommender(),
        private InTermStatusService $inTermStatus = new InTermStatusService()
    ) {
    }

    /**
     * The per-student component breakdown reachable WITHOUT waiting on
     * risk_results (see the class docblock and the routes/web.php
     * comment on getAtRiskStudentsData's whereHas('riskResults') for why
     * that gate exists on Interventions but must not exist here — a
     * missing term report has nothing to do with whether assessment
     * evidence already answers "which component is this student weakest
     * in").
     *
     * A subject must be selected to compute anything (analyzeStudent()
     * needs one), and selecting a subject already narrows the roster to
     * that subject's eligible cohort (one grade level, further narrowed
     * by track/specialization for an elective, plus any Grade Level/
     * Section filter below) — never the whole school. That bound is what
     * keeps calling analyzeStudent() over this filtered set cheap; only
     * the current page's 25 rows are then displayed and sorted from data
     * already in hand, so nothing further is recomputed to fill the page.
     */
    public function index(Request $request)
    {
        $gradingPeriod = (int) $request->input('period', 1);

        $gradeLevels = Section::select('grade_level')->distinct()->orderBy('grade_level')->pluck('grade_level');
        $sections = Section::select('id', 'name', 'grade_level', 'track_id', 'specialization_id')
            ->with(['track:id,name', 'specialization:id,name'])
            ->orderBy('grade_level')->orderBy('name')->get();

        // TASK 1 of "subject scoping and bulk threshold" — the Subject
        // dropdown (and the table it drives) is scoped to whichever
        // section is selected, using the SAME Subject::forSection() the
        // adviser's own Assessments page already trusts — see that
        // method's docblock. Every filter change already triggers a full
        // page reload (initAutoSubmitFilter binds every <select> in this
        // form), so this can be resolved statelessly, per-request, with
        // no client-side dropdown repopulation needed.
        $selectedSectionId = $request->input('section_id');
        $resolvedSection = $selectedSectionId
            ? $sections->firstWhere('id', (int) $selectedSectionId)
            : null;

        if ($resolvedSection) {
            $subjects = Subject::forSection($resolvedSection)->orderBy('type')->orderBy('name')->get();
        } else {
            // No section selected — keep the full list, but ordered so the
            // view can group it by grade level (still "at least navigable"
            // per the task, short of full section-scoping).
            $subjects = Subject::orderBy('grade_level')->orderBy('type')->orderBy('name')->get();
        }

        // A section with a real Track/Specialization but genuinely zero
        // matching subjects is a legitimate empty result; a section with
        // a NULL track_id/specialization_id is a data fault — Subject::forSection()
        // can never match an elective without one, and the empty dropdown
        // must say so rather than look like "this section just has no
        // subjects" (see the view for how this is surfaced).
        $sectionMissingTrackOrSpec = $resolvedSection
            && $subjects->isEmpty()
            && (!$resolvedSection->track_id || !$resolvedSection->specialization_id);

        $requestedSubjectId = $request->input('subject_id');
        if ($requestedSubjectId !== null && $requestedSubjectId !== '') {
            // A subject WAS requested — if it isn't in the (possibly
            // section-scoped) list, the pairing became invalid, almost
            // always because the Section filter just changed. Clear it
            // rather than silently substituting a different subject —
            // see the ground rule and this task's own wording.
            $subject = $subjects->firstWhere('id', (int) $requestedSubjectId);
        } else {
            // No subject requested at all (fresh page load, or the
            // Section/Grade Level filter was cleared) — default to the
            // first subject in whatever list is currently in scope, same
            // as before this task.
            $subject = $subjects->first();
        }

        $focus = $request->input('focus');
        if (!array_key_exists($focus, self::COMPONENT_LABELS)) {
            $focus = null;
        }

        $students = null;
        $bulkCandidates = collect();
        $sortKey = 'in_term_status';
        $sortDir = 'asc';

        if ($subject) {
            $students_ = Student::with(['section.track', 'section.specialization', 'section.adviser'])
                ->whereHas('section', function ($q) use ($subject, $request) {
                    $q->where('grade_level', $subject->grade_level);
                    if ($subject->type === 'elective') {
                        $q->where('track_id', $subject->track_id)
                          ->where(function ($q2) use ($subject) {
                              $q2->whereNull('specialization_id')
                                 ->orWhere('specialization_id', $subject->specialization_id);
                          });
                    }
                    if ($request->filled('grade_level')) {
                        $q->where('grade_level', $request->input('grade_level'));
                    }
                })
                ->when($request->filled('section_id'), fn($q) => $q->where('section_id', $request->input('section_id')))
                ->orderBy('last_name')
                ->get();

            // "The Failing layer" TASK 2 — the OFFICIAL grade for this
            // subject/term, one query for every student in scope rather
            // than one per row (same shape as Adviser\AssessmentController's
            // own $officialGrades lookup).
            $officialGrades = Grade::where('subject_id', $subject->id)
                ->where('grading_period', $gradingPeriod)
                ->where('school_year', Section::activeSchoolYear())
                ->whereIn('student_id', $students_->pluck('id'))
                ->get()
                ->keyBy('student_id');

            // "Workflow completion pass" TASK 3a — display only, computed
            // independently of GradingEngine: which students have at
            // least one SCORED item marked is_additional_support for
            // this subject/term, across every section in scope. One
            // query for the whole roster, not per row.
            $additionalSupportStudentIds = AssessmentScore::whereIn('student_id', $students_->pluck('id'))
                ->whereHas('assessment', function ($q) use ($subject, $gradingPeriod) {
                    $q->where('subject_id', $subject->id)
                        ->where('grading_period', $gradingPeriod)
                        ->where('is_additional_support', true);
                })->distinct()->pluck('student_id');

            $rows = $students_->map(function (Student $student) use ($subject, $gradingPeriod, $officialGrades, $additionalSupportStudentIds) {
                    $section = $student->section;
                    $result = $this->analysis->analyzeStudent($student, $subject, $section, $gradingPeriod, $section->school_year);
                    // Reuses $result (already computed above) rather than
                    // calling analyzeStudent() a second time — see
                    // InTermStatusService::fromAnalysis().
                    $inTermStatus = $this->inTermStatus->fromAnalysis($result, $student, $subject, $section, $gradingPeriod, $section->school_year);
                    $officialGrade = $officialGrades->get($student->id);
                    return array_merge([
                        'has_additional_support' => $additionalSupportStudentIds->contains($student->id),
                        'student'        => $student,
                        'section'        => $section,
                        'in_term_status' => $inTermStatus,
                        'official_grade' => $officialGrade,
                        'is_failing'     => InTermStatusService::isFailing($officialGrade),
                    ], $result);
                });

            if ($focus) {
                $rows = $rows->filter(fn($row) => $row['weakest_component'] === $focus)->values();
            }

            // "The Failing layer" TASK 2e — a single status filter
            // spanning both signals; 'Failing' reads the official grade,
            // everything else reads In-Term Status. See the Blade for
            // the grouped dropdown this drives.
            $statusFilter = $request->input('status_filter');
            if (in_array($statusFilter, self::STATUS_FILTER_OPTIONS, true)) {
                $rows = $statusFilter === 'Failing'
                    ? $rows->filter(fn($row) => $row['is_failing'])->values()
                    : $rows->filter(fn($row) => ($row['in_term_status']['status'] ?? null) === $statusFilter)->values();
            }

            // TASK 4 of "clarity, progress, and visual design pass" —
            // whether an OPEN intervention already exists for this
            // student/subject, computed for the WHOLE filtered set (not
            // just the current page) so the "Hide learners with an
            // active intervention" filter below can act on rows before
            // pagination splits them up. One query for the whole set,
            // same shape as buildBulkInterventionCandidates()'s own
            // lookup — this does NOT replace attachInterventionContext()
            // below, which still attaches the full modal payload
            // (recommendation, risk_result_exists, etc.) for the current
            // page only.
            $hasActiveInterventionByStudentId = Intervention::where('subject_id', $subject->id)
                ->where('grading_period', $gradingPeriod)
                ->whereIn('student_id', $rows->pluck('student.id'))
                ->whereIn('status', Intervention::OPEN_STATUSES)
                ->distinct()
                ->pluck('student_id')
                ->flip();

            $rows = $rows->map(function ($row) use ($hasActiveInterventionByStudentId) {
                $row['has_active_intervention'] = $hasActiveInterventionByStudentId->has($row['student']->id);
                return $row;
            });

            // TASK 4a — a VIEW, never a truth change: In-Term Status stays
            // computed fresh from evidence regardless of this filter (see
            // CLAUDE.md: "the status clears on its own when new evidence
            // lifts the learner's components above target" — it must
            // never be suppressed because an intervention exists). This
            // only shortens what the Principal sees; one click (clearing
            // the checkbox) restores the full list.
            $hideActiveIntervention = $request->boolean('hide_active_intervention');
            if ($hideActiveIntervention) {
                $rows = $rows->reject(fn($row) => $row['has_active_intervention'])->values();
            }

            [$sortKey, $sortDir] = $this->resolveSort($request, $focus);
            $rows = $this->sortRows($rows, $sortKey, $sortDir);

            // TASK 4 of "close the intervention loop" / TASK 2 of "subject
            // scoping and bulk threshold": every At Risk AND Needs
            // Attention student in the WHOLE currently filtered set, not
            // just the current page — bounded to that subset (usually a
            // small fraction of the roster), not the full filtered set the
            // way attachInterventionContext() would be. The dialog itself
            // decides (client-side, via each row's own 'status') which
            // threshold is currently in view — see
            // resources/views/principal/students.blade.php.
            $bulkCandidates = $this->buildBulkInterventionCandidates($rows, $subject, $gradingPeriod);

            $page = max(1, (int) $request->input('page', 1));
            $pageItems = $rows->forPage($page, self::PER_PAGE)->values();

            // Record Intervention modal context (Task 1 of "separate
            // intervention discovery from tracking") — batched for the
            // current page only, same "defer the expensive part to the
            // page you're actually showing" pattern as everything else in
            // this method.
            $pageItems = $this->attachInterventionContext($pageItems, $subject, $gradingPeriod);

            $students = new LengthAwarePaginator(
                $pageItems,
                $rows->count(),
                self::PER_PAGE,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        }

        return view('principal.students', [
            'subjects'      => $subjects,
            'subject'       => $subject,
            'gradingPeriod' => $gradingPeriod,
            'gradeLevels'   => $gradeLevels,
            'sections'      => $sections,
            'students'      => $students,
            'focus'         => $focus,
            'focusLabel'    => $focus ? self::COMPONENT_LABELS[$focus] : null,
            'sortKey'       => $sortKey,
            'sortDir'       => $sortDir,
            'interventionTypes' => Intervention::TYPES,
            'staleRiskTerms' => AcademicTerm::staleRiskTerms(Section::activeSchoolYear()),
            'bulkCandidates' => $bulkCandidates,
            'resolvedSection' => $resolvedSection,
            'sectionMissingTrackOrSpec' => $sectionMissingTrackOrSpec,
            'hideActiveIntervention' => $hideActiveIntervention ?? false,
        ]);
    }

    /**
     * TASK 2 of "subject scoping and bulk threshold" — every At Risk AND
     * Needs Attention row in the WHOLE currently filtered set (not just
     * the page being displayed), shaped for the bulk-record dialog.
     * Formerly At-Risk-only (see TASK 4 of "close the intervention
     * loop"); each row now carries its own 'status' so the dialog can
     * show/hide the Needs Attention rows depending on which threshold
     * the Principal has selected, WITHOUT a second server round trip —
     * "At Risk only" stays the default (see the view), this just makes
     * the wider set available if the Principal asks for it. Flags rather
     * than silently drops a student who already has an open intervention
     * for this subject: the dialog must say so, not omit them without
     * explanation.
     *
     * "The Failing layer" TASK 3c — also includes a student who is
     * FAILING (official grade <= 74) even when their In-Term Status is
     * On Track, so the new Failing-only threshold in the dialog never
     * shows an incomplete list. Such a row's 'status' is 'Failing', not
     * 'At Risk' — see storeBulk()'s per-row trigger reason, which reads
     * this same value and must never write it to the database as
     * 'At Risk'.
     *
     * "Correctness and interface pass" TASK 1a — BUG FIX: the existing-
     * intervention guard used to check (student, subject) only, ignoring
     * grading_period entirely. An intervention belongs to one subject AND
     * one term (see the migration that added grading_period), so a Term 1
     * intervention has nothing to say about whether Term 2 or Term 3 is
     * clear to record — it was wrongly blocking both forever. Every
     * candidate here is already scoped to $gradingPeriod (the term
     * selected on the page — see index()), so the guard now matches it.
     */
    private function buildBulkInterventionCandidates(Collection $rows, ?Subject $subject, int $gradingPeriod): Collection
    {
        if (!$subject) {
            return collect();
        }

        $candidateRows = $rows->filter(fn($row) =>
            in_array($row['in_term_status']['status'] ?? null, ['At Risk', 'Needs Attention'], true)
            || ($row['is_failing'] ?? false)
        )->values();

        if ($candidateRows->isEmpty()) {
            return collect();
        }

        $existingByStudentId = Intervention::where('subject_id', $subject->id)
            ->where('grading_period', $gradingPeriod)
            ->whereIn('student_id', $candidateRows->pluck('student.id'))
            ->whereIn('status', Intervention::OPEN_STATUSES)
            ->latest()
            ->get()
            ->unique('student_id')
            ->keyBy('student_id');

        return $candidateRows->map(function ($row) use ($existingByStudentId) {
            $weakest = $row['weakest_component'];

            // Failing always wins the label, even when In-Term Status is
            // also At Risk/Needs Attention for the same row — a row is
            // shown under exactly one threshold bucket, its highest one.
            $status = ($row['is_failing'] ?? false) ? 'Failing' : $row['in_term_status']['status'];

            return [
                'student_id'               => $row['student']->id,
                'student_name'             => $row['student']->last_name . ', ' . $row['student']->first_name,
                'status'                   => $status, // 'Failing', 'At Risk', or 'Needs Attention'
                'weakest_component'        => $weakest,
                'weakest_component_label'  => $weakest ? (self::COMPONENT_LABELS[$weakest] ?? $weakest) : null,
                'suggested_type'           => self::FOCUS_TO_TYPE[$weakest] ?? 'teacher_monitoring',
                'has_existing_intervention' => $existingByStudentId->has($row['student']->id),
            ];
        })->values();
    }

    /**
     * Explicit 'sort'/'dir' query params always win (a clicked column
     * header) — the focus component only supplies the DEFAULT sort when
     * neither is present, e.g. arriving fresh from a Subject Analysis
     * drill-down link. Absent BOTH, the default is In-Term Status,
     * worst first — a principal acting mid-term needs the most urgent
     * cases at the top without sorting anything (see the "live in-term
     * risk + stale data guard" prompt, Problem 2b).
     */
    private function resolveSort(Request $request, ?string $focus): array
    {
        if ($request->filled('sort')) {
            $key = $request->input('sort');
            $dir = $request->input('dir', 'asc');
        } elseif ($focus) {
            $key = $focus;
            $dir = 'asc';
        } else {
            $key = 'in_term_status';
            $dir = 'asc';
        }

        if (!in_array($key, self::SORTABLE, true)) {
            $key = 'in_term_status';
        }
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'asc';
        }

        return [$key, $dir];
    }

    /**
     * Rows with no evidence for the sort key (percentage/grade is null —
     * shown as an em dash, never a fabricated zero) are always pushed to
     * the end regardless of direction: missing data is unknown, not
     * "worse than everyone with a real number."
     */
    private function sortRows(Collection $rows, string $key, string $dir): Collection
    {
        $value = fn($row) => match ($key) {
            'name'             => $row['student']->last_name . ', ' . $row['student']->first_name,
            'computed_grade'   => $row['computed_grade'],
            'transmuted_grade' => $row['transmuted_grade'],
            // "The Failing layer" TASK 2d — Failing outranks At Risk
            // outranks Needs Attention outranks everyone else, on the
            // DEFAULT sort (this key). Always present (derived from
            // evidence/official grade, never missing the way a raw grade
            // can be) — never partitioned into $withoutValue.
            'in_term_status'   => InTermStatusService::priority($row['official_grade'] ?? null, $row['in_term_status']['status']),
            default            => $row['components'][$key]['percentage'] ?? null,
        };

        [$withValue, $withoutValue] = $rows->partition(fn($row) => $value($row) !== null);

        $sorted = $dir === 'desc' ? $withValue->sortByDesc($value) : $withValue->sortBy($value);

        return $sorted->concat($withoutValue->sortBy(fn($row) => $row['student']->last_name))->values();
    }

    /**
     * Batched, current-page-only context for the Students page's Record
     * Intervention modal (Task 1 of "separate intervention discovery from
     * tracking"): whether a risk result exists for THIS student/term
     * (drives whether a DSS recommendation can be pre-selected at all —
     * the whole point of this task is that recording must still work
     * when it doesn't), the recommendation itself when one can be
     * computed, and any already-open intervention for this student and
     * the selected subject (so the modal shows status instead of a
     * second create action).
     */
    private function attachInterventionContext(Collection $pageItems, Subject $subject, int $gradingPeriod): Collection
    {
        if ($pageItems->isEmpty()) {
            return $pageItems;
        }

        $studentIds = $pageItems->pluck('student.id');

        $riskResultsByStudent = RiskResult::whereIn('student_id', $studentIds)->get()->groupBy('student_id');

        // "Correctness and interface pass" TASK 1b — same term-scoping fix
        // as buildBulkInterventionCandidates(): $gradingPeriod was already
        // a parameter here but this query ignored it, so the modal could
        // show "already has an open intervention" for a DIFFERENT term's
        // intervention.
        $existingByStudentId = Intervention::where('subject_id', $subject->id)
            ->where('grading_period', $gradingPeriod)
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', Intervention::OPEN_STATUSES)
            ->latest()
            ->get()
            ->unique('student_id')
            ->keyBy('student_id');

        return $pageItems->map(function ($row) use ($riskResultsByStudent, $existingByStudentId, $gradingPeriod) {
            $student = $row['student'];
            $schoolYear = $row['section']->school_year;

            $history = $riskResultsByStudent->get($student->id, collect())
                ->where('school_year', $schoolYear)
                ->sortBy('grading_period')
                ->values();

            $termRisk = $history->firstWhere('grading_period', $gradingPeriod);

            $recommendation = null;
            if ($termRisk) {
                $weakestComponent = $row['weakest_component'] ? [
                    'key'        => $row['weakest_component'],
                    'status'     => $row['components'][$row['weakest_component']]['status'],
                    'percentage' => $row['components'][$row['weakest_component']]['percentage'],
                    'gap'        => $row['components'][$row['weakest_component']]['gap'],
                ] : null;

                // Shaped like DashboardAnalyticsService::getAtRiskStudentsData()'s
                // per-row array, since InterventionRecommender::recommend()
                // expects that shape — but built from THIS subject's own
                // evidence (weakest_component above), not the student's
                // overall weakest subject, since the intervention being
                // recorded here is scoped to the subject selected on this page.
                $recommendation = $this->recommender->recommend([
                    'risk_level'                => $termRisk->risk_level,
                    'weakest_subject'           => $termRisk->weakest_subject,
                    'failing_subjects'          => $termRisk->failing_subjects ?? [],
                    'consecutive_decline'       => $this->dashboardAnalytics->computeConsecutiveDecline($history),
                    'weakest_subject_component' => $weakestComponent,
                ]);
            }

            $row['risk_result_exists'] = (bool) $termRisk;
            // The classifier Risk Level — school-year-wide, term-over-term
            // trend, from the submitted term report. Shown ALONGSIDE
            // in_term_status (subject-scoped, evidence-only, available
            // before any report is submitted), never merged into one
            // value — see the "live in-term risk + stale data guard" prompt.
            $row['risk_level'] = $termRisk?->risk_level;
            $row['recommendation'] = $recommendation;
            $row['existing_intervention'] = $existingByStudentId->get($student->id);

            return $row;
        });
    }

    public function show(Request $request, Student $student)
    {
        $section = $student->section;
        $latestRisk = RiskResult::where('student_id', $student->id)->orderByDesc('grading_period')->first();
        $period = (int) $request->input('period', $latestRisk?->grading_period ?? 1);

        $subjects = $section ? Subject::forSection($section)->orderBy('type')->orderBy('name')->get() : collect();

        $subjectAnalysis = $subjects->map(function ($subject) use ($student, $section, $period) {
            $result = $this->analysis->analyzeStudent($student, $subject, $section, $period, $section->school_year);

            // The actual evidence behind the numbers — every assessment
            // item for this subject/term, with this student's score (if any).
            $evidence = Assessment::where('subject_id', $subject->id)
                ->where('section_id', $section->id)
                ->where('grading_period', $period)
                ->where('school_year', $section->school_year)
                ->with(['scores' => fn($q) => $q->where('student_id', $student->id)])
                ->orderBy('component')
                ->orderBy('name')
                ->get()
                ->map(fn($item) => [
                    'name'      => $item->name,
                    'component' => $item->component,
                    'max_score' => $item->max_score,
                    'score'     => $item->scores->first()?->score,
                    // "Workflow completion pass" TASK 3c.
                    'is_additional_support' => $item->is_additional_support,
                ]);

            return array_merge(['subject' => $subject, 'evidence' => $evidence], $result);
        });

        $riskHistory = RiskResult::where('student_id', $student->id)->orderBy('grading_period')->get();
        $interventions = Intervention::where('student_id', $student->id)->latest()->get();

        $dssStatus = $latestRisk
            ? $this->dashboardAnalytics->dssStatusLabel(
                $latestRisk->risk_level,
                $this->dashboardAnalytics->computeTrend($riskHistory),
                $this->dashboardAnalytics->computeConsecutiveDecline($riskHistory)
            )
            : null;

        return view('principal.student-detail', compact(
            'student', 'section', 'period', 'subjectAnalysis', 'riskHistory', 'interventions', 'latestRisk', 'dssStatus'
        ));
    }
}
