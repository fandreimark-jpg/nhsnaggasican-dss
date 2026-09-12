<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\Track;
use App\Models\Specialization;
use App\Http\Controllers\Concerns\SummarizesImportFailures;
use App\Http\Controllers\Concerns\ValidatesSpreadsheetUpload;
use App\Imports\SubjectsImport;
use Maatwebsite\Excel\Facades\Excel;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SubjectController (Admin)
 *
 * Manages SHS subjects — both core and elective.
 * Core subjects apply to all sections of a grade level.
 * Elective subjects are tied to a specific track and optionally a specialization.
 */
class SubjectController extends Controller
{
    use SummarizesImportFailures;
    use ValidatesSpreadsheetUpload;

    /**
     * Which subject_group a subject can be assigned — read from
     * subject_group_weights itself rather than hardcoded, so a future
     * scheme's different group list is a seeded row, never a code
     * change. Scoped to 'do015_2026': the five do8_* rows are resolved
     * automatically by GradingEngine::resolveDo8GroupKey() from a
     * section's track and a subject's type, never chosen by a human — a
     * subject's own subject_group column isn't even read for the
     * do8_2015 scheme. Offering them here would let an admin put DO 8
     * weights on a DO 015 subject, silently. 'all' is also excluded:
     * that's do8_2015's scheme-wide fallback bucket, never a real group
     * a subject is actually assigned.
     */
    private function availableSubjectGroups()
    {
        return SubjectGroupWeight::where('scheme', 'do015_2026')
            ->where('subject_group', '!=', 'all')
            ->distinct()
            ->orderBy('subject_group')
            ->pluck('subject_group');
    }

    /**
     * Show all subjects with optional type filter (core/elective), or
     * filtered to the "may have the wrong grading weight" set the Admin
     * dashboard's Data Health check links to — see Subject::
     * withSuspectSubjectGroup() ("ECR alignment" work order, PART 4b), the
     * one place that query exists so this filter and the dashboard count
     * can never drift apart.
     * URL: /admin/subjects?type=core or ?type=elective or ?subject_group_check=1
     */
    public function index()
    {
        $subjects = Subject::with(['track', 'specialization', 'catalog'])
            ->when(request('type'), fn($q) => $q->where('type', request('type')))
            ->when(request('subject_group_check'), fn($q) => $q->whereIn('id', Subject::withSuspectSubjectGroup()->pluck('id')))
            ->orderBy('grade_level')
            ->orderBy('type') // core subjects first
            ->orderBy('name')
            ->get();

        // SYSTEM_FIXES_AND_ML_AUDIT.md, "Grading details per subject" —
        // resolved the same way GradingEngine actually resolves it (catalog
        // row wins over subject_group, per CLAUDE.md's resolution order),
        // not a second copy of the arithmetic, so this can never drift from
        // what a grade actually computes to. Grade 12 (do8_2015) is
        // deliberately NOT resolved to a number here: that scheme weights by
        // a SECTION's track, not by subject_group at all (subject_group
        // isn't even read for do8_2015 — see GradingEngine::
        // resolveDo8GroupKey()), so guessing one here would show a
        // plausible-looking percentage that may not be what any given
        // section's grade actually uses.
        $subjects->each(function (Subject $subject) {
            if ($subject->catalog) {
                $subject->grading_weights_display = [
                    'source' => 'catalog', 'ww' => $subject->catalog->ww_weight,
                    'pt' => $subject->catalog->pt_weight, 'ex' => $subject->catalog->ex_weight,
                ];
                return;
            }

            if ($subject->grade_level == 11) {
                try {
                    $w = SubjectGroupWeight::resolve('do015_2026', $subject->subject_group);
                    $subject->grading_weights_display = [
                        'source' => 'subject_group', 'ww' => $w->ww_weight, 'pt' => $w->pt_weight, 'ex' => $w->ex_weight,
                    ];
                } catch (\Throwable $e) {
                    $subject->grading_weights_display = null;
                }
                return;
            }

            // Grade 12 / do8_2015 — see note above.
            $subject->grading_weights_display = ['source' => 'do8_by_track'];
        });

        $tracks          = Track::with('specializations')->orderBy('name')->get();
        $specializations = Specialization::with('track')->orderBy('name')->get();
        $subjectGroups   = $this->availableSubjectGroups();

        return view('admin.subjects', compact('subjects', 'tracks', 'specializations', 'subjectGroups'));
    }

    /**
     * Create a new subject.
     * Track and specialization are only saved for elective subjects.
     * Core subjects have null track_id and specialization_id.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'              => 'required|string|max:255',
            'type'              => 'required|in:core,elective',
            'grade_level'       => 'required|in:11,12',
            'subject_group'     => ['required', Rule::in($this->availableSubjectGroups())],
            'track_id'          => 'nullable|exists:tracks,id',
            'specialization_id' => 'nullable|exists:specializations,id',
        ]);

        Subject::create([
            'name'              => $request->name,
            'type'              => $request->type,
            'grade_level'       => $request->grade_level,
            'subject_group'     => $request->subject_group,
            // Only elective subjects have track/specialization
            'track_id'          => $request->type === 'elective' ? $request->track_id : null,
            'specialization_id' => $request->type === 'elective' ? $request->specialization_id : null,
        ]);

        LogActivity::log(
            'create_subject',
            'Created ' . $request->role . ' subjects: ' . $request->name,
            'subjects',
            null
        );

        return redirect()->route('admin.subjects')
            ->with('success', 'Subject added successfully!');
    }

    /**
     * Bulk-import subjects from an Excel/CSV file so Admin doesn't have to
     * manually encode every subject. See App\Imports\SubjectsImport for the
     * expected column layout and validation/duplicate rules.
     */
    public function import(Request $request)
    {
        $request->validateWithBag('import', [
            'grade_level' => 'required|in:11,12',
            'file'        => $this->spreadsheetFileRule(),
        ]);

        $import = new SubjectsImport((int) $request->grade_level);
        Excel::import($import, $request->file('file'));

        $failures = $import->failures();

        // "ECR alignment" work order, PART 4a — every row whose
        // subject_group cell was blank/absent fell back to core_academic
        // silently; report it by name via the import-result panel's
        // existing (previously unused) import_warnings notice channel
        // rather than leaving the fallback invisible. Set on both branches
        // below — a row can default its group and still import cleanly.
        $importWarnings = [];
        if (!empty($import->defaultedSubjectGroupNames)) {
            $importWarnings[] = count($import->defaultedSubjectGroupNames) . ' subject(s) had no subject_group in the file and defaulted to Core Academic (20/50/30): '
                . implode(', ', $import->defaultedSubjectGroupNames) . '.';
        }

        if ($failures->count() > 0) {
            $result = $this->summarizeImportFailures($failures, $import);

            LogActivity::log(
                'import_subjects',
                'Imported ' . $import->importedCount . ' subject(s), ' . $result['skippedCount'] . ' row(s) skipped',
                'subjects',
                null
            );

            return redirect()->route('admin.subjects')
                ->with('warning', $import->importedCount . ' subject(s) imported. ' . $result['skippedCount'] . ' row(s) were skipped:')
                ->with('import_errors', $result['rowMessages'])
                ->with('import_header_hint', $result['headerHint'])
                ->with('import_warnings', $importWarnings);
        }

        LogActivity::log(
            'import_subjects',
            'Bulk imported ' . $import->importedCount . ' subject(s) via file upload',
            'subjects',
            null
        );

        return redirect()->route('admin.subjects')
            ->with('success', $import->importedCount . ' subject(s) imported successfully!')
            ->with('import_warnings', $importWarnings);
    }

    /** Update an existing subject. */
    public function update(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        $request->validate([
            'name'              => 'required|string|max:255',
            'type'              => 'required|in:core,elective',
            'grade_level'       => 'required|in:11,12',
            'subject_group'     => ['required', Rule::in($this->availableSubjectGroups())],
            'track_id'          => 'nullable|exists:tracks,id',
            'specialization_id' => 'nullable|exists:specializations,id',
        ]);

        $subject->update([
            'name'              => $request->name,
            'type'              => $request->type,
            'grade_level'       => $request->grade_level,
            'subject_group'     => $request->subject_group,
            'track_id'          => $request->type === 'elective' ? $request->track_id : null,
            'specialization_id' => $request->type === 'elective' ? $request->specialization_id : null,
        ]);

        return redirect()->route('admin.subjects')
            ->with('success', 'Subject updated successfully!');
    }

    /**
     * Delete a subject.
     * Cannot delete if grades have been recorded for this subject.
     */
    public function destroy($id)
    {
        $subject = Subject::findOrFail($id);

        if ($subject->grades()->count() > 0) {
            return redirect()->route('admin.subjects')
                ->with('error', 'Cannot delete subject with existing grades.');
        }

        $subject->delete();

        return redirect()->route('admin.subjects')
            ->with('success', 'Subject deleted successfully!');
    }
}