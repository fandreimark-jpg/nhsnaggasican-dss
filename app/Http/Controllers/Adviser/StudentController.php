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
    /** TASK 5d of "clarity, progress, and visual design pass" — real pagination, same page size as every other paginated table in this app. */
    private const PER_PAGE = 25;

    /**
     * Show all students in the adviser's section.
     *
     * "Clarity, progress, and visual design pass" TASK 5d — this page
     * used to render the whole section roster in one unpaginated table
     * (fine for ~40 students, not for a larger section). ->withQueryString()
     * carries forward any filter this page gains in the future across
     * page changes, same convention as every other paginated table here
     * (Principal\StudentController, Principal\InterventionController).
     */
    public function index()
    {
        // Get only the section assigned to this adviser
        $section  = Section::where('adviser_id', auth()->id())->first();
        $students = $section
            ? Student::where('section_id', $section->id)->orderBy('last_name')->paginate(self::PER_PAGE)->withQueryString()
            // No section assigned — an empty paginator (not a plain
            // Collection) so the view can call ->total()/->hasPages()
            // unconditionally either way, same shape either branch.
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, self::PER_PAGE);

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