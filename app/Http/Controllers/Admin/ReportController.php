<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Section;
use Illuminate\Http\Request;

/**
 * ReportController (Admin)
 *
 * Shows the reports page — grade overview and risk levels per section.
 * The admin can see all submitted reports from all advisers.
 */
class ReportController extends Controller
{

    public function index(Request $request)
    {
        // As more grade levels and sections get added, a page that
        // always renders every section becomes a very long scroll.
        // These optional filters (grade level / section name / adviser)
        // let the admin narrow down to what they actually need to see,
        // without touching how any individual section's table looks.
        $query = Section::with([
            'adviser',
            'track',
            'specialization',
            'students.grades',       // all grades per student
            'students.riskResults',  // risk classification results per student
            'reportSubmissions',     // which terms have been submitted
        ])
        ->orderBy('grade_level');

        if ($request->filled('grade_level')) {
            $query->where('grade_level', $request->input('grade_level'));
        }

        if ($request->filled('section_search')) {
            $query->where('name', 'like', '%' . $request->input('section_search') . '%');
        }

        $sections = $query->get();

        // Distinct grade levels across ALL sections (not just the
        // filtered ones) so the dropdown always shows every option,
        // even while a filter is currently applied.
        $gradeLevels = Section::select('grade_level')
            ->distinct()
            ->orderBy('grade_level')
            ->pluck('grade_level');

        return view('admin.reports', compact('sections', 'gradeLevels'));
    }
}