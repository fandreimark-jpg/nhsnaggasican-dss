<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Section;
use App\Models\Grade;
use App\Models\RiskResult;
use App\Models\User;

/**
 * DashboardController (Admin)
 *
 * Handles the Admin Dashboard — the main Decision Support System interface.
 * Shows risk distribution, performance trends, at-risk students,
 * academic honors, and intervention recommendations.
 */
class DashboardController extends Controller
{
    public function index()
    {
        // AJAX partial refresh — the person only changed the "Students
        // Needing Attention" filters, so there's no need to recompute
        // the whole dashboard (charts, honors, section summaries) just
        // to update a small list. This is what makes the filter feel
        // instant instead of reloading the entire page: the browser
        // fetches this in the background and only swaps out the
        // results container, not the address bar, sidebar, or charts.
        if (request()->ajax()) {
            return view('admin.partials.at-risk-results', $this->getAtRiskStudentsData());
        }

        // Summary counts for the top cards
        $totalStudents = Student::count();
        $totalSections = Section::count();
        $totalAdvisers = User::where('role', 'adviser')->count();

        // Get the latest risk result per student using MAX(id) grouping
        // This avoids duplicate counts when students have results for multiple terms
        $latestPerStudent = RiskResult::whereIn('id',
            RiskResult::selectRaw('MAX(id) as id')
                ->groupBy('student_id')
                ->pluck('id')
        )->get();

        // Count students per risk level from the latest results
        $lowRisk      = $latestPerStudent->where('risk_level', 'low')->count();
        $moderateRisk = $latestPerStudent->where('risk_level', 'moderate')->count();
        $highRisk     = $latestPerStudent->where('risk_level', 'high')->count();

        // Load sections with only the relationships needed for charts
        // Avoids loading unnecessary grade data for dashboard
        $sections = Section::with([
            'students.riskResults',
            'adviser',
            'track',
            'specialization',
        ])->get();

        // Performance Trend — average grade per term across all sections
        // Single query with GROUP BY instead of 3 separate queries
        $termTrends = [];
        $allTermAvgs = Grade::selectRaw('grading_period, AVG(grade) as avg_grade')
            ->groupBy('grading_period')
            ->pluck('avg_grade', 'grading_period');

        foreach ([1, 2, 3] as $term) {
            $termTrends[] = isset($allTermAvgs[$term])
                ? round($allTermAvgs[$term], 2)
                : null;
        }

        // At-risk students — only moderate and high risk, with the
        // Grade Level / Section filters applied. See getAtRiskStudentsData()
        // — the same method the AJAX branch above calls, so a normal
        // page load and a filter refresh always compute this identically.
        [
            'atRiskStudents'      => $atRiskStudents,
            'atRiskStudentsTotal' => $atRiskStudentsTotal,
            'atRiskGradeLevels'   => $atRiskGradeLevels,
            'atRiskSections'      => $atRiskSections,
        ] = $this->getAtRiskStudentsData();

        // Risk per section — used for the bar chart
        // Computed from pre-loaded data to avoid extra queries
        $sectionRiskData = $sections->map(function ($section) {
            $low = $moderate = $high = 0;
            foreach ($section->students as $student) {
                $latest = $student->riskResults->sortByDesc('grading_period')->first();
                if ($latest) {
                    match($latest->risk_level) {
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

        // Academic Honors — based on latest average_grade from risk_results
        // DepEd honors thresholds: Highest (98+), High (95-97), With Honors (90-94)
        $allStudentsWithRisk = Student::with(['section', 'riskResults'])
            ->whereHas('riskResults')
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

        $highestHonors = $allStudentsWithRisk->filter(fn($s) => $s['average'] >= 98)->values();
        $highHonors    = $allStudentsWithRisk->filter(fn($s) => $s['average'] >= 95 && $s['average'] < 98)->values();
        $withHonors    = $allStudentsWithRisk->filter(fn($s) => $s['average'] >= 90 && $s['average'] < 95)->values();

        return view('admin.dashboard', array_merge(compact(
            'totalStudents', 'totalSections', 'totalAdvisers',
            'lowRisk', 'moderateRisk', 'highRisk',
            'sections', 'termTrends', 'sectionRiskData',
            'highestHonors', 'highHonors', 'withHonors'
        ), compact(
            'atRiskStudents', 'atRiskStudentsTotal', 'atRiskGradeLevels', 'atRiskSections'
        )));
    }

    /**
     * Everything needed to render the "Students Needing Attention"
     * widget — students, filtered by the ar_grade_level / ar_section_search
     * query params if present, sorted by urgency.
     *
     * Pulled into its own method so BOTH a normal dashboard page load
     * and an AJAX filter refresh (see index() above) compute this
     * identically, from one place, instead of two copies drifting apart.
     */
    private function getAtRiskStudentsData(): array
    {
        // Grade Level / Section filters (mirrors the Reports page) —
        // once every grade level is actively submitting grades, this
        // list can get large. Filtering server-side, BEFORE the
        // trend/decline computation runs per student, keeps the page
        // fast instead of computing history for hundreds of students
        // just to show a handful.
        $atRiskGradeLevel = request('ar_grade_level');
        $atRiskSection    = request('ar_section_search');

        // IMPORTANT: filter by the student's LATEST risk_level, not by
        // whether they were EVER moderate/high in any past term. Without
        // this, a student who improved to "Low" this term (e.g. Aquino)
        // would incorrectly stay stuck in "Students Needing Attention"
        // forever just because an old term happened to be moderate/high.
        $atRiskStudents = Student::with(['section', 'riskResults'])
        ->whereHas('riskResults')
        ->when($atRiskGradeLevel, fn($q) =>
            $q->whereHas('section', fn($s) => $s->where('grade_level', $atRiskGradeLevel))
        )
        // Exact match, not "like" — the section field is now a
        // dropdown (populated from real section names), not free
        // text, so there's no partial-match typing to account for.
        ->when($atRiskSection, fn($q) =>
            $q->whereHas('section', fn($s) => $s->where('name', $atRiskSection))
        )
        ->get()
        ->map(function ($student) {
            $history    = $student->riskResults->sortBy('grading_period')->values();
            $latestRisk = $history->last();

            return [
                'name'                  => $student->last_name . ', ' . $student->first_name,
                'section'               => $student->section->name ?? '—',
                'grade_level'           => $student->section->grade_level ?? null,
                'average'               => $latestRisk->average_grade ?? '—',
                'risk_level'            => $latestRisk->risk_level ?? '—',
                'weakest_subject'       => $latestRisk->weakest_subject ?? null,
                'weakest_subject_grade' => $latestRisk->weakest_subject_grade ?? null,
                'failing_subjects'      => $latestRisk->failing_subjects ?? [],
                'confidence'            => $latestRisk->confidence ?? null,
                'was_overridden'        => $latestRisk->was_overridden ?? false,
                'ml_risk_level'         => $latestRisk->ml_risk_level ?? null,
                'trend'                 => $this->computeTrend($history),
                'consecutive_decline'   => $this->computeConsecutiveDecline($history),
                'subject_declines'      => $this->computeSubjectDeclines($student->id, $history),
            ];
        })
        // The actual "moderate/high only" filter now happens HERE,
        // against each student's latest risk_level — after it's been
        // computed — instead of against their raw historical rows.
        ->filter(fn($s) => in_array($s['risk_level'], ['moderate', 'high']))
        // Sort by urgency so the most pressing cases are always at the
        // top of the (scrollable, not paginated) list: High risk
        // first, then students in a "Watch" (2 consecutive declining
        // terms) state, then lowest average first.
        ->sortBy([
            fn($s) => $s['risk_level'] === 'high' ? 0 : 1,
            fn($s) => $s['consecutive_decline'] ? 0 : 1,
            fn($s) => is_numeric($s['average']) ? $s['average'] : 999,
        ])
        ->values();

        $atRiskStudentsTotal = $atRiskStudents->count();

        // Distinct grade levels for the filter dropdown — same source
        // list used on the Admin Reports page, so the two stay in sync.
        $atRiskGradeLevels = Section::select('grade_level')
            ->distinct()
            ->orderBy('grade_level')
            ->pluck('grade_level');

        // ALL sections with their grade level, for the cascading
        // Section dropdown — sent once on page load; the browser then
        // filters which <option>s are visible client-side whenever
        // Grade Level changes, no extra request needed for that part.
        $atRiskSections = Section::select('id', 'name', 'grade_level')
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get();

        return compact('atRiskStudents', 'atRiskStudentsTotal', 'atRiskGradeLevels', 'atRiskSections');
    }

    /**
     * Compare a student's average grade across grading periods to see
     * how their performance is actually moving — not just a single
     * snapshot. This answers the prof's concern that grades/behavior
     * change over time and the system should reflect that.
     *
     * $history = the student's riskResults, already sorted oldest → newest.
     *
     * Returns one of: 'improving', 'declining', 'stable', or null
     * (null when there's only one term on record — nothing to compare yet).
     *
     * Public so it's directly unit-testable without a full DB round trip.
     */
    public function computeTrend($history): ?string
    {
        if ($history->count() < 2) {
            return null;
        }

        $previous = $history[$history->count() - 2]->average_grade;
        $current  = $history[$history->count() - 1]->average_grade;
        $diff     = $current - $previous;

        // Small threshold (±1 point) so tiny fluctuations aren't
        // reported as a meaningful trend either way.
        if ($diff > 1) {
            return 'improving';
        }
        if ($diff < -1) {
            return 'declining';
        }
        return 'stable';
    }

    /**
     * Turns the trend from something you have to notice into something
     * the system actively flags. A student can be "Moderate" for two
     * terms in a row while still sliding downward each time — by the
     * time they hit "High Risk" it's already a crisis. This catches
     * that pattern early: two consecutive declining terms is treated
     * as a "Watch" case regardless of what the current risk_level says.
     *
     * Needs at least 3 terms on record (two term-to-term comparisons)
     * to say anything — with only 2 terms there's only one comparison,
     * which is just the regular trend, not a "consecutive" pattern yet.
     *
     * Public so it's directly unit-testable without a full DB round trip.
     */
    public function computeConsecutiveDecline($history): bool
    {
        if ($history->count() < 3) {
            return false;
        }

        $n = $history->count();

        $latestDiff = $history[$n - 1]->average_grade - $history[$n - 2]->average_grade;
        $priorDiff  = $history[$n - 2]->average_grade - $history[$n - 3]->average_grade;

        // Same ±1 noise threshold as computeTrend(), so "declining" here
        // means the same thing it means everywhere else in the system.
        return $latestDiff < -1 && $priorDiff < -1;
    }

    /**
     * The overall average can stay "stable" while one specific subject
     * is quietly getting worse — e.g. Math drops from 85 to 70 but other
     * subjects compensate so the average barely moves. This looks past
     * the average and compares each SUBJECT's grade between the two most
     * recent terms, so a subject-specific decline doesn't stay invisible.
     *
     * Returns a list of subjects that dropped 5+ points since last term,
     * worst decline first. Empty array if there's no prior term to
     * compare against, or nothing declined by more than a few points.
     */
    private function computeSubjectDeclines(int $studentId, $history): array
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
                continue; // no prior term to compare this subject against
            }

            $diff = $g->grade - $prev;

            // Same 5-point threshold used elsewhere as a "meaningful" change
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