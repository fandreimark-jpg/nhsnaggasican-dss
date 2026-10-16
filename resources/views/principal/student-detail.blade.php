@extends('layouts.app')

@section('title', 'Student Detail')
@section('subtitle', $student->last_name . ', ' . $student->first_name . ' — ' . ($section ? 'Grade ' . $section->grade_level . ' ' . $section->name : 'No section') . ' — School Year ' . $schoolYear . ' (read-only)')

@section('content')

@php
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
@endphp

<div class="mb-4">
    <a href="{{ url()->previous() }}" class="text-sm text-muted hover:underline"><i class="bi bi-arrow-left"></i> Back</a>
</div>

{{-- "Multi-school-year academic history" work order, PART 6 — the
     academic context this page is showing, and a way to switch year. --}}
<div class="card px-5 py-3 mb-4 flex flex-wrap items-center justify-between gap-3">
    <div class="text-sm text-gray-700 flex flex-wrap items-center gap-x-4 gap-y-1">
        <span><span class="text-xs text-gray-500">School Year:</span> <span class="font-medium">{{ $schoolYear }}</span></span>
        <span><span class="text-xs text-gray-500">Grade:</span> <span class="font-medium">{{ $section ? 'Grade ' . $section->grade_level : '—' }}</span></span>
        <span><span class="text-xs text-gray-500">Section:</span> <span class="font-medium">{{ $section->name ?? '—' }}</span></span>
        <span><span class="text-xs text-gray-500">LRN:</span> <span class="font-medium">{{ $student->lrn }}</span></span>
        @if($isHistoricalYear)
            <span class="badge badge-gray"><i class="bi bi-archive"></i> Historical Record</span>
        @endif
    </div>
    @if($enrollmentYears->count() > 1)
    <form method="GET" class="flex items-center gap-2">
        <input type="hidden" name="period" value="{{ $period }}">
        <label class="text-xs text-gray-500">Enrollment:</label>
        <select name="school_year" onchange="this.form.submit()" class="form-select-sm">
            @foreach($student->enrollments as $en)
                <option value="{{ $en->school_year }}" {{ $en->school_year === $schoolYear ? 'selected' : '' }}>
                    {{ $en->school_year }} — Grade {{ $en->grade_level }} {{ $en->section->name ?? '' }}
                </option>
            @endforeach
        </select>
    </form>
    @endif
</div>

{{-- Term selector --}}
<div class="card p-5 mb-4 flex flex-wrap justify-between items-center gap-3">
    <div>
        <p class="text-xs text-gray-500">Assessment evidence — School Year {{ $schoolYear }}</p>
        <p class="text-sm text-gray-600 mt-1">Recorded evidence per subject for the selected term. Academic concerns are listed on
            <a href="{{ route('principal.academic-monitoring', ['school_year' => $schoolYear]) }}" class="text-brand-700 underline hover:text-brand-800">Academic Monitoring</a>.</p>
    </div>
    <form method="GET" class="flex items-center gap-2">
        <label class="text-xs text-gray-500">Term:</label>
        @foreach([1, 2, 3] as $t)
            <a href="{{ route('principal.students.show', ['student' => $student->id, 'period' => $t, 'school_year' => $schoolYear]) }}"
               class="px-3 py-1 rounded-full text-xs font-medium border {{ $period == $t ? 'bg-brand-800 text-white border-brand-800' : 'bg-white text-gray-600 border-gray-300' }}">
                Term {{ $t }}
            </a>
        @endforeach
    </form>
</div>

{{-- Overall Monitoring (open Academic Concerns) is NOT academic performance:
     the per-subject evidence below can read On Track while this is For Review. --}}
{{-- Subject first: each subject's OFFICIAL Term grade on its own. The
     overall average is summary information and never hides a Failed subject. --}}
