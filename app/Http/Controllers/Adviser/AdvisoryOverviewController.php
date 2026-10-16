<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Subject;
use App\Services\ClassRecordService;
use App\Services\LearnerResultsService;
use App\Services\MonitoringClassificationService;
use App\Services\SubjectResultSummary;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Advisory Overview ("Subject Teacher and Class Record" pass) — the
 * adviser's read-only view of their advisory section for one school year
 * and term: every applicable subject's result per learner (official /
 * not yet official / provisional / partial / no evidence), official
 * Passed/Failed counts, the open concerns with their exact subjects and
 * requirements, the overall monitoring status, an evidence-completeness
 * indicator and each intervention's follow-up state.
 *
 * Every figure comes from an existing source: LearnerResultsService
 * (grades + GradingEngine), SubjectResultSummary (official counts) and
 * MonitoringClassificationService (open concerns — the one source of For
 * Review). Nothing here writes, and no subject's score is editable here.
 */
class AdvisoryOverviewController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $advisory = Section::where('adviser_id', $user->id)->orderByDesc('school_year')->orderBy('name')->get();
        $years = $advisory->pluck('school_year')->unique()->values();

        $request->validate([
            'school_year' => ['nullable', 'string', 'max:9'],
            'section_id'  => ['nullable', 'integer'],
            'period'      => ['nullable', 'integer', Rule::in(AcademicTerm::termNumbers())],
        ]);

        $schoolYear = $years->contains($request->input('school_year'))
            ? $request->input('school_year')
            : ($years->contains(Section::activeSchoolYear()) ? Section::activeSchoolYear() : $years->first());
        $sections = $advisory->where('school_year', $schoolYear)->values();
        $section = $request->filled('section_id')
            ? ($sections->firstWhere('id', (int) $request->input('section_id')) ?? abort(403))
            : $sections->first();

        $term = $request->filled('period')
            ? (int) $request->input('period')
            : ($section ? (AcademicTerm::currentOpenTerm($section->school_year) ?? 1) : 1);

        $rows = collect();
        $subjects = collect();
        $summary = ['learners' => 0, 'for_review' => 0, 'open_concerns' => 0];
        if ($section) {
            $students = (new ClassRecordService())->roster($section);
            $subjects = Subject::forSection($section, $term)->orderBy('type')->orderBy('name')->get();
            $results = (new LearnerResultsService())->forSection($section, $students, $subjects, $term);
            $monitoring = (new MonitoringClassificationService())->forStudents($students->pluck('id'), $section->school_year, $term);

            $grades = Grade::with('subject')->where('section_id', $section->id)->where('grading_period', $term)
                ->where('school_year', $section->school_year)->get()->groupBy('student_id');
            $interventions = Intervention::with('subject')->where('school_year', $section->school_year)
                ->where('grading_period', $term)->whereIn('student_id', $students->pluck('id'))
                ->orderByDesc('id')->get()->groupBy('student_id');

            $rows = $students->map(fn($student) => [
                'student'       => $student,
                'results'       => $results[$student->id] ?? [],
                'official'      => SubjectResultSummary::fromGrades($grades->get($student->id, collect())),
                'monitoring'    => $monitoring->get($student->id),
                'completeness'  => LearnerResultsService::completeness($results[$student->id] ?? []),
                'interventions' => $interventions->get($student->id, collect()),
            ]);

            $summary = [
                'learners'      => $students->count(),
                'for_review'    => $monitoring->filter->isForReview()->count(),
                'open_concerns' => $monitoring->sum(fn($m) => $m->openCount()),
            ];
        }

        return view('adviser.advisory-overview', [
            'years'    => $years,
            'schoolYear' => $schoolYear,
            'sections' => $sections,
            'section'  => $section,
            'term'     => $term,
            'terms'    => AcademicTerm::termNumbers(),
            'subjects' => $subjects,
            'rows'     => $rows,
            'summary'  => $summary,
        ]);
    }
}
