<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Imports\TracksImport;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * TrackController (Admin)
 *
 * Manages SHS tracks — Academic Track and Technical-Professional Track.
 * Tracks are the top-level category for sections and specializations.
 */
class TrackController extends Controller
{
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
            'name' => 'required|string|max:255|unique:tracks,name',
            'code' => 'required|string|max:20|unique:tracks,code',
        ]);

        Track::create([
            'name' => $request->name,
            'code' => strtoupper($request->code),
        ]);

         LogActivity::log(
            'create_track',
            'Created ' . $request->role . ' tracks: ' . $request->name,
            'tracks',
            null
        );

        return redirect()->route('admin.tracks')
            ->with('success', 'Track added successfully!');
    }

    /**
     * Bulk-import tracks from an Excel/CSV file. See App\Imports\TracksImport
     * for the expected column layout and duplicate-detection rules.
     */
    public function import(Request $request)
    {
        $request->validateWithBag('import', [
            'file' => 'required|mimes:xlsx,xls,csv,txt|max:2048',
        ]);

        $import = new TracksImport();
        Excel::import($import, $request->file('file'));

        $failures = $import->failures();

        if ($failures->count() > 0) {
            $errorMessages = $failures->map(function ($failure) {
                return 'Row ' . $failure->row() . ': ' . implode(', ', $failure->errors());
            })->toArray();

            LogActivity::log(
                'import_tracks',
                'Imported ' . $import->importedCount . ' track(s), ' . $failures->count() . ' row(s) skipped',
                'tracks',
                null
            );

            return redirect()->route('admin.tracks')
                ->with('warning', $import->importedCount . ' track(s) imported. ' . $failures->count() . ' row(s) were skipped:')
                ->with('import_errors', $errorMessages);
        }

        LogActivity::log(
            'import_tracks',
            'Bulk imported ' . $import->importedCount . ' track(s) via file upload',
            'tracks',
            null
        );

        return redirect()->route('admin.tracks')
            ->with('success', $import->importedCount . ' track(s) imported successfully!');
    }

    /**
     * Update a track.
     * Uniqueness check excludes the current track being edited.
     */
    public function update(Request $request, $id)
    {
        $track = Track::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255|unique:tracks,name,' . $id,
            'code' => 'required|string|max:20|unique:tracks,code,' . $id,
        ]);

        $track->update([
            'name' => $request->name,
            'code' => strtoupper($request->code),
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