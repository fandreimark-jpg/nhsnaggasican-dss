@extends('layouts.app')

@section('title', 'My Students')
@section('subtitle', $section ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level : 'No section assigned')

@section('content')

@include('partials.section-school-year-context')

<div class="card overflow-x-auto">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="card-title">Students</h2>
            <p class="text-xs text-muted"><x-count-label :count="$students->total()" noun="student" /> in your section</p>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            {{-- Search box--}}
            <div>
                <div class="relative">
                    <input type="text" id="adviserStudentSearch"
                        placeholder="Search this page..."
                        class="form-input !w-56 pl-9">
                    <i class="bi bi-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                </div>
                {{-- TASK 5d of "clarity, progress, and visual design pass"
                     — honest about scope now that the roster is paginated:
                     this box filters only the {{ $students->count() }} rows
                     on the current page, not the full section. --}}
                @if($students->hasPages())
                <p class="text-[11px] text-gray-400 mt-1">Searches this page only ({{ $students->firstItem() }}–{{ $students->lastItem() }} of {{ $students->total() }}).</p>
                @endif
            </div>
            <p class="text-xs text-muted">
                <i class="bi bi-info-circle"></i> Adding new students is done by the Admin.
            </p>
        </div>
    </div>
    <table class="tbl">
        <thead>
            <tr>
                <th scope="col">LRN</th>
                <th scope="col">Last Name</th>
                <th scope="col">First Name</th>
                <th scope="col">Middle Name</th>
                <th scope="col">Birthdate</th>
                <th scope="col">Gender</th>
                <th scope="col" class="text-right">Actions</th>
            </tr>
        </thead>

        <tbody id="adviserStudentTableBody">
            @forelse($students as $student)

            <tr class="student-row">
                <td>{{ $student->lrn }}</td>
                <td class="font-medium text-ink">{{ $student->last_name }}</td>
                <td>{{ $student->first_name }}</td>
                <td>{{ $student->middle_name ?? '—' }}</td>
                <td class="capitalize">{{ $student->formatted_birthdate }}</td>
                <td class="capitalize">{{ $student->gender }}</td>
                <td class="text-right">
                    <button type="button"
                        onclick='openEditModal(@json($student))'
                        class="text-brand-600 hover:underline text-sm"><i class="bi bi-pencil-square"></i>Edit</button>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6">
                    <x-empty-state message="No students in your section yet." hint="An Admin adds students and assigns them to your section." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

    @if($students->hasPages())
    <div class="px-5 py-4 border-t border-line flex flex-col items-center gap-2 text-sm text-muted">
        <div class="flex items-center gap-1">
            @if($students->onFirstPage())
                <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">← Prev</span>
            @else
                <a href="{{ $students->previousPageUrl() }}" class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">← Prev</a>
            @endif
            <span class="px-3 py-1 rounded-md border border-brand-800 bg-brand-800 text-white font-medium">{{ $students->currentPage() }}</span>
            @if($students->hasMorePages())
                <a href="{{ $students->nextPageUrl() }}" class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">Next →</a>
            @else
                <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">Next →</span>
            @endif
        </div>
        <span class="text-xs">Showing {{ $students->firstItem() }}–{{ $students->lastItem() }} of <x-count-label :count="$students->total()" noun="student" /></span>
    </div>
    @endif
</div>

{{-- EDIT MODAL ONLY --}}
<div id="editModal"
     class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200 opacity-0">
    <div class="modal-box bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200 scale-95 opacity-0">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Edit Student</h3>
            <button type="button" onclick="closeEditModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <form id="editForm" method="POST" class="space-y-4">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" id="edit_last_name" required
                           class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" id="edit_first_name" required
                           class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            <div>
                <label class="form-label">Middle Name</label>
                <input type="text" name="middle_name" id="edit_middle_name"
                       class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Gender</label>
                    <select name="gender" id="edit_gender" required
                            class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Birthdate</label>
                    <input type="date" name="birthdate" id="edit_birthdate"
                           max="{{ date('Y-m-d') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeEditModal()"
                        class="px-4 py-2 text-sm text-muted">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
                    Update Student
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTableSearch('adviserStudentSearch', '#adviserStudentTableBody .student-row');
    });
</script>
@endpush

@endsection