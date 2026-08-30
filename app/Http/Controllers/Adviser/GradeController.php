<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Section;
use App\Models\AcademicTerm;
use App\Imports\GradesImport;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * GradeController (Adviser)
 *
 * Handles grade encoding for the adviser's assigned section.
 * Advisers can only encode grades for students in their own section,
 * AND only while the selected term is the currently open term
 * (system-wide, controlled by the Admin via AcademicTermController).
 */
class GradeController extends Controller
{
    public function index()
    {
        $section = Section::where('adviser_id', auth()->id())
            ->with(['track', 'specialization'])
            ->first();

        if (!$section) {
            return view('adviser.grades', [
                'section'        => null,
                'students'       => collect(),
                'subjects'       => collect(),
                'grades'         => collect(),
                'selectedPeriod' => 1,
                'openTerm'       => null,
            ]);
        }

        $students = Student::where('section_id', $section->id)
            ->orderBy('last_name')
            ->get();

        $subjects = Subject::forSection($section)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $selectedPeriod = (int) request('period', 1);

        $grades = Grade::where('section_id', $section->id)
            ->where('grading_period', $selectedPeriod)
            ->where('school_year', $section->school_year)
            ->get()
            ->keyBy(fn($g) => $g->student_id . '_' . $g->subject_id);

        // Which term is open right now, system-wide, for this school year?
        $openTerm = AcademicTerm::currentOpenTerm($section->school_year);

        return view('adviser.grades', compact(
            'section', 'students', 'subjects', 'grades', 'selectedPeriod', 'openTerm'
        ));
    }

    public function store(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();

        $request->validate([
            'grading_period'          => 'required|in:1,2,3',
            'grades'                  => 'required|array',
            'grades.*.student_id'     => 'required|exists:students,id',
            'grades.*.subject_id'     => 'required|exists:subjects,id',
            'grades.*.grade'          => 'nullable|numeric|min:60|max:100',
        ]);

        // Server-side enforcement — never trust the disabled inputs on the
        // front end alone. Someone could re-enable them via devtools.
        if (!AcademicTerm::isOpen($section->school_year, (int) $request->grading_period)) {
            return redirect()->route('adviser.grades', ['period' => $request->grading_period])
                ->with('error', 'Term ' . $request->grading_period . ' is currently closed for encoding. Contact the admin.');
        }

        $validStudentIds = Student::where('section_id', $section->id)
            ->pluck('id')
            ->toArray();

        // Same reasoning as $validStudentIds above: 'exists:subjects,id' alone
        // only proves the subject exists SOMEWHERE, not that it's actually
        // offered to this section (grade level / track / specialization).
        // Without this, an adviser could POST a subject_id belonging to a
        // different grade level or track and have it silently accepted.
        $validSubjectIds = Subject::forSection($section)->pluck('id')->toArray();

        foreach ($request->grades as $gradeData) {
            if (!isset($gradeData['grade']) || $gradeData['grade'] === null || $gradeData['grade'] === '') {
                continue;
            }

            if (!in_array($gradeData['student_id'], $validStudentIds)) continue;
            if (!in_array($gradeData['subject_id'], $validSubjectIds)) continue;

            Grade::updateOrCreate(
                [
                    'student_id'     => $gradeData['student_id'],
                    'subject_id'     => $gradeData['subject_id'],
                    'section_id'     => $section->id,
                    'grading_period' => $request->grading_period,
                    'school_year'    => $section->school_year,
                ],
                [
                    'grade'      => $gradeData['grade'],
                    'encoded_by' => auth()->id(),
                ]
            );
        }

        LogActivity::log(
            'encode_grades',
            'Encoded grades for Term ' . $request->grading_period . ' — Section ' . $section->name,
            'grades',
            null
        );

        return redirect()
            ->route('adviser.grades', ['period' => $request->grading_period])
            ->with('success', 'Grades for Term ' . $request->grading_period . ' saved successfully!');
    }

    /**
     * Bulk import grades from an uploaded Excel/CSV file.
     * Blocked server-side if the term is not currently open — same
     * rule as the manual encoding form above.
     */
    public function importGrades(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('grading_period', 1);

        if (!AcademicTerm::isOpen($section->school_year, $gradingPeriod)) {
            return redirect()->route('adviser.grades', ['period' => $gradingPeriod])
                ->with('error', 'Term ' . $gradingPeriod . ' is currently closed for encoding. Contact the admin.');
        }

        $request->validateWithBag('gradeImport', [
            'file' => 'required|mimes:xlsx,xls,csv,txt|max:2048',
        ]);

        $subjects = Subject::forSection($section)->orderBy('type')->orderBy('name')->get();
        $students = Student::where('section_id', $section->id)->get();

        $import = new GradesImport($section->id, $gradingPeriod, $section->school_year, $subjects, $students);
        Excel::import($import, $request->file('file'));

        LogActivity::log(
            action:      'import_grades',
            description: 'Bulk imported grades for Term ' . $gradingPeriod . ' — Section ' . $section->name,
            tableName:   'grades',
            recordId:    null
        );

        if (!empty($import->errors)) {
            return redirect()->route('adviser.grades', ['period' => $gradingPeriod])
                ->with('warning', 'Imported ' . $import->importedCount . ' grade(s). ' . count($import->errors) . ' entr' . (count($import->errors) === 1 ? 'y' : 'ies') . ' were skipped:')
                ->with('import_errors', $import->errors);
        }

        return redirect()->route('adviser.grades', ['period' => $gradingPeriod])
            ->with('success', 'Successfully imported ' . $import->importedCount . ' grade(s) for Term ' . $gradingPeriod . '!');
    }

    /**
     * Downloadable template — pre-filled with this adviser's actual
     * students so they only need to type in the grade columns.
     */
    public function downloadGradeTemplate(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('period', 1);

        $subjects = Subject::forSection($section)->orderBy('type')->orderBy('name')->get();
        $students = Student::where('section_id', $section->id)->orderBy('last_name')->get();

        $headers = array_merge(['lrn', 'last_name', 'first_name'], $subjects->pluck('name')->toArray());

        return response()->streamDownload(function () use ($headers, $students, $subjects) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($students as $student) {
                $row = array_merge(
                    [$student->lrn, $student->last_name, $student->first_name],
                    array_fill(0, $subjects->count(), '') // blank grade cells to fill in
                );
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'grade_template_term_' . $gradingPeriod . '.csv');
    }
}