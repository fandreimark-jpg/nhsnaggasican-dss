<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardAnalyticsService;

/**
 * DashboardController (Admin)
 *
 * The Admin Dashboard is a SYSTEM/MASTER-DATA overview only — user,
 * student, section, subject, track, and academic-term counts. It
 * deliberately shows none of the Principal's Decision Support analytics
 * (risk distribution, at-risk students, recommendations, subject/component
 * analysis) so the two dashboards can never be mistaken for each other;
 * that data lives exclusively under /principal (see
 * Principal\DashboardController).
 *
 * The actual data computation lives in DashboardAnalyticsService::getAdminSummary(),
 * so counting logic (e.g. "active school year") stays in one place even
 * though the Admin and Principal dashboards no longer share any view data.
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
        return view('admin.dashboard', array_merge(
            $this->analytics->getAdminSummary(),
            [
                'dataHealth'     => $this->analytics->getDataHealthChecks(),
                'openTermPanel'  => $this->analytics->getOpenTermPanel(),
                // WORK ORDER Part 5 — the dashboard is a glance, not the
                // log; admin.activity.logs is the full list.
                'recentActivity' => $this->analytics->getRecentActivity(5),
            ]
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
