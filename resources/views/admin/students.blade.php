@extends('layouts.app')

@section('title', 'Students')
@section('subtitle', 'Manage student records')

@section('content')

@include('partials.import-result')

@if($errors->enroll->any())
    <div class="alert alert-warning mb-4">
        <p class="font-medium"><i class="bi bi-exclamation-triangle-fill"></i> Enrollment not recorded</p>
        @foreach($errors->enroll->all() as $error)<p class="mt-1">{{ $error }}</p>@endforeach
    </div>
@endif

{{-- Rejected-rows re-upload feature — students.import() stores just the
     rejected rows (in the same shape the upload expects) in session so
     fixing what was wrong means re-uploading only those rows, not the
     whole original file, which would bounce every already-imported row
     off the LRN unique constraint a second time for nothing. Stays
     available across repeat downloads until the next import() call
     (success or failure) replaces or clears it. --}}
@if(session('rejected_students'))
    <div class="alert alert-warning mb-4 flex items-center justify-between gap-3 flex-wrap">
        <p>
            <i class="bi bi-file-earmark-arrow-down"></i>
            Fix the rows above, then re-upload just those — not the whole original file.
        </p>
        <a href="{{ route('admin.students.rejected.download') }}"
           class="btn btn-primary btn-sm shrink-0">
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
           class="btn btn-primary btn-sm">
            <i class="bi bi-download"></i> Download CSV
        </a>
    </div>
@endif

