<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Services\SectionReportService;
use Illuminate\Http\Request;

/**
 * ReportController (Principal)
 *
 * Read-only section/grade/submission overview — identical content to
 * Admin's Reports page (CLAUDE.md doesn't call for anything
 * Principal-specific here, unlike the dashboard), reusing the same
 * view and the same SectionReportService query so the two can never
 * drift apart. No write actions anywhere in this controller.
 */
class ReportController extends Controller
{
    public function __construct(private SectionReportService $reports = new SectionReportService())
    {
    }

    public function index(Request $request)
    {
        $data = $this->reports->getFilteredSections(
            $request->input('grade_level'),
            $request->input('section_search')
        );

        return view('admin.reports', array_merge($data, ['clearRoute' => route('principal.reports')]));
    }
}
