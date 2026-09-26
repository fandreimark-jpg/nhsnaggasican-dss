<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Services\AcademicMonitoringOverviewService;
use Illuminate\Http\Request;

/**
 * Principal "Academic Monitoring" (Phase 2D) — read-only.
 *
 * Shows the observable academic concerns the approved monitoring rules found
 * in current assessment evidence (academic_concerns), For Review by default,
 * with the evidence behind each one and the resolved history.
 *
 * A concern is not a prediction and not an intervention: this page never
 * creates, approves or links one, and it does not write review metadata —
 * what "Reviewed" means has not been decided.
 */
class AcademicMonitoringController extends Controller
{
    public function __construct(private AcademicMonitoringOverviewService $overview = new AcademicMonitoringOverviewService())
    {
    }

    public function index(Request $request)
    {
        $filters = $this->overview->filtersFrom($request);
        $schoolYear = $filters['school_year'];

        $sections = $this->overview->sectionOptions($schoolYear, $filters['grade_level']);

        return view('principal.academic-monitoring', [
            'filters'          => $filters,
            'groups'           => $this->overview->groups($filters),
            'counts'           => $this->overview->counts($filters),
            'schoolYears'      => AcademicYear::selectableSchoolYears(),
            'gradeLevels'      => $this->overview->gradeLevelOptions($schoolYear),
            'sections'         => $sections,
            'selectedSection'  => $filters['section_id'] ? $sections->firstWhere('id', $filters['section_id']) : null,
            'subjects'         => $this->overview->subjectOptions($schoolYear),
            'terms'            => AcademicTerm::termNumbers(),
            'openTerm'         => $schoolYear === Section::activeSchoolYear() ? AcademicTerm::currentOpenTerm($schoolYear) : null,
            'isHistoricalYear' => $schoolYear !== Section::activeSchoolYear(),
        ]);
    }
}
