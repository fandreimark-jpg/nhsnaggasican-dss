<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Services\DashboardAnalyticsService;

/**
 * DashboardController (Principal)
 *
 * Read-only Decision Support dashboard for the Principal — same risk
 * distribution, at-risk students, performance trends, and academic honors
 * data as the Admin dashboard, computed by the shared
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
        // Shared with the Admin dashboard — see the note at the top of
        // admin/partials/at-risk-results.blade.php.
        if (request()->ajax()) {
            return view('admin.partials.at-risk-results', $this->analytics->getAtRiskStudentsData());
        }

        return view('principal.dashboard', array_merge(
            $this->analytics->getSummaryData(),
            $this->analytics->getAtRiskStudentsData()
        ));
    }
}
