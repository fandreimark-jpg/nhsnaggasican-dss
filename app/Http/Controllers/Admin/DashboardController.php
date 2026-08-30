<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardAnalyticsService;

/**
 * DashboardController (Admin)
 *
 * Handles the Admin Dashboard — the main Decision Support System interface.
 * Shows risk distribution, performance trends, at-risk students,
 * academic honors, and intervention recommendations.
 *
 * The actual data computation lives in DashboardAnalyticsService, shared
 * with Principal\DashboardController, so the two role's dashboards can
 * never drift out of sync with each other.
 */
class DashboardController extends Controller
{
    // Defaulted (PHP 8.1+ "new in initializers") so existing unit tests that
    // do `new DashboardController()` directly, with no container resolution,
    // keep working unchanged.
    public function __construct(private DashboardAnalyticsService $analytics = new DashboardAnalyticsService())
    {
    }

    public function index()
    {
        // AJAX partial refresh — the person only changed the "Students
        // Needing Attention" filters, so there's no need to recompute
        // the whole dashboard (charts, honors, section summaries) just
        // to update a small list. This is what makes the filter feel
        // instant instead of reloading the entire page: the browser
        // fetches this in the background and only swaps out the
        // results container, not the address bar, sidebar, or charts.
        $reportRoute = route('admin.reports');

        if (request()->ajax()) {
            return view('admin.partials.at-risk-results', array_merge(
                $this->analytics->getAtRiskStudentsData(),
                compact('reportRoute')
            ));
        }

        return view('admin.dashboard', array_merge(
            $this->analytics->getSummaryData(),
            $this->analytics->getAtRiskStudentsData(),
            compact('reportRoute')
        ));
    }

    /** @deprecated Kept for direct-call test coverage — use DashboardAnalyticsService. */
    public function computeTrend($history): ?string
    {
        return $this->analytics->computeTrend($history);
    }

    /** @deprecated Kept for direct-call test coverage — use DashboardAnalyticsService. */
    public function computeConsecutiveDecline($history): bool
    {
        return $this->analytics->computeConsecutiveDecline($history);
    }
}
