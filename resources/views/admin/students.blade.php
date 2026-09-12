@extends('layouts.app')

@section('title', 'Students')
@section('subtitle', 'Manage student records')

@section('content')

@include('partials.import-result')

{{-- Rejected-rows re-upload feature — students.import() stores just the
     rejected rows (in the same shape the upload expects) in session so
     fixing what was wrong means re-uploading only those rows, not the
     whole original file, which would bounce every already-imported row
     off the LRN unique constraint a second time for nothing. Stays
     available across repeat downloads until the next import() call
     (success or failure) replaces or clears it. --}}
@if(session('rejected_students'))
    <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 text-sm p-4 rounded-lg mb-4 flex items-center justify-between gap-3 flex-wrap">
        <p>
            <i class="bi bi-file-earmark-arrow-down"></i>
            Fix the rows above, then re-upload just those — not the whole original file.
        </p>
        <a href="{{ route('admin.students.rejected.download') }}"
           class="inline-flex items-center gap-1 bg-brand-700 text-white px-4 py-2 rounded-lg text-xs font-medium hover:bg-brand-800 shrink-0">
            <i class="bi bi-download"></i> Download rejected rows
        </a>
    </div>
@endif

{{-- "Draft roster from an E-Class Record" feature — shows once, right after
     the upload, then clears itself the moment the CSV is downloaded (see
     StudentController::downloadRosterExtraction()). Export only: nothing
     here has created a student. --}}
@if(session('roster_extraction'))
    @php $rx = session('roster_extraction'); @endphp
    <div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm p-4 rounded-lg mb-4">
        <p class="text-xs font-medium text-blue-600 mb-1">
            <i class="bi bi-2-circle-fill"></i> Step 2 of 3 — Correct this draft, then Import Students below.
        </p>
        <p class="font-medium mb-1">
            <i class="bi bi-file-earmark-spreadsheet"></i>
            Draft roster from "{{ $rx['source_filename'] }}" — {{ count($rx['rows']) }} row(s) extracted.
        </p>
        <ul class="list-disc list-inside mb-2">
            <li>{{ $rx['skipped_empty'] }} empty row(s) skipped (no LRN and no name).</li>
            @if($rx['missing_lrn_count'] > 0)
                <li class="font-medium">
                    {{ $rx['missing_lrn_count'] }} row(s) have a name but no LRN — fill those in before importing;
                    Import Students will reject a blank LRN by name, not silently skip it.
                </li>
            @else
                <li>Every row has an LRN.</li>
            @endif
        </ul>
        <p class="text-xs text-blue-700 mb-3">
            Name split: everything before the comma is the last name; after it, the last word is taken as the
            middle name and the rest as the first name. This is a convention, not a rule — check names with a
            multi-word middle name (e.g. Spanish-style double surnames) before importing.
        </p>
        <a href="{{ route('admin.students.extract-roster.download') }}"
           class="inline-flex items-center gap-1 bg-brand-700 text-white px-4 py-2 rounded-lg text-xs font-medium hover:bg-brand-800">
            <i class="bi bi-download"></i> Download CSV
        </a>
    </div>
@endif

