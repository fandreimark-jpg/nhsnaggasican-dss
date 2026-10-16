<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
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
    /**
     * "Multi-school-year academic history" work order, PART 13 — the ONE
     * school year every Principal-facing figure in this class describes.
     * Defaults to the active year; a historical year is selectable via
     * the page's own ?school_year= parameter (validated by
     * AcademicYear::resolveSelected() — an unknown value falls back to the
     * active year, never to "all years"). The Admin-facing methods
     * (getAdminSummary/getDataHealthChecks/getOpenTermPanel) deliberately
     * keep reading Section::activeSchoolYear() directly — master-data
     * health is about the current year only.
     */
    public function selectedSchoolYear(): string
    {
        return \App\Models\AcademicYear::resolveSelected(request('school_year'));
    }

    public function getSummaryData(): array
    {
        $schoolYear = $this->selectedSchoolYear();

        // Scoped to the selected year (PART 13): learners ENROLLED that
        // year (student_enrollments), sections OF that year, and advisers
        // assigned to one of them — never a whole-history total under a
        // single-year label.
        $totalStudents = StudentEnrollment::where('school_year', $schoolYear)->distinct()->count('student_id');
        $totalSections = Section::where('school_year', $schoolYear)->count();
        $totalAdvisers = User::where('role', 'adviser')
            ->whereIn('id', Section::where('school_year', $schoolYear)->whereNotNull('adviser_id')->select('adviser_id'))
            ->count();

        $sections = Section::where('school_year', $schoolYear)->with(['adviser', 'track', 'specialization'])->get();

        // TASK 3 of "status clarity and progress consistency" — Performance
        // Trend must render before any term report is submitted, so it can
        // no longer read from `grades.grade` (the OFFICIAL grade, which
        // only exists once an adviser manually encodes or verifies it —
        // exactly the delay this chart needs to not have). See
        // computeAssessmentEvidenceTrend() below.
        $termTrends = $this->computeAssessmentEvidenceTrend($schoolYear);

        return [
            'schoolYear'      => $schoolYear,
            'isHistoricalYear' => $schoolYear !== Section::activeSchoolYear(),
            'schoolYears'     => \App\Models\AcademicYear::selectableSchoolYears(),
            'totalStudents'   => $totalStudents,
            'totalSections'   => $totalSections,
            'totalAdvisers'   => $totalAdvisers,
            'sections'        => $sections,
            'termTrends'      => $termTrends,
        ];
    }

    /**
     * TASK 3 of "status clarity and progress consistency" — average
     * COMPUTED grade (GradingEngine's raw weighted evidence — the SAME
     * per-scheme, per-subject-group weights and the SAME "every
     * applicable component or it doesn't count" completeness rule as
     * GradingEngine::computeGrade(), read through GradingEngine::
     * resolveWeightProfile() itself so this can never silently drift
     * from what GradingEngine would compute) per term, across every student/subject pair with
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
        // THE resolver ("Grading policy display" pass, 2026-09-20): the
        // weights each (section, subject) bucket is judged by come from
        // GradingEngine::resolveWeightProfile() — catalog row, then the
        // scheme's group, with DO 8 read from the SECTION's track — exactly
        // what computeGrade() uses. This used to call
        // SubjectGroupWeight::resolve($scheme, $subject_group) directly,
        // which skipped the catalog for Grade 11 and put every Grade 12
        // bucket on the DO 8 'all' row instead of its track bucket.
        // Resolved once per distinct (section, subject) pair, not per row.
        $engine = new GradingEngine();
        $sectionsById = Section::where('school_year', $schoolYear)->with('track')->get()->keyBy('id');
        $subjectsById = Subject::all()->keyBy('id');
        $profileCache = [];
        $profileFor = function (int $sectionId, int $subjectId) use (&$profileCache, $engine, $sectionsById, $subjectsById): ?array {
            $cacheKey = $sectionId . '|' . $subjectId;
            if (!array_key_exists($cacheKey, $profileCache)) {
                $section = $sectionsById->get($sectionId);
                $subject = $subjectsById->get($subjectId);
                try {
                    $profileCache[$cacheKey] = ($section && $subject) ? $engine->resolveWeightProfile($section, $subject) : null;
                } catch (\Throwable $e) {
                    // An unclassified subject has no weights — its buckets
                    // cannot be judged and are skipped, never defaulted.
                    $profileCache[$cacheKey] = null;
                }
            }

            return $profileCache[$cacheKey];
        };

        $rows = DB::table('assessment_scores')
            ->join('assessments', 'assessment_scores.assessment_id', '=', 'assessments.id')
            ->where('assessments.school_year', $schoolYear)
            ->selectRaw('assessment_scores.student_id as student_id, assessments.subject_id as subject_id, assessments.section_id as section_id, assessments.grading_period as grading_period, assessments.component as component, SUM(assessment_scores.score) as earned, SUM(CASE WHEN assessments.is_additional_support THEN assessments.max_score ELSE 0 END) as support_hps')
            ->groupBy('assessment_scores.student_id', 'assessments.subject_id', 'assessments.section_id', 'assessments.grading_period', 'assessments.component')
            ->get();

        // One bucket per (student, subject, section, term) — up to 3
        // component rows each, same "missing means incomplete, not zero"
        // rule as GradingEngine: a bucket missing one of ITS subject
        // group's actually-applicable components contributes nothing
        // below (a subject group with no Examination component never
        // needs one to be "complete" — see the loop below).
        // The workbook denominator (D1, 2026-10-04): the HPS of EVERY item in
        // the class/term/component, not only the items this learner was scored on.
        // Additional-support items apply only to the learners scored on them
        // (added per learner below via support_hps), never to the class total.
        $hpsTotals = DB::table('assessments')->where('school_year', $schoolYear)->where('is_additional_support', false)
            ->selectRaw('subject_id, section_id, grading_period, component, SUM(max_score) as hps')
            ->groupBy('subject_id', 'section_id', 'grading_period', 'component')->get()
            ->mapWithKeys(fn($r) => ["{$r->subject_id}|{$r->section_id}|{$r->grading_period}|{$r->component}" => (float) $r->hps]);

        $buckets = [];
        foreach ($rows as $row) {
            $key = "{$row->student_id}|{$row->subject_id}|{$row->section_id}|{$row->grading_period}";
            $hps = ($hpsTotals["{$row->subject_id}|{$row->section_id}|{$row->grading_period}|{$row->component}"] ?? 0.0) + (float) $row->support_hps;
            $buckets[$key]['term'] = (int) $row->grading_period;
            $buckets[$key]['subject_id'] = $row->subject_id;
            $buckets[$key]['section_id'] = $row->section_id;
            $buckets[$key]['components'][$row->component] = $hps > 0
                ? ((float) $row->earned / $hps) * 100
                : null;
        }

        $sums = [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $counts = [1 => 0, 2 => 0, 3 => 0];

        foreach ($buckets as $bucket) {
            $profile = $profileFor((int) $bucket['section_id'], (int) $bucket['subject_id']);
            if ($profile === null || $profile['configuration_error'] !== null) {
                continue;
            }

            $weightMap = [
                'written_work'     => $profile['ww_weight'],
                'performance_task' => $profile['pt_weight'],
                'examination'      => $profile['ex_weight'],
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
        // "Student identity and term-specific subject offerings" pass —
        // "no subjects resolved" is year-wide (any term), while the
        // in-use set for the assessment check below is the OPEN term's
        // offerings only, since a Term-1-only subject is not expected to
        // have Term 2 evidence. Sections still on the curriculum default
        // (no term-specific assignments at all) are listed separately as
        // an attention item — nothing is broken, but the section has not
        // yet been told which subjects it takes per term.
        $sectionsWithNoSubjects = collect();
        // "Subject applicability" refactor — the one per-section decision
        // left is an SSHS section's elective choice; a section whose track
        // has electives to offer but has chosen none is flagged (yellow),
        // exactly SectionElectiveStatus::isFullyConfigured()'s question.
        $sectionsAwaitingElectiveChoice = collect();
        $inUseSubjectIds = collect();
        $electiveStatus = new SectionElectiveStatus();
        foreach ($sections as $section) {
            $subjectIds = Subject::forSection($section)->pluck('id');
            if ($subjectIds->isEmpty()) {
                $sectionsWithNoSubjects->push($section);
            }
            if ($section->school_year === $schoolYear && !$electiveStatus->isFullyConfigured($section)) {
                $sectionsAwaitingElectiveChoice->push($section);
            }
            $inUseSubjectIds = $inUseSubjectIds->merge(
                $openTerm ? Subject::forSection($section, $openTerm)->pluck('id') : $subjectIds
            );
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

        // "ECR alignment" work order, PART 4b — see Subject::
        // withSuspectSubjectGroup()'s own docblock for what this flags and why.
        $subjectsWithSuspectGroup = Subject::withSuspectSubjectGroup();

        return [
            'sectionsWithoutAdviser'     => $sectionsWithoutAdviser,
            'learnersWithoutSection'     => $learnersWithoutSection,
            'sectionsWithNoSubjects'     => $sectionsWithNoSubjects->values(),
            'duplicateLrns'              => $duplicateLrns,
            'openTermForAssessmentCheck' => $openTerm,
            'subjectsWithNoAssessments'  => $subjectsWithNoAssessments,
            'usersNeverLoggedIn'         => $usersNeverLoggedIn,
            'subjectsWithSuspectGroup'   => $subjectsWithSuspectGroup,
            'sectionsAwaitingElectiveChoice' => $sectionsAwaitingElectiveChoice->values(),
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
            $submittedSectionIds = \App\Models\ReportSubmission::submitted()->where('school_year', $schoolYear)
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
        $schoolYear = $this->selectedSchoolYear();

        // PART 12/13 — intervention figures for THIS year only.
        $interventionCounts = Intervention::where('school_year', $schoolYear)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $underIntervention = $interventionCounts->only(['approved', 'in_progress', 'monitoring'])->sum();
        // "Progress column honesty and the last duplicate rule" work
        // order, PART 2 — was `only(['recommended', 'in_review'])->sum()`,
        // a second copy of Intervention::scopeUndecided() that
        // disagreed with it: 'in_review' is a DECIDED status (see
        // DECIDED_STATUSES), so counting it here as still-awaiting was
        // the bug. Now the same query-level scope the Interventions page
        // banner uses — see CLAUDE.md Design Decision #3.
        $awaitingDecision   = Intervention::undecided()->where('school_year', $schoolYear)->count();
        $completedCount     = $interventionCounts->get('completed', 0);

        // Expected = one score per (assessment item, student ENROLLED in
        // that item's section for that year) pair; actual = how many of
        // those have actually been scored — a single join-count each, not
        // a loop. Joins student_enrollments (PART 5) so a historical
        // year's completion is computed against that year's roster.
        $expectedScores = DB::table('assessments')
            ->join('student_enrollments', function ($join) {
                $join->on('student_enrollments.section_id', '=', 'assessments.section_id')
                     ->on('student_enrollments.school_year', '=', 'assessments.school_year');
            })
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
            'ready_for_review'    => $this->inTermStatus->readyForReviewCount($schoolYear),
            'assessment_completion' => [
                'has_data'   => $expectedScores > 0,
                'expected'   => $expectedScores,
                'actual'     => $actualScores,
                'percentage' => $expectedScores > 0 ? round(($actualScores / $expectedScores) * 100, 1) : null,
            ],
        ];
    }

    /**
     * TASK 3 of "close the intervention loop": whole-school In-Term
     * Status counts (On Track / Needs Attention / At Risk) for the
     * Principal dashboard, populated the instant assessment evidence
     * exists — unlike the Risk cards above them, which stay at zero
     * until a term report is submitted.
     *
     * "In-Term Status reconciliation" work order, PART 2 — this used to
     * run its OWN aggregate SQL query, a second copy of
     * InTermStatusService::fromAnalysis()'s "components below target"
     * rule that bypassed GradingEngine entirely. It agreed with the
     * per-row pages for written_work/performance_task (both are a flat
     * SUM(earned)/SUM(max_score)) but silently disagreed for examination
     * once a subject used exam roles — GradingEngine::
     * examinationPercentage() weights each role by exam_role_shares and
     * excludes no-role additional-support items, and the duplicate SQL
     * here did neither. That drift produced a real learner whose Term 3
     * status read differently on this dashboard than on the Adviser's —
     * see CLAUDE.md's account of the incident. Now calls the same
     * InTermStatusService::overallStatusForSection() the Adviser
     * dashboard calls, once per section, so the two can never diverge
     * again.
     *
     * A student contributes to these counts only if at least one
     * subject has actual scored evidence this term (never fabricated as
     * "On Track" for a student the school simply hasn't uploaded
     * anything for yet — same "missing means incomplete, not zero"
     * principle as everywhere else in this app).
     */
    public function getInTermStatusSummary(): array
    {
        $schoolYear = $this->selectedSchoolYear();
        $term = $this->displayTermFor($schoolYear);

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
     * The per-term computation getInTermStatusSummary() used to run
     * inline, factored out so "correctness and interface pass" TASK 6b's
     * getInTermStatusTrend() below can call it once per term without
     * duplicating the query/classification logic (and so the two can
     * never silently disagree on what "the count for term N" means).
     *
     * Whole-school, so it loops every section active this school year —
     * bounded by (number of sections) x (roster x subject list) calls
     * into overallStatusForSection(), the same cost shape the Adviser
     * dashboard already carries for its one section. With the school's
     * current single-section pilot this is the same query volume as
     * before; a school running many sections would see this scale
     * linearly with total enrollment, which a future optimisation could
     * revisit if it becomes a real cost — correctness came first here
     * because the alternative was two dashboards that disagree.
     *
     * @return array{On Track: int, Needs Attention: int, At Risk: int, total: int}
     */
    private function computeInTermStatusCounts(string $schoolYear, int $term): array
    {
        // "Performance audit" pass — the Principal dashboard asks for the
        // open term's counts twice per request (getInTermStatusSummary()
        // and getInTermStatusTrend()); the second answer is the first one.
        // Instance-scoped, so it never outlives the request.
        return $this->inTermStatusCounts[$schoolYear . '|' . $term] ??= $this->computeInTermStatusCountsUncached($schoolYear, $term);
    }

    /** @var array<string, array{On Track: int, Needs Attention: int, At Risk: int, total: int}> */
    private array $inTermStatusCounts = [];

    private function computeInTermStatusCountsUncached(string $schoolYear, int $term): array
    {
        $counts = ['On Track' => 0, 'Needs Attention' => 0, 'At Risk' => 0];
        $total = 0;

        $sections = Section::where('school_year', $schoolYear)->get();
        foreach ($sections as $section) {
            $subjects = Subject::forSection($section, $term)->get();
            if ($subjects->isEmpty()) {
                continue;
            }

            // PART 5/6 — the roster as enrolled THAT year, not whoever
            // currently points at this section.
            $students = $section->enrolledStudents()->get();
            if ($students->isEmpty()) {
                continue;
            }

            $rows = $this->inTermStatus->overallStatusForSection($section, $students, $subjects, $term);
            foreach ($rows as $row) {
                if ($row['in_term_status'] === null) {
                    continue; // no evidence in any subject yet — not counted
                }
                $counts[$row['in_term_status']['status']]++;
                $total++;
            }
        }

        $counts['total'] = $total;

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
        $schoolYear = $this->selectedSchoolYear();

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
        $schoolYear = $this->selectedSchoolYear();
        $term = $this->displayTermFor($schoolYear);

        $failingCount = Grade::where('school_year', $schoolYear)
            ->where('grading_period', $term)
            ->where('is_verified', true)
            ->where('is_provisional', false)
            ->where('grade', '<=', InTermStatusService::FAILING_THRESHOLD)
            ->distinct()
            ->count('student_id');

        return ['failingCount' => $failingCount];
    }

    /**
     * The term the dashboard's current-term tiles describe: the open
     * term for the active year; for a historical (completed) year, which
     * has no open term, the LAST term that has any evidence, so the
     * tiles describe the year as it ended rather than defaulting to
     * Term 1.
     */
    private function displayTermFor(string $schoolYear): int
    {
        if ($schoolYear === Section::activeSchoolYear()) {
            return AcademicTerm::currentOpenTerm($schoolYear) ?? 1;
        }

        $lastWithEvidence = DB::table('assessments')->where('school_year', $schoolYear)->max('grading_period')
            ?? Grade::where('school_year', $schoolYear)->max('grading_period');

        return (int) ($lastWithEvidence ?: (AcademicTerm::currentOpenTerm($schoolYear) ?? 1));
    }
}
