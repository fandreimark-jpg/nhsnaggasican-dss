<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        if ($track->sections()->exists()) {
            return redirect()->route('admin.tracks')
                ->with('error', 'Cannot delete track with existing sections.');
        }

        $track->delete();
        
        return redirect()->route('admin.tracks')
            ->with('success', 'Track deleted successfully!');
    }
}