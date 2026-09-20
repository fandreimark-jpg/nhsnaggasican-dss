@extends('layouts.app')

@section('title', 'Subject Analysis')
@section('subtitle', 'Assessment component performance, failure rate, and at-risk count by subject — read-only')

@section('content')

@php
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    $withEvidence = collect($summaries)->filter(fn($r) => collect($r['components'])->filter()->isNotEmpty())->count();
    $needsAttention = collect($summaries)->filter(fn($r) => $r['weakest_component'] && (($r['components'][$r['weakest_component']]['status'] ?? null) === 'Needs Attention'))->count();
    $failingSubjects = collect($summaries)->filter(fn($r) => ($r['failure_rate'] ?? 0) > 0)->count();
@endphp

{{-- Unified filter toolbar — School Year / Section / Term, one card. --}}
<x-ui.filter-bar id="subjectAnalysisFilters" :clear="route('principal.subject-analysis', ['school_year' => $schoolYear])" :show-clear="(bool) ($selectedSection || $selectedTerm || ($selectedGradeLevel ?? null))">
    <div class="filter-field">
        <label class="form-label" for="saSchoolYear">School Year</label>
        <select id="saSchoolYear" name="school_year" onchange="this.form.submit()" class="form-select-sm">
            @foreach($schoolYears as $sy)
                <option value="{{ $sy }}" {{ $schoolYear === $sy ? 'selected' : '' }}>{{ $sy }}{{ !$isHistoricalYear && $sy === $schoolYear ? ' (active)' : '' }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label" for="saGradeLevel">Grade Level</label>
        <select id="saGradeLevel" name="grade_level" onchange="this.form.submit()" class="form-select-sm">
            <option value="">All grade levels</option>
            @foreach($gradeLevels ?? [] as $gl)
                <option value="{{ $gl }}" {{ (string) ($selectedGradeLevel ?? '') === (string) $gl ? 'selected' : '' }}>Grade {{ $gl }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label" for="saSection">Section</label>
        <select id="saSection" name="section_id" onchange="this.form.submit()" class="form-select-sm">
            <option value="">All sections</option>
            @foreach($sections as $section)
                <option value="{{ $section->id }}" {{ (string) $selectedSection === (string) $section->id ? 'selected' : '' }}>
                    {{ $section->name }} — Grade {{ $section->grade_level }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label" for="saTerm">Term</label>
        <select id="saTerm" name="term" onchange="this.form.submit()" class="form-select-sm">
            <option value="">All terms this school year</option>
            @foreach([1, 2, 3] as $t)
                <option value="{{ $t }}" {{ (string) $selectedTerm === (string) $t ? 'selected' : '' }}>
                    Term {{ $t }} {{ $currentTerm === $t ? '(currently open)' : '' }}
                </option>
            @endforeach
        </select>
    </div>
    <x-slot:trailing>
        <span class="pill"><span class="pill-label">Viewing</span> SY {{ $schoolYear }}{{ ($selectedGradeLevel ?? null) ? ' · Grade ' . $selectedGradeLevel : '' }} · {{ $selectedTerm ? 'Term ' . $selectedTerm : 'All terms' }}</span>
        @if($isHistoricalYear)
            <x-ui.status-badge tone="gray" icon="bi-archive" label="Historical Record" />
        @endif
    </x-slot:trailing>
</x-ui.filter-bar>

{{-- Scope summary — counts of what the table below contains. --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
    <x-stat-card label="Subjects with evidence" :value="$withEvidence" accent="count-2" icon="book" />
    <x-stat-card label="Subjects needing attention" :value="$needsAttention" :accent="$needsAttention > 0 ? 'status-attention' : 'count-8'" icon="exclamation-triangle" note="Weakest component below the 75 target" />
    <x-stat-card label="Subjects with failures" :value="$failingSubjects" :accent="$failingSubjects > 0 ? 'status-failing' : 'count-8'" icon="x-octagon" note="Official grade 74 and below, verified grades only" />
</div>

<x-panel title="Subject Analysis" :padded="false"
    subtitle="Average score per assessment component, failure rate, and at-risk count, across every student with evidence in the selected scope. A subject can look fine overall while one component quietly needs attention.">

    <div class="tbl-scroll">
    <table class="tbl tbl-sticky">
        <thead>
            <tr>
                <th scope="col">Subject</th>
                <th scope="col">Written Work</th>
                <th scope="col">Performance Task</th>
                <th scope="col">Examination</th>
                <th scope="col">Weakest Component</th>
                <th scope="col" class="tbl-num">Failure Rate</th>
                <th scope="col" class="tbl-num">At-Risk Count</th>
            </tr>
        </thead>
        <tbody>
            @forelse($summaries as $row)
            <tr>
                <td class="font-medium text-ink whitespace-nowrap">{{ $row['subject']->name }}
                    <span class="block text-xs text-muted font-normal">Grade {{ $row['subject']->grade_level }} · {{ ucfirst($row['subject']->type) }}</span>
                </td>
                @foreach(['written_work', 'performance_task', 'examination'] as $key)
                    @php $c = $row['components'][$key]; @endphp
                    <td>
                        @if($c === null)
                            <span class="badge badge-outline">No data</span>
                        @else
                            <a href="{{ route('principal.students', ['subject_id' => $row['subject']->id, 'focus' => $key]) }}"
                               class="block hover:opacity-80" title="See the students behind this number">
                                <x-ui.metric-bar :value="$c['avg_percentage']" />
                            </a>
                            <span class="block text-[11px] text-muted mt-1">{{ $c['student_count'] }} student{{ $c['student_count'] === 1 ? '' : 's' }}</span>
                        @endif
                    </td>
                @endforeach
                <td>
                    @if($row['weakest_component'] && ($row['components'][$row['weakest_component']]['status'] ?? null) === 'Needs Attention')
                        <a href="{{ route('principal.students', ['subject_id' => $row['subject']->id, 'focus' => $row['weakest_component']]) }}" class="hover:opacity-80">
                            <x-ui.status-badge tone="warning" icon="bi-exclamation-triangle-fill" :label="$componentLabels[$row['weakest_component']] ?? $row['weakest_component']" />
                        </a>
                        <span class="block text-xs text-muted mt-1">
                            {{ $row['below_target_count'] }} student{{ $row['below_target_count'] === 1 ? '' : 's' }} below target
                        </span>
                    @elseif($row['weakest_component'])
                        <x-ui.status-badge tone="success" icon="bi-check-circle-fill" label="All on track" />
                    @else
                        <span class="badge badge-outline">No data</span>
                    @endif
                </td>
                <td class="tbl-num">
                    @if($row['failure_rate'] === null)
                        <span class="badge badge-outline">No grades yet</span>
                    @else
                        <span class="font-semibold {{ $row['failure_rate'] > 0 ? 'text-status-failing' : 'text-status-ontrack' }}">
                            {{ number_format($row['failure_rate'], 1) }}%
                        </span>
                        <span class="block text-xs text-muted">{{ $row['failing_count'] }} of {{ $row['graded_count'] }}</span>
                    @endif
                </td>
                <td class="tbl-num">
                    @if($row['at_risk_count'] > 0)
                        <a href="{{ route('principal.students', ['subject_id' => $row['subject']->id]) }}"
                           class="badge badge-danger hover:opacity-80">{{ $row['at_risk_count'] }}</a>
                    @else
                        <span class="text-muted">0</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7">
                    <x-empty-state icon="bi bi-bar-chart" message="No assessment evidence for this scope yet."
                        hint="This fills in once advisers upload and import assessment forms for their sections, or try clearing the section/term filters." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    {{-- "Student identity and term-specific subject offerings" pass, STEP J
         — offered in this section/term but with nothing recorded yet. Only
         shown when one section AND one term are selected, because an
         offering is per section per term. A subject offered in Term 1
         only is simply absent under Term 2 — not listed here as "missing". --}}
    @if(($offeredWithoutData ?? null) !== null)
        <div class="px-5 py-3 border-t border-line text-xs text-muted">
            @if($offeredWithoutData->isEmpty())
                <i class="bi bi-check2-circle"></i> Every subject assigned to this section for Term {{ $selectedTerm }} has evidence, grades, or risk results above.
            @else
                <i class="bi bi-journal"></i> Assigned to this section for Term {{ $selectedTerm }} but with nothing recorded yet:
                <strong>{{ $offeredWithoutData->pluck('name')->implode(', ') }}</strong>.
            @endif
        </div>
    @endif
</x-panel>

@endsection
