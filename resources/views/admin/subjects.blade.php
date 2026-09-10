@extends('layouts.app')

@section('title', 'Subjects')
@section('subtitle', 'Manage SHS subjects')

@section('content')

@php
    // DO 015, s. 2026's subject group names — purely a display label for
    // the slug stored in subject_group_weights/subjects.subject_group,
    // never a weight or share itself. A group not in this map (a future
    // scheme's addition) still renders a readable fallback.
    $subjectGroupLabels = [
        'core_academic'        => 'Core Academic',
        'field_exposure'       => 'Field Exposure',
        'arts_sports_wellness' => 'Arts, Sports & Wellness',
        'research_innovation'  => 'Research/Innovation',
        'techpro'              => 'Tech-Pro',
        'work_immersion'       => 'Work Immersion',
    ];
    $subjectGroupLabel = fn($group) => $subjectGroupLabels[$group] ?? ucwords(str_replace('_', ' ', $group ?? ''));

    // "ECR alignment" work order, PART 4c — what each group actually
    // COVERS, not just its slug. core_academic is genuinely three
    // different DepEd clusters sharing one weight (Core, STEM, Business &
    // Entrepreneurship) — an admin reading only "Core Academic" will leave
    // this blank on an academic elective and be right by accident, then do
    // the same on a Sports elective and be wrong. Derived from the Part 2
    // catalog's actual membership per weight pattern.
    // field_exposure, techpro corrected 2026-09-10: the FIELD EXPERIENCE
    // catalog cluster actually splits across THREE slugs by weight pattern
    // (field_exposure 15/70/15, research_innovation 40/60 for Research 1/2
    // and Design and Innovation, work_immersion 20/80 for Work Immersion
    // for Academic Track) — naming the whole cluster here would send an
    // admin to file Research 1 under field_exposure. And "Tech-Vocational-
    // Livelihood" is DO 8/2013 vocabulary; SSHS calls this track Tech-Pro —
    // Part 3 just spent a migration keeping those two taxonomies apart.
    $subjectGroupCovers = [
        'core_academic'        => 'Core Subjects and Other Academic Electives',
        'field_exposure'       => 'Field Exposure and Arts Apprenticeship Electives',
        'arts_sports_wellness' => 'Arts, Social Sciences, Humanities, and Sports/Wellness Electives',
        'research_innovation'  => 'Research and Innovation Electives',
        'techpro'              => 'Tech-Pro Track Electives',
        // Covers both the Academic Track's Work Immersion for Academic
        // Track subject and Tech-Pro's five Work Immersion variants — same
        // 20/80 pattern, one slug.
        'work_immersion'       => 'Work Immersion',
    ];
    $subjectGroupCoverText = fn($group) => $subjectGroupCovers[$group] ?? null;
@endphp

@include('partials.import-result')

<div class="bg-white rounded-xl shadow-sm mb-0">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-6 py-4 border-b">
        <div>
            <h2 class="text-sm font-semibold text-gray-800">All Subjects</h2>
            <p class="text-xs text-gray-400"><x-count-label :count="$subjects->count()" noun="subject" total /></p>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <div class="flex gap-2">
                <a href="{{ route('admin.subjects') }}"
                   class="text-xs px-3 py-1.5 rounded-full {{ !request('type') ? 'bg-brand-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">All</a>
                <a href="{{ route('admin.subjects', ['type' => 'core']) }}"
                   class="text-xs px-3 py-1.5 rounded-full {{ request('type') === 'core' ? 'bg-brand-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">Core</a>
                <a href="{{ route('admin.subjects', ['type' => 'elective']) }}"
                   class="text-xs px-3 py-1.5 rounded-full {{ request('type') === 'elective' ? 'bg-brand-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">Elective</a>
            </div>
            <div class="relative">
                <input type="text" id="subjectSearch"
                    placeholder="Search subjects..."
                    class="border rounded-lg pl-9 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 w-56">
                <i class="bi bi-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
            </div>
            <button type="button" onclick="openImportSubjectsModal()"
                class="bg-white border border-brand-700 text-brand-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-50 whitespace-nowrap">
                <i class="bi bi-upload"></i> Import Subjects
            </button>
            <button type="button" onclick="openAddSubjectModal()"
                class="bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-800 whitespace-nowrap">
                <i class="bi bi-plus-lg"></i> Add Subject
            </button>
        </div>
    </div>

    <div class="tbl-scroll">
    <table class="tbl" id="subjectTable">
        <thead>
            <tr>
                <th scope="col">Subject Name</th>
                <th scope="col">Type</th>
                <th scope="col">Grade Level</th>
                <th scope="col">Subject Group</th>
                <th scope="col">Track</th>
                <th scope="col">Specialization</th>
                <th scope="col" class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($subjects as $subject)
            <tr class="subject-row">
                <td class="font-medium text-gray-800">{{ $subject->name }}</td>
                <td>
                    @if($subject->type === 'core')
                        <span class="bg-green-100 text-green-700 text-xs font-semibold px-2 py-1 rounded">Core</span>
                    @else
                        <span class="bg-orange-100 text-orange-700 text-xs font-semibold px-2 py-1 rounded">Elective</span>
                    @endif
                </td>
                <td>Grade {{ $subject->grade_level }}</td>
                <td>
                    {{ $subjectGroupLabel($subject->subject_group) }}
                    @if($subjectGroupCoverText($subject->subject_group))
                        <span class="block text-xs text-gray-400">{{ $subjectGroupCoverText($subject->subject_group) }}</span>
                    @endif
                </td>
                <td>{{ $subject->track->name ?? '—' }}</td>
                <td>{{ $subject->specialization->name ?? '—' }}</td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        <button type="button"
                            onclick='openEditSubjectModal(@json($subject))'
                            class="inline-flex items-center gap-1 text-brand-600 hover:text-brand-800 text-xs font-medium border border-brand-200 rounded px-2 py-1 hover:bg-brand-50 whitespace-nowrap">
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>
                        <form method="POST"
                              action="{{ route('admin.subjects.destroy', $subject->id) }}"
                              class="inline"
                              data-confirm="Delete subject {{ $subject->name }}?">
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
                    <x-empty-state message="No subjects yet." icon="bi-book"
                        hint="Use &quot;Add Subject&quot; or &quot;Import Subjects&quot; above to get started." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <div id="noSubjectResults" class="hidden px-6 py-8 text-center text-gray-400">
        <i class="bi bi-search text-2xl block mb-2"></i>
        No subjects found matching your search.
    </div>
