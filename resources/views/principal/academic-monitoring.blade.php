@extends('layouts.app')

@section('title', 'Academic Monitoring')
@section('subtitle', 'Academic concerns found in the current assessment evidence, For Review — read-only')

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
    $hasNarrowing = $filters['term'] !== null || $filters['grade_level'] || $filters['section_id'] || $filters['subject_id'] || $filters['status'] !== 'open';
@endphp

{{-- What this page is — and is not. --}}
<div class="alert alert-info mb-4" data-monitoring-explainer>
    <p>
        A learner appears here <strong>For Review</strong> when an approved monitoring rule finds a concern in the
        recorded assessment evidence. It is not a prediction and not a grade, and nothing on this page creates an
        intervention — you review the evidence and decide.
    </p>
    <p class="mt-1">
        The rule in use is <strong>Missing Requirement</strong>: the adviser explicitly marked a required assessment
        as Missing. A blank score (not yet given) is never a concern, and a recorded score of 0 is Completed, not Missing.
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
                    <p class="text-sm text-gray-600">{{ $group['subject']?->name ?? 'All subjects' }}</p>
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
                        $item = $e['assessment'] ?? [];
                        $itemLine = ($item['name'] ?? 'Assessment') . ' — ' . ($item['component_label'] ?? '') . (!empty($item['exam_role_label']) ? ' (' . $item['exam_role_label'] . ')' : '');
                    @endphp
                    <div class="rounded-lg border border-line p-3" data-concern-id="{{ $concern->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm">
                                @if($concern->isOpen())
                                    <x-ui.status-badge tone="warning" label="For Review" />
                                @else
                                    <x-ui.status-badge tone="success" label="Resolved" />
                                @endif
                                <span class="font-medium text-ink ml-1">{{ $concern->concern_label }}</span>
                                @if($concern->isOpen())
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

                        <details class="mt-2">
                            <summary class="text-xs text-brand-700 cursor-pointer">View Evidence</summary>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-xs mt-2" data-evidence>
                                <dt class="text-muted">Why this learner is listed</dt>
                                <dd class="text-ink">{{ $concern->concern_label }}: the adviser explicitly marked {{ $item['name'] ?? 'this assessment' }} as Missing.</dd>
                                <dt class="text-muted">Learner</dt>
                                <dd>{{ $group['student']->last_name }}, {{ $group['student']->first_name }}</dd>
                                <dt class="text-muted">Section</dt>
                                <dd>{{ $section->name }} — Grade {{ $section->grade_level }}</dd>
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
                                <dd>Not recorded — review actions are not enabled yet.</dd>
                            </dl>
                        </details>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <x-empty-state icon="bi bi-flag"
            :message="$filters['status'] === 'resolved' ? 'No resolved concerns in this scope.' : 'No learners are For Review in this scope.'"
            hint="A concern appears when an adviser explicitly marks a required assessment as Missing. Blank scores are not concerns." />
    @endforelse
</x-panel>

@endsection