<div class="card mb-0">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="card-title">All Students</h2>
            <p class="text-xs text-muted"><x-count-label :count="$students->total()" noun="student" total /></p>
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
                    class="form-input !w-56 pl-9">
                <i class="bi bi-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
            </div>
            {{-- "One entry point" pass — a single primary action opening a
                 chooser, instead of three equal-weight buttons that hid the
                 real order (extract is step 1 of a flow; import is step 3;
                 neither can do the other's job). See addStudentsChooserModal
                 below and CLAUDE.md's note on this restructure. --}}
            <button type="button" onclick="openAddStudentsChooserModal()"
                class="btn btn-primary whitespace-nowrap">
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
                <th scope="col">Section <span class="font-normal text-gray-400">(current)</span></th>
                <th scope="col">Enrollment History</th>
                <th scope="col" class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody id="studentTableBody">
            @forelse($students as $student)
            <tr class="student-row">
                <td>{{ $student->lrn }}</td>
                <td class="font-medium text-ink">{{ $student->last_name }}</td>
                <td>{{ $student->first_name }}</td>
                <td class="text-gray-500">{{ $student->middle_name ?? '—' }}</td>
                <td class="capitalize">{{ $student->formatted_birthdate }}</td>
                <td class="capitalize">{{ $student->gender }}</td>
                <td>
                    {{ $student->section->name ?? '—' }}
                    <span class="text-gray-400 text-xs">
                        {{ $student->section ? '(Grade ' . $student->section->grade_level . ', SY ' . $student->section->school_year . ')' : '' }}
                    </span>
                </td>
                {{-- "Multi-school-year academic history" work order, PART 5 —
                     one line per school year the learner was enrolled in;
                     the identity row above never changes on promotion. --}}
                <td class="text-xs text-gray-500">
                    @forelse($student->enrollments as $en)
                        <span class="block whitespace-nowrap">
                            SY {{ $en->school_year }} — Grade {{ $en->grade_level }} {{ $en->section->name ?? '—' }}
                            @if($en->school_year === $activeSchoolYear)
                                <span class="text-green-700">(current)</span>
                            @else
                                <span class="text-gray-400">(historical)</span>
                            @endif
                        </span>
                    @empty
                        <span class="text-gray-300">No enrollment on record</span>
                    @endforelse
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        @php
                            $enrolledYears = $student->enrollments->pluck('school_year');
                            $canEnrollThisYear = !$enrolledYears->contains($activeSchoolYear) && $activeYearSections->isNotEmpty();
                            $enrollPayload = [
                                'id'      => $student->id,
                                'name'    => $student->last_name . ', ' . $student->first_name,
                                'lrn'     => $student->lrn,
                                'history' => $student->enrollments->map(function ($en) {
                                    return 'SY ' . $en->school_year . ' — Grade ' . $en->grade_level . ' ' . ($en->section->name ?? '');
                                })->values()->all(),
                            ];
                        @endphp
                        @if($canEnrollThisYear)
                        <button type="button"
                            onclick='openEnrollStudentModal(@json($enrollPayload))'
                            class="inline-flex items-center gap-1 text-green-700 hover:text-green-900 text-xs font-medium border border-green-200 rounded px-2 py-1 hover:bg-green-50 whitespace-nowrap"
                            title="Enroll or promote this learner into a section of School Year {{ $activeSchoolYear }}. Previous enrollments are kept.">
                            <i class="bi bi-arrow-up-right-circle"></i> Enroll {{ $activeSchoolYear }}
                        </button>
                        @endif
                        <button type="button"
                            onclick='openEditStudentModal(@json($student))'
                            class="inline-flex items-center gap-1 btn btn-xs btn-secondary whitespace-nowrap">
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>
                        <form method="POST"
                              action="{{ route('admin.students.destroy', $student->id) }}"
                              class="inline"
                              data-confirm="Remove student {{ $student->last_name }}, {{ $student->first_name }}?">
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
                <td colspan="8">
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
    <div class="px-5 py-4 border-t border-line flex flex-col items-center gap-2 text-sm text-muted">
        <div class="flex items-center gap-1">
            @if($students->onFirstPage())
                <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">← Prev</span>
            @else
                <a href="{{ $students->previousPageUrl() }}"
                   class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">← Prev</a>
            @endif
            <span class="px-3 py-1 rounded-md border border-brand-800 bg-brand-800 text-white font-medium">
                {{ $students->currentPage() }}
            </span>
            @if($students->hasMorePages())
                <a href="{{ $students->nextPageUrl() }}"
                   class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">Next →</a>
            @else
                <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">Next →</span>
            @endif
        </div>
        <span class="text-xs">Showing {{ $students->firstItem() }}–{{ $students->lastItem() }} of <x-count-label :count="$students->total()" noun="student" /></span>
    </div>
    @endif
</div>

{{-- ENROLL / PROMOTE MODAL — "Multi-school-year academic history" work
     order, PART 15. Places ONE learner into a section of the ACTIVE
     school year; the previous year's enrollment row is never rewritten.
     Not a delete — uses the neutral action confirmation, never the red
     Delete modal. --}}
<div id="enrollStudentModal" role="dialog" aria-modal="true" aria-labelledby="enrollStudentTitle"
     class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-md p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 id="enrollStudentTitle" class="text-lg font-semibold text-ink">Enroll in School Year {{ $activeSchoolYear }}</h3>
            <button type="button" onclick="closeEnrollStudentModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <p class="text-sm text-gray-700 mb-1"><span id="enrollStudentName" class="font-medium"></span> <span class="text-xs text-muted">LRN <span id="enrollStudentLrn"></span></span></p>
        <div id="enrollStudentHistory" class="text-xs text-gray-500 mb-4"></div>

        <form id="enrollStudentForm" method="POST" class="space-y-4"
              data-action-confirm="Enroll this learner in the selected section for School Year {{ $activeSchoolYear }}? Their previous enrollment record is kept as history — the same learner, one more school year."
              data-action-title="Confirm Enrollment" data-action-label="Yes, Enroll" data-action-icon="bi-arrow-up-right-circle" data-action-loading="Enrolling...">
            @csrf
            <div>
                <label class="form-label">Section for {{ $activeSchoolYear }}</label>
                <select name="section_id" required
                        class="form-input">
                    <option value="">Select a section</option>
                    @foreach($activeYearSections as $s)
                        <option value="{{ $s->id }}">{{ $s->name }} — Grade {{ $s->grade_level }}@if($s->track) ({{ $s->track->name }}@if($s->specialization) / {{ $s->specialization->name }}@endif)@endif</option>
                    @endforeach
                </select>
                <p class="text-xs text-muted mt-1">Only sections of the active school year are listed. Create the new year's sections under Sections first.</p>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeEnrollStudentModal()" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-up-right-circle"></i> Enroll
                </button>
            </div>
        </form>
    </div>
</div>

{{-- EDIT STUDENT MODAL (opened via openEditStudentModal() in modal.js) --}}
<div id="adminStudentModal"
     class="{{ ($errors->any() && !$errors->has('deletion')) ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ ($errors->any() && !$errors->has('deletion')) ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 id="studentModalTitle" class="text-lg font-semibold text-ink">Edit Student</h3>
            <button type="button" onclick="closeStudentModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if(($errors->any() && !$errors->has('deletion')))
            <div class="alert alert-danger mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="adminStudentForm" method="POST"
              data-update-url="{{ route('admin.students.update', ['id' => '__ID__']) }}"
              class="space-y-4">
            @csrf
            <input type="hidden" name="_method" id="studentMethod" value="PUT">

            <div>
                <label class="form-label">LRN (12 digits)</label>
                <input type="text" name="lrn" id="ps_lrn" maxlength="12" required
                       value="{{ old('lrn') }}"
                       class="form-input">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" id="ps_last_name" required
                           value="{{ old('last_name') }}"
                           class="form-input">
                </div>
                <div>
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" id="ps_first_name" required
                           value="{{ old('first_name') }}"
                           class="form-input">
                </div>
            </div>

            <div>
                <label class="form-label">Middle Name</label>
                <input type="text" name="middle_name" id="ps_middle_name"
                       value="{{ old('middle_name') }}"
                       class="form-input">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Gender</label>
                    <select name="gender" id="ps_gender" required
                            class="form-input">
                        <option value="">Select</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Birthdate</label>
                    <input type="date" name="birthdate" id="ps_birthdate"
                           value="{{ old('birthdate') }}"
                           class="form-input">
                </div>
            </div>

            <div>
                <label class="form-label">Section</label>
                <select name="section_id" id="ps_section_id" required
                        class="form-input">
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
                        class="btn btn-outline">Cancel</button>
                <button type="submit" id="studentSubmitBtn"
                        class="btn btn-primary">
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
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Add Students</h3>
            <button type="button" onclick="closeAddStudentsChooserModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <div class="space-y-3">
            <button type="button" onclick="closeAddStudentsChooserModal(); openExtractRosterModal();"
                class="w-full text-left border rounded-lg p-4 hover:bg-surface hover:border-brand-300 transition-colors">
                <p class="font-medium text-ink">
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

            <button type="button" onclick="closeAddStudentsChooserModal(); openImportLearnersFromEcrModal();"
                class="w-full text-left border rounded-lg p-4 hover:bg-surface hover:border-brand-300 transition-colors">
                <p class="font-medium text-ink">
                    <i class="bi bi-file-earmark-person text-brand-700"></i> Import Learners from ECR
                </p>
                <p class="text-xs text-gray-500 mt-1.5">
                    Reads learner identity directly from a Strengthened SHS or Grade 12 class-record workbook — never
                    assessment scores. Shows Insert/Existing/Conflict/Rejected before anything is saved.
                </p>
            </button>

            <button type="button" onclick="closeAddStudentsChooserModal(); openImportStudentsModal();"
                class="w-full text-left border rounded-lg p-4 hover:bg-surface hover:border-brand-300 transition-colors">
                <p class="font-medium text-ink">
                    <i class="bi bi-upload text-brand-700"></i> I already have a CSV
                </p>
                <p class="text-xs text-gray-500 mt-1.5">Import a roster file directly — lrn, last_name, first_name, middle_name, gender, birthdate.</p>
            </button>

            <button type="button" onclick="closeAddStudentsChooserModal(); openAddStudentModal();"
                class="w-full text-left border rounded-lg p-4 hover:bg-surface hover:border-brand-300 transition-colors">
                <p class="font-medium text-ink">
                    <i class="bi bi-person-plus text-brand-700"></i> One at a time
                </p>
                <p class="text-xs text-gray-500 mt-1.5">Add a single student by hand.</p>
            </button>
        </div>
    </div>
</div>

{{-- IMPORT LEARNERS FROM ECR MODAL — reads learner identity directly from a
     real SSHS or Grade 12 class-record workbook. Never assessment scores;
     never writes to the database until the Admin reviews the classified
     preview (Insert/Existing/Conflict/Rejected) and confirms it. Distinct
     from "From an E-Class Record" above (which only ever exports a draft
     CSV) and from "I already have a CSV" (a plain roster file, not a real
     class record). --}}
<div id="importLearnersFromEcrModal"
     class="{{ $errors->ecrLearnerImport->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->ecrLearnerImport->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Import Learners from ECR</h3>
            <button type="button" onclick="closeImportLearnersFromEcrModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->ecrLearnerImport->any())
            <div class="alert alert-danger mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->ecrLearnerImport->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-sm text-muted mb-4">
            Supports the Strengthened SHS E-Class Record and the Grade 12 class-record workbook. Choose the section
            this file's roster belongs to, then upload — you'll see exactly what will be inserted before anything is
            saved.
        </p>

        <form method="POST" action="{{ route('admin.students.import-from-ecr.preview') }}"
              enctype="multipart/form-data" class="space-y-4" data-loading="Validating E-Class Record...">
            @csrf
            <div>
                <label class="form-label">Section</label>
                <select name="section_id" required
                        class="form-input">
                    <option value="">— Select Section —</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}">{{ $section->name }} — Grade {{ $section->grade_level }}</option>
                    @endforeach
                </select>
            </div>
            <input type="file" name="file" accept=".xlsx,.xls" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeImportLearnersFromEcrModal()"
                        class="px-4 py-2 text-sm text-muted">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
                    Preview
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ADD STUDENT MODAL --}}
<div id="addStudentModal"
     class="{{ ($errors->any() && !$errors->has('deletion')) && !$errors->import->any() && !$errors->extractRoster->any() && !$errors->ecrLearnerImport->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ ($errors->any() && !$errors->has('deletion')) && !$errors->import->any() && !$errors->extractRoster->any() && !$errors->ecrLearnerImport->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Add Student</h3>
            <button type="button" onclick="closeAddStudentModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if(($errors->any() && !$errors->has('deletion')) && !$errors->import->any() && !$errors->extractRoster->any() && !$errors->ecrLearnerImport->any())
            <div class="alert alert-danger mb-4">
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
                <label class="form-label">LRN (12 digits)</label>
                <input type="text" name="lrn" maxlength="12" required
                       inputmode="numeric" pattern="[0-9]*"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                       value="{{ old('lrn') }}"
                       class="form-input">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" required
                           value="{{ old('last_name') }}"
                           class="form-input">
                </div>
                <div>
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" required
                           value="{{ old('first_name') }}"
                           class="form-input">
                </div>
            </div>

            <div>
                <label class="form-label">Middle Name</label>
                <input type="text" name="middle_name"
                       value="{{ old('middle_name') }}"
                       class="form-input">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Gender</label>
                    <select name="gender" required
                            class="form-input">
                        <option value="">Select</option>
                        <option value="male" {{ old('gender') == 'male' ? 'selected' : '' }}>Male</option>
                        <option value="female" {{ old('gender') == 'female' ? 'selected' : '' }}>Female</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Birthdate</label>
                    <input type="date" name="birthdate"
                           max="{{ date('Y-m-d') }}"
                           value="{{ old('birthdate') }}"
                           class="form-input">
                </div>
            </div>

            <div>
                <label class="form-label">Section</label>
                <select name="section_id" required
                        class="form-input">
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
                        class="btn btn-outline">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
                    Save Student
                </button>
            </div>
        </form>
    </div>
