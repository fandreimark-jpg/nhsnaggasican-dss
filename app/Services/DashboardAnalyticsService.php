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

        $latestPerStudent = RiskResult::whereIn('id',
            RiskResult::where('school_year', $schoolYear)
                ->selectRaw('MAX(id) as id')
                ->groupBy('student_id')
                ->pluck('id')
        )->get();

        $lowRisk      = $latestPerStudent->where('risk_level', 'low')->count();
        $moderateRisk = $latestPerStudent->where('risk_level', 'moderate')->count();
        $highRisk     = $latestPerStudent->where('risk_level', 'high')->count();

        // "UI legibility pass" — which term(s) the Risk Level summary above
        // is actually drawn from. Each student's contribution is their own
        // MOST RECENT submitted report, so this can legitimately be more
        // than one term if sections submit on different schedules — e.g.
        // one section's Term 2 report is in while another's Term 3 isn't
        // yet. Reported as a distinct, sorted list rather than collapsed
        // into "latest term," so the dashboard never implies more terms
        // are accounted for than actually are.
        $riskLevelTerms = $latestPerStudent->pluck('grading_period')->unique()->sort()->values()->all();

        // Sections of THIS year, and each one's risk results read through
        // risk_results.section_id (the section the learner was in when
        // classified — PART 11), not through students.section_id, which
        // moves on promotion.
        $sections = Section::where('school_year', $schoolYear)->with([
            'riskResults' => fn($q) => $q->where('school_year', $schoolYear),
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
            foreach ($section->riskResults->groupBy('student_id') as $studentResults) {
                $latest = $studentResults->sortByDesc('grading_period')->first();
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
            'schoolYear'      => $schoolYear,
            'isHistoricalYear' => $schoolYear !== Section::activeSchoolYear(),
            'schoolYears'     => \App\Models\AcademicYear::selectableSchoolYears(),
            'totalStudents'   => $totalStudents,
            'totalSections'   => $totalSections,
            'totalAdvisers'   => $totalAdvisers,
            'lowRisk'         => $lowRisk,
            'moderateRisk'    => $moderateRisk,
            'highRisk'        => $highRisk,
            'riskLevelTerms'  => $riskLevelTerms,
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
            $profile = $profileFor((int) $bucket['section_id'], (int) $bucket['subject_id']);
            if ($profile === null) {
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

        // PART 11/13 — risk results of the SELECTED school year only,
        // and each row's section is the one stored ON the risk result
        // (the learner's section when classified), so a promoted learner
        // is listed under their Grade 11 section for the Grade 11 year.
        $schoolYear = $this->selectedSchoolYear();

        $rows = Student::with([
                'riskResults' => fn($q) => $q->where('school_year', $schoolYear)->with(['weakestSubject', 'section.track', 'section.specialization']),
            ])
        ->whereHas('riskResults', function ($q) use ($schoolYear, $atRiskGradeLevel, $atRiskSection) {
            $q->where('school_year', $schoolYear)
              ->when($atRiskGradeLevel, fn($r) => $r->whereHas('section', fn($s) => $s->where('grade_level', $atRiskGradeLevel)))
              ->when($atRiskSection, fn($r) => $r->whereHas('section', fn($s) => $s->where('name', $atRiskSection)));
        })
        ->get()
        ->map(function ($student) {
            $history    = $student->riskResults->sortBy('grading_period')->values();
            $latestRisk = $history->last();
            $section    = $latestRisk?->section;

            return [
                'student_id'              => $student->id,
                'name'                    => $student->last_name . ', ' . $student->first_name,
                'section'                 => $section->name ?? '—',
                'grade_level'             => $section->grade_level ?? null,
                // Section already determines these — displayed read-only
                // next to the Section filter rather than offered as
                // separate selectable dropdowns (CLAUDE.md: Track/
                // Specialization must not be manually selectable).
                'track'                   => $section->track->name ?? null,
                'specialization'          => $section->specialization->name ?? null,
                'average'                 => $latestRisk->average_grade ?? '—',
                'risk_level'              => $latestRisk->risk_level ?? '—',
                'weakest_subject'         => $latestRisk->weakest_subject ?? null,
                'weakest_subject_grade'   => $latestRisk->weakest_subject_grade ?? null,
                'failing_subjects'        => $latestRisk->failing_subjects ?? [],
                'confidence'              => $latestRisk->confidence ?? null,
                'was_overridden'          => $latestRisk->was_overridden ?? false,
                'ml_risk_level'           => $latestRisk->ml_risk_level ?? null,
                'trend'                   => $this->computeTrend($history),
                // STEP K — whether the overall trend above compares two
                // different subject mixes; the view says so when it does.
                'subject_composition'     => $this->subjectCompositionBetween($student->id, $history),
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

        $atRiskGradeLevels = Section::where('school_year', $schoolYear)
            ->select('grade_level')
            ->distinct()
            ->orderBy('grade_level')
            ->pluck('grade_level');

        // Track/Specialization are carried as data-* attributes on each
        // <option> so the page can display them read-only the instant a
        // Section is picked, with no extra request.
        $atRiskSections = Section::where('school_year', $schoolYear)
            ->select('id', 'name', 'grade_level', 'track_id', 'specialization_id')
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
        // The section the result was generated under (PART 11), with the
        // learner's enrollment for that year as the fallback for a result
        // created before section_id existed — never the current section.
        $section = $latestRisk?->section ?? ($latestRisk ? $student->sectionFor($latestRisk->school_year) : null);

        if (!$latestRisk?->weakest_subject_id || !$latestRisk->weakestSubject || !$section) {
            return null;
        }

        $analysis = $this->performanceAnalysis->analyzeStudent(
            $student,
            $latestRisk->weakestSubject,
            $section,
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
     * could otherwise mask. A subset of computeSameSubjectTrend(): only a
     * subject graded in BOTH compared terms can decline.
     */
    public function computeSubjectDeclines(int $studentId, $history): array
    {
        $declines = array_values(array_filter(
            $this->computeSameSubjectTrend($studentId, $history),
            fn(array $row) => $row['diff'] <= -5
        ));

        usort($declines, fn($a, $b) => $a['diff'] <=> $b['diff']);

        return $declines;
    }

    /**
     * SAME-SUBJECT TREND — "Student identity and term-specific subject
     * offerings" pass, STEP K. Subjects may differ between terms, so a
     * subject-level comparison is only ever made between the SAME subject
     * graded in both of the two most recent terms on record:
     *
     *   VALID:   General Mathematics Term 1 vs General Mathematics Term 2
     *   INVALID: Effective Communication Term 1 vs Basic Calculus Term 2
     *
     * A subject present in only one of the two terms is simply not in this
     * list — see subjectCompositionBetween() for that half of the story.
     * computeTrend() (the OVERALL term trend on average_grade) is a
     * different, coarser signal and deliberately stays separate.
     *
     * @return array<int, array{subject: string, subject_id: int, from: float, to: float, diff: float}>
     */
    public function computeSameSubjectTrend(int $studentId, $history): array
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
            ->orderBy('subject_id')
            ->get();

        $rows = [];
        foreach ($currentGrades as $g) {
            $prev = $previousGrades[$g->subject_id] ?? null;
            if ($prev === null) {
                continue;
            }

            $rows[] = [
                'subject'    => $g->subject->name ?? 'Unknown',
                'subject_id' => (int) $g->subject_id,
                'from'       => (float) $prev,
                'to'         => (float) $g->grade,
                'diff'       => round((float) $g->grade - (float) $prev, 2),
            ];
        }

        return $rows;
    }

    /**
     * Did the SET of graded subjects change between the two terms the
     * overall trend compares? computeTrend() compares term averages even
     * when the subject mix differs (Term 1: General Mathematics, Effective
     * Communication; Term 2: General Mathematics, Basic Calculus) — that
     * is still a legitimate overall trend, but the interface must not
     * imply every underlying subject was identical. This says exactly
     * which subjects were shared and which were not, so every screen
     * showing a trend can say so in words.
     *
     * Null when there are fewer than two terms on record (nothing is
     * being compared).
     *
     * @return ?array{changed: bool, previous_term: int, current_term: int, shared: string[], only_previous: string[], only_current: string[]}
     */
    public function subjectCompositionBetween(int $studentId, $history): ?array
    {
        if ($history->count() < 2) {
            return null;
        }

        $n = $history->count();
        $previousResult = $history[$n - 2];
        $currentResult  = $history[$n - 1];

        $namesFor = fn($result) => Grade::where('student_id', $studentId)
            ->where('grading_period', $result->grading_period)
            ->where('school_year', $result->school_year)
            ->with('subject')
            ->get()
            ->map(fn($g) => $g->subject->name ?? 'Unknown')
            ->unique()
            ->sort()
            ->values();

        $previous = $namesFor($previousResult);
        $current  = $namesFor($currentResult);

        return [
            'changed'       => $previous->diff($current)->isNotEmpty() || $current->diff($previous)->isNotEmpty(),
            'previous_term' => (int) $previousResult->grading_period,
            'current_term'  => (int) $currentResult->grading_period,
            'shared'        => $previous->intersect($current)->values()->all(),
            'only_previous' => $previous->diff($current)->values()->all(),
            'only_current'  => $current->diff($previous)->values()->all(),
        ];
    }
}