<div class="bg-white rounded-xl shadow-sm mb-0">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-6 py-4 border-b">
        <div>
            <h2 class="text-sm font-semibold text-gray-800">All Students</h2>
            <p class="text-xs text-gray-400"><x-count-label :count="$students->total()" noun="student" total /></p>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <form method="GET">
                <select name="section_id" onchange="this.form.submit()"
                    class="w-64 border rounded-lg pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <option value="">All Sections</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}"
                            {{ request('section_id') == $section->id ? 'selected' : '' }}>
                            {{ $section->name }} — Grade {{ $section->grade_level }}
                        </option>
                    @endforeach
                </select>
            </form>
            <div class="relative">
                <input type="text" id="studentSearch"
                    placeholder="Search students..."
                    class="border rounded-lg pl-9 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 w-56">
                <i class="bi bi-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
            </div>
            {{-- "One entry point" pass — a single primary action opening a
                 chooser, instead of three equal-weight buttons that hid the
                 real order (extract is step 1 of a flow; import is step 3;
                 neither can do the other's job). See addStudentsChooserModal
                 below and CLAUDE.md's note on this restructure. --}}
            <button type="button" onclick="openAddStudentsChooserModal()"
                class="bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-800 whitespace-nowrap">
                <i class="bi bi-plus-lg"></i> Add Students
            </button>
        </div>
    </div>

    {{-- TASK 5 of "terminology, transmutation, and interface cleanup" —
         body scrolls within a fixed-height container (header stays
         pinned) instead of the whole page scrolling. Row count above the
         table already existed here (x-count-label in the header). --}}
    <div class="tbl-scroll">
    <table class="tbl tbl-sticky" id="studentTable">
        <thead>
            <tr>
                <th scope="col">LRN</th>
                <th scope="col">Last Name</th>
                <th scope="col">First Name</th>
                <th scope="col">Middle Name</th>
                <th scope="col">Birthdate</th>
                <th scope="col">Gender</th>
                <th scope="col">Section</th>
                <th scope="col" class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody id="studentTableBody">
            @forelse($students as $student)
            <tr class="student-row">
                <td>{{ $student->lrn }}</td>
                <td class="font-medium text-gray-800">{{ $student->last_name }}</td>
                <td>{{ $student->first_name }}</td>
                <td class="text-gray-500">{{ $student->middle_name ?? '—' }}</td>
                <td class="capitalize">{{ $student->formatted_birthdate }}</td>
                <td class="capitalize">{{ $student->gender }}</td>
                <td>
                    {{ $student->section->name ?? '—' }}
                    <span class="text-gray-400 text-xs">
                        {{ $student->section ? '(Grade ' . $student->section->grade_level . ')' : '' }}
                    </span>
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        <button type="button"
                            onclick='openEditStudentModal(@json($student))'
                            class="inline-flex items-center gap-1 text-brand-600 hover:text-brand-800 text-xs font-medium border border-brand-200 rounded px-2 py-1 hover:bg-brand-50 whitespace-nowrap">
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>
                        <form method="POST"
                              action="{{ route('admin.students.destroy', $student->id) }}"
                              class="inline"
                              data-confirm="Remove student {{ $student->last_name }}, {{ $student->first_name }}?">
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
                <td colspan="7">
                    <x-empty-state message="No students yet." icon="bi-people"
                        hint='Use "Add Student" or "Import Students" above. Each student needs a section.' />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <div id="noStudentResults" class="hidden px-6 py-8 text-center text-gray-400">
        <i class="bi bi-search text-2xl block mb-2"></i>
        No students found matching your search.
    </div>

    @if($students->hasPages())
    <div class="px-6 py-4 border-t flex flex-col items-center gap-2 text-sm text-gray-500">
        <div class="flex items-center gap-1">
            @if($students->onFirstPage())
                <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">← Prev</span>
            @else
                <a href="{{ $students->previousPageUrl() }}"
                   class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">← Prev</a>
            @endif
            <span class="px-3 py-1 rounded border bg-brand-700 text-white font-medium">
                {{ $students->currentPage() }}
            </span>
            @if($students->hasMorePages())
                <a href="{{ $students->nextPageUrl() }}"
                   class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">Next →</a>
            @else
                <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">Next →</span>
            @endif
        </div>
        <span class="text-xs">Showing {{ $students->firstItem() }}–{{ $students->lastItem() }} of <x-count-label :count="$students->total()" noun="student" /></span>
    </div>
    @endif
</div>

{{-- EDIT STUDENT MODAL (opened via openEditStudentModal() in modal.js) --}}
<div id="adminStudentModal"
     class="{{ $errors->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 id="studentModalTitle" class="text-lg font-semibold text-gray-800">Edit Student</h3>
            <button type="button" onclick="closeStudentModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->any())
            <div class="bg-red-100 text-red-700 text-sm p-3 rounded-lg mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="adminStudentForm" method="POST"
              class="space-y-4">
            @csrf
            <input type="hidden" name="_method" id="studentMethod" value="PUT">

            <div>
                <label class="block text-sm text-gray-600 mb-1">LRN (12 digits)</label>
                <input type="text" name="lrn" id="ps_lrn" maxlength="12" required
                       value="{{ old('lrn') }}"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Last Name</label>
                    <input type="text" name="last_name" id="ps_last_name" required
                           value="{{ old('last_name') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">First Name</label>
                    <input type="text" name="first_name" id="ps_first_name" required
                           value="{{ old('first_name') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Middle Name</label>
                <input type="text" name="middle_name" id="ps_middle_name"
                       value="{{ old('middle_name') }}"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Gender</label>
                    <select name="gender" id="ps_gender" required
                            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                        <option value="">Select</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Birthdate</label>
                    <input type="date" name="birthdate" id="ps_birthdate"
                           value="{{ old('birthdate') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Section</label>
                <select name="section_id" id="ps_section_id" required
                        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <option value="">Select Section</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}">
                            {{ $section->name }} — Grade {{ $section->grade_level }}
                            ({{ $section->school_year }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeStudentModal()"
                        class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                <button type="submit" id="studentSubmitBtn"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                    Update Student
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ADD STUDENTS CHOOSER MODAL — "one entry point" pass. The single door;
     each option below opens one of the three existing, unchanged modals.
     No new capability, no capability removed — this only changes how an
     admin gets to the one they already need. --}}
<div id="addStudentsChooserModal"
     class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Add Students</h3>
            <button type="button" onclick="closeAddStudentsChooserModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <div class="space-y-3">
            <button type="button" onclick="closeAddStudentsChooserModal(); openExtractRosterModal();"
                class="w-full text-left border rounded-lg p-4 hover:bg-gray-50 hover:border-brand-300 transition-colors">
                <p class="font-medium text-gray-800">
                    <i class="bi bi-file-earmark-spreadsheet text-brand-700"></i> From an E-Class Record
                </p>
                <p class="text-xs text-gray-500 mt-1.5">
                    <span class="font-medium text-gray-600">1. Extract</span> the roster
                    <i class="bi bi-arrow-right mx-1 text-gray-300"></i>
                    <span class="font-medium text-gray-600">2. Correct</span> the draft
                    <i class="bi bi-arrow-right mx-1 text-gray-300"></i>
                    <span class="font-medium text-gray-600">3. Import</span> it below
                </p>
            </button>

            <button type="button" onclick="closeAddStudentsChooserModal(); openImportStudentsModal();"
                class="w-full text-left border rounded-lg p-4 hover:bg-gray-50 hover:border-brand-300 transition-colors">
                <p class="font-medium text-gray-800">
                    <i class="bi bi-upload text-brand-700"></i> I already have a CSV
                </p>
                <p class="text-xs text-gray-500 mt-1.5">Import a roster file directly — lrn, last_name, first_name, middle_name, gender, birthdate.</p>
            </button>

            <button type="button" onclick="closeAddStudentsChooserModal(); openAddStudentModal();"
                class="w-full text-left border rounded-lg p-4 hover:bg-gray-50 hover:border-brand-300 transition-colors">
                <p class="font-medium text-gray-800">
                    <i class="bi bi-person-plus text-brand-700"></i> One at a time
                </p>
                <p class="text-xs text-gray-500 mt-1.5">Add a single student by hand.</p>
            </button>
        </div>
    </div>
</div>

{{-- ADD STUDENT MODAL --}}
<div id="addStudentModal"
     class="{{ $errors->any() && !$errors->import->any() && !$errors->extractRoster->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->any() && !$errors->import->any() && !$errors->extractRoster->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Add Student</h3>
            <button type="button" onclick="closeAddStudentModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->any() && !$errors->import->any() && !$errors->extractRoster->any())
            <div class="bg-red-100 text-red-700 text-sm p-3 rounded-lg mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.students.store') }}" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm text-gray-600 mb-1">LRN (12 digits)</label>
                <input type="text" name="lrn" maxlength="12" required
                       inputmode="numeric" pattern="[0-9]*"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                       value="{{ old('lrn') }}"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Last Name</label>
                    <input type="text" name="last_name" required
                           value="{{ old('last_name') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">First Name</label>
                    <input type="text" name="first_name" required
                           value="{{ old('first_name') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Middle Name</label>
                <input type="text" name="middle_name"
                       value="{{ old('middle_name') }}"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Gender</label>
                    <select name="gender" required
                            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                        <option value="">Select</option>
                        <option value="male" {{ old('gender') == 'male' ? 'selected' : '' }}>Male</option>
                        <option value="female" {{ old('gender') == 'female' ? 'selected' : '' }}>Female</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Birthdate</label>
                    <input type="date" name="birthdate"
                           max="{{ date('Y-m-d') }}"
                           value="{{ old('birthdate') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Section</label>
                <select name="section_id" required
                        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <option value="">Select Section</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}" {{ old('section_id') == $section->id ? 'selected' : '' }}>
                            {{ $section->name }} — Grade {{ $section->grade_level }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeAddStudentModal()"
                        class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                    Save Student
                </button>
            </div>
        </form>
    </div>
</div>

{{-- IMPORT STUDENTS MODAL --}}
<div id="importStudentsModal"
     class="{{ $errors->import->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->import->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Import Students</h3>
            <button type="button" onclick="closeImportStudentsModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
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
            <strong>lrn, last_name, first_name, middle_name, gender, birthdate</strong>.
            All rows are imported into the section selected below.
        </p>

        <form method="POST" action="{{ route('admin.students.import') }}"
              enctype="multipart/form-data" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm text-gray-600 mb-1">Target Section</label>
                <select name="section_id" required
                        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <option value="">Select Section</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}">
                            {{ $section->name }} — Grade {{ $section->grade_level }}
                        </option>
                    @endforeach
                </select>
            </div>

            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeImportStudentsModal()"
                        class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                    Upload & Import
                </button>
            </div>
        </form>
    </div>
</div>

{{-- EXTRACT ROSTER FROM E-CLASS RECORD MODAL — export only, never creates a
     student. See StudentController::extractRosterPreview(). --}}
<div id="extractRosterModal"
     class="{{ $errors->extractRoster->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->extractRoster->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-1">
            <h3 class="text-lg font-semibold text-gray-800">Extract Roster from E-Class Record</h3>
            <button type="button" onclick="closeExtractRosterModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <p class="text-xs font-medium text-brand-700 mb-3">
            <i class="bi bi-1-circle-fill"></i> Step 1 of 3 — Extract, then correct the CSV, then Import Students.
        </p>

        @if($errors->extractRoster->any())
            <div class="bg-red-100 text-red-700 text-sm p-3 rounded-lg mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->extractRoster->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-sm text-gray-500 mb-2">
            Upload an official SSHS E-Class Record (.xlsx). This reads the roster from its <strong>INPUT DATA</strong>
            sheet and produces a downloadable draft CSV in the same shape Import Students already accepts —
            it does <strong>not</strong> create any student.
        </p>
        <p class="text-xs text-gray-400 mb-4">
            Name split: everything before the comma is the last name; after it, the last word becomes the middle
            name and the rest becomes the first name. Gender comes from which block (male/female) the row is in.
            Birthdate is always left blank — it isn't in the E-Class Record. Review the CSV before importing it.
        </p>

        <form method="POST" action="{{ route('admin.students.extract-roster') }}"
              enctype="multipart/form-data" class="space-y-4">
            @csrf

            <input type="file" name="file" accept=".xlsx,.xls" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeExtractRosterModal()"
                        class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                    Upload & Extract
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTableSearch('studentSearch', '.student-row', 'noStudentResults');
    });
</script>
@endpush

@endsection