<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Track;
use App\Models\Specialization;
use App\Models\Subject;
use App\Models\User;
use App\Helpers\LogActivity;
use App\Http\Controllers\Concerns\SummarizesImportFailures;
use App\Http\Controllers\Concerns\ValidatesSpreadsheetUpload;
use App\Imports\SectionsImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * SectionController (Admin)
 *
 * Manages class sections — creating, updating, and deleting sections.
 * Each section is assigned to one adviser and belongs to one track/specialization.
 */
class SectionController extends Controller
{
    use SummarizesImportFailures;
    use ValidatesSpreadsheetUpload;

    /**
     * Show all sections with their related data.
     * Available advisers = advisers who are NOT yet assigned to any section.
     */
    public function index()
    {
        $sections = Section::with(['adviser', 'students', 'track', 'specialization'])
            ->orderBy('grade_level')
            ->get();

        // TASK 4 of "dashboard structure and upload safeguards" — the same
        // subject count AcademicTerm::completionStatus()/sectionCapacityBreakdown()
        // multiply against student count to decide whether a term can
        // complete, surfaced here per section so the gap is visible before
        // it silently blocks the next term. Bounded to this page's section
        // list (typically small), same cost shape as other per-row lookups
        // elsewhere in this app.
        $sections->each(function (Section $section) {
            $section->subject_count = Subject::forSection($section)->count();
        });

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

        // The "Add Section" school-year default reads this instead of a
        // hardcoded literal — see SYSTEM_FIXES_AND_ML_AUDIT.md's academic-
        // year finding. Section::activeSchoolYear() is the same resolver
        // AcademicTermController already treats as the source of truth
        // (most recent section's school_year; a computed, not hardcoded,
        // fallback only when the database has no sections at all yet).
        $activeSchoolYear = Section::activeSchoolYear();

        return view('admin.sections', compact(
            'sections', 'allAdvisers',
            'tracks', 'specializations', 'activeSchoolYear'
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
     * Bulk-import sections from an Excel/CSV file. See App\Imports\SectionsImport
     * for the expected column layout and every row-failure/warning rule —
     * same transaction-wrapped pattern as Admin\TrackController::import().
     * Unlike that import, sections are NOT idempotent (there's no natural
     * code to firstOrCreate() on), so a mid-file failure could otherwise
     * leave a partially-applied file behind; the transaction still exists
     * for that reason, even though SkipsOnFailure means a bad row is
     * skipped rather than throwing.
     */
    public function import(Request $request)
    {
        $request->validateWithBag('import', [
            'file' => $this->spreadsheetFileRule(),
        ]);

        $import = new SectionsImport();

        DB::transaction(function () use ($import, $request) {
            Excel::import($import, $request->file('file'));
        });

        $failures = $import->failures();
        $summary  = $import->importedCount . ' section(s)';

        // A warning is not an error and must not look like one — see
        // SectionsImport::$duplicateAdviserWarnings' docblock (the same
        // adviser_email on more than one row never blocks the import).
        $warnings = collect($import->duplicateAdviserWarnings)
            ->map(fn(array $names, string $email) => "\"{$email}\" is assigned to more than one section (" . implode(', ', $names) . ') — only the first will appear on that adviser\'s own dashboard.')
            ->values()
            ->all();

        if ($failures->count() > 0) {
            $result = $this->summarizeImportFailures($failures, $import);

            LogActivity::log(
                'import_sections',
                "Imported {$summary}, " . $result['skippedCount'] . ' row(s) skipped',
                'sections',
                null
            );

            return redirect()->route('admin.sections')
                ->with('warning', "{$summary} imported. " . $result['skippedCount'] . ' row(s) were skipped:')
                ->with('import_errors', $result['rowMessages'])
                ->with('import_header_hint', $result['headerHint'])
                ->with('import_warnings', $warnings);
        }

        LogActivity::log(
            'import_sections',
            "Bulk imported {$summary} via file upload",
            'sections',
            null
        );

        return redirect()->route('admin.sections')
            ->with('success', "{$summary} imported successfully!")
            ->with('import_warnings', $warnings);
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