<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Services\DashboardAnalyticsService;
use App\Services\TransmutationService;

/**
 * DashboardController (Principal)
 *
 * Read-only Decision Support dashboard for the Principal — same risk
 * distribution, at-risk students, and performance trends data as the
 * Admin dashboard, computed by the shared
 * DashboardAnalyticsService so the two never drift apart. The Principal
 * role has no write routes anywhere in this controller by design: grades
 * and assessment records stay adviser/admin-controlled (see the
 * 'role:principal' middleware on principal.* routes and
 * RoleAuthorizationTest for the enforcement).
 */
class DashboardController extends Controller
{
    public function __construct(private DashboardAnalyticsService $analytics = new DashboardAnalyticsService())
    {
    }

    public function index()
    {
        // "UI work order" PART 4 — moved from admin/partials in this pass:
        // this DSS analytics partial was never actually used by the Admin
        // dashboard (Admin has no DSS analytics — see CLAUDE.md), only by
        // this controller and principal/dashboard.blade.php.
        if (request()->ajax()) {
            return view('principal.partials.at-risk-results', $this->analytics->getAtRiskStudentsData());
        }

        $inTermStatusSummary = $this->analytics->getInTermStatusSummary();
        // "Correctness and interface pass" TASK 6a/6b — the term-over-term
        // trend (every term 1-3) doubles as the source for the "up/down
        // from Term N" comparison next to the current term's counts, so
        // the two can never silently disagree.
        $inTermStatusTrend = $this->analytics->getInTermStatusTrend();
        $previousTermCounts = collect($inTermStatusTrend)->firstWhere('term', $inTermStatusSummary['inTermTerm'] - 1);

        return view('principal.dashboard', array_merge(
            $this->analytics->getSummaryData(),
            $this->analytics->getAtRiskStudentsData(),
            $this->analytics->getPrincipalSummary(),
            $inTermStatusSummary,
            $this->analytics->getFailingSummary(),
            [
                // "UI modernization pass" — Component Performance chart data:
                // the mean of each subject's component average from the SAME
                // SubjectAnalysisService the Subject Analysis page uses. A
                // presentation summary of existing figures, not a new metric.
                'componentPerformance' => $this->componentPerformance(),
                // Compact "pending interventions" list for Zone 2 — the five
                // most recent rows the same Intervention::scopeUndecided()
                // rule counts on the Awaiting Your Decision card (CLAUDE.md
                // Design Decision #3), for the selected school year only.
                'pendingInterventions' => \App\Models\Intervention::undecided()
                    ->where('school_year', $this->analytics->selectedSchoolYear())
                    ->with(['student:id,last_name,first_name', 'section:id,name,grade_level', 'subject:id,name'])
                    ->latest()->take(5)->get(),
                'staleRiskTerms' => AcademicTerm::staleRiskTerms($this->analytics->selectedSchoolYear()),
                'transmutationBanner' => $this->buildTransmutationBanner(),
                'inTermStatusTrend' => $inTermStatusTrend,
                'previousTermCounts' => $previousTermCounts,
            ]
        ));
    }

    /**
     * @return array<int, array{key: string, label: string, average: float, subjects: int}>
     */
    private function componentPerformance(): array
    {
        $summaries = (new \App\Services\SubjectAnalysisService())->getSubjectSummaries($this->analytics->selectedSchoolYear());
        $labels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
        $out = [];

        foreach ($labels as $key => $label) {
            $values = collect($summaries)
                ->map(fn($row) => $row['components'][$key]['avg_percentage'] ?? null)
                ->filter(fn($v) => $v !== null);

            if ($values->isEmpty()) {
                continue;
            }

            $out[] = ['key' => $key, 'label' => $label, 'average' => round($values->avg(), 2), 'subjects' => $values->count()];
        }

        return $out;
    }

    /**
     * TASK 1 of "unblock verification" — school-wide version of the same
     * banner the Adviser dashboard shows for its one section: which
     * SHS grade level(s) (11-12; this system covers no others) currently
     * have sections actually running on a fallback transmutation scheme.
     * null when no fallback is configured or none is actually in use.
     */
    private function buildTransmutationBanner(): ?array
    {
        $fallbackScheme = config('dss.transmutation_fallback_scheme');
        if (!$fallbackScheme) {
            return null;
        }

        $schoolYear = $this->analytics->selectedSchoolYear();
        $transmutation = new TransmutationService();

        $affectedGradeLevels = collect([11, 12])
            ->filter(fn($gradeLevel) => Section::where('grade_level', $gradeLevel)->where('school_year', $schoolYear)->exists()
                && $transmutation->fallbackActiveFor($gradeLevel, $schoolYear))
            ->values();

        if ($affectedGradeLevels->isEmpty()) {
            return null;
        }

        return [
            'fallback_scheme' => $fallbackScheme,
            'grade_levels'    => $affectedGradeLevels->all(),
        ];
    }
}
