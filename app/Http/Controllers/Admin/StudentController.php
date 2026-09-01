<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Section;
use App\Imports\StudentsImport;
use Maatwebsite\Excel\Facades\Excel;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;

/**
 * StudentController (Admin)
 *
 * Students are master data — Admin is the only role that can add, import,
 * edit, or remove student records. Advisers only view/edit students already
 * placed in their own section (see Adviser\StudentController).
 */
class StudentController extends Controller
{
    /**
     * Show all students with optional section filter.
     * Paginated at 10 per page for performance.
     */
    public function index()
    {
        $query = Student::with(['section'])->orderBy('last_name');

        // Filter by section if selected in dropdown
        if (request('section_id')) {
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
            'file'       => 'required|mimes:xlsx,xls,csv,txt|max:2048',
        ]);

        $import = new StudentsImport((int) $request->section_id);
        Excel::import($import, $request->file('file'));

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

            return redirect()->route('admin.students')
                ->with('warning', 'Valid rows were imported. ' . $failures->count() . ' row(s) were skipped:')
                ->with('import_errors', $errorMessages);
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
}