<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Section;
use App\Http\Controllers\Concerns\SummarizesImportFailures;
use App\Http\Controllers\Concerns\ValidatesSpreadsheetUpload;
use App\Imports\StudentsImport;
use App\Services\EcrProfileDetector;
use App\Services\EcrReaderService;
use Maatwebsite\Excel\Facades\Excel;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * StudentController (Admin)
 *
 * Students are master data — Admin is the only role that can add, import,
 * edit, or remove student records. Advisers only view/edit students already
 * placed in their own section (see Adviser\StudentController).
 */
class StudentController extends Controller
{
    use SummarizesImportFailures;
    use ValidatesSpreadsheetUpload;

    /**
     * Show all students with optional section filter.
     * Paginated at 10 per page for performance.
     */
    public function index()
    {
        $query = Student::with(['section'])->orderBy('last_name');

        // "Decision flow, report scoping, and dashboard pass" TASK 4b —
        // the Admin dashboard's "learners not assigned to any section"
        // data-health figure links here with this sentinel value.
        if (request('section_id') === 'none') {
            $query->whereNull('section_id');
        } elseif (request('section_id')) {
            // Filter by section if selected in dropdown
            $query->where('section_id', request('section_id'));
        }

        $students = $query->paginate(10);
        $sections = Section::with(['track', 'specialization'])
            ->orderBy('grade_level')
            ->get();

        return view('admin.students', compact('students', 'sections'));
    }

    /**
     * Add a single new student. Unlike the (removed) adviser version, the
     * section is not implicit — Admin manages all sections, so it must be
     * chosen explicitly and is validated against the sections table.
     */
    public function store(Request $request)
    {
        $request->validate([
            'lrn'         => 'required|digits:12|unique:students,lrn',
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'gender'      => 'required|in:male,female',
            'birthdate'   => 'nullable|date',
            'section_id'  => 'required|exists:sections,id',
        ]);

        $student = Student::create($request->only([
            'lrn', 'last_name', 'first_name',
            'middle_name', 'gender', 'birthdate', 'section_id',
        ]));

        LogActivity::log(
            action:      'add_student',
            description: 'Added student: ' . $student->last_name . ', ' . $student->first_name,
            tableName:   'students',
            recordId:    $student->id
        );

        return redirect()->route('admin.students')
            ->with('success', 'Student added successfully!');
    }

    /**
     * Bulk-import students from an Excel/CSV file into a single, explicitly
     * chosen section (mirrors the removed adviser upload's design: the
     * target section is forced server-side from the form field, never read
     * from the uploaded file, so a crafted file can't redirect students
     * into a different section).
     */
    public function import(Request $request)
    {
        $request->validateWithBag('import', [
            'section_id' => 'required|exists:sections,id',
            'file'       => $this->spreadsheetFileRule(),
        ]);

        $import = new StudentsImport((int) $request->section_id);
        Excel::import($import, $request->file('file'));

        $failures = $import->failures();

        if ($failures->count() > 0) {
            $result = $this->summarizeImportFailures($failures, $import);

            LogActivity::log(
                action:      'import_students',
                description: 'Imported students (with ' . $result['skippedCount'] . ' skipped rows)',
                tableName:   'students',
                recordId:    null
            );

            return redirect()->route('admin.students')
                ->with('warning', 'Valid rows were imported. ' . $result['skippedCount'] . ' row(s) were skipped:')
                ->with('import_errors', $result['rowMessages'])
                ->with('import_header_hint', $result['headerHint']);
        }

        LogActivity::log(
            action:      'import_students',
            description: 'Bulk imported students via file upload',
            tableName:   'students',
            recordId:    null
        );

        return redirect()->route('admin.students')
            ->with('success', 'Students imported successfully!');
    }

