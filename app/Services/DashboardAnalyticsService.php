<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\Track;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The read-only Decision Support dashboard data (risk distribution, at-risk
 * students, performance trends) — the Principal dashboard's
 * data source. Also provides getAdminSummary(), the deliberately separate
 * MASTER-DATA-ONLY dataset for the Admin dashboard: Admin manages system
 * configuration (users, sections, tracks, subjects...), not academic risk,
 * so the two dashboards intentionally do not share risk/DSS data — only the
 * service class, to keep the counting logic in one place.
 *
 * Everything here is read-only by construction — it only ever queries data,
 * never writes it — matching the rule that Principal may view but not
 * modify academic records.
 */
class DashboardAnalyticsService
{
    public function __construct(
        private PerformanceAnalysisService $performanceAnalysis = new PerformanceAnalysisService(),
        private InTermStatusService $inTermStatus = new InTermStatusService()
    ) {
    }

    /**
     * Everything needed to render the main dashboard body (summary cards,
     * risk distribution, section chart) for the currently active school
     * year.
     */
    public function getSummaryData(): array
    {
        $totalStudents = Student::count();
        $totalSections = Section::count();
        $totalAdvisers = User::where('role', 'adviser')->count();

        $schoolYear = Section::activeSchoolYear();

        $latestPerStudent = RiskResult::whereIn('id',
            RiskResult::where('school_year', $schoolYear)
                ->selectRaw('MAX(id) as id')
                ->groupBy('student_id')
                ->pluck('id')
        )->get();

        $lowRisk      = $latestPerStudent->where('risk_level', 'low')->count();
        $moderateRisk = $latestPerStudent->where('risk_level', 'moderate')->count();
        $highRisk     = $latestPerStudent->where('risk_level', 'high')->count();

        $sections = Section::with([
            'students.riskResults' => fn($q) => $q->where('school_year', $schoolYear),
            'adviser',
            'track',
            'specialization',
        ])->get();

        // TASK 3 of "status clarity and progress consistency" — Performance
        // Trend must render before any term report is submitted, so it can
        // no longer read from `grades.grade` (the OFFICIAL grade, which
        // only exists once an adviser manually encodes or verifies it —
        // exactly the delay this chart needs to not have). See
        // computeAssessmentEvidenceTrend() below.
        $termTrends = $this->computeAssessmentEvidenceTrend($schoolYear);

        $sectionRiskData = $sections->map(function ($section) {
            $low = $moderate = $high = 0;
            foreach ($section->students as $student) {
                $latest = $student->riskResults->sortByDesc('grading_period')->first();
                if ($latest) {
                    match ($latest->risk_level) {
                        'low'      => $low++,
                        'moderate' => $moderate++,
                        'high'     => $high++,
                        default    => null,
                    };
                }
            }
            return [
                'section'  => $section->name,
                'low'      => $low,
                'moderate' => $moderate,
                'high'     => $high,
            ];
        });

        return [
            'totalStudents'   => $totalStudents,
            'totalSections'   => $totalSections,
            'totalAdvisers'   => $totalAdvisers,
            'lowRisk'         => $lowRisk,
            'moderateRisk'    => $moderateRisk,
            'highRisk'        => $highRisk,
            'sections'        => $sections,
            'termTrends'      => $termTrends,
            'sectionRiskData' => $sectionRiskData,
        ];
    }

