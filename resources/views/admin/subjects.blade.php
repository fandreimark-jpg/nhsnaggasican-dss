@extends('layouts.app')

@section('title', 'Subjects')
@section('subtitle', 'Configure each subject — what it is, which grade and track it belongs to, and the terms it is taught in')

@section('content')

@php
    // DO 015, s. 2026's subject group names — the one label map lives on
    // SubjectGroupWeight::LABELS so Admin > Sections > Subjects reads the
    // same words. Purely a display label, never a weight or share itself.
    $subjectGroupLabel = fn($group) => \App\Models\SubjectGroupWeight::labelFor($group);

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

{{-- Every validation error the Subject form can raise (name, type, grade
     level, subject_group, track, specialization, terms) — a hand-picked
     key list used to swallow a subject_group rejection silently. The
     'deletion' key is rendered by the layout itself, so it is left out. --}}
@php $formErrors = collect($errors->getMessages())->except('deletion')->flatten(); @endphp
@if($formErrors->isNotEmpty())
    <div class="alert alert-danger mb-4" role="alert">
        <ul class="list-disc list-inside">
            @foreach($formErrors as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    </div>
@endif

{{-- "Subject applicability" refactor (2026-09-20) — this page is THE
     configuration point for where and when a subject applies. Every
     section resolves its subject list from what is set here
     (SubjectApplicabilityService); nothing is assigned section by section. --}}

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
                <th scope="col">Terms Taught</th>
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
                    @endif
                    {{-- Subject Group, Grade 11 and Grade 12 alike. A row with none
                         (only possible for data created before the group became
                         required) is a configuration-needed state, never guessed. --}}
                    @if($subject->subject_group)
                        <span class="block text-xs text-muted mt-0.5" data-subject-group="{{ $subject->subject_group }}">{{ $subjectGroupLabel($subject->subject_group) }}</span>
                    @else
                        <span class="block mt-0.5"><span class="badge badge-warning" title="No Subject Group is set — open Edit and choose the group this subject belongs to.">Subject Group needed</span></span>
                    @endif
                </td>
                <td class="text-xs">
                    {{-- Grading Profile — system-resolved by GradingEngine
                         (resolveSubjectProfile -> resolveWeightProfile, the
                         same path computeGrade() takes), read-only, Grade 11
                         and Grade 12 alike. Never an editable field. --}}
                    @php
                        $w = $subject->grading_weights_display;
                        $pct = fn($v) => $v !== null ? rtrim(rtrim(number_format($v, 2), '0'), '.') . '%' : 'none';
                    @endphp
                    @if(($w['source'] ?? null) === 'section_context')
                        @php
                            $variantText = collect($w['variants'])->map(fn($v) => "{$v['track']} section: WW {$pct($v['ww'])} / PT {$pct($v['pt'])} / Exam {$pct($v['ex'])} ({$v['label']})")->implode('; ');
                        @endphp
                        <span class="text-gray-500 cursor-help" data-grading-source="section_context" title="The section's track decides the split under DO 8, s. 2015, and the tracks in the system resolve differently: {{ $variantText }}">Resolved by section context</span>
                        <span class="block text-[10px] text-muted mt-0.5">{{ $variantText }}</span>
                    @elseif($w)
                        @php
                            $sourceNote = $w['source'] === 'catalog' ? 'From DepEd catalog match' : (($w['scheme'] ?? null) === 'do8_2015' ? 'Resolved from track and subject type' : 'Assigned by grading group');
                            $tooltip = "WW {$pct($w['ww'])} / PT {$pct($w['pt'])} / Exam {$pct($w['ex'])}. Source: {$w['label']}. Grade {$subject->grade_level}.";
                        @endphp
                        <div class="inline-grid grid-cols-[auto_auto] gap-x-3 gap-y-0.5 text-[11px] leading-tight cursor-help" title="{{ $tooltip }}" data-grading-source="{{ $w['source'] }}" data-grading-key="{{ $w['group_key'] ?? 'catalog' }}">
                            <span class="text-muted">Written Work</span><span class="font-semibold text-ink tabular-nums text-right">{{ $pct($w['ww']) }}</span>
                            <span class="text-muted">Performance Task</span><span class="font-semibold text-ink tabular-nums text-right">{{ $pct($w['pt']) }}</span>
                            <span class="text-muted">Examination</span><span class="font-semibold text-ink tabular-nums text-right">{{ $pct($w['ex']) }}</span>
                        </div>
                        <span class="block text-[10px] text-muted mt-0.5">
                            {{ $sourceNote }} — {{ $w['label'] }}
                            <i class="bi bi-info-circle text-gray-300" title="{{ $tooltip }}" aria-hidden="true"></i>
                        </span>
                    @else
                        <span class="text-gray-400" title="No catalog match and no Subject Group set — grading weights cannot be resolved until this subject is classified.">Not configured</span>
                    @endif
                </td>
                <td class="text-xs">{{ trim(($subject->track->name ?? '') . ($subject->specialization ? ' / ' . $subject->specialization->name : ''), ' /') ?: '—' }}</td>
                <td>
                    @php $taught = $subject->termNumbers(); @endphp
                    @if($taught === [])
                        <span class="badge badge-warning" title="No term is set — this subject does not apply to any section until Terms Taught is configured.">No term set</span>
                    @else
                        <span class="inline-flex gap-1" title="Taught in {{ implode(', ', array_map(fn($t) => 'Term ' . $t, $taught)) }}">
                            @foreach($termNumbers as $t)
                                <span class="badge {{ in_array($t, $taught, true) ? 'badge-brand' : 'badge-outline text-gray-300' }}">T{{ $t }}</span>
                            @endforeach
                        </span>
                    @endif
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        <button type="button"
                            onclick='openEditSubjectModal(@json($subject), @json($subject->termNumbers()))'
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
                <td colspan="7">
                    <x-empty-state message="No subjects yet." icon="bi-book"
                        hint='Use "Add Subject" above to get started.' />
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
              data-update-url="{{ route('admin.subjects.update', ['id' => '__ID__']) }}"
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
                    {{-- Grade Level has no effect on the Subject Group control. --}}
                    <select name="grade_level" id="subjectGrade" required
                            class="form-input">
                        <option value="">— Select Grade —</option>
                        <option value="11">Grade 11</option>
                        <option value="12">Grade 12</option>
                    </select>
                </div>
            </div>

            {{-- Subject Group — the subject's classification, required for
                 Grade 11 and Grade 12 alike ("Subject Group for both grade
                 levels" pass). The option list is the one authoritative
                 source (SubjectGroupWeight::allGroups()); modal.js only
                 narrows it to the selected Type (core_academic is Core-only).
                 Grade level never hides, disables or clears this control. --}}
            <div id="subjectGroupWrapper">
                <label class="form-label" for="subjectGroupField">Subject Group</label>
                <select name="subject_group" id="subjectGroupField" required
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
                    {{-- Still nullable: "— All specializations in track —" is a
                         valid choice (the elective applies to the whole track). --}}
                    <label class="form-label" for="subjectSpec">Specialization</label>
                    <select name="specialization_id" id="subjectSpec"
                            class="form-input">
                        <option value="">— All specializations in track —</option>
                    </select>
                </div>
            </div>

            <fieldset id="termsTaughtFieldset">
                <legend class="form-label">
                    Terms Taught
                    <span class="text-gray-400 text-xs">(select every academic term this subject is taught in)</span>
                </legend>
                <div class="flex flex-wrap gap-4 mt-1">
                    @foreach($termNumbers as $t)
                        <label class="inline-flex items-center gap-2 text-sm text-ink cursor-pointer">
                            <input type="checkbox" name="terms[]" value="{{ $t }}" data-term-checkbox
                                   class="rounded border-gray-300 text-brand-700 focus:ring-brand-400">
                            Term {{ $t }}
                        </label>
                    @endforeach
                </div>
                <p class="form-help">Sections resolve this subject automatically in these terms only. Whether a term is <em>open</em> for encoding is set separately under Academic Terms.</p>
            </fieldset>

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

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTableSearch('subjectSearch', '.subject-row', 'noSubjectResults');
    });
</script>
@endpush

@endsection