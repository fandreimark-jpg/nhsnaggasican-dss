<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Services\SubjectAnalysisService;
use Illuminate\Http\Request;

/**
 * SubjectAnalysisController (Principal) — read-only.
 *
 * CLAUDE.md's "Subject Analysis" and "Assessment Component Analysis"
 * areas, consolidated into one page: each subject's component averages
 * are shown as columns rather than as a separate page, since it's the
 * same underlying data at a different grouping and a second page would
 * just fragment it. SYSTEM_FIXES_AND_ML_AUDIT.md's "Subject Analysis"
 * item added failure rate, at-risk-by-subject count, and section/term
 * filters on top of the existing component-average analysis.
 */
class SubjectAnalysisController extends Controller
{
    public function __construct(private SubjectAnalysisService $subjectAnalysis = new SubjectAnalysisService())
    {
    }

    public function index(Request $request)
    {
        // "Multi-school-year academic history" work order, PART 13 — one
        // school year at a time, the active one by default.
        $schoolYear = AcademicYear::resolveSelected($request->input('school_year'));
        $sectionId  = $request->integer('section_id') ?: null;
        $term       = $request->integer('term') ?: null;
        // "Student identity and term-specific subject offerings" pass, STEP
        // J — Grade Level joins the scope; only 11/12 are meaningful.
        $gradeLevel = in_array($request->integer('grade_level'), [11, 12], true) ? $request->integer('grade_level') : null;

        $summaries = $this->subjectAnalysis->getSubjectSummaries($schoolYear, $sectionId, $term, $gradeLevel);

        return view('principal.subject-analysis', [
            'summaries'      => $summaries,
            // Offered to the selected section in the selected term, with
            // nothing recorded yet — "offered, no evidence" is a different
            // statement from "not offered this term", and the page says which.
            'offeredWithoutData' => $this->subjectAnalysis->offeredWithoutData($schoolYear, $sectionId, $term, $summaries),
            'sections'       => Section::where('school_year', $schoolYear)
                ->when($gradeLevel, fn($q) => $q->where('grade_level', $gradeLevel))
                ->orderBy('name')->get(),
            'gradeLevels'    => Section::where('school_year', $schoolYear)->select('grade_level')->distinct()->orderBy('grade_level')->pluck('grade_level'),
            'selectedGradeLevel' => $gradeLevel,
            'selectedSection' => $sectionId,
            'selectedTerm'    => $term,
            'currentTerm'     => $schoolYear === Section::activeSchoolYear() ? AcademicTerm::currentOpenTerm($schoolYear) : null,
            'schoolYear'      => $schoolYear,
            'schoolYears'     => AcademicYear::selectableSchoolYears(),
            'isHistoricalYear' => $schoolYear !== Section::activeSchoolYear(),
        ]);
    }
}