    /**
     * Update student information.
     * LRN uniqueness check excludes the current student.
     */
    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);

        $request->validate([
            'lrn'         => 'required|digits:12|unique:students,lrn,' . $student->id,
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'gender'      => 'required|in:male,female',
            'birthdate'   => 'nullable|date',
            'section_id'  => 'required|exists:sections,id',
        ]);

        $student->update($request->only([
            'lrn', 'last_name', 'first_name',
            'middle_name', 'gender', 'birthdate', 'section_id'
        ]));

        return redirect()->route('admin.students')
            ->with('success', 'Student updated successfully!');
    }

    /**
     * Delete a student record.
     * Related grades and risk results are deleted via cascade in the database.
     */
    public function destroy($id)
    {
        $student = Student::findOrFail($id);
        $student->delete();

        LogActivity::log(
            'delete_student',
            'Removed student: ' . $student->last_name . ', ' . $student->first_name,
            'students',
            $id
        );

        return redirect()->route('admin.students')
            ->with('success', 'Student removed successfully!');
    }

    /**
     * "Draft roster from an E-Class Record" feature — reads the uploaded
     * workbook's roster and stores a DRAFT csv (session only, never the
     * database) for the admin to download, correct, and import through
     * students.import above, unchanged. No student is created here.
     *
     * Reuses the existing ECR profile detector before reading anything —
     * a file that isn't a real SSHS E-Class Record falls through with a
     * clear message instead of EcrReaderService guessing at a shape that
     * isn't there.
     *
     * Validated by EXTENSION, not `mimes:` (MIME-sniffing) — see
     * ValidatesSpreadsheetUpload and CLAUDE.md, "mimes: MIME-sniffing
     * rejects the official DepEd ECR." Content is verified for real
     * immediately after by EcrProfileDetector, which checks actual
     * workbook structure (sheet names, a marker cell, a version tag), not
     * a guess from bytes.
     */
    public function extractRosterPreview(Request $request)
    {
        $request->validateWithBag('extractRoster', [
            'file' => $this->spreadsheetFileRule(['xlsx', 'xls'], 10240),
        ]);

        $path = $request->file('file')->getRealPath();
        $originalName = $request->file('file')->getClientOriginalName();

        $version = (new EcrProfileDetector())->detect($path);
        if ($version === null) {
            return redirect()->route('admin.students')
                ->with('error', "\"{$originalName}\" does not look like an SSHS E-Class Record — profile not detected. Nothing was extracted.");
        }

        $extraction = (new EcrReaderService())->extractDraftRoster($path);

        if (empty($extraction['rows'])) {
            return redirect()->route('admin.students')
                ->with('error', "\"{$originalName}\" was recognised as an E-Class Record ({$version}), but INPUT DATA's roster is empty — nothing to extract.");
        }

        // Session only — this is a draft export, never written to the
        // database. Cleared once downloaded (see downloadRosterExtraction()).
        session([
            'roster_extraction' => [
                'rows'              => $extraction['rows'],
                'source_filename'   => $originalName,
                'skipped_empty'     => $extraction['skipped_empty'],
                'missing_lrn_count' => $extraction['missing_lrn_count'],
            ],
        ]);

        LogActivity::log(
            action:      'extract_roster',
            description: 'Extracted a draft roster (' . count($extraction['rows']) . ' row(s)) from E-Class Record: ' . $originalName,
            tableName:   'students',
            recordId:    null
        );

        return redirect()->route('admin.students')
            ->with('success', 'Draft roster extracted from "' . $originalName . '" — review the summary below and download the CSV.');
    }

    /** Streams the CSV built from the last extractRosterPreview() result, then clears it. */
    public function downloadRosterExtraction(): Response
    {
        $extraction = session('roster_extraction');
        abort_if(!$extraction, 404, 'No draft roster extraction is pending — upload an E-Class Record first.');

        $csv = "lrn,last_name,first_name,middle_name,gender,birthdate\n";
        foreach ($extraction['rows'] as $row) {
            $csv .= implode(',', array_map(function ($value) {
                $value = (string) $value;
                return str_contains($value, ',') || str_contains($value, '"')
                    ? '"' . str_replace('"', '""', $value) . '"'
                    : $value;
            }, [
                $row['lrn'], $row['last_name'], $row['first_name'],
                $row['middle_name'], $row['gender'], $row['birthdate'],
            ])) . "\n";
        }

        session()->forget('roster_extraction');

        $downloadName = 'draft_roster_' . pathinfo($extraction['source_filename'], PATHINFO_FILENAME) . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
        ]);
    }
}