    /**
     * TASK 3 of "status clarity and progress consistency" — average
     * COMPUTED grade (GradingEngine's raw weighted evidence — the SAME
     * per-scheme, per-subject-group weights and the SAME "every
     * applicable component or it doesn't count" completeness rule as
     * GradingEngine::computeGrade(), reused via SubjectGroupWeight so
     * this can never silently drift from what GradingEngine itself
     * would compute) per term, across every student/subject pair with
     * complete evidence, straight from Assessment/AssessmentScore.
     * Deliberately NOT `grades.grade` (the official grade): that column
     * only fills in once an adviser manually encodes or verifies a
     * grade, which can lag well behind assessment evidence — exactly
     * the gap that trapped this chart inside the risk_results-gated
     * block it's now moved out of.
     *
     * One aggregate SQL query (grouped by student/subject/section/term/
     * component), not a GradingEngine::computeGrade() loop per student
     * per subject at whole-school scale — see the "never the whole
     * school" cost-shape rule followed everywhere else in this class.
     * Does NOT weight a subject's Examination component by exam_role
     * (see GradingEngine::examinationPercentage()) — a whole-school
     * average trend line doesn't need that per-item precision, only the
     * per-subject-group weight split and the 2-vs-3-component
     * completeness rule, both of which it does share with GradingEngine.
     *
     * @return array{0: float|null, 1: float|null, 2: float|null} indexed 0..2 for terms 1..3
     */
    private function computeAssessmentEvidenceTrend(string $schoolYear): array
    {
        $subjectGroups = Subject::pluck('subject_group', 'id');
        $sectionSchemes = Section::pluck('grade_level', 'id')
            ->map(fn($gradeLevel) => (new TransmutationService())->schemeFor((int) $gradeLevel, $schoolYear));

        // (scheme, subject_group) -> SubjectGroupWeight, resolved once per
        // distinct pair actually seen below rather than per row.
        $weightsCache = [];
        $weightsFor = function (string $scheme, ?string $subjectGroup) use (&$weightsCache) {
            $cacheKey = $scheme . '|' . ($subjectGroup ?? '');
            return $weightsCache[$cacheKey] ??= SubjectGroupWeight::resolve($scheme, $subjectGroup);
        };

        $rows = DB::table('assessment_scores')
            ->join('assessments', 'assessment_scores.assessment_id', '=', 'assessments.id')
            ->where('assessments.school_year', $schoolYear)
            ->selectRaw('assessment_scores.student_id as student_id, assessments.subject_id as subject_id, assessments.section_id as section_id, assessments.grading_period as grading_period, assessments.component as component, SUM(assessment_scores.score) as earned, SUM(assessments.max_score) as max_score')
            ->groupBy('assessment_scores.student_id', 'assessments.subject_id', 'assessments.section_id', 'assessments.grading_period', 'assessments.component')
            ->get();

        // One bucket per (student, subject, section, term) — up to 3
        // component rows each, same "missing means incomplete, not zero"
        // rule as GradingEngine: a bucket missing one of ITS subject
        // group's actually-applicable components contributes nothing
        // below (a subject group with no Examination component never
        // needs one to be "complete" — see the loop below).
        $buckets = [];
        foreach ($rows as $row) {
            $key = "{$row->student_id}|{$row->subject_id}|{$row->section_id}|{$row->grading_period}";
            $buckets[$key]['term'] = (int) $row->grading_period;
            $buckets[$key]['subject_id'] = $row->subject_id;
            $buckets[$key]['section_id'] = $row->section_id;
            $buckets[$key]['components'][$row->component] = (float) $row->max_score > 0
                ? ((float) $row->earned / (float) $row->max_score) * 100
                : null;
        }

        $sums = [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $counts = [1 => 0, 2 => 0, 3 => 0];

        foreach ($buckets as $bucket) {
            $scheme = $sectionSchemes[$bucket['section_id']] ?? TransmutationService::DEFAULT_SCHEME;
            $weights = $weightsFor($scheme, $subjectGroups[$bucket['subject_id']] ?? null);

            $weightMap = [
                'written_work'     => (float) $weights->ww_weight,
                'performance_task' => (float) $weights->pt_weight,
                'examination'      => $weights->ex_weight !== null ? (float) $weights->ex_weight : null,
            ];
            $requiredKeys = array_keys(array_filter($weightMap, fn($w) => $w !== null));

            $components = $bucket['components'];
            $hasEveryRequired = collect($requiredKeys)->every(fn($key) => ($components[$key] ?? null) !== null);
            if (!$hasEveryRequired) {
                continue;
            }

            $computedGrade = 0.0;
            foreach ($requiredKeys as $key) {
                $computedGrade += $components[$key] * $weightMap[$key] / 100;
            }

            $term = $bucket['term'];
            $sums[$term] += $computedGrade;
            $counts[$term]++;
        }

        return collect([1, 2, 3])
            ->map(fn($term) => $counts[$term] > 0 ? round($sums[$term] / $counts[$term], 2) : null)
            ->all();
    }

    /**
     * Admin-dashboard-only summary data: pure system/master-data counts —
     * no risk levels, no at-risk students, no DSS analytics. That data
     * belongs solely to the Principal (see getSummaryData/getPrincipalSummary),
     * so the two dashboards can never look alike by accident.
     */
    public function getAdminSummary(): array
    {
        $schoolYear = Section::activeSchoolYear();
        $openTerm   = AcademicTerm::currentOpenTerm($schoolYear);

        return [
            'totalUsers'            => User::count(),
            'totalStudents'         => Student::count(),
            'totalAdvisers'         => User::where('role', 'adviser')->count(),
            'totalPrincipals'       => User::where('role', 'principal')->count(),
            'totalSections'         => Section::count(),
            'totalSubjects'         => Subject::count(),
            'totalTracks'           => Track::count(),
            'totalSpecializations'  => Specialization::count(),
            'activeSchoolYear'      => $schoolYear,
            'activeTerm'            => $openTerm,
        ];
    }

    /**
     * "Decision flow, report scoping, and dashboard pass" TASK 4a — genuine
     * master-data integrity problems the Admin owns and can actually fix,
     * not decorative colour. Each check is a real blocker to the academic
     * workflow (see the table in the prompt this implements), computed
     * fresh on every dashboard load like everything else in this service —
     * no cache layer, so a fix taken elsewhere is reflected immediately.
     *
     * @return array{
     *   sectionsWithoutAdviser: \Illuminate\Support\Collection,
     *   learnersWithoutSection: \Illuminate\Support\Collection,
     *   sectionsWithNoSubjects: \Illuminate\Support\Collection,
     *   duplicateLrns: \Illuminate\Support\Collection,
     *   openTermForAssessmentCheck: ?int,
     *   subjectsWithNoAssessments: \Illuminate\Support\Collection,
     *   usersNeverLoggedIn: \Illuminate\Support\Collection,
     * }
     */
    public function getDataHealthChecks(): array
    {
        $schoolYear = Section::activeSchoolYear();
        $openTerm   = AcademicTerm::currentOpenTerm($schoolYear);

        $sections = Section::with('adviser')->orderBy('grade_level')->orderBy('name')->get();

        $sectionsWithoutAdviser = $sections->whereNull('adviser_id')->values();

        $learnersWithoutSection = Student::whereNull('section_id')
            ->orderBy('last_name')
            ->get(['id', 'last_name', 'first_name']);

        // One pass over every section's resolved subjects — feeds both
        // "sections with no subjects resolved" AND the in-use subject set
        // the next check needs, rather than querying forSection() twice.
        $sectionsWithNoSubjects = collect();
        $inUseSubjectIds = collect();
        foreach ($sections as $section) {
            $subjectIds = Subject::forSection($section)->pluck('id');
            if ($subjectIds->isEmpty()) {
                $sectionsWithNoSubjects->push($section);
            }
            $inUseSubjectIds = $inUseSubjectIds->merge($subjectIds);
        }
        $inUseSubjectIds = $inUseSubjectIds->unique()->values();

        $duplicateLrns = Student::select('lrn')
            ->whereNotNull('lrn')
            ->groupBy('lrn')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('lrn');

        $subjectsWithNoAssessments = collect();
        if ($openTerm && $inUseSubjectIds->isNotEmpty()) {
            $subjectIdsWithAssessments = Assessment::where('school_year', $schoolYear)
                ->where('grading_period', $openTerm)
                ->whereIn('subject_id', $inUseSubjectIds)
                ->distinct()
                ->pluck('subject_id');

            $subjectsWithNoAssessments = Subject::whereIn('id', $inUseSubjectIds->diff($subjectIdsWithAssessments))
                ->orderBy('name')
                ->get(['id', 'name', 'grade_level']);
        }

        $loggedInUserIds = ActivityLog::where('action', 'login')->distinct()->pluck('user_id');
        $usersNeverLoggedIn = User::whereNotIn('id', $loggedInUserIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return [
            'sectionsWithoutAdviser'     => $sectionsWithoutAdviser,
            'learnersWithoutSection'     => $learnersWithoutSection,
            'sectionsWithNoSubjects'     => $sectionsWithNoSubjects->values(),
            'duplicateLrns'              => $duplicateLrns,
            'openTermForAssessmentCheck' => $openTerm,
            'subjectsWithNoAssessments'  => $subjectsWithNoAssessments,
            'usersNeverLoggedIn'         => $usersNeverLoggedIn,
        ];
    }

    /**
     * TASK 4c — the Admin controls academic terms but had no view of
     * their effect. Reuses AcademicTerm::sectionCapacityBreakdown() (the
     * exact same expected/encoded arithmetic already shown on the Admin
     * Sections/Academic Terms pages) rather than recomputing it, plus
     * each section's submission status for the same term.
     *
     * @return array{schoolYear: string, openTerm: ?int, sections: array}
     */
    public function getOpenTermPanel(): array
    {
        $schoolYear = Section::activeSchoolYear();
        $openTerm   = AcademicTerm::currentOpenTerm($schoolYear);

        $sections = [];
        if ($openTerm) {
            $breakdown = AcademicTerm::sectionCapacityBreakdown($schoolYear, $openTerm);
            $submittedSectionIds = \App\Models\ReportSubmission::where('school_year', $schoolYear)
                ->where('grading_period', $openTerm)
                ->pluck('section_id');

            $sections = collect($breakdown)->map(function (array $row) use ($submittedSectionIds) {
                $row['submitted'] = $submittedSectionIds->contains($row['section']->id);
                return $row;
            })->all();
        }

        return [
            'schoolYear' => $schoolYear,
            'openTerm'   => $openTerm,
            'sections'   => $sections,
        ];
    }

    /**
     * TASK 4d — the last 10 activity_logs entries, exactly what
     * admin/activity-logs.blade.php's full page already displays per row,
     * just capped and eager-loaded for a compact dashboard panel.
     */
    public function getRecentActivity(int $limit = 10): \Illuminate\Support\Collection
    {
        return ActivityLog::with('user')->latest()->limit($limit)->get();
    }

    /**
     * Principal-dashboard-only summary data: intervention status counts
     * and assessment-evidence completion — the two elements CLAUDE.md's
     * Principal dashboard spec calls for ("Under Intervention",
     * "Assessment Completion") that never appeared on any dashboard
     * before. Deliberately NOT added to the Admin dashboard — this is
     * what actually differentiates the two now, rather than reusing
     * getSummaryData() and getAtRiskStudentsData() alone.
     *
     * Computed with 2 aggregate join queries (not a per-student
     * GradingEngine loop) so a whole-school dashboard load stays cheap
     * regardless of how many students/subjects exist.
     */
    public function getPrincipalSummary(): array
    {
        $schoolYear = Section::activeSchoolYear();

        $interventionCounts = Intervention::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $underIntervention = $interventionCounts->only(['approved', 'in_progress', 'monitoring'])->sum();
        $awaitingDecision   = $interventionCounts->only(['recommended', 'in_review'])->sum();
        $completedCount     = $interventionCounts->get('completed', 0);

        // Expected = one score per (assessment item, student in that item's
        // section) pair; actual = how many of those have actually been
        // scored — a single join-count each, not a loop.
        $expectedScores = DB::table('assessments')
            ->join('students', 'students.section_id', '=', 'assessments.section_id')
            ->where('assessments.school_year', $schoolYear)
            ->count();

        $actualScores = DB::table('assessment_scores')
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.school_year', $schoolYear)
            ->count();

        return [
            'under_intervention'  => $underIntervention,
            'awaiting_decision'   => $awaitingDecision,
            'completed_interventions' => $completedCount,
            // TASK 2 of "bulk dialog and intervention closure" — see
            // InTermStatusService::readyForReviewCount()'s docblock; this
            // is a signal count, never a count of anything auto-closed.
            'ready_for_review'    => $this->inTermStatus->readyForReviewCount(),
            'assessment_completion' => [
                'has_data'   => $expectedScores > 0,
                'expected'   => $expectedScores,
                'actual'     => $actualScores,
                'percentage' => $expectedScores > 0 ? round(($actualScores / $expectedScores) * 100, 1) : null,
            ],
        ];
    }

    /**
     * Worst-first severity, same convention as
     * Principal\StudentController::IN_TERM_STATUS_SEVERITY.
     */
    private const IN_TERM_STATUS_SEVERITY = ['At Risk' => 0, 'Needs Attention' => 1, 'On Track' => 2];

    /**
     * TASK 3 of "close the intervention loop": whole-school In-Term
     * Status counts (On Track / Needs Attention / At Risk) for the
     * Principal dashboard, populated the instant assessment evidence
     * exists — unlike the Risk cards above them, which stay at zero
     * until a term report is submitted.
     *
     * Deliberately NOT built by looping
     * InTermStatusService::statusFor()/PerformanceAnalysisService::analyzeStudent()
     * per student per subject — DashboardScalePerformanceTest exists
     * specifically because that N+1 shape doesn't scale to a whole
     * school (see getAtRiskStudentsData()'s docblock). Instead this runs
     * ONE aggregate query computing the exact same
     * SUM(earned)/SUM(max_score) per (student, subject, component) that
     * GradingEngine::componentPercentage() computes per-call, then
     * applies InTermStatusService::classify() — the same public
     * threshold rule the per-row pages use — so the two can never
     * silently disagree.
     *
     * A student contributes to these counts only if at least one
     * subject has actual scored evidence this term (never fabricated as
     * "On Track" for a student the school simply hasn't uploaded
     * anything for yet — same "missing means incomplete, not zero"
     * principle as everywhere else in this app).
     */
    public function getInTermStatusSummary(): array
    {
        $schoolYear = Section::activeSchoolYear();
        $term = AcademicTerm::currentOpenTerm($schoolYear) ?? 1;

        $counts = $this->computeInTermStatusCounts($schoolYear, $term);

        return [
            'inTermOnTrack'       => $counts['On Track'],
            'inTermNeedsAttention' => $counts['Needs Attention'],
            'inTermAtRisk'        => $counts['At Risk'],
            'inTermTotal'         => $counts['total'],
            'inTermTerm'          => $term,
        ];
    }

    /**
     * The per-term aggregate query getInTermStatusSummary() used to run
     * inline, factored out so "correctness and interface pass" TASK 6b's
     * getInTermStatusTrend() below can call it once per term without
     * duplicating the query/classification logic (and so the two can
     * never silently disagree on what "the count for term N" means).
     *
     * @return array{On Track: int, Needs Attention: int, At Risk: int, total: int}
     */
    private function computeInTermStatusCounts(string $schoolYear, int $term): array
    {
        $componentRows = DB::table('assessment_scores')
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.school_year', $schoolYear)
            ->where('assessments.grading_period', $term)
            ->selectRaw('assessment_scores.student_id, assessments.subject_id, assessments.component, SUM(assessment_scores.score) as earned, SUM(assessments.max_score) as max_score')
            ->groupBy('assessment_scores.student_id', 'assessments.subject_id', 'assessments.component')
            ->get();

        // [student_id][subject_id] => how many of that subject's SCORED
        // components are below target — matches
        // InTermStatusService::fromAnalysis()'s componentsBelowTarget,
        // just computed for the whole school in one query instead of one
        // analyzeStudent() call per student per subject.
        $belowTargetBySubject = [];
        foreach ($componentRows as $row) {
            if ((float) $row->max_score <= 0) {
                continue;
            }
            $percentage = round(((float) $row->earned / (float) $row->max_score) * 100, 2);

            $belowTargetBySubject[$row->student_id][$row->subject_id] ??= 0;
            if ($percentage < PerformanceAnalysisService::DEFAULT_TARGET) {
                $belowTargetBySubject[$row->student_id][$row->subject_id]++;
            }
        }

        $counts = ['On Track' => 0, 'Needs Attention' => 0, 'At Risk' => 0];
        foreach ($belowTargetBySubject as $subjects) {
            $worst = 'On Track';
            foreach ($subjects as $componentsBelowTarget) {
                $status = InTermStatusService::classify($componentsBelowTarget);
                if (self::IN_TERM_STATUS_SEVERITY[$status] < self::IN_TERM_STATUS_SEVERITY[$worst]) {
                    $worst = $status;
                }
            }
            $counts[$worst]++;
        }

        $counts['total'] = count($belowTargetBySubject);

        return $counts;
    }

    /**
     * "Correctness and interface pass" TASK 6b — "the single most useful
     * thing the dashboard is currently missing": On Track / Needs
     * Attention / At Risk counts for EACH of terms 1-3, not just the
     * currently open one, so the Principal can see whether the whole
     * school is trending better or worse across the year at a glance.
     * Three calls to computeInTermStatusCounts() (one per term) rather
     * than a single query across all terms — the school only ever has 3
     * terms, so this is 3 bounded aggregate queries, not a school-scale
     * N+1 the way looping per STUDENT would be.
     *
     * Also the source of the "up/down from Term N" comparison shown next
     * to the current term's At Risk count (TASK 6a) — both read from this
     * exact same array, so the sparkline and the inline comparison can
     * never silently disagree.
     *
     * @return array<int, array{term: int, onTrack: int, needsAttention: int, atRisk: int, total: int}>
     */
    public function getInTermStatusTrend(): array
    {
        $schoolYear = Section::activeSchoolYear();

        return collect([1, 2, 3])->map(function (int $term) use ($schoolYear) {
            $counts = $this->computeInTermStatusCounts($schoolYear, $term);
            return [
                'term'           => $term,
                'onTrack'        => $counts['On Track'],
                'needsAttention' => $counts['Needs Attention'],
                'atRisk'         => $counts['At Risk'],
                'total'          => $counts['total'],
            ];
        })->all();
    }

    /**
     * "The Failing layer" TASK 4 — whole-school count of DISTINCT students
     * with a verified, non-provisional OFFICIAL grade at or below
     * InTermStatusService::FAILING_THRESHOLD, for the currently active
     * school year and term — same scope (active school year, current
     * open term) as getInTermStatusSummary()'s sibling In-Term Status
     * tiles, so the two rows shown together on the dashboard can never
     * silently describe different years/terms. A student failing more
     * than one subject this term is still counted once, matching how
     * the In-Term Status tiles count students, not (student, subject)
     * pairs. One aggregate query — never a loop over every student's
     * every subject at whole-school scale.
     */
    public function getFailingSummary(): array
    {
        $schoolYear = Section::activeSchoolYear();
        $term = AcademicTerm::currentOpenTerm($schoolYear) ?? 1;

        $failingCount = Grade::where('school_year', $schoolYear)
            ->where('grading_period', $term)
            ->where('is_verified', true)
            ->where('is_provisional', false)
            ->where('grade', '<=', InTermStatusService::FAILING_THRESHOLD)
            ->distinct()
            ->count('student_id');

        return ['failingCount' => $failingCount];
    }

    /** Rows per page for the at-risk widget — see Task 3a in the "remaining system issues" prompt. */
    private const AT_RISK_PER_PAGE = 25;

    /**
     * Everything needed to render the "Students Needing Attention" widget —
     * students, filtered by the ar_grade_level / ar_section_search query
     * params if present, sorted by urgency. Shared by a normal page load
     * and an AJAX filter refresh so both compute this identically.
     *
     * weakest_subject_component is the expensive field here — each one
     * runs PerformanceAnalysisService::analyzeStudent(), several more
     * queries. At school scale (hundreds of at-risk students) computing
     * it for every row before paginating would still be the N+1 this
     * task exists to fix, so it's deferred until AFTER filtering/sorting
     * (which don't depend on it — see the sort keys below, none of them
     * reference it) and computed only for the current page's 25 rows.
     * The one exception is ar_component: filtering BY component is the
     * one case that genuinely needs it for the whole matching set, since
     * there's no way to know which rows match without computing it — see
     * the branch below.
     *
     * Everything else here (the base fetch, the filter/sort pipeline) is
     * left exactly as it was before this task: risk_results are not
     * scoped to the active school year and "latest" means "the row with
     * the highest grading_period among ALL of a student's risk results,
     * in whatever order Eloquent loaded them" — a pre-existing quirk,
     * not something this task changes. Reproducing that tie-break in SQL
     * to push filtering into the database was rejected as too risky
     * ("without changing any result" is the hard constraint here); this
     * fetch is unavoidable to preserve exact behavior, but it is FAR
     * cheaper than the analyzeStudent() N+1 that used to run on top of
     * it, which is where the real cost was.
     */
    public function getAtRiskStudentsData(): array
    {
        $atRiskGradeLevel    = request('ar_grade_level');
        $atRiskSection       = request('ar_section_search');
        $atRiskRiskLevel     = request('ar_risk_level');
        $atRiskComponent     = request('ar_component');
        $page                = max(1, (int) request('page', 1));

        $rows = Student::with(['section.track', 'section.specialization', 'riskResults.weakestSubject'])
        ->whereHas('riskResults')
        ->when($atRiskGradeLevel, fn($q) =>
            $q->whereHas('section', fn($s) => $s->where('grade_level', $atRiskGradeLevel))
        )
        ->when($atRiskSection, fn($q) =>
            $q->whereHas('section', fn($s) => $s->where('name', $atRiskSection))
        )
        ->get()
        ->map(function ($student) {
            $history    = $student->riskResults->sortBy('grading_period')->values();
            $latestRisk = $history->last();

            return [
                'student_id'              => $student->id,
                'name'                    => $student->last_name . ', ' . $student->first_name,
                'section'                 => $student->section->name ?? '—',
                'grade_level'             => $student->section->grade_level ?? null,
                // Section already determines these — displayed read-only
                // next to the Section filter rather than offered as
                // separate selectable dropdowns (CLAUDE.md: Track/
                // Specialization must not be manually selectable).
                'track'                   => $student->section->track->name ?? null,
                'specialization'          => $student->section->specialization->name ?? null,
                'average'                 => $latestRisk->average_grade ?? '—',
                'risk_level'              => $latestRisk->risk_level ?? '—',
                'weakest_subject'         => $latestRisk->weakest_subject ?? null,
                'weakest_subject_grade'   => $latestRisk->weakest_subject_grade ?? null,
                'failing_subjects'        => $latestRisk->failing_subjects ?? [],
                'confidence'              => $latestRisk->confidence ?? null,
                'was_overridden'          => $latestRisk->was_overridden ?? false,
                'ml_risk_level'           => $latestRisk->ml_risk_level ?? null,
                'trend'                   => $this->computeTrend($history),
                'consecutive_decline'     => $this->computeConsecutiveDecline($history),
                'subject_declines'        => $this->computeSubjectDeclines($student->id, $history),
                // Kept only long enough to compute weakest_subject_component
                // for this row (deferred — see the docblock above); never
                // present in the array this method actually returns.
                '_student'                => $student,
                '_latest_risk'            => $latestRisk,
            ];
        })
        ->filter(fn($s) => in_array($s['risk_level'], ['moderate', 'high']))
        ->when($atRiskRiskLevel, fn($rows) => $rows->where('risk_level', $atRiskRiskLevel))
        ->sortBy([
            fn($s) => $s['risk_level'] === 'high' ? 0 : 1,
            fn($s) => $s['consecutive_decline'] ? 0 : 1,
            fn($s) => is_numeric($s['average']) ? $s['average'] : 999,
        ])
        ->values();

        if ($atRiskComponent) {
            // The filter itself needs weakest_subject_component, so — for
            // this one filter only — it has to be computed for the whole
            // matching set, not just a page.
            $rows = $rows->map(fn($row) => $this->withWeakestComponent($row))
                ->filter(fn($s) => ($s['weakest_subject_component']['key'] ?? null) === $atRiskComponent)
                ->values();
        }

        $atRiskStudentsTotal = $rows->count();

        $pageItems = $rows->forPage($page, self::AT_RISK_PER_PAGE)->values();
        if (!$atRiskComponent) {
            $pageItems = $pageItems->map(fn($row) => $this->withWeakestComponent($row));
        }

        $atRiskStudents = new LengthAwarePaginator(
            $pageItems,
            $atRiskStudentsTotal,
            self::AT_RISK_PER_PAGE,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $atRiskGradeLevels = Section::select('grade_level')
            ->distinct()
            ->orderBy('grade_level')
            ->pluck('grade_level');

        // Track/Specialization are carried as data-* attributes on each
        // <option> so the page can display them read-only the instant a
        // Section is picked, with no extra request.
        $atRiskSections = Section::select('id', 'name', 'grade_level', 'track_id', 'specialization_id')
            ->with(['track:id,name', 'specialization:id,name'])
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get();

        return compact('atRiskStudents', 'atRiskStudentsTotal', 'atRiskGradeLevels', 'atRiskSections');
    }

    /**
     * Component-level evidence (P-8: DSS integration) for WHY the
     * weakest subject is the weakest — reuses PerformanceAnalysisService
     * rather than building a second, parallel DSS. Returns null whenever
     * there's no assessment evidence yet for that subject/term (an
     * average-grade-only submission, or a subject with no uploads) —
     * the existing subject/grade-based reasoning still shows on its own
     * in that case; this only ADDS detail when it's actually available.
     */
    /**
     * Fills in the deferred weakest_subject_component field for one
     * at-risk row and drops the temporary model references
     * getAtRiskStudentsData() stashed on it — see that method's docblock.
     */
    private function withWeakestComponent(array $row): array
    {
        $row['weakest_subject_component'] = $this->weakestSubjectComponent($row['_student'], $row['_latest_risk']);
        unset($row['_student'], $row['_latest_risk']);

        return $row;
    }

    private function weakestSubjectComponent(Student $student, ?RiskResult $latestRisk): ?array
    {
        if (!$latestRisk?->weakest_subject_id || !$latestRisk->weakestSubject || !$student->section) {
            return null;
        }

        $analysis = $this->performanceAnalysis->analyzeStudent(
            $student,
            $latestRisk->weakestSubject,
            $student->section,
            $latestRisk->grading_period,
            $latestRisk->school_year
        );

        if (!$analysis['weakest_component']) {
            return null;
        }

        $component = $analysis['components'][$analysis['weakest_component']];

        return [
            'key'        => $analysis['weakest_component'],
            'percentage' => $component['percentage'],
            'gap'        => $component['gap'],
            'status'     => $component['status'],
        ];
    }

    /**
     * CLAUDE.md's Principal-facing language uses 4 buckets (On Track /
     * Needs Monitoring / Needs Attention / At Risk) where the rest of the
     * app uses risk_level's 3 (low/moderate/high) — this is a PURE
     * DISPLAY mapping, never stored, never used for filtering/business
     * logic. Deliberately not a schema change: risk_level's 3-level
     * scheme is already used throughout the ML pipeline, dashboards, and
     * reports, and remapping the stored value would be a much bigger,
     * riskier change for a wording difference. 'low' risk with a
     * declining trend becomes "Needs Monitoring" rather than "On Track"
     * — an early warning that a 3-bucket badge alone wouldn't show.
     */
    public function dssStatusLabel(string $riskLevel, ?string $trend, bool $consecutiveDecline): string
    {
        return match (true) {
            $riskLevel === 'high' => 'At Risk',
            $riskLevel === 'moderate' => 'Needs Attention',
            $consecutiveDecline || $trend === 'declining' => 'Needs Monitoring',
            default => 'On Track',
        };
    }

    /**
     * Compare a student's average grade across grading periods to see how
     * their performance is actually moving — not just a single snapshot.
     *
     * $history = the student's riskResults, already sorted oldest → newest.
     *
     * Returns one of: 'improving', 'declining', 'stable', or null (null
     * when there's only one term on record — nothing to compare yet).
     */
    public function computeTrend($history): ?string
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

    /**
     * Two consecutive declining terms, regardless of the current
     * risk_level — catches a student quietly sliding downward before
     * they cross into "High Risk". Needs at least 3 terms on record.
     */
    public function computeConsecutiveDecline($history): bool
    {
        if ($history->count() < 3) {
            return false;
        }

        $n = $history->count();

        $latestDiff = $history[$n - 1]->average_grade - $history[$n - 2]->average_grade;
        $priorDiff  = $history[$n - 2]->average_grade - $history[$n - 3]->average_grade;

        return $latestDiff < -1 && $priorDiff < -1;
    }

    /**
     * Subjects that dropped 5+ points since the last term, worst decline
     * first — catches a subject-specific decline that the overall average
     * could otherwise mask.
     */
    public function computeSubjectDeclines(int $studentId, $history): array
    {
        if ($history->count() < 2) {
            return [];
        }

        $n = $history->count();
        $previousResult = $history[$n - 2];
        $currentResult  = $history[$n - 1];

        $previousGrades = Grade::where('student_id', $studentId)
            ->where('grading_period', $previousResult->grading_period)
            ->where('school_year', $previousResult->school_year)
            ->pluck('grade', 'subject_id');

        $currentGrades = Grade::where('student_id', $studentId)
            ->where('grading_period', $currentResult->grading_period)
            ->where('school_year', $currentResult->school_year)
            ->with('subject')
            ->get();

        $declines = [];

        foreach ($currentGrades as $g) {
            $prev = $previousGrades[$g->subject_id] ?? null;

            if ($prev === null) {
                continue;
            }

            $diff = $g->grade - $prev;

            if ($diff <= -5) {
                $declines[] = [
                    'subject' => $g->subject->name ?? 'Unknown',
                    'from'    => $prev,
                    'to'      => $g->grade,
                    'diff'    => $diff,
                ];
            }
        }

        usort($declines, fn($a, $b) => $a['diff'] <=> $b['diff']);

        return $declines;
    }
}
