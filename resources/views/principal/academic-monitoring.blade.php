@extends('layouts.app')

@section('title', 'Academic Monitoring')
@section('subtitle', 'Academic concerns found in the current assessment evidence, For Review')

@section('content')

@php
    $fmt = fn($date) => $date ? $date->format('M j, Y g:i A') : '—';
    $statusLabel = fn(?string $s) => $s ? \App\Services\AssessmentStatusService::label($s) : 'Unknown';
    $sectionPlacement = function ($section) {
        if (!$section) return '';
        $parts = [];
        if ($section->track) $parts[] = $section->track->name;
        if ($section->specialization) {
            $parts[] = $section->specialization->name;
        } elseif ($section->curriculum === 'sshs') {
            $parts[] = 'Strengthened SHS';
        }
        return implode(' · ', $parts);
    };
    $hasNarrowing = $filters['term'] !== null || $filters['grade_level'] || $filters['section_id'] || $filters['subject_id'] || $filters['concern_type'] || $filters['status'] !== 'open';
    $subjectKey = \App\Monitoring\Rules\OfficialFailingSubjectRule::KEY;
    // Same display labels the Interventions pages use.
    $ivTypeLabel = fn(?string $t) => \App\Models\Intervention::typeLabel($t);
    $ivStatusLabel = fn(?string $s) => ucfirst(str_replace('_', ' ', (string) $s));
@endphp

@include('partials.validation-errors')

{{-- What this page is — and is not. --}}
<div class="alert alert-info mb-4" data-monitoring-explainer>
    <p>
        A learner appears here <strong>For Review</strong> when an approved monitoring rule finds a concern in the
        recorded evidence. It is not a prediction and not a grade, and nothing here creates an intervention
        on its own — you review the evidence and decide.
    </p>
    <p class="mt-1">
        <strong>Mark as Reviewed</strong> only records that you inspected the evidence: the concern stays open and no
        intervention is created. <strong>Record Intervention</strong> opens the intervention form for this learner; an
        intervention exists only when you choose one and submit it.
    </p>
    <p class="mt-1">
        Two rules are in use, and neither introduces a new threshold.
        <strong>Missing Requirement</strong>: the adviser marked a required assessment as Missing (a blank score is
        never a concern, and a recorded 0 is Completed). <strong>Subject Performance Concern</strong>: a subject's OFFICIAL grade is Failing (the DepEd failing mark already used for official grades). Each subject is evaluated on its own, so a learner's overall average never hides a failing subject; an in-term Below Target reading is not an official result and opens no concern.
        A concern is <strong>Resolved</strong> automatically when that evidence changes, and kept as history.
    </p>
</div>

