<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Track;
use App\Models\Specialization;
use App\Models\User;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SectionController (Admin)
 *
 * Manages class sections — creating, updating, and deleting sections.
 * Each section is assigned to one adviser and belongs to one track/specialization.
 */
class SectionController extends Controller
{
    /**
     * Show all sections with their related data.
     * Available advisers = advisers who are NOT yet assigned to any section.
     */
    public function index()
    {
        $sections = Section::with(['adviser', 'students', 'track', 'specialization'])
            ->orderBy('grade_level')
            ->get();

        // All advisers, each tagged (via ->section) with whichever section
        // they're currently assigned to, if any. The single "Assign Adviser"
        // dropdown (shared by both Add and Edit) uses this full list —
        // JavaScript then filters which options are visible depending on
        // whether we're adding a new section or editing an existing one.
        $allAdvisers = User::where('role', 'adviser')
            ->with('section')
            ->orderBy('last_name')
            ->get();

        $tracks          = Track::with('specializations')->orderBy('name')->get();
        $specializations = Specialization::with('track')->orderBy('name')->get();

        return view('admin.sections', compact(
            'sections', 'allAdvisers',
            'tracks', 'specializations'
        ));
    }

    /**
     * Create a new section.
     * Adviser assignment is optional — section can exist without an adviser.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'              => 'required|string|max:255',
            'grade_level'       => 'required|in:11,12',
            'track_id'          => 'required|exists:tracks,id',
            'specialization_id' => 'required|exists:specializations,id',
            'school_year'       => 'required|string|max:20',
            // exists:users,id alone would let a non-adviser account (e.g.
            // another admin) be assigned as a section's adviser.
            'adviser_id'        => ['nullable', Rule::exists('users', 'id')->where('role', 'adviser')],
        ]);

        Section::create([
            'name'              => $request->name,
            'grade_level'       => $request->grade_level,
            'track_id'          => $request->track_id,
            'specialization_id' => $request->specialization_id,
            'school_year'       => $request->school_year,
            'adviser_id'        => $request->adviser_id ?: null,
        ]);

        LogActivity::log('create_section', 'Created section: ' . $request->name, 'sections', null);

        return redirect()->route('admin.sections')
            ->with('success', 'Section created successfully!');
    }

    /**
     * Update an existing section.
     * Checks if the selected adviser is already assigned to another section.
     */
    public function update(Request $request, $id)
    {
        $section   = Section::findOrFail($id);
        $adviserId = $request->adviser_id ?: null;

        $request->validate([
            'name'              => 'required|string|max:255',
            'grade_level'       => 'required|in:11,12',
            'track_id'          => 'required|exists:tracks,id',
            'specialization_id' => 'required|exists:specializations,id',
            'school_year'       => 'required|string|max:20',
            // exists:users,id alone would let a non-adviser account (e.g.
            // another admin) be assigned as a section's adviser.
            'adviser_id'        => ['nullable', Rule::exists('users', 'id')->where('role', 'adviser')],
        ]);

        // Prevent assigning an adviser who is already assigned to another section
        if ($adviserId) {
            $existingSection = Section::where('adviser_id', $adviserId)
                ->where('id', '!=', $id) // exclude current section from check
                ->first();

            if ($existingSection) {
                return back()->withErrors([
                    'adviser_id' => 'This adviser is already assigned to Section ' . $existingSection->name . '.'
                ])->withInput();
            }
        }

        $section->update([
            'name'              => $request->name,
            'grade_level'       => $request->grade_level,
            'track_id'          => $request->track_id,
            'specialization_id' => $request->specialization_id,
            'school_year'       => $request->school_year,
            'adviser_id'        => $request->adviser_id ?: null,
        ]);

        return redirect()->route('admin.sections')
            ->with('success', 'Section updated successfully!');
    }

    /**
     * Delete a section.
     * Cannot delete a section that still has students enrolled.
     */
    public function destroy($id)
    {
        $section = Section::findOrFail($id);

        // Prevent deletion if section has students
        if ($section->students()->count() > 0) {
            return redirect()->route('admin.sections')
                ->with('error', 'Cannot delete section with existing students.');
        }

        $section->delete();

        return redirect()->route('admin.sections')
            ->with('success', 'Section deleted successfully!');
    }
}