@extends('layouts.app')

@section('title', 'Specializations')
@section('subtitle', 'Manage SHS specializations per track')

@section('content')

@include('partials.import-result')

<div class="bg-white rounded-xl shadow-sm mb-0">
    <div class="flex items-center justify-between px-6 py-4 border-b">
        <div>
            <h2 class="text-sm font-semibold text-gray-800">All Specializations</h2>
            <p class="text-xs text-gray-400"><x-count-label :count="$specializations->count()" noun="specialization" total /></p>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" onclick="openImportSpecializationsModal()"
                class="bg-white border border-brand-700 text-brand-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-50 whitespace-nowrap">
                <i class="bi bi-upload"></i> Import Specializations
            </button>
            <button type="button" onclick="openAddSpecModal()"
                class="bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-800 whitespace-nowrap">
                <i class="bi bi-plus-lg"></i> Add Specialization
            </button>
        </div>
    </div>

    <div class="tbl-scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th scope="col">Specialization</th>
                <th scope="col">Code</th>
                <th scope="col">Track</th>
                <th scope="col" class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($specializations as $spec)
            <tr>
                <td class="font-medium text-gray-800">{{ $spec->name }}</td>
                <td>
                    <span class="bg-purple-100 text-purple-700 text-xs font-semibold px-2 py-1 rounded">
                        {{ $spec->code }}
                    </span>
                </td>
                <td>{{ $spec->track->name ?? '—' }}</td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        <button type="button"
                            onclick='openEditSpecModal(@json($spec))'
                            class="inline-flex items-center gap-1 text-brand-600 hover:text-brand-800 text-xs font-medium border border-brand-200 rounded px-2 py-1 hover:bg-brand-50 whitespace-nowrap">
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>
                        <form method="POST"
                              action="{{ route('admin.specializations.destroy', $spec->id) }}"
                              class="inline"
                              data-confirm="Delete specialization {{ $spec->name }}?">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="inline-flex items-center gap-1 text-red-600 hover:text-red-800 text-xs font-medium border border-red-200 rounded px-2 py-1 hover:bg-red-50 whitespace-nowrap">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="4">
                    <x-empty-state message="No specializations yet." icon="bi-collection"
                        hint="Specializations belong to a track — add a track first, then use &quot;Add Specialization&quot; or &quot;Import Specializations&quot; above." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

{{-- MODAL --}}
<div id="specModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-lg w-full max-w-md p-6">

        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTitle" class="text-lg font-semibold text-gray-800">Add Specialization</h3>
            <button type="button" onclick="closeSpecModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <form id="specForm" method="POST" class="space-y-4" data-store-url="{{ route('admin.specializations.store') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">

            <div>
                <label class="block text-sm text-gray-600 mb-1">Track</label>
                <select name="track_id" id="specTrack" required
                        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <option value="">— Select Track —</option>
                    @foreach($tracks as $track)
                        <option value="{{ $track->id }}">{{ $track->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Specialization Name</label>
                <input type="text" name="name" id="specName" required
                       placeholder="e.g. Humanities and Social Sciences"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Code</label>
                <input type="text" name="code" id="specCode" required
                       placeholder="e.g. HUMSS"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeSpecModal()"
                        class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                    Save Specialization
                </button>
            </div>
        </form>
    </div>
</div>
{{-- Modal logic now lives in resources/js/modal.js. --}}

{{-- IMPORT SPECIALIZATIONS MODAL --}}
<div id="importSpecializationsModal"
     class="{{ $errors->import->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->import->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Import Specializations</h3>
            <button type="button" onclick="closeImportSpecializationsModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->import->any())
            <div class="bg-red-100 text-red-700 text-sm p-3 rounded-lg mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->import->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-sm text-gray-500 mb-4">
            Upload an Excel (.xlsx) or CSV file. Required columns:
            <strong>name, code, track</strong>. Track is matched by name
            or code (e.g. "Academic Track" or "ACAD") and must already
            exist. Code must be unique across all specializations; name
            must be unique within its track. Code is auto-uppercased.
        </p>

        <form method="POST" action="{{ route('admin.specializations.import') }}"
              enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeImportSpecializationsModal()"
                        class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                    Upload & Import
                </button>
            </div>
        </form>
    </div>
</div>

@endsection