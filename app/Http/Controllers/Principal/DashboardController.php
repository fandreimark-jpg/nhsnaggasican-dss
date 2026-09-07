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
                'staleRiskTerms' => AcademicTerm::staleRiskTerms(Section::activeSchoolYear()),
                'transmutationBanner' => $this->buildTransmutationBanner(),
                'inTermStatusTrend' => $inTermStatusTrend,
                'previousTermCounts' => $previousTermCounts,
            ]
        ));
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

        $schoolYear = Section::activeSchoolYear();
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
