<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Student;
use App\Models\Subject;
use App\Services\PerformanceAnalysisService;
use Illuminate\Http\Request;

/**
 * StudentController (Principal) — read-only.
 *
 * The drill-down destination CLAUDE.md describes: Dashboard -> Student
 * -> Subject -> Assessment Component -> Evidence -> DSS Analysis. Every
 * at-risk student row across the Principal dashboard and Interventions
 * page links here. For each subject the student takes, shows the same
 * PerformanceAnalysisService breakdown used elsewhere (so it can never
 * disagree with the DSS/Interventions pages) PLUS the actual individual
 * assessment items and scores behind it — the "Evidence" step, which
 * nothing before this showed at the individual-item level.
 */
class StudentController extends Controller
{
    public function __construct(private PerformanceAnalysisService $analysis = new PerformanceAnalysisService())
    {
    }

    public function show(Request $request, Student $student)
    {
        $section = $student->section;
        $latestRisk = RiskResult::where('student_id', $student->id)->orderByDesc('grading_period')->first();
        $period = (int) $request->input('period', $latestRisk?->grading_period ?? 1);

        $subjects = $section ? Subject::forSection($section)->orderBy('type')->orderBy('name')->get() : collect();

        $subjectAnalysis = $subjects->map(function ($subject) use ($student, $section, $period) {
            $result = $this->analysis->analyzeStudent($student, $subject, $section, $period, $section->school_year);

            // The actual evidence behind the numbers — every assessment
            // item for this subject/term, with this student's score (if any).
            $evidence = Assessment::where('subject_id', $subject->id)
                ->where('section_id', $section->id)
                ->where('grading_period', $period)
                ->where('school_year', $section->school_year)
                ->with(['scores' => fn($q) => $q->where('student_id', $student->id)])
                ->orderBy('component')
                ->orderBy('name')
                ->get()
                ->map(fn($item) => [
                    'name'      => $item->name,
                    'component' => $item->component,
                    'max_score' => $item->max_score,
                    'score'     => $item->scores->first()?->score,
                ]);

            return array_merge(['subject' => $subject, 'evidence' => $evidence], $result);
        });

        $riskHistory = RiskResult::where('student_id', $student->id)->orderBy('grading_period')->get();
        $interventions = Intervention::where('student_id', $student->id)->latest()->get();

        return view('principal.student-detail', compact(
            'student', 'section', 'period', 'subjectAnalysis', 'riskHistory', 'interventions', 'latestRisk'
        ));
    }
}