</div>

{{-- MODAL --}}
<div id="subjectModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200 opacity-0">
    <div class="modal-box bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200 scale-95 opacity-0">

        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTitle" class="text-lg font-semibold text-gray-800">Add Subject</h3>
            <button type="button" onclick="closeSubjectModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <form id="subjectForm" method="POST" class="space-y-4"
              data-store-url="{{ route('admin.subjects.store') }}"
              data-spec-url="{{ url('admin/specializations-by-track') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">

            <div>
                <label class="block text-sm text-gray-600 mb-1">Subject Name</label>
                <input type="text" name="name" id="subjectName" required
                       placeholder="e.g. Effective Communication"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Type</label>
                    <select name="type" id="subjectType" required onchange="toggleTrackFields()"
                            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                        <option value="">— Select Type —</option>
                        <option value="core">Core</option>
                        <option value="elective">Elective</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Grade Level</label>
                    <select name="grade_level" id="subjectGrade" required
                            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                        <option value="">— Select Grade —</option>
                        <option value="11">Grade 11</option>
                        <option value="12">Grade 12</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">
                    Subject Group
                    <span class="text-gray-400 text-xs">(DO 015, s. 2026 grading weight group)</span>
                </label>
                <select name="subject_group" id="subjectGroupField" required
                        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    @foreach($subjectGroups as $group)
                        <option value="{{ $group }}">{{ $subjectGroupLabel($group) }}{{ $subjectGroupCoverText($group) ? ' — covers ' . $subjectGroupCoverText($group) : '' }}</option>
                    @endforeach
                </select>
            </div>

            <div id="trackFields" class="hidden space-y-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Track</label>
                    <select name="track_id" id="subjectTrack"
                            onchange="loadSpecializations(this.value, 'subjectSpec', document.getElementById('subjectForm').dataset.specUrl)"
                            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                        <option value="">— Select Track —</option>
                        @foreach($tracks as $track)
                            <option value="{{ $track->id }}">{{ $track->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">
                        Specialization
                        <span class="text-gray-400 text-xs">(optional)</span>
                    </label>
                    <select name="specialization_id" id="subjectSpec"
                            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                        <option value="">— All specializations in track —</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeSubjectModal()"
                        class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                    Save Subject
                </button>
            </div>
        </form>
    </div>
</div>
{{-- Modal logic now lives in resources/js/modal.js. --}}

{{-- IMPORT SUBJECTS MODAL --}}
<div id="importSubjectsModal"
     class="{{ $errors->import->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->import->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Import Subjects</h3>
            <button type="button" onclick="closeImportSubjectsModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
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
            <strong>name, type, grade_level</strong>.
            Optional: <strong>subject_group</strong> (defaults to
            <strong>core_academic</strong> when omitted — the DO 015, s. 2026
            grading weight group; see the Subject Group column above for the
            full list), <strong>track, specialization</strong> (by name or code —
            for elective subjects). Type must be <strong>core</strong> or
            <strong>elective</strong>; grade_level must be <strong>11</strong> or <strong>12</strong>.
        </p>

        <form method="POST" action="{{ route('admin.subjects.import') }}"
              enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeImportSubjectsModal()"
                        class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                    Upload & Import
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTableSearch('subjectSearch', '.subject-row', 'noSubjectResults');
    });
</script>
@endpush

@endsection