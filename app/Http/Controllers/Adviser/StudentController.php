<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Section;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;

/**
 * StudentController (Adviser)
 *
 * Allows the adviser to view and edit students in their own section.
 * Advisers CANNOT add, import, or remove students — those are Admin-only
 * master-data actions (see Admin\StudentController).
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
}