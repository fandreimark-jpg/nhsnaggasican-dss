<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SectionReportService;
use Illuminate\Http\Request;

/**
 * ReportController (Admin)
 *
 * Shows the reports page — grade overview and risk levels per section.
 * The admin can see all submitted reports from all advisers. Query
 * logic lives in SectionReportService, shared with Principal's
 * identical read-only Reports page.
 */
class ReportController extends Controller
{
    public function __construct(private SectionReportService $reports = new SectionReportService())
    {
    }

    public function index(Request $request)
    {
        // "Multi-school-year academic history" work order, PART 10 — every
        // filter (school year, term, grade, section, subject) is read and
        // validated inside the service; the Request is passed whole.
        $data = $this->reports->getFilteredSections($request);

        return view('admin.reports', array_merge($data, ['clearRoute' => route('admin.reports')]));
    }
}
