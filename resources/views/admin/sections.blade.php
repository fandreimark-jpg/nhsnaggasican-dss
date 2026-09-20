@extends('layouts.app')

@section('title', 'Sections')
@section('subtitle', 'Manage sections and assign advisers')

@section('content')


<div class="card mb-0">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="card-title">All Sections</h2>
            <p class="text-xs text-muted"><x-count-label :count="$sections->count()" noun="section" total /></p>
        </div>
        <div class="flex items-center gap-3">
            <div class="relative">
                <input type="text" id="sectionSearch"
                    placeholder="Search sections..."
                    class="form-input !w-56 pl-9">
                <i class="bi bi-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
            </div>
            <button type="button" onclick="openAddSectionModal()"
                class="btn btn-primary whitespace-nowrap">
                <i class="bi bi-plus-lg"></i> Add Section
            </button>
        </div>
    </div>

    <div class="tbl-scroll">
    <table class="tbl" id="sectionTable">
        <thead>
            <tr>
                <th scope="col">Section Name</th>
                <th scope="col">Grade Level</th>
                <th scope="col">Track</th>
                <th scope="col">Specialization</th>
                <th scope="col">School Year</th>
                <th scope="col">Adviser</th>
                <th scope="col" class="tbl-num">Students</th>
                {{-- TASK 4 of "dashboard structure and upload safeguards" —
                     the same subjects x students arithmetic
                     AcademicTerm::completionStatus() uses to decide whether
                     a term can complete, shown here so the gap is visible
                     before it silently blocks the next term from opening. --}}
                <th scope="col" class="tbl-num" title="Subjects assigned per academic term (Term 1 / Term 2 / Term 3)">Subjects <span class="font-normal text-gray-400">T1 / T2 / T3</span></th>
                <th scope="col" class="tbl-num" title="Subjects x Students — grades required per term before it can complete (Term 1 / Term 2 / Term 3)">Expected/Term</th>
                <th scope="col" class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($sections as $section)
            <tr class="section-row">
                <td class="font-medium text-ink">{{ $section->name }}</td>
                <td>Grade {{ $section->grade_level }}</td>
                <td>{{ $section->track->name ?? '—' }}</td>
                <td>{{ $section->specialization->name ?? '—' }}</td>
                <td>{{ $section->school_year }}</td>
                <td>
                    @if($section->adviser)
                        {{ $section->adviser->name }}
                    @else
                        <span class="text-yellow-500 text-xs font-medium">Unassigned</span>
                    @endif
                </td>
                <td class="tbl-num"><x-count-label :count="$section->students->count()" noun="student" /></td>
                <td class="tbl-num whitespace-nowrap" title="Subjects resolved per term from the subject configuration (Admin > Subjects)">
                    {{ implode(' / ', $section->subject_counts) }}
                </td>
                <td class="tbl-num whitespace-nowrap">{{ implode(' / ', array_map(fn($n) => $n * $section->students->count(), $section->subject_counts)) }}</td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        <a href="{{ route('admin.sections.subjects', $section->id) }}"
                           class="inline-flex items-center gap-1 btn btn-xs btn-outline whitespace-nowrap" title="Assign subjects per academic term">
                            <i class="bi bi-journal-text"></i> Subjects
                        </a>
                        <button type="button"
                            {{-- Final pre-demo audit (2026-09-20): the JSON directive's argument must
                                 not contain a comma. Blade splits the directive on commas, so the
                                 former "$section->load([track, specialization])" form compiled to
                                 json_encode(..., 512) with NO HEX_APOS/HEX_TAG escaping, and a
                                 section name containing a quote broke out of this attribute.
                                 track/specialization are eager-loaded by the controller instead. --}}
                            onclick='openEditSectionModal(@json($section))'
                            class="inline-flex items-center gap-1 btn btn-xs btn-secondary whitespace-nowrap">
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>
                        <form method="POST"
                              action="{{ route('admin.sections.destroy', $section->id) }}"
                              class="inline"
                              data-confirm="Delete section {{ $section->name }}?">
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
                <td colspan="10">
                    <x-empty-state message="No sections yet." icon="bi-grid"
                        hint="Sections need a track and specialization, so add those first if you haven't yet. Then use Add Section." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <div id="noSectionResults" class="hidden px-6 py-8 text-center text-gray-400">
        <i class="bi bi-search text-2xl block mb-2"></i>
        No sections found matching your search.
    </div>
</div>


{{-- ADD / EDIT MODAL --}}
<div id="sectionModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200 opacity-0">
    <div class="modal-box bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200 scale-95 opacity-0">

        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTitle" class="text-lg font-semibold text-ink">Add Section</h3>
            <button type="button" onclick="closeSectionModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->any())
            <div class="alert alert-danger mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="sectionForm" method="POST" class="space-y-4"
              data-store-url="{{ route('admin.sections.store') }}"
              data-update-url="{{ route('admin.sections.update', ['id' => '__ID__']) }}"
              data-spec-url="{{ url('admin/specializations-by-track') }}"
              data-active-school-year="{{ $activeSchoolYear }}">
            @csrf
            <input type="hidden" name="_method" id="sectionMethod" value="POST">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Section Name</label>
                    <input type="text" name="name" id="sectionName" required
                           placeholder="e.g. Narra"
                           class="form-input">
                </div>
                <div>
                    <label class="form-label">Grade Level</label>
                    <select name="grade_level" id="sectionGrade" required
                            class="form-input">
                        <option value="">— Select Grade —</option>
                        <option value="11">Grade 11</option>
                        <option value="12">Grade 12</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="form-label">
                    Curriculum <span class="text-gray-400">(optional)</span>
                </label>
                <select name="curriculum" id="sectionCurriculum"
                        class="form-input">
                    <option value="">— Not set (inferred from grade level) —</option>
                    <option value="sshs">Strengthened SHS (sshs)</option>
                    <option value="k12_2013">2013 Curriculum (k12_2013)</option>
                </select>
                <p class="text-xs text-muted mt-1">
                    Leave unset to keep using grade-level inference. Set explicitly once the school's real curriculum
                    assignment is confirmed for this section.
                </p>
            </div>

            <div>
                <label class="form-label">Track</label>
                <select name="track_id" id="sectionTrack" required
                        onchange="loadSpecializations(this.value, 'sectionSpec', document.getElementById('sectionForm').dataset.specUrl)"
                        class="form-input">
                    <option value="">— Select Track —</option>
                    @foreach($tracks as $track)
                        <option value="{{ $track->id }}">{{ $track->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="form-label">Specialization</label>
                <select name="specialization_id" id="sectionSpec" required
                        class="form-input">
                    <option value="">— Select Track First —</option>
                </select>
            </div>

            <div>
                <label class="form-label">School Year</label>
                <input type="text" name="school_year" id="sectionSchoolYear" required
                       placeholder="e.g. 2026-2027"
                       class="form-input">
            </div>

            <div>
                <label class="form-label">Assign Adviser</label>
                <select name="adviser_id" id="sectionAdviser"
                        class="form-input">
                    <option value="">— No Adviser —</option>
                    @foreach($allAdvisers as $adviser)
                        <option value="{{ $adviser->id }}"
                            data-section-id="{{ $adviser->section->id ?? '' }}">
                            {{ $adviser->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeSectionModal()"
                        class="btn btn-outline">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
                    Save Section
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTableSearch('sectionSearch', '.section-row', 'noSectionResults');
    });
</script>
@endpush