@extends('layouts.app')

@section('title', 'Section Subjects')
@section('subtitle', 'The subjects a section takes in each academic term, resolved from the subject configuration')

@section('content')

@php
    $fmtWeight = fn($w) => $w === null ? '—' : rtrim(rtrim(number_format((float) $w, 2), '0'), '.') . '%';
    $sourceBadge = [
        \App\Services\SubjectApplicabilityService::SOURCE_CORE           => ['badge-brand', 'Core'],
        \App\Services\SubjectApplicabilityService::SOURCE_TRACK          => ['badge-info', 'Track / Specialization'],
        \App\Services\SubjectApplicabilityService::SOURCE_SECTION_CHOICE => ['badge-success', 'Section choice'],
    ];
    $curriculumLabel = $section->curriculum === 'sshs'
        ? 'Strengthened SHS (DO 015, s. 2026)'
        : ($section->curriculum === 'k12_2013' ? '2013 curriculum (DO 8, s. 2015)' : 'Curriculum inferred from grade level');
@endphp

{{-- "Subject applicability" refactor (2026-09-20) — a RESOLVED view.
     Which subjects apply, and in which terms, is configured once under
     Admin > Subjects (grade level, track/specialization, Terms Taught)
     and resolved here by SubjectApplicabilityService for every section.
     Nothing on this page assigns a normal curriculum subject; the one
     per-section action is choosing an elective for a section the
     curriculum cannot match one to. Every figure under Grading Profile
     is resolved by GradingEngine::resolveWeightProfile(), read-only. --}}

