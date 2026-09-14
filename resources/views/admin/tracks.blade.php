@extends('layouts.app')

@section('title', 'Tracks')
@section('subtitle', 'Manage SHS tracks')

@section('content')

@include('partials.import-result')

<div class="card mb-0">
    <div class="flex items-center justify-between px-5 py-4 border-b border-line">
        <div>
            <h2 class="card-title">All Tracks</h2>
            <p class="text-xs text-muted"><x-count-label :count="$tracks->count()" noun="track" total /></p>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" onclick="openImportTracksModal()"
                class="bg-white border border-brand-700 text-brand-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-50 whitespace-nowrap">
                <i class="bi bi-upload"></i> Import Tracks
            </button>
            <button type="button" onclick="openAddTrackModal()"
                class="btn btn-primary whitespace-nowrap">
                <i class="bi bi-plus-lg"></i> Add Track
            </button>
        </div>
    </div>

    <div class="tbl-scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th scope="col">Track Name</th>
                <th scope="col">Code</th>
                <th scope="col">Description</th>
                <th scope="col">Specializations</th>
                <th scope="col" class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tracks as $track)
            <tr>
                <td class="font-medium text-ink">{{ $track->name }}</td>
                <td>
                    <span class="bg-brand-100 text-brand-700 text-xs font-semibold px-2 py-1 rounded">
                        {{ $track->code }}
                    </span>
                </td>
                <td class="text-gray-600 max-w-xs">
                    @if($track->description)
                        {{ $track->description }}
                    @else
                        <span class="text-gray-400 text-xs">No description yet</span>
                    @endif
                </td>
                <td>
                    @if($track->specializations->count() > 0)
                        {{ $track->specializations->pluck('name')->join(', ') }}
                    @else
                        <span class="text-gray-400 text-xs">No specializations yet</span>
                    @endif
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        <button type="button"
                            onclick='openEditTrackModal(@json($track))'
                            class="inline-flex items-center gap-1 btn btn-xs btn-secondary whitespace-nowrap">
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>
                        <form method="POST"
                              action="{{ route('admin.tracks.destroy', $track->id) }}"
                              class="inline"
                              data-confirm="Delete track {{ $track->name }}? All specializations under it will also be deleted.">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="inline-flex items-center gap-1 btn btn-xs btn-danger-outline whitespace-nowrap">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5">
                    <x-empty-state message="No tracks yet." icon="bi-diagram-3"
                        hint='Use "Add Track" or "Import Tracks" above to get started.' />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

{{-- ADD / EDIT MODAL --}}
<div id="trackModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-modal w-full max-w-md p-6">

        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTitle" class="text-lg font-semibold text-ink">Add Track</h3>
            <button type="button" onclick="closeTrackModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <form id="trackForm" method="POST" class="space-y-4" data-store-url="{{ route('admin.tracks.store') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">

            <div>
                <label class="form-label">Track Name</label>
                <input type="text" name="name" id="trackName" required
                       placeholder="e.g. Academic Track"
                       class="form-input">
            </div>

            <div>
                <label class="form-label">Code</label>
                <input type="text" name="code" id="trackCode" required
                       placeholder="e.g. ACAD"
                       class="form-input">
                <p class="text-xs text-muted mt-1">Short code for the track (auto-uppercased)</p>
            </div>

            <div>
                <label class="form-label">Description <span class="text-gray-400">(optional)</span></label>
                <textarea name="description" id="trackDescription" rows="3"
                          placeholder="e.g. Prepares learners for higher education through academic subjects."
                          class="form-input"></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeTrackModal()"
                        class="btn btn-outline">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
                    Save Track
                </button>
            </div>
        </form>
    </div>
</div>
{{-- Modal logic now lives in resources/js/modal.js, using the
     data-store-url attribute on the form above instead of a
     separate inline <script> variable. --}}

{{-- IMPORT TRACKS MODAL --}}
<div id="importTracksModal"
     class="{{ $errors->import->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->import->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Import Tracks</h3>
            <button type="button" onclick="closeImportTracksModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->import->any())
            <div class="alert alert-danger mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->import->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-sm text-muted mb-4">
            Upload an Excel (.xlsx) or CSV file. Required columns:
            <strong>track_name, track_code, specialization_name, specialization_code</strong>.
            One row per specialization — repeat the same track on each of
            its rows. Leave both specialization columns blank on a row to
            add a track with no specializations yet. Codes are
            auto-uppercased. Re-uploading the same file is safe — matching
            tracks/specializations are reused, never duplicated.
        </p>

        <form method="POST" action="{{ route('admin.tracks.import') }}"
              enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeImportTracksModal()"
                        class="px-4 py-2 text-sm text-muted">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
                    Upload & Import
                </button>
            </div>
        </form>
    </div>
</div>

@endsection