<x-ui.filter-bar id="academicMonitoringFilters" :action="route('principal.academic-monitoring')"
    :clear="route('principal.academic-monitoring', ['school_year' => $filters['school_year']])" :show-clear="$hasNarrowing">
    <div class="filter-field">
        <label class="form-label" for="amSchoolYear">School Year</label>
        <select id="amSchoolYear" name="school_year" onchange="this.form.submit()" class="form-select-sm">
            @foreach($schoolYears as $sy)
                <option value="{{ $sy }}" {{ $filters['school_year'] === $sy ? 'selected' : '' }}>{{ $sy }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label" for="amTerm">Term</label>
        <select id="amTerm" name="term" onchange="this.form.submit()" class="form-select-sm">
            <option value="" {{ $filters['term'] === null ? 'selected' : '' }}>All terms</option>
            @foreach($terms as $t)
                <option value="{{ $t }}" {{ $filters['term'] === $t ? 'selected' : '' }}>Term {{ $t }}{{ $openTerm === $t ? ' (open)' : '' }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label" for="amGradeLevel">Grade Level</label>
        <select id="amGradeLevel" name="grade_level" onchange="this.form.submit()" class="form-select-sm">
            <option value="">All grade levels</option>
            @foreach($gradeLevels as $gl)
                <option value="{{ $gl }}" {{ (int) $filters['grade_level'] === (int) $gl ? 'selected' : '' }}>Grade {{ $gl }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label" for="amSection">Section</label>
        <select id="amSection" name="section_id" onchange="this.form.submit()" class="form-select-sm">
            <option value="">All sections</option>
            @foreach($sections as $section)
                <option value="{{ $section->id }}" {{ (int) $filters['section_id'] === $section->id ? 'selected' : '' }}>
                    {{ $section->name }} — Grade {{ $section->grade_level }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label" for="amSubject">Subject</label>
        <select id="amSubject" name="subject_id" onchange="this.form.submit()" class="form-select-sm">
            <option value="">All subjects</option>
            @foreach($subjects as $subject)
                <option value="{{ $subject->id }}" {{ (int) $filters['subject_id'] === $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label" for="amConcernType">Concern Type</label>
        <select id="amConcernType" name="concern_type" onchange="this.form.submit()" class="form-select-sm">
            <option value="">All</option>
            @foreach($concernTypes as $key => $label)
                <option value="{{ $key }}" {{ $filters['concern_type'] === $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label" for="amStatus">Status</label>
        <select id="amStatus" name="status" onchange="this.form.submit()" class="form-select-sm">
            <option value="open" {{ $filters['status'] === 'open' ? 'selected' : '' }}>For Review</option>
            <option value="resolved" {{ $filters['status'] === 'resolved' ? 'selected' : '' }}>Resolved</option>
            <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>All</option>
        </select>
    </div>
    <x-slot:trailing>
        @if($selectedSection)
            {{-- Track and Specialization come from the section; they are shown, not chosen. --}}
            <span class="pill" data-section-placement><span class="pill-label">{{ $selectedSection->name }}</span> {{ $sectionPlacement($selectedSection) ?: 'No track set' }}</span>
        @endif
        @if($isHistoricalYear)
            <x-ui.status-badge tone="gray" icon="bi-archive" label="Historical Record" />
        @endif
    </x-slot:trailing>
</x-ui.filter-bar>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
    <x-stat-card label="For Review" :value="$counts['open']" accent="count-4" icon="flag"
        note="Open concerns in the selected scope" />
    <x-stat-card label="Learners For Review" :value="$counts['learners_for_review']" accent="count-2" icon="people"
        note="Learners with at least one open concern" />
    <x-stat-card label="Resolved" :value="$counts['resolved']" accent="count-8" icon="check2-circle"
        note="Concerns whose evidence has since changed — kept as history" />
</div>

<x-panel title="{{ ['open' => 'For Review', 'resolved' => 'Resolved Concerns', 'all' => 'All Concerns'][$filters['status']] }}"
    subtitle="SY {{ $filters['school_year'] }} · {{ $filters['term'] ? 'Term ' . $filters['term'] : 'All terms' }} — grouped by learner and subject"
    :padded="false">

    @forelse($groups as $group)
        @php $section = $group['section']; @endphp
        <div class="border-b border-line last:border-b-0 px-4 py-4" data-concern-group>
            <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
                <div>
                    <p class="font-semibold text-ink">
                        {{ $group['student']->last_name }}, {{ $group['student']->first_name }}
                        @if($group['student']->lrn)
                            <span class="text-xs text-muted font-normal">LRN {{ $group['student']->lrn }}</span>
                        @endif
                    </p>
                    <p class="text-sm text-gray-600">{{ $group['subject']?->name ?? '—' }}</p>
                    <p class="text-xs text-muted">
                        {{ $section->name }} — Grade {{ $section->grade_level }}
                        @if($sectionPlacement($section)) · {{ $sectionPlacement($section) }} @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-1">
                    @if($group['open_count'] > 0)
                        <x-ui.status-badge tone="warning" icon="bi-flag" :label="'For Review · ' . $group['open_count'] . ' open ' . ($group['open_count'] === 1 ? 'concern' : 'concerns')" />
                    @endif
                    @if($group['resolved_count'] > 0)
                        <x-ui.status-badge tone="gray" icon="bi-check2" :label="$group['resolved_count'] . ' resolved'" />
                    @endif
                </div>
            </div>

            <div class="space-y-2">
                @foreach($group['concerns'] as $concern)
                    @php
                        $e = $concern->evidence;
                        $isSubject = $concern->rule_key === $subjectKey;
                        $item = $e['assessment'] ?? [];
                        $official = $e['official_grade'] ?? [];
                        $itemLine = ($item['name'] ?? 'Assessment') . ' — ' . ($item['component_label'] ?? '') . (!empty($item['exam_role_label']) ? ' (' . $item['exam_role_label'] . ')' : '');
                        $gradeLine = 'Official grade ' . (isset($official['grade']) ? number_format((float) $official['grade'], 2) : '—') . ' · ' . ($official['result'] ?? 'Failed');
                    @endphp
                    <div class="rounded-lg border border-line p-3" data-concern-id="{{ $concern->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm">
                                @if($concern->isOpen() && $concern->isReviewed())
                                    {{-- Still open (current evidence) — Reviewed never means Resolved. --}}
                                    <x-ui.status-badge tone="info" label="Open · Reviewed" />
                                @elseif($concern->isOpen())
                                    <x-ui.status-badge tone="warning" label="For Review" />
                                @else
                                    <x-ui.status-badge tone="success" label="Resolved" />
                                @endif
                                <span class="font-medium text-ink ml-1">{{ $concern->concern_label }}</span>
                                @if($isSubject)
                                    <span class="text-gray-600" data-official-grade-line>· {{ $e['subject']['name'] ?? '' }} · {{ $concern->isOpen() ? $gradeLine : 'was ' . $gradeLine }}</span>
                                @elseif($concern->isOpen())
                                    <span class="text-gray-600">· {{ $itemLine }} · {{ $e['status_label'] ?? 'Missing' }}</span>
                                @else
                                    {{-- A resolved concern's evidence status is history: say "was",
                                         and state what the item is NOW (a recorded 0 is Completed). --}}
                                    <span class="text-gray-600" data-status-history>· {{ $itemLine }} · was {{ $e['status_label'] ?? 'Missing' }} · now
                                        @if($concern->has_newer_open_concern && $concern->current_item_status === \App\Services\AssessmentStatusService::MISSING)
                                            Missing again
                                        @else
                                            {{ $statusLabel($concern->current_item_status) }}
                                        @endif
                                    </span>
                                @endif
                            </div>
                            <div class="text-xs text-muted">
                                Term {{ $concern->grading_period }} · First detected {{ $fmt($concern->first_detected_at) }}
                                @unless($concern->isOpen()) · Resolved {{ $fmt($concern->resolved_at) }} @endunless
                            </div>
                        </div>

                        @unless($concern->isOpen())
                            <p class="text-xs text-gray-600 mt-1" data-resolution-reason>{{ $concern->resolution_reason }}</p>
                        @endunless

                        @if($concern->isReviewed())
                            <p class="text-xs text-gray-600 mt-1" data-review-summary>
                                <i class="bi bi-eye"></i> Reviewed by {{ trim($concern->reviewedBy?->full_name ?? 'the Principal') }} on {{ $fmt($concern->reviewed_at) }}{{ $concern->isOpen() ? ' — still open until its evidence changes' : '' }}.
                                @if($concern->review_note)
                                    <span class="block text-gray-500">Note: {{ $concern->review_note }}</span>
                                @endif
                            </p>
                        @endif

                        {{-- Phase 2E actions — open concerns only; history is never edited here. --}}
                        @if($concern->isOpen())
                            @php
                                $advisers ??= []; $advisers[$section->id] ??= app(\App\Services\InterventionScheduleService::class)->resolveAdviser($section, null);
                                $ivPayload = [
                                    'responsible'         => $advisers[$section->id][0]?->name,
                                    'responsible_problem' => $advisers[$section->id][1],
                                    'academic_concern_id' => $concern->id,
                                    'student_id'          => $group['student']->id,
                                    'subject_id'          => $concern->subject_id,
                                    'grading_period'      => $concern->grading_period,
                                    'learner'             => $group['student']->last_name . ', ' . $group['student']->first_name,
                                    'context'             => $section->name . ' — ' . ($e['subject']['name'] ?? '') . ', Term ' . $concern->grading_period,
                                    'evidence'            => $isSubject
                                        ? $concern->concern_label . ': the official ' . ($e['subject']['name'] ?? 'subject') . ' grade is ' . (isset($official['grade']) ? number_format((float) $official['grade'], 2) : '—') . ' (Failed).'
                                        : $concern->concern_label . ': ' . ($item['name'] ?? 'assessment') . ' (' . ($item['component_label'] ?? '') . ') was recorded as Missing in the Class Record.',
                                ];
                            @endphp
                            <div class="flex flex-wrap items-start gap-3 mt-2" data-concern-actions>
                                @unless($concern->isReviewed())
                                    <details class="text-xs" data-review-action>
                                        <summary class="btn btn-secondary btn-xs cursor-pointer">Mark as Reviewed</summary>
                                        <form method="POST" action="{{ route('principal.academic-monitoring.review', $concern) }}" class="mt-2 space-y-2 max-w-md" data-loading="Saving review...">
                                            @csrf
                                            @if($reviewNoteAvailable)
                                                <label class="form-label" for="reviewNote{{ $concern->id }}">Review note <span class="text-gray-400">(optional)</span></label>
                                                <textarea id="reviewNote{{ $concern->id }}" name="review_note" rows="2" maxlength="1000" class="form-input" placeholder="What you checked, if anything to note"></textarea>
                                            @endif
                                            <p class="text-gray-500">This records that you inspected the evidence. The concern stays open and no intervention is created.</p>
                                            <button type="submit" class="btn btn-primary btn-xs">Confirm — Mark as Reviewed</button>
                                        </form>
                                    </details>
                                @endunless
                                @if($interventionLinkAvailable)
                                    <button type="button" class="btn btn-outline btn-xs" data-record-intervention
                                            onclick='openConcernInterventionModal(@json($ivPayload))'>
                                        <i class="bi bi-clipboard2-plus"></i> Record Intervention
                                    </button>
                                @endif
                            </div>
                        @endif

                        <details class="mt-2">
                            <summary class="text-xs text-brand-700 cursor-pointer">View Evidence</summary>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-xs mt-2" data-evidence>
                                <dt class="text-muted">Why this learner is listed</dt>
                                @if($isSubject)
                                <dd class="text-ink">{{ $concern->concern_label }}: the learner's OFFICIAL {{ $e['subject']['name'] ?? 'subject' }} grade for Term {{ $concern->grading_period }} is Failing. Evaluated for this subject alone — other subjects and the overall average do not change it.</dd>
                                @else
                                <dd class="text-ink">{{ $concern->concern_label }}: {{ $item['name'] ?? 'this assessment' }} was recorded as Missing in the Class Record.</dd>
                                @endif
                                <dt class="text-muted">Learner</dt>
                                <dd>{{ $group['student']->last_name }}, {{ $group['student']->first_name }}</dd>
                                <dt class="text-muted">Section</dt>
                                <dd>{{ $section->name }} — Grade {{ $section->grade_level }}</dd>
                                @if($isSubject)
                                <dt class="text-muted">Subject</dt>
                                <dd>{{ $e['subject']['name'] ?? ($group['subject']?->name ?? '—') }}</dd>
                                <dt class="text-muted">Official Grade</dt>
                                <dd data-official-grade>{{ isset($official['grade']) ? number_format((float) $official['grade'], 2) : '—' }}</dd>
                                <dt class="text-muted">Result</dt>
                                <dd>{{ $official['result'] ?? 'Failed' }} (Failing is {{ number_format((float) ($official['failing_threshold'] ?? 74), 0) }} and below)</dd>
                                <dt class="text-muted">Verified</dt>
                                <dd>{{ !empty($official['verified_at']) ? \Illuminate\Support\Carbon::parse($official['verified_at'])->format('M j, Y g:i A') : '—' }}</dd>
                                @else
                                <dt class="text-muted">Subject</dt>
                                <dd>{{ $e['subject']['name'] ?? ($group['subject']?->name ?? '—') }}</dd>
                                <dt class="text-muted">Assessment Item</dt>
                                <dd>{{ $item['name'] ?? '—' }}</dd>
                                <dt class="text-muted">Component</dt>
                                <dd>{{ $item['component_label'] ?? '—' }}{{ !empty($item['exam_role_label']) ? ' — ' . $item['exam_role_label'] : '' }}</dd>
                                <dt class="text-muted">Max Score</dt>
                                <dd>{{ isset($item['max_score']) ? rtrim(rtrim(number_format((float) $item['max_score'], 2), '0'), '.') : '—' }}</dd>
                                <dt class="text-muted">Assessment Status when detected</dt>
                                <dd>{{ $e['status_label'] ?? '—' }}</dd>
                                <dt class="text-muted">Current Assessment Status</dt>
                                <dd data-current-item-status>
                                    {{ $statusLabel($concern->current_item_status) }}
                                    @if($concern->has_newer_open_concern)
                                        <span class="text-muted">— marked Missing again; a newer open concern is listed for this item</span>
                                    @endif
                                </dd>
                                @endif
                                <dt class="text-muted">School Year / Term</dt>
                                <dd>{{ $concern->school_year }} · Term {{ $concern->grading_period }}</dd>
                                <dt class="text-muted">First Detected</dt>
                                <dd>{{ $fmt($concern->first_detected_at) }}</dd>
                                <dt class="text-muted">Last Evaluated</dt>
                                <dd>{{ $fmt($concern->last_evaluated_at) }}</dd>
                                <dt class="text-muted">Concern Status</dt>
                                <dd>{{ $concern->statusLabel() }}</dd>
                                @unless($concern->isOpen())
                                    <dt class="text-muted">Resolved At</dt>
                                    <dd>{{ $fmt($concern->resolved_at) }}</dd>
                                    <dt class="text-muted">Resolution Reason</dt>
                                    <dd>{{ $concern->resolution_reason }}</dd>
                                @endunless
                                <dt class="text-muted">Principal Review</dt>
                                <dd data-principal-review>
                                    @if($concern->isReviewed())
                                        Reviewed by {{ trim($concern->reviewedBy?->full_name ?? 'the Principal') }} on {{ $fmt($concern->reviewed_at) }}{{ $concern->review_note ? ' — ' . $concern->review_note : '' }}
                                    @else
                                        {{ $concern->isOpen() ? 'Not yet reviewed.' : 'Not reviewed before it was resolved.' }}
                                    @endif
                                </dd>
                                @if($interventionLinkAvailable)
                                    <dt class="text-muted">Intervention</dt>
                                    <dd data-linked-interventions>
                                        @forelse($concern->interventions as $iv)
                                            <span class="block" data-linked-intervention="{{ $iv->id }}">
                                                {{ $ivTypeLabel($iv->recommended_type) }} · {{ $ivStatusLabel($iv->status) }} · recorded {{ $fmt($iv->created_at) }}{{ $iv->createdBy ? ' by ' . trim($iv->createdBy->full_name) : '' }}
                                                {{-- The adviser's side, so delivery is visible here —
                                                     and never mistaken for the concern resolving. --}}
                                                <span class="block text-muted" data-intervention-delivery>
                                                    @if($iv->delivered_at)
                                                        Delivered {{ $fmt($iv->delivered_at) }}{{ $iv->deliveredBy ? ' by ' . trim($iv->deliveredBy->full_name) : '' }}@if($iv->delivery_notes) — “{{ $iv->delivery_notes }}”@endif
                                                        @if($concern->isOpen())
                                                            <span class="block">Delivery does not close this concern; it stays open until its evidence changes.</span>
                                                        @endif
                                                    @elseif($iv->acknowledged_at)
                                                        Acknowledged by the adviser {{ $fmt($iv->acknowledged_at) }} · not yet delivered
                                                    @else
                                                        Not yet acknowledged by the adviser
                                                    @endif
                                                </span>
                                            </span>
                                        @empty
                                            No intervention recorded for this concern.
                                        @endforelse
                                    </dd>
                                @endif
                            </dl>
                        </details>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <x-empty-state icon="bi bi-flag"
            :message="$filters['status'] === 'resolved' ? 'No resolved concerns in this scope.' : 'No learners are For Review in this scope.'"
            hint="A concern appears when a subject's official grade is Failing, or when an adviser explicitly marks a required assessment as Missing. Blank scores are not concerns." />
    @endforelse
</x-panel>

@if($interventionLinkAvailable)
{{-- Phase 2E — RECORD INTERVENTION from a concern. Posts to the EXISTING
     Principal\InterventionController::store() with its usual fields and
     validation, plus academic_concern_id. The type starts unselected: the
     Principal chooses; nothing is picked or submitted automatically. --}}
<div id="concernInterventionModal" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Record Intervention</h3>
            <button type="button" onclick="closeConcernInterventionModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <div class="bg-gray-50 rounded-lg p-3 mb-4 text-sm">
            <p class="font-medium text-ink" id="civLearner"></p>
            <p class="text-xs text-gray-500" id="civContext"></p>
            <p class="text-xs text-gray-700 mt-1"><span class="text-gray-500">Evidence:</span> <span id="civEvidence"></span></p>
        </div>

        <form method="POST" action="{{ route('principal.interventions.store') }}" class="space-y-4" data-loading="Recording intervention...">
            @csrf
            <input type="hidden" name="academic_concern_id" id="civConcernId">
            <input type="hidden" name="student_id" id="civStudentId">
            <input type="hidden" name="subject_id" id="civSubjectId">
            <input type="hidden" name="grading_period" id="civGradingPeriod">
            <input type="hidden" name="recommendation_reason" id="civReason">

            <div class="text-xs bg-gray-50 rounded-lg p-3" data-responsible-preview>
                <span class="text-gray-500">Responsible adviser (assigned automatically):</span>
                <span class="font-medium text-gray-700" id="civResponsible">—</span>
                <span class="block text-red-700 mt-1 hidden" id="civResponsibleProblem" role="alert"></span>
            </div>
            <div>
                <label class="form-label" for="civType">Intervention Type</label>
                <select name="recommended_type" id="civType" required class="form-input">
                    <option value="" selected disabled>— Choose an intervention —</option>
                    @foreach(\App\Models\Intervention::SELECTABLE_TYPES as $t)
                        <option value="{{ $t }}">{{ $ivTypeLabel($t) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="form-label" for="civNotes">Notes <span class="text-gray-400 text-xs">(optional)</span></label>
                <textarea name="principal_notes" id="civNotes" rows="3" class="form-input" placeholder="Any additional context for this decision..."></textarea>
            </div>

            <label class="flex items-start gap-2 text-xs text-gray-600 bg-gray-50 rounded-lg p-3 cursor-pointer">
                <input type="checkbox" name="recommendation_only" value="1" class="mt-0.5">
                <span>Record as a recommendation only — I will decide later</span>
            </label>
            <p class="text-xs text-gray-500">
                Submitting records your decision and links it to this concern; the adviser sees it on their Interventions page.
                The concern itself stays open until its evidence changes.
            </p>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeConcernInterventionModal()" class="px-4 py-2 text-sm text-muted">Cancel</button>
                <button type="submit" class="btn btn-primary" id="civSubmit">Record Intervention</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    window.openConcernInterventionModal = function (data) {
        document.getElementById('civLearner').textContent = data.learner;
        document.getElementById('civContext').textContent = data.context;
        document.getElementById('civEvidence').textContent = data.evidence;
        document.getElementById('civConcernId').value = data.academic_concern_id;
        document.getElementById('civStudentId').value = data.student_id;
        document.getElementById('civSubjectId').value = data.subject_id;
        document.getElementById('civGradingPeriod').value = data.grading_period;
        document.getElementById('civReason').value = data.evidence;
        const respName = document.getElementById('civResponsible');
        const respProblem = document.getElementById('civResponsibleProblem');
        respName.textContent = data.responsible || 'None — cannot be recorded';
        respProblem.textContent = data.responsible_problem || '';
        respProblem.classList.toggle('hidden', !data.responsible_problem);
        document.getElementById('civSubmit').disabled = !!data.responsible_problem;
        document.getElementById('civType').selectedIndex = 0;
        window.showModal('concernInterventionModal');
    };
    window.closeConcernInterventionModal = () => window.hideModal('concernInterventionModal');
    document.addEventListener('DOMContentLoaded', () => window.bindModalOverlayClose('concernInterventionModal'));
</script>
@endpush
@endif

@endsection