<div class="mb-4">
    <a href="{{ route('admin.sections') }}" class="text-sm text-brand-700 hover:underline"><i class="bi bi-arrow-left"></i> Back to Sections</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-8 gap-y-3 text-sm">
            <div>
                <p class="text-xs text-gray-500">Section</p>
                <p class="font-semibold text-ink text-base">{{ $section->name }}</p>
                <p class="text-xs text-muted">Grade {{ $section->grade_level }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ $usesTrackElectives ? 'Track / Specialization' : 'Track' }}</p>
                <p class="font-semibold text-ink text-base">{{ $section->track->name ?? '—' }}</p>
                @if($usesTrackElectives)
                    <p class="text-xs text-muted">{{ $section->specialization->name ?? 'No specialization (whole-track electives apply)' }}</p>
                @else
                    <p class="text-xs text-muted">Strengthened SHS has no strands — electives are chosen per section</p>
                @endif
            </div>
            <div>
                <p class="text-xs text-gray-500">Academic Year</p>
                <p class="font-semibold text-ink text-base">{{ $section->school_year }}</p>
                <p class="text-xs text-muted">{{ $curriculumLabel }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Adviser</p>
                <p class="font-semibold text-ink text-base">{{ $section->adviser->name ?? 'Unassigned' }}</p>
                <p class="text-xs text-muted">{{ $openTerm ? 'Term ' . $openTerm . ' is open for encoding' : 'No term is open' }}</p>
            </div>
        </div>

        {{-- Term switcher — one tab per academic term, with how many subjects resolve in it. --}}
        <div class="mt-4 flex flex-wrap items-center gap-2" role="tablist" aria-label="Academic Term">
            @foreach($termNumbers as $t)
                <a href="{{ route('admin.sections.subjects', ['section' => $section->id, 'term' => $t]) }}"
                   role="tab" aria-selected="{{ $t === $term ? 'true' : 'false' }}"
                   class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm border {{ $t === $term ? 'bg-brand-800 text-white border-brand-800' : 'bg-white text-ink border-line hover:bg-brand-50' }}">
                    Term {{ $t }}
                    <span class="text-xs {{ $t === $term ? 'bg-white/20' : 'bg-surface' }} px-1.5 py-0.5 rounded">{{ $countsPerTerm[$t] }}</span>
                    @if($openTerm === $t)<span class="text-[10px] uppercase tracking-wide {{ $t === $term ? 'text-white/80' : 'text-brand-700' }}">open</span>@endif
                </a>
            @endforeach
        </div>
    </div>
</div>

@if($errors->assign->any())
    <div class="alert alert-danger mb-4">
        <ul class="list-disc list-inside">
            @foreach($errors->assign->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
    <div class="xl:col-span-2">
        <x-panel :padded="false" title="Term {{ $term }} Subjects" subtitle="{{ $section->name }} · {{ $section->school_year }} · resolved from the subject configuration">
            @if($subjects->isEmpty())
                <div class="p-4">
                    <x-empty-state icon="bi-journal-x"
                        message="No subject applies to {{ $section->name }} in Term {{ $term }}."
                        hint="A subject applies here when it is a Grade {{ $section->grade_level }} subject taught in Term {{ $term }} (Terms Taught under Admin > Subjects) and is core, matched by this section's track, or chosen for this section below." />
                </div>
            @else
                <div class="tbl-scroll">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th scope="col">Subject</th>
                            <th scope="col">Applies because</th>
                            <th scope="col">Terms Taught</th>
                            <th scope="col">Grading Profile <span class="font-normal text-gray-400">(WW / PT / Exam — resolved)</span></th>
                            <th scope="col" class="text-right">Section choice</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($subjects as $subject)
                            <tr>
                                <td class="font-medium text-ink">
                                    {{ $subject->name }}
                                    <span class="block text-xs text-muted mt-0.5">{{ $subject->profile['group_label'] }}</span>
                                    @if($subject->history->isNotEmpty())
                                        <span class="block text-xs text-muted mt-0.5" title="{{ $subject->history->map(fn($n, $t) => $n . ' ' . str_replace('_', ' ', $t))->implode(', ') }}">
                                            <i class="bi bi-archive"></i> has Term {{ $term }} records
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @php [$cls, $label] = $sourceBadge[$subject->source] ?? ['badge-gray', 'Unknown']; @endphp
                                    <span class="badge {{ $cls }}" title="{{ $subject->source_label }}">{{ $label }}</span>
                                </td>
                                <td class="text-xs">{{ $subject->termsLabel() }}</td>
                                <td class="text-sm">
                                    @if($subject->profile['error'])
                                        <span class="text-danger-text">Not resolvable — classify this subject first</span>
                                    @else
                                        <span class="font-medium">{{ $fmtWeight($subject->profile['ww']) }} / {{ $fmtWeight($subject->profile['pt']) }} / {{ $fmtWeight($subject->profile['ex']) }}</span>
                                        <span class="block text-xs text-muted">{{ $subject->profile['source'] }}</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if($subject->source === \App\Services\SubjectApplicabilityService::SOURCE_SECTION_CHOICE)
                                        @if($subject->history_any_term)
                                            <span class="text-xs text-muted" title="Academic records exist for this elective in this section; the choice cannot be removed. Narrow its Terms Taught under Admin > Subjects if it should stop in a later term.">Kept — has records</span>
                                        @else
                                            <form method="POST"
                                                  action="{{ route('admin.sections.subjects.destroy', ['section' => $section->id, 'subject' => $subject->id]) }}"
                                                  class="inline"
                                                  data-action-confirm="Remove {{ $subject->name }} as a chosen elective for {{ $section->name }}? No academic records exist for it yet, so nothing is lost."
                                                  data-action-title="Confirm Removal" data-action-label="Yes, Remove" data-action-icon="bi-x-circle" data-action-loading="Removing...">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="term" value="{{ $term }}">
                                                <button type="submit" class="btn btn-xs btn-outline"><i class="bi bi-x-circle"></i> Remove choice</button>
                                            </form>
                                        @endif
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
            <div class="px-4 py-3 border-t border-line text-xs text-muted">
                Source: subject configuration under <a href="{{ route('admin.subjects') }}" class="text-brand-700 hover:underline">Admin &gt; Subjects</a>
                (grade level, track / specialization, Terms Taught). Whether Term {{ $term }} is open for encoding is a separate setting under Academic Terms.
            </div>
        </x-panel>
    </div>

    <div>
        <x-panel title="Choose an Elective" subtitle="For electives the curriculum cannot match to this section">
            @if($usesTrackElectives)
                <p class="text-sm text-muted mb-3">
                    Electives reach {{ $section->name }} through its track and specialization automatically. Use this only for an elective outside that match that the section genuinely takes.
                </p>
            @else
                <p class="text-sm text-muted mb-3">
                    Strengthened SHS has no strands, so an SSHS section's electives are chosen here. A choice applies in every term the elective is taught (Terms Taught under Admin &gt; Subjects).
                </p>
            @endif
            @if($choiceCandidates->isEmpty())
                <p class="text-sm text-muted">
                    No Grade {{ $section->grade_level }} elective is left to choose — every one already applies to {{ $section->name }}, or none exists yet.
                    Add electives under <a href="{{ route('admin.subjects') }}" class="text-brand-700 hover:underline">Admin &gt; Subjects</a>.
                </p>
            @else
                <form method="POST" action="{{ route('admin.sections.subjects.store', $section->id) }}" data-loading="Recording choice...">
                    @csrf
                    <input type="hidden" name="term" value="{{ $term }}">
                    <div class="space-y-3">
                        <div>
                            <label for="choose_subject_id" class="form-label">Elective</label>
                            <select name="subject_id" id="choose_subject_id" class="form-select" required>
                                <option value="">Select an elective</option>
                                @foreach($choiceCandidates as $subject)
                                    <option value="{{ $subject->id }}"
                                            data-terms="{{ $subject->termsLabel() }}"
                                            data-group="{{ $subject->profile['group_label'] }}"
                                            data-ww="{{ $fmtWeight($subject->profile['ww']) }}"
                                            data-pt="{{ $fmtWeight($subject->profile['pt']) }}"
                                            data-ex="{{ $subject->profile['ex'] === null && !$subject->profile['error'] ? 'No examination component' : $fmtWeight($subject->profile['ex']) }}"
                                            data-source="{{ $subject->profile['source'] }}"
                                            data-error="{{ $subject->profile['error'] ? 'Grading profile not resolvable — classify this subject under Admin > Subjects first.' : '' }}"
                                            {{ old('subject_id') == $subject->id ? 'selected' : '' }}>
                                        {{ $subject->name }} ({{ $subject->termsLabel() }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Resolved grading profile of the selected elective — display only. --}}
                        <div id="chooseProfile" class="rounded-lg border border-line bg-surface p-3 text-sm" hidden>
                            <p class="text-xs text-gray-500 mb-1">Grading profile (resolved — read-only)</p>
                            <p><span id="chooseProfileName" class="font-semibold text-ink"></span> <span class="text-muted">·</span> <span id="chooseProfileGroup"></span> <span class="text-muted">·</span> Taught in <span id="chooseProfileTerms"></span></p>
                            <dl id="chooseProfileWeights" class="grid grid-cols-3 gap-2 mt-2">
                                <div><dt class="text-xs text-gray-500">Written Work</dt><dd id="chooseProfileWw" class="font-medium"></dd></div>
                                <div><dt class="text-xs text-gray-500">Performance Task</dt><dd id="chooseProfilePt" class="font-medium"></dd></div>
                                <div><dt class="text-xs text-gray-500">Examination</dt><dd id="chooseProfileEx" class="font-medium"></dd></div>
                            </dl>
                            <p id="chooseProfileSource" class="text-xs text-muted mt-2"></p>
                            <p id="chooseProfileError" class="text-xs text-danger-text mt-2" hidden></p>
                        </div>

                        <button type="submit" class="btn btn-primary w-full"><i class="bi bi-plus-lg"></i> Choose for {{ $section->name }}</button>
                    </div>
                </form>
            @endif
        </x-panel>
    </div>
</div>

<script>
    (function () {
        var select = document.getElementById('choose_subject_id');
        if (!select) return;
        var box = document.getElementById('chooseProfile');
        function render() {
            var opt = select.options[select.selectedIndex];
            if (!opt || !opt.value) { box.hidden = true; return; }
            document.getElementById('chooseProfileName').textContent = opt.textContent.replace(/\s*\([^)]*\)\s*$/, '').trim();
            document.getElementById('chooseProfileGroup').textContent = opt.dataset.group;
            document.getElementById('chooseProfileTerms').textContent = opt.dataset.terms;
            document.getElementById('chooseProfileWw').textContent = opt.dataset.ww;
            document.getElementById('chooseProfilePt').textContent = opt.dataset.pt;
            document.getElementById('chooseProfileEx').textContent = opt.dataset.ex;
            document.getElementById('chooseProfileSource').textContent = opt.dataset.error ? '' : 'Source: ' + opt.dataset.source;
            var err = document.getElementById('chooseProfileError');
            err.textContent = opt.dataset.error;
            err.hidden = !opt.dataset.error;
            document.getElementById('chooseProfileWeights').hidden = !!opt.dataset.error;
            box.hidden = false;
        }
        select.addEventListener('change', render);
        render();
    })();
</script>

@endsection
