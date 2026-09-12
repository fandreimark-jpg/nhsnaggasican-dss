<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Http\Controllers\Concerns\SummarizesImportFailures;
use App\Http\Controllers\Concerns\ValidatesSpreadsheetUpload;
use App\Imports\TracksImport;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * TrackController (Admin)
 *
 * Manages SHS tracks — Academic Track and Technical-Professional Track.
 * Tracks are the top-level category for sections and specializations.
 */
class TrackController extends Controller
{
    use SummarizesImportFailures;
    use ValidatesSpreadsheetUpload;

    /** Show all tracks with their specializations. */
    public function index()
    {
        $tracks = Track::with('specializations')->orderBy('name')->get();
        return view('admin.tracks', compact('tracks'));
    }

    /**
     * Create a new track.
     * Code is auto-uppercased (e.g. 'acad' → 'ACAD').
     * Name and code must be unique.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255|unique:tracks,name',
            'code'        => 'required|string|max:20|unique:tracks,code',
            'description' => 'nullable|string|max:2000',
        ]);

        Track::create([
            'name'        => $request->name,
            'code'        => strtoupper($request->code),
            'description' => $request->description,
        ]);

         LogActivity::log(
            'create_track',
            'Created track: ' . $request->name,
            'tracks',
            null
        );

        return redirect()->route('admin.tracks')
            ->with('success', 'Track added successfully!');
    }

    /**
     * Bulk-import tracks + specializations from an Excel/CSV file. See
     * App\Imports\TracksImport for the expected column layout — the import
     * is idempotent (re-uploading the same file is a no-op), so it's run
     * inside a transaction purely so a mid-file failure can't leave a
     * partially-applied file behind.
     */
    public function import(Request $request)
    {
        $request->validateWithBag('import', [
            'file' => $this->spreadsheetFileRule(),
        ]);

        $import = new TracksImport();

        DB::transaction(function () use ($import, $request) {
            Excel::import($import, $request->file('file'));
        });

        $failures = $import->failures();
        $summary  = $import->trackCount . ' track(s) and ' . $import->specializationCount . ' specialization(s)';

        if ($failures->count() > 0) {
            $result = $this->summarizeImportFailures($failures, $import);

            LogActivity::log(
                'import_tracks',
                "Imported {$summary}, " . $result['skippedCount'] . ' row(s) skipped',
                'tracks',
                null
            );

            return redirect()->route('admin.tracks')
                ->with('warning', "{$summary} imported. " . $result['skippedCount'] . ' row(s) were skipped:')
                ->with('import_errors', $result['rowMessages'])
                ->with('import_header_hint', $result['headerHint']);
        }

        LogActivity::log(
            'import_tracks',
            "Bulk imported {$summary} via file upload",
            'tracks',
            null
        );

        return redirect()->route('admin.tracks')
            ->with('success', "{$summary} imported successfully!");
    }

    /**
     * Update a track.
     * Uniqueness check excludes the current track being edited.
     */
    public function update(Request $request, $id)
    {
        $track = Track::findOrFail($id);

        $request->validate([
            'name'        => 'required|string|max:255|unique:tracks,name,' . $id,
            'code'        => 'required|string|max:20|unique:tracks,code,' . $id,
            'description' => 'nullable|string|max:2000',
        ]);

        $track->update([
            'name'        => $request->name,
            'code'        => strtoupper($request->code),
            'description' => $request->description,
        ]);

        return redirect()->route('admin.tracks')
            ->with('success', 'Track updated successfully!');
    }

    /**
     * Delete a track.
     * Cannot delete if sections are using this track.
     */
    public function destroy($id)
    {
        $track = Track::findOrFail($id);

        if ($track->sections()->count() > 0) {
            return redirect()->route('admin.tracks')
                ->with('error', 'Cannot delete track with existing sections.');
        }

        $track->delete();
        
        return redirect()->route('admin.tracks')
            ->with('success', 'Track deleted successfully!');
    }
}