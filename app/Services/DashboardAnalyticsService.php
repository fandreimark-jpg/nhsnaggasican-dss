<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The read-only Decision Support dashboard data (risk distribution, at-risk
 * students, performance trends, academic honors) — the Principal dashboard's
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
    public function __construct(private PerformanceAnalysisService $performanceAnalysis = new PerformanceAnalysisService())
    {
    }

    /**
     * Everything needed to render the main dashboard body (summary cards,
     * risk distribution, section chart, academic honors) for the currently
     * active school year.
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

        $termTrends = [];
        $allTermAvgs = Grade::selectRaw('grading_period, AVG(grade) as avg_grade')
            ->groupBy('grading_period')
            ->pluck('avg_grade', 'grading_period');

        foreach ([1, 2, 3] as $term) {
            $termTrends[] = isset($allTermAvgs[$term])
                ? round($allTermAvgs[$term], 2)
                : null;
        }

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

        $allStudentsWithRisk = Student::with(['section', 'riskResults' => fn($q) => $q->where('school_year', $schoolYear)])
            ->whereHas('riskResults', fn($q) => $q->where('school_year', $schoolYear))
            ->get()
            ->map(function ($student) {
                $latest = $student->riskResults->sortByDesc('grading_period')->first();
                return [
                    'name'    => $student->last_name . ', ' . $student->first_name,
                    'section' => $student->section->name ?? '—',
                    'average' => $latest->average_grade ?? null,
                ];
            })
            ->filter(fn($s) => $s['average'] !== null);

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
            'highestHonors'   => $allStudentsWithRisk->filter(fn($s) => $s['average'] >= 98)->values(),
            'highHonors'      => $allStudentsWithRisk->filter(fn($s) => $s['average'] >= 95 && $s['average'] < 98)->values(),
            'withHonors'      => $allStudentsWithRisk->filter(fn($s) => $s['average'] >= 90 && $s['average'] < 95)->values(),
        ];
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
            'assessment_completion' => [
                'has_data'   => $expectedScores > 0,
                'expected'   => $expectedScores,
                'actual'     => $actualScores,
                'percentage' => $expectedScores > 0 ? round(($actualScores / $expectedScores) * 100, 1) : null,
            ],
        ];
    }

    /**
     * Everything needed to render the "Students Needing Attention" widget —
     * students, filtered by the ar_grade_level / ar_section_search query
     * params if present, sorted by urgency. Shared by a normal page load
     * and an AJAX filter refresh so both compute this identically.
     */
    public function getAtRiskStudentsData(): array
    {
        $atRiskGradeLevel    = request('ar_grade_level');
        $atRiskSection       = request('ar_section_search');
        $atRiskRiskLevel     = request('ar_risk_level');
        $atRiskComponent     = request('ar_component');

        $atRiskStudents = Student::with(['section.track', 'section.specialization', 'riskResults.weakestSubject'])
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
                'weakest_subject_component' => $this->weakestSubjectComponent($student, $latestRisk),
                'failing_subjects'        => $latestRisk->failing_subjects ?? [],
                'confidence'              => $latestRisk->confidence ?? null,
                'was_overridden'          => $latestRisk->was_overridden ?? false,
                'ml_risk_level'           => $latestRisk->ml_risk_level ?? null,
                'trend'                   => $this->computeTrend($history),
                'consecutive_decline'     => $this->computeConsecutiveDecline($history),
                'subject_declines'        => $this->computeSubjectDeclines($student->id, $history),
            ];
        })
        ->filter(fn($s) => in_array($s['risk_level'], ['moderate', 'high']))
        ->when($atRiskRiskLevel, fn($rows) => $rows->where('risk_level', $atRiskRiskLevel))
        ->when($atRiskComponent, fn($rows) => $rows->filter(
            fn($s) => ($s['weakest_subject_component']['key'] ?? null) === $atRiskComponent
        ))
        ->sortBy([
            fn($s) => $s['risk_level'] === 'high' ? 0 : 1,
            fn($s) => $s['consecutive_decline'] ? 0 : 1,
            fn($s) => is_numeric($s['average']) ? $s['average'] : 999,
        ])
        ->values();

        $atRiskStudentsTotal = $atRiskStudents->count();

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