</div>

{{-- IMPORT STUDENTS MODAL --}}
<div id="importStudentsModal"
     class="{{ $errors->import->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->import->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Import Students</h3>
            <button type="button" onclick="closeImportStudentsModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
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
            <strong>lrn, last_name, first_name, middle_name, gender, birthdate</strong>.
            All rows are imported into the section selected below.
        </p>

        <form method="POST" action="{{ route('admin.students.import') }}" data-loading="Importing students..."
              enctype="multipart/form-data" class="space-y-4">
            @csrf

            <div>
                <label class="form-label">Target Section</label>
                <select name="section_id" required
                        class="form-input">
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
                        class="px-4 py-2 text-sm text-muted">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
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
    <div class="modal-box {{ $errors->extractRoster->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-1">
            <h3 class="text-lg font-semibold text-ink">Extract Roster from E-Class Record</h3>
            <button type="button" onclick="closeExtractRosterModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <p class="text-xs font-medium text-brand-700 mb-3">
            <i class="bi bi-1-circle-fill"></i> Step 1 of 3 — Extract, then correct the CSV, then Import Students.
        </p>

        @if($errors->extractRoster->any())
            <div class="alert alert-danger mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->extractRoster->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-sm text-muted mb-2">
            Upload an official SSHS E-Class Record (.xlsx). This reads the roster from its <strong>INPUT DATA</strong>
            sheet and produces a downloadable draft CSV in the same shape Import Students already accepts —
            it does <strong>not</strong> create any student.
        </p>
        <p class="text-xs text-muted mb-4">
            Name split: everything before the comma is the last name; after it, the last word becomes the middle
            name and the rest becomes the first name. Gender comes from which block (male/female) the row is in.
            Birthdate is always left blank — it isn't in the E-Class Record. Review the CSV before importing it.
        </p>

        <form method="POST" action="{{ route('admin.students.extract-roster') }}"
              enctype="multipart/form-data" class="space-y-4" data-loading="Reading E-Class Record...">
            @csrf

            <input type="file" name="file" accept=".xlsx,.xls" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeExtractRosterModal()"
                        class="px-4 py-2 text-sm text-muted">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
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

@push('scripts')
<script>
    // Enroll / Promote modal — PART 15. Pure display; the form posts to
    // admin.students.enroll and the server enforces every rule.
    window.openEnrollStudentModal = function (data) {
        document.getElementById('enrollStudentName').textContent = data.name;
        document.getElementById('enrollStudentLrn').textContent = data.lrn;
        const history = document.getElementById('enrollStudentHistory');
        history.textContent = '';
        (data.history || []).forEach(function (line) {
            const row = document.createElement('div');
            row.textContent = line + ' (kept as history)';
            history.appendChild(row);
        });
        const form = document.getElementById('enrollStudentForm');
        form.action = @json(url('/admin/students')) + '/' + data.id + '/enroll';
        form.querySelector('select[name="section_id"]').value = '';
        window.showModal('enrollStudentModal');
    };
    window.closeEnrollStudentModal = () => window.hideModal('enrollStudentModal');
</script>
@endpush
