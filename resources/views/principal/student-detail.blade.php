@extends('layouts.app')

@section('title', 'Student Detail')
@section('subtitle', $student->last_name . ', ' . $student->first_name . ' — ' . ($section?->name ?? 'No section') . ' (read-only)')

@section('content')

@php
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    $riskColors = ['low' => 'bg-status-ontrack/10 text-status-ontrack', 'moderate' => 'bg-status-attention/10 text-status-attention', 'high' => 'bg-status-risk/10 text-status-risk'];
@endphp

<div class="mb-4">
    <a href="{{ url()->previous() }}" class="text-sm text-gray-500 hover:underline"><i class="bi bi-arrow-left"></i> Back</a>
</div>

{{-- Risk overview + term selector --}}
<div class="bg-white rounded-xl shadow-sm p-5 mb-4 flex flex-wrap justify-between items-center gap-3">
    <div>
        <p class="text-xs text-gray-500">Latest Risk Level</p>
        @if($latestRisk)
            <span class="inline-block mt-1 px-3 py-1 rounded-full text-sm font-medium {{ $riskColors[$latestRisk->risk_level] ?? 'bg-gray-100 text-gray-600' }}">
                {{ ucfirst($latestRisk->risk_level) }} — {{ number_format($latestRisk->average_grade, 2) }} avg
            </span>
            <span class="block text-xs text-gray-400 mt-1" title="Plain-language status: On Track / Needs Monitoring / Needs Attention / At Risk">
                DSS Status: <span class="font-medium text-gray-600">{{ $dssStatus }}</span>
            </span>
            {{-- Standing property of the output, not a notification —
                 never dismissible. See the "honest model evaluation" prompt. --}}
            <span class="block text-xs text-gray-400 mt-1">
                Risk level is derived from grade thresholds, not predictive certainty about this student.
            </span>
        @else
            <p class="text-sm text-gray-400 mt-1">No risk classification yet.</p>
        @endif
    </div>
    <form method="GET" class="flex items-center gap-2">
        <label class="text-xs text-gray-500">Term:</label>
        @foreach([1, 2, 3] as $t)
            <a href="{{ route('principal.students.show', ['student' => $student->id, 'period' => $t]) }}"
               class="px-3 py-1 rounded-full text-xs font-medium border {{ $period == $t ? 'bg-brand-700 text-white border-brand-700' : 'bg-white text-gray-600 border-gray-300' }}">
                Term {{ $t }}
            </a>
        @endforeach
    </form>
</div>

{{-- Subject -> Component -> Evidence drill-down --}}
<div class="space-y-4 mb-4">
    @forelse($subjectAnalysis as $row)
    <div class="bg-white rounded-xl shadow-sm overflow-hidden {{ $row['weakest_component'] && ($row['components'][$row['weakest_component']]['status'] ?? null) === 'Needs Attention' ? 'ring-1 ring-status-attention/30' : '' }}">
        <div class="px-5 py-3 border-b flex justify-between items-center">
            <h3 class="font-semibold text-gray-800">{{ $row['subject']->name }}</h3>
            <span class="font-semibold {{ $row['complete'] ? 'text-gray-800' : 'text-gray-300' }}">
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
                        <p class="text-xs text-gray-400">
                            gap {{ $c['gap'] >= 0 ? '+' : '' }}{{ number_format($c['gap'], 1) }} — {{ $c['status'] }}
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
        <div class="border-t px-5 py-3 text-xs text-gray-400">No assessment evidence uploaded yet for this subject/term.</div>
        @endif
    </div>
    @empty
    <div class="bg-white rounded-xl shadow-sm">
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
    $ivTypeLabels = ['remediation' => 'Additional Practice and Re-teaching'];
@endphp
<div class="bg-white rounded-xl shadow-sm p-5">
    <h3 class="font-semibold text-gray-800 text-sm mb-3">Intervention History</h3>
    @forelse($interventions as $iv)
        <div class="text-xs text-gray-600 py-1.5 border-b last:border-0">
            <span class="font-medium">{{ $ivTypeLabels[$iv->recommended_type] ?? ucfirst(str_replace('_', ' ', $iv->recommended_type)) }}</span>
            — {{ ucfirst(str_replace('_', ' ', $iv->status)) }}
            <span class="text-gray-400">({{ $iv->created_at->format('M d, Y') }})</span>
        </div>
    @empty
        <p class="text-xs text-gray-400">No interventions recorded for this student.
            <a href="{{ route('principal.interventions') }}" class="text-brand-700 underline">Go to Interventions</a>
        </p>
    @endforelse
</div>

@endsection
