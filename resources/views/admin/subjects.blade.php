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
        'academic_other'       => 'Academic Elective',
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
        'core_academic'        => 'Core Subjects only',
        // Split out of core_academic during "subject classification and
        // grading weights cleanup" — same 20/50/30 numbers as Core, but a
        // genuinely different DepEd profile (STEM and Business &
        // Entrepreneurship cluster electives), so an elective never has to
        // sit in the Core-labelled bucket to get its correct weights.
        'academic_other'       => 'Academic Electives (STEM, Business & Entrepreneurship) — not Core',
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

<div class="card mb-0">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="card-title">All Subjects</h2>
            <p class="text-xs text-muted"><x-count-label :count="$subjects->count()" noun="subject" total /></p>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <div class="flex gap-2">
                <a href="{{ route('admin.subjects') }}"
                   class="text-xs px-3 py-1.5 rounded-full {{ !request('type') ? 'bg-brand-800 text-white' : 'bg-white border border-line text-gray-600 hover:bg-surface' }}">All</a>
                <a href="{{ route('admin.subjects', ['type' => 'core']) }}"
                   class="text-xs px-3 py-1.5 rounded-full {{ request('type') === 'core' ? 'bg-brand-800 text-white' : 'bg-white border border-line text-gray-600 hover:bg-surface' }}">Core</a>
                <a href="{{ route('admin.subjects', ['type' => 'elective']) }}"
                   class="text-xs px-3 py-1.5 rounded-full {{ request('type') === 'elective' ? 'bg-brand-800 text-white' : 'bg-white border border-line text-gray-600 hover:bg-surface' }}">Elective</a>
            </div>
            <div class="relative">
                <input type="text" id="subjectSearch"
                    placeholder="Search subjects..."
                    class="form-input !w-56 pl-9">
                <i class="bi bi-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
            </div>
            <button type="button" onclick="openImportSubjectsModal()"
                class="bg-white border border-brand-700 text-brand-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-50 whitespace-nowrap">
                <i class="bi bi-upload"></i> Import Subjects
            </button>
            <button type="button" onclick="openAddSubjectModal()"
                class="btn btn-primary whitespace-nowrap">
                <i class="bi bi-plus-lg"></i> Add Subject
            </button>
        </div>
    </div>

    <div class="tbl-scroll">
    <table class="tbl" id="subjectTable">
        <thead>
            <tr>
                <th scope="col">Subject Name</th>
                <th scope="col">Grade</th>
                <th scope="col">Category</th>
                <th scope="col">Grading Profile / Weights</th>
                <th scope="col">Track / Specialization</th>
                <th scope="col" class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($subjects as $subject)
            <tr class="subject-row">
                <td class="font-medium text-ink">{{ $subject->name }}</td>
                <td>Grade {{ $subject->grade_level }}</td>
                <td>
                    @if($subject->type === 'core')
                        <span class="bg-green-100 text-green-700 text-xs font-semibold px-2 py-1 rounded">Core</span>
                    @else
                        <span class="badge badge-warning">Elective</span>
                        @if($subject->subject_group)
                            <span class="block text-xs text-muted mt-0.5">{{ $subjectGroupLabel($subject->subject_group) }}</span>
                        @endif
                    @endif
                </td>
                <td class="text-xs">
                    @php $w = $subject->grading_weights_display; @endphp
                    @if(($w['source'] ?? null) === 'do8_by_track')
                        <span class="text-gray-400" title="DO 8, s. 2015 — this section's track decides the split; see Sections.">DO 8, s. 2015 — by section track</span>
                    @elseif($w)
                        @php
                            $wwStr = rtrim(rtrim(number_format($w['ww'], 2), '0'), '.');
                            $ptStr = rtrim(rtrim(number_format($w['pt'], 2), '0'), '.');
                            $exStr = $w['ex'] !== null ? rtrim(rtrim(number_format($w['ex'], 2), '0'), '.') . '%' : 'none';
                            $sourceLabel = $w['source'] === 'catalog'
                                ? 'Source: DepEd Strengthened SHS catalog (exact subject match)'
                                : 'Profile: ' . $subjectGroupLabel($subject->subject_group) . ' — Source: configured grading policy';
                            $tooltip = "WW {$wwStr}% / PT {$ptStr}% / Exam {$exStr}. {$sourceLabel}. Effective: DO 015, s. 2026 (Grade 11).";
                        @endphp
                        {{-- Grading Profile — system-resolved, read-only: never an editable field. --}}
                        <div class="inline-grid grid-cols-[auto_auto] gap-x-3 gap-y-0.5 text-[11px] leading-tight cursor-help" title="{{ $tooltip }}">
                            <span class="text-muted">Written Work</span><span class="font-semibold text-ink tabular-nums text-right">{{ $wwStr }}%</span>
                            <span class="text-muted">Performance Task</span><span class="font-semibold text-ink tabular-nums text-right">{{ $ptStr }}%</span>
                            <span class="text-muted">Examination</span><span class="font-semibold text-ink tabular-nums text-right">{{ $w['ex'] !== null ? rtrim(rtrim(number_format($w['ex'], 2), '0'), '.') . '%' : 'none' }}</span>
                        </div>
                        <span class="block text-[10px] text-muted mt-0.5">
                            {{ $w['source'] === 'catalog' ? 'From DepEd catalog match' : 'Assigned by grading group' }}
                            <i class="bi bi-info-circle text-gray-300" title="{{ $tooltip }}" aria-hidden="true"></i>
                        </span>
                    @else
                        <span class="text-gray-400" title="No catalog match and no Subject Group set — grading weights cannot be resolved until this subject is classified.">Not configured</span>
                    @endif
                </td>
                <td class="text-xs">{{ trim(($subject->track->name ?? '') . ($subject->specialization ? ' / ' . $subject->specialization->name : ''), ' /') ?: '—' }}</td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        <button type="button"
                            onclick='openEditSubjectModal(@json($subject))'
                            class="inline-flex items-center gap-1 btn btn-xs btn-secondary whitespace-nowrap">
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>
                        <form method="POST"
                              action="{{ route('admin.subjects.destroy', $subject->id) }}"
                              class="inline"
                              data-confirm="Delete subject {{ $subject->name }}?">
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
                <td colspan="6">
                    <x-empty-state message="No subjects yet." icon="bi-book"
                        hint='Use "Add Subject" or "Import Subjects" above to get started.' />
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
    <div class="modal-box bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200 scale-95 opacity-0">

        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTitle" class="text-lg font-semibold text-ink">Add Subject</h3>
            <button type="button" onclick="closeSubjectModal()"
                    aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <form id="subjectForm" method="POST" class="space-y-4"
              data-store-url="{{ route('admin.subjects.store') }}"
              data-spec-url="{{ url('admin/specializations-by-track') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">

            <div>
                <label class="form-label">Subject Name</label>
                <input type="text" name="name" id="subjectName" required
                       placeholder="e.g. Effective Communication"
                       class="form-input">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Type</label>
                    <select name="type" id="subjectType" required onchange="toggleTrackFields(); refreshSubjectGroupField();"
                            class="form-input">
                        <option value="">— Select Type —</option>
                        <option value="core">Core</option>
                        <option value="elective">Elective</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Grade Level</label>
                    <select name="grade_level" id="subjectGrade" required onchange="refreshSubjectGroupField()"
                            class="form-input">
                        <option value="">— Select Grade —</option>
                        <option value="11">Grade 11</option>
                        <option value="12">Grade 12</option>
                    </select>
                </div>
            </div>

            <div id="subjectGroupWrapper">
                <label class="form-label">
                    Subject Group
                    <span class="text-gray-400 text-xs">(DO 015, s. 2026 grading weight group — Grade 11 only; Grade 12 weighs by section track instead)</span>
                </label>
                <select name="subject_group" id="subjectGroupField"
                        class="form-input">
                    <option value="">— Select Subject Group —</option>
                    @foreach($subjectGroups as $group)
                        <option value="{{ $group }}" data-for-type="{{ $group === 'core_academic' ? 'core' : 'elective' }}">{{ $subjectGroupLabel($group) }}{{ $subjectGroupCoverText($group) ? ' — covers ' . $subjectGroupCoverText($group) : '' }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-muted mt-1">Resolved grading weights (WW / PT / Exam) are shown on the Subjects list — never typed in manually.</p>
            </div>

            <div id="trackFields" class="hidden space-y-4">
                <div>
                    <label class="form-label">Track</label>
                    <select name="track_id" id="subjectTrack"
                            onchange="loadSpecializations(this.value, 'subjectSpec', document.getElementById('subjectForm').dataset.specUrl)"
                            class="form-input">
                        <option value="">— Select Track —</option>
                        @foreach($tracks as $track)
                            <option value="{{ $track->id }}">{{ $track->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">
                        Specialization
                        <span class="text-gray-400 text-xs">(optional)</span>
                    </label>
                    <select name="specialization_id" id="subjectSpec"
                            class="form-input">
                        <option value="">— All specializations in track —</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeSubjectModal()"
                        class="btn btn-outline">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
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
    <div class="modal-box {{ $errors->import->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Import Subjects</h3>
            <button type="button" onclick="closeImportSubjectsModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
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
            <strong>name, type, grade_level</strong>. Never include WW/PT/Exam
            percentage columns — grading weights are always resolved
            automatically, from the DepEd catalog (by exact subject name) or
            from Subject Group, never typed into a file.
            <strong>subject_group</strong> is <strong>required for every Grade
            11</strong> row (core or elective — there is no safe default; an
            unclassified subject is rejected, never silently made Core) and
            must be <strong>left blank for Grade 12</strong> rows (DO 8, s. 2015
            weighs by section track, not subject group). It must also match
            the row's type — <strong>core_academic</strong> is for
            <strong>core</strong> rows only; an elective must use one of the
            other groups listed in the Subject Group column above.
            <strong>track, specialization</strong> (by name or code) are also
            required for elective subjects. Type must be <strong>core</strong>
            or <strong>elective</strong>; grade_level must be
            <strong>11</strong> or <strong>12</strong>.
        </p>

        <form method="POST" action="{{ route('admin.subjects.import') }}"
              enctype="multipart/form-data" class="space-y-4">
            @csrf

            <div>
                <label class="form-label">Grade Level being uploaded</label>
                <select name="grade_level" required
                        class="form-input">
                    <option value="">— Select Grade Level —</option>
                    <option value="11">Grade 11</option>
                    <option value="12">Grade 12</option>
                </select>
                <p class="text-xs text-muted mt-1">
                    Every row in the file must match this grade level — a row for
                    the other grade is rejected, not silently imported.
                </p>
            </div>

            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeImportSubjectsModal()"
                        class="px-4 py-2 text-sm text-muted">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
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