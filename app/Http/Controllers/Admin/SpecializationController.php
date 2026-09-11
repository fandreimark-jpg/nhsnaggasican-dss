<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Specialization;
use App\Models\Track;
use App\Http\Controllers\Concerns\SummarizesImportFailures;
use App\Http\Controllers\Concerns\ValidatesSpreadsheetUpload;
use App\Imports\SpecializationsImport;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * SpecializationController (Admin)
 *
 * Manages SHS specializations under each track.
 * Examples: HUMSS, STEM, ABM under Academic Track.
 *           ICT, HE under Technical-Professional Track.
 */
class SpecializationController extends Controller
{
    use SummarizesImportFailures;
    use ValidatesSpreadsheetUpload;

    /** Show all specializations with their parent track. */
    public function index()
    {
        $specializations = Specialization::with('track')->orderBy('name')->get();
        $tracks          = Track::orderBy('name')->get();
        return view('admin.specializations', compact('specializations', 'tracks'));
    }

    /** Create a new specialization under a track. */
    public function store(Request $request)
    {
        $request->validate([
            'track_id' => 'required|exists:tracks,id',
            'name'     => 'required|string|max:255',
            'code'     => ['required', 'string', 'max:20',
                Rule::unique('specializations')->where('track_id', $request->track_id)],
        ]);

        Specialization::create([
            'track_id' => $request->track_id,
            'name'     => $request->name,
            'code'     => strtoupper($request->code), // auto-uppercase
        ]);

        LogActivity::log(
            'create_specialization',
            'Created specialization: ' . $request->name,
            'specializations',
            null
        );

        return redirect()->route('admin.specializations')
            ->with('success', 'Specialization added successfully!');
    }

    /**
     * Bulk-import specializations from an Excel/CSV file. See
     * App\Imports\SpecializationsImport for the expected column layout
     * (name, code, track) and duplicate-detection rules.
     */
    public function import(Request $request)
    {
        $request->validateWithBag('import', [
            'file' => $this->spreadsheetFileRule(),
        ]);

        $import = new SpecializationsImport();
        Excel::import($import, $request->file('file'));

        $failures = $import->failures();

        if ($failures->count() > 0) {
            $result = $this->summarizeImportFailures($failures, $import);

            LogActivity::log(
                'import_specializations',
                'Imported ' . $import->importedCount . ' specialization(s), ' . $result['skippedCount'] . ' row(s) skipped',
                'specializations',
                null
            );

            return redirect()->route('admin.specializations')
                ->with('warning', $import->importedCount . ' specialization(s) imported. ' . $result['skippedCount'] . ' row(s) were skipped:')
                ->with('import_errors', $result['rowMessages'])
                ->with('import_header_hint', $result['headerHint']);
        }

        LogActivity::log(
            'import_specializations',
            'Bulk imported ' . $import->importedCount . ' specialization(s) via file upload',
            'specializations',
            null
        );

        return redirect()->route('admin.specializations')
            ->with('success', $import->importedCount . ' specialization(s) imported successfully!');
    }

    /** Update an existing specialization. */
    public function update(Request $request, $id)
    {
        $specialization = Specialization::findOrFail($id);

        $request->validate([
            'track_id' => 'required|exists:tracks,id',
            'name'     => 'required|string|max:255',
            'code'     => ['required', 'string', 'max:20',
                Rule::unique('specializations')->where('track_id', $request->track_id)->ignore($id)],
        ]);

        $specialization->update([
            'track_id' => $request->track_id,
            'name'     => $request->name,
            'code'     => strtoupper($request->code),
        ]);

        return redirect()->route('admin.specializations')
            ->with('success', 'Specialization updated successfully!');
    }

    /**
     * Delete a specialization.
     * Cannot delete if sections are using this specialization.
     */
    public function destroy($id)
    {
        $specialization = Specialization::findOrFail($id);

        if ($specialization->sections()->count() > 0) {
            return redirect()->route('admin.specializations')
                ->with('error', 'Cannot delete specialization with existing sections.');
        }

        $specialization->delete();

        return redirect()->route('admin.specializations')
            ->with('success', 'Specialization deleted successfully!');
    }

    /**
     * AJAX endpoint — returns specializations for a specific track.
     * Used by the section and subject forms to populate the specialization dropdown
     * when a track is selected.
     */
    public function byTrack($trackId)
    {
        $specializations = Specialization::where('track_id', $trackId)
            ->orderBy('name')
            ->get(['id', 'name', 'code']); // only return needed fields

        return response()->json($specializations);
    }
}