<div class="card p-5 mb-4" data-subject-results>
    <p class="text-xs text-gray-500">Official Subject Results — Term {{ $period }}</p>
    @if($subjectResults['subjects'] === 0)
        <p class="text-sm text-muted mt-1">No grades recorded for this term yet.</p>
    @else
        <div class="flex flex-wrap gap-x-6 gap-y-1 mt-1 text-sm">
            <span>Overall Average: <strong data-overall-average>{{ $subjectResults['overall_average'] !== null ? number_format($subjectResults['overall_average'], 2) : '—' }}</strong></span>
            <span>Passed: <strong>{{ $subjectResults['passed'] }}</strong></span>
            <span>Failed: <strong class="{{ $subjectResults['failed'] ? 'text-status-failing' : '' }}">{{ $subjectResults['failed'] }}</strong></span>
            @if($subjectResults['not_official'])
                <span class="text-muted">Not yet official: {{ $subjectResults['not_official'] }}</span>
            @endif
            @if($subjectResults['stale'])
                <span class="font-medium" data-stale-official>Evidence changed since verification — re-verify: {{ implode(', ', $subjectResults['stale_subjects']) }}</span>
            @endif
        </div>
        @foreach($subjectResults['failed_subjects'] as $f)
            <p class="text-sm text-status-failing mt-1" data-failed-subject>Failed: {{ $f['subject'] }} — {{ number_format($f['grade'], 2) }}</p>
        @endforeach
        <p class="text-xs text-muted mt-2">
            Overall Average summarizes performance across subjects. Monitoring also considers each subject on its own,
            so strong performance in other subjects does not hide a subject that requires review. A grade not yet
            verified is not an official result and is never shown as Failed.
        </p>
    @endif
</div>

<div class="card p-5 mb-4 flex flex-wrap items-start justify-between gap-3" data-overall-monitoring>
    <div>
        <p class="text-xs text-gray-500">Overall Monitoring — Term {{ $period }}</p>
        <div class="mt-1"><x-ui.monitoring-status :status="$monitoring" /></div>
        <p class="text-xs text-muted mt-2">
            From open Academic Concerns only, evaluated subject by subject — the overall average never hides a subject that needs review. Academic Performance per subject is shown below and is a separate reading.
            @if($resolvedConcernCount > 0)
                History: {{ $resolvedConcernCount }} resolved concern(s) this term.
            @endif
        </p>
    </div>
    <a href="{{ route('principal.academic-monitoring', ['school_year' => $schoolYear, 'term' => $period, 'status' => 'all']) }}"
       class="text-sm text-brand-700 hover:underline whitespace-nowrap">Academic Monitoring →</a>
</div>

{{-- Subject -> Component -> Evidence drill-down --}}
<div class="space-y-4 mb-4">
    @forelse($subjectAnalysis as $row)
    <div class="card overflow-hidden {{ $row['weakest_component'] && ($row['components'][$row['weakest_component']]['status'] ?? null) === 'Needs Attention' ? 'ring-1 ring-status-attention/30' : '' }}">
        <div class="px-5 py-3 border-b flex justify-between items-center">
            <h3 class="font-semibold text-ink">{{ $row['subject']->name }}</h3>
            <span class="font-semibold {{ $row['complete'] ? 'text-ink' : 'text-gray-300' }}">
                {{ $row['complete'] ? number_format($row['computed_grade'], 2) : 'Incomplete' }}
            </span>
        </div>

        {{-- Component breakdown --}}
        <div class="grid grid-cols-3 divide-x">
            @foreach(['written_work', 'performance_task', 'examination'] as $key)
                @php $c = $row['components'][$key]; @endphp
                <div class="p-3 text-center {{ $row['weakest_component'] === $key && $c['status'] === 'Needs Attention' ? 'bg-status-attention/5' : '' }}">
                    <p class="text-xs text-gray-500">{{ $componentLabels[$key] }}</p>
                    @if($c['percentage'] === null)
                        <p class="text-sm text-gray-300 mt-1">No data</p>
                    @else
                        <p class="text-lg font-bold {{ $c['status'] === 'On Track' ? 'text-status-ontrack' : 'text-status-risk' }} mt-1">
                            {{ number_format($c['percentage'], 1) }}%
                        </p>
                        <p class="text-xs text-muted">
                            gap {{ $c['gap'] >= 0 ? '+' : '' }}{{ number_format($c['gap'], 1) }} — {{ \App\Services\InTermStatusService::componentLabel($c['status']) }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Evidence: individual assessment items --}}
        @if($row['evidence']->isNotEmpty())
        <div class="border-t px-5 py-3">
            <p class="text-xs font-medium text-gray-500 mb-2">Evidence</p>
            <div class="tbl-scroll">
            <table class="tbl">
                <tbody>
                    @foreach($row['evidence'] as $item)
                    <tr>
                        <td class="text-gray-700">
                            {{ $item['name'] }}
                            @if($item['is_additional_support'])
                                {{-- "Workflow completion pass" TASK 3c — lists
                                     exactly which items were additional so the
                                     Principal can see exactly what contributed. --}}
                                <span class="ml-1 text-[10px] text-gray-400" title="Within-term additional support, marked by the Adviser.">
                                    <i class="bi bi-info-circle"></i> additional
                                </span>
                            @endif
                        </td>
                        <td class="text-gray-400">{{ $componentLabels[$item['component']] ?? $item['component'] }}</td>
                        <td class="tbl-num font-medium">
                            @if($item['score'] !== null)
                                {{ number_format($item['score'], 2) }} / {{ number_format($item['max_score'], 2) }}
                            @else
                                <span class="text-gray-300">Not yet scored</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
        @else
        <div class="border-t px-5 py-3 text-xs text-muted">No assessment evidence uploaded yet for this subject/term.</div>
        @endif
    </div>
    @empty
    <div class="card">
        <x-empty-state message="No subjects found for this student's section." hint="Subjects are assigned to a section by an Admin — this student's section may not have any yet." />
    </div>
    @endforelse
</div>

{{-- Intervention history --}}
@php
    // TASK 1 of "terminology, transmutation, and interface cleanup" —
    // 'remediation' is a within-term action here (an additional
    // assessment item), not DepEd's formal post-term remediation — see
    // CLAUDE.md's Known limitations. Every other type still title-cases
    // fine from its raw enum value, so only this one needs a real label.
    $ivTypeLabels = \App\Models\Intervention::TYPE_LABELS;
@endphp
<div class="card p-5">
    <h3 class="card-title mb-3">Intervention History</h3>
    @forelse($interventions as $iv)
        <div class="text-xs text-gray-600 py-1.5 border-b last:border-0">
            <span class="font-medium">{{ $ivTypeLabels[$iv->recommended_type] ?? ucfirst(str_replace('_', ' ', $iv->recommended_type)) }}</span>
            — {{ ucfirst(str_replace('_', ' ', $iv->status)) }}
            <span class="text-gray-400">({{ $iv->created_at->format('M d, Y') }})</span>
            @if(\App\Models\Intervention::schedulingAvailable())
                · @include('partials.intervention-lifecycle-badge', ['iv' => $iv])
                <a href="{{ route('principal.interventions.show', $iv) }}" class="text-brand-700 hover:underline">Open<span class="sr-only"> intervention</span></a>
            @endif
            {{-- PART 12 — the year/term/section stored ON the intervention. --}}
            <span class="block text-gray-400">
                SY {{ $iv->school_year ?? '—' }}@if($iv->grading_period) · Term {{ $iv->grading_period }}@endif
                @if($iv->section) · Grade {{ $iv->section->grade_level }} {{ $iv->section->name }}@endif
            </span>
        </div>
    @empty
        <p class="text-xs text-muted">No interventions recorded for this student.
            <a href="{{ route('principal.interventions') }}" class="text-brand-700 underline">Go to Interventions</a>
        </p>
    @endforelse
</div>

@endsection
