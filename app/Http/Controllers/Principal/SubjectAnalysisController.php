<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Services\SubjectAnalysisService;

/**
 * SubjectAnalysisController (Principal) — read-only.
 *
 * CLAUDE.md's "Subject Analysis" and "Assessment Component Analysis"
 * areas, consolidated into one page: each subject's component averages
 * are shown as columns rather than as a separate page, since it's the
 * same underlying data at a different grouping and a second page would
 * just fragment it.
 */
class SubjectAnalysisController extends Controller
{
    public function __construct(private SubjectAnalysisService $subjectAnalysis = new SubjectAnalysisService())
    {
    }

    public function index()
    {
        return view('principal.subject-analysis', [
            'summaries' => $this->subjectAnalysis->getSubjectSummaries(),
        ]);
    }
}
