<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Section;
use App\Imports\StudentsImport;
use Maatwebsite\Excel\Facades\Excel;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;

/**
 * StudentController (Adviser)
 *
 * Allows the adviser to view, add, and edit students in their own section.
 * Advisers CANNOT remove students — only the Admin can do that.
 * Scoped to the adviser's section for security.
 */
class StudentController extends Controller
{
    /**
     * Show all students in the adviser's section.
     */
    public function index()
    {
        // Get only the section assigned to this adviser
        $section  = Section::where('adviser_id', auth()->id())->first();
        $students = $section
            ? Student::where('section_id', $section->id)->orderBy('last_name')->get()
            : collect();

        return view('adviser.students', compact('students', 'section'));
    }

    /**
     * ADD STUDENT (new function)
     * ---------------------------
     * Runs when the adviser clicks "Save Student" in the Add Student modal.
     *
     * Steps:
     * 1. Find the section that belongs to the logged-in adviser.
     * 2. If the adviser has no section yet (edge case), stop and show an error.
     * 3. Validate the input (required fields, unique LRN, etc.).
     * 4. Save the new student using Student::create().
     * 5. Force 'section_id' to come from the server (not from the form)
     *    — this is a security measure. Even if someone tampers with the
     *    request, the new student can only ever be added to the adviser's
     *    own section.
     */
    public function store(Request $request)
    {
        // Step 1: find the adviser's own section
        $section = Section::where('adviser_id', auth()->id())->first();

        // Step 2: stop early if this adviser has no section assigned
        if (!$section) {
            return redirect()->route('adviser.students')
                ->with('error', 'No section assigned to you yet. Contact the admin.');
        }

        // Step 3: validate all incoming fields before saving anything
        $request->validate([
            'lrn'         => 'required|digits:12|unique:students,lrn', // must be unique across ALL students
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255', // optional field
            'gender'      => 'required|in:male,female',
            'birthdate'   => 'nullable|date',
        ]);

        // Step 4 & 5: create the student record
        $student = Student::create([
            'lrn'         => $request->lrn,
            'last_name'   => $request->last_name,
            'first_name'  => $request->first_name,
            'middle_name' => $request->middle_name,
            'gender'      => $request->gender,
            'birthdate'   => $request->birthdate,
            'section_id'  => $section->id, // forced from server — never trust client input for this
        ]);

        // Record this action in the Activity Logs so the Admin can see it.
        // Without this line, the "add" would happen but stay invisible in the logs.
        LogActivity::log(
            action:      'add_student',
            description: 'Added student: ' . $student->last_name . ', ' . $student->first_name,
            tableName:   'students',
            recordId:    $student->id
        );

        return redirect()->route('adviser.students')
            ->with('success', 'Student added successfully!');
    }

    /**
     * Update a student's basic information.
     * Advisers can only update personal info — not LRN or section.
     */
    public function update(Request $request, $id)
    {
        $section = Section::where('adviser_id', auth()->id())->first();

        // BUG FIX: without this check, an adviser with no assigned section
        // would crash here trying to read ->id from null.
        if (!$section) {
            return redirect()->route('adviser.students')
                ->with('error', 'No section assigned to you yet. Contact the admin.');
        }

        // Security check — ensure student belongs to adviser's section
        $student = Student::where('id', $id)
            ->where('section_id', $section->id)
            ->firstOrFail();

        $request->validate([
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'gender'      => 'required|in:male,female',
            'birthdate'   => 'nullable|date',
        ]);

        $student->update($request->only([
            'last_name', 'first_name', 'middle_name', 'gender', 'birthdate'
        ]));

        // Record this action in the Activity Logs so the Admin can see it.
        LogActivity::log(
            action:      'edit_student',
            description: 'Edited student: ' . $student->last_name . ', ' . $student->first_name,
            tableName:   'students',
            recordId:    $student->id
        );

        return redirect()->route('adviser.students')
            ->with('success', 'Student updated successfully!');
    }

    /**
 * IMPORT STUDENTS (bulk upload)
 * ------------------------------
 * Tumatanggap ng Excel/CSV file na base sa SF1-style column layout.
 * Parehong section_id restriction gaya ng manual add — kahit ilang
 * estudyante ang nasa file, palaging sa sarili lang na section
 * ng adviser sila mapupunta.
 */
public function import(Request $request)
{
    $section = Section::where('adviser_id', auth()->id())->first();

    if (!$section) {
        return redirect()->route('adviser.students')
            ->with('error', 'No section assigned to you yet. Contact the admin.');
    }

    $request->validateWithBag('import', [
        'file' => 'required|mimes:xlsx,xls,csv,txt|max:2048',
    ]);

    $import = new StudentsImport($section->id);
    Excel::import($import, $request->file('file'));

    // I-check kung may mga row na na-skip dahil sa validation errors
    $failures = $import->failures();

    if ($failures->count() > 0) {
        $errorMessages = $failures->map(function ($failure) {
            return 'Row ' . $failure->row() . ': ' . implode(', ', $failure->errors());
        })->toArray();

        LogActivity::log(
            action:      'import_students',
            description: 'Imported students (with ' . $failures->count() . ' skipped rows)',
            tableName:   'students',
            recordId:    null
        );

       return redirect()->route('adviser.students')
        ->with('warning', 'Valid rows were imported. ' . $failures->count() . ' row(s) were skipped:')
        ->with('import_errors', $errorMessages);
    }

    LogActivity::log(
        action:      'import_students',
        description: 'Bulk imported students via file upload',
        tableName:   'students',
        recordId:    null
    );

    return redirect()->route('adviser.students')
        ->with('success', 'Students imported successfully!');
}


}