@extends('layouts.app')

@section('title', 'Subject Analysis')
@section('subtitle', 'Assessment component performance, failure rate, and at-risk count by subject — read-only')

@section('content')

@php
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
@endphp

<div class="bg-white rounded-xl shadow-sm p-4 mb-4">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Section</label>
            <select name="section_id" onchange="this.form.submit()"
                    class="border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                <option value="">All sections</option>
                @foreach($sections as $section)
                    <option value="{{ $section->id }}" {{ (string) $selectedSection === (string) $section->id ? 'selected' : '' }}>
                        {{ $section->name }} — Grade {{ $section->grade_level }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Term</label>
            <select name="term" onchange="this.form.submit()"
                    class="border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                <option value="">All terms this school year</option>
                @foreach([1, 2, 3] as $t)
                    <option value="{{ $t }}" {{ (string) $selectedTerm === (string) $t ? 'selected' : '' }}>
                        Term {{ $t }} {{ $currentTerm === $t ? '(currently open)' : '' }}
                    </option>
                @endforeach
            </select>
        </div>
        @if($selectedSection || $selectedTerm)
            <a href="{{ route('principal.subject-analysis') }}" class="text-xs text-gray-500 hover:text-gray-700 underline pb-2">Clear filters</a>
        @endif
    </form>
</div>

<x-panel title="Subject Analysis"
    subtitle="Average score per assessment component, failure rate, and at-risk count, across every student with evidence in the selected scope. A subject can look fine overall while one component quietly needs attention."
    class="overflow-x-auto">

    {{-- TASK 7c of "clarity, progress, and visual design pass" — sticky
         header on the scrollable table, same pattern as every other
         table in this app. --}}
    <div class="tbl-scroll -m-4">
    <table class="tbl tbl-sticky">
        <thead>
            <tr>
                <th scope="col">Subject</th>
                <th scope="col" class="text-center">Written Work</th>
                <th scope="col" class="text-center">Performance Task</th>
                <th scope="col" class="text-center">Examination</th>
                <th scope="col">Weakest Component</th>
                <th scope="col" class="text-center tbl-num">Failure Rate</th>
                <th scope="col" class="text-center tbl-num">At-Risk Count</th>
            </tr>
        </thead>
        <tbody>
            @forelse($summaries as $row)
            <tr>
                <td class="font-medium text-gray-800">{{ $row['subject']->name }}</td>
                @foreach(['written_work', 'performance_task', 'examination'] as $key)
                    @php $c = $row['components'][$key]; @endphp
                    <td class="text-center">
                        @if($c === null)
                            <span class="text-gray-300 text-xs">No data</span>
                        @else
                            <a href="{{ route('principal.students', ['subject_id' => $row['subject']->id, 'focus' => $key]) }}"
                               class="{{ $c['status'] === 'On Track' ? 'text-status-ontrack' : 'text-status-risk font-medium' }} hover:underline"
                               title="See the students behind this number">
                                {{ number_format($c['avg_percentage'], 1) }}%
                            </a>
                            <span class="block text-xs text-gray-400">{{ $c['student_count'] }} student{{ $c['student_count'] === 1 ? '' : 's' }}</span>
                        @endif
                    </td>
                @endforeach
                <td>
                    @if($row['weakest_component'] && ($row['components'][$row['weakest_component']]['status'] ?? null) === 'Needs Attention')
                        <a href="{{ route('principal.students', ['subject_id' => $row['subject']->id, 'focus' => $row['weakest_component']]) }}"
                           class="px-2 py-0.5 rounded-full text-xs font-medium bg-status-attention/10 text-status-attention hover:bg-status-attention/20">
                            {{ $componentLabels[$row['weakest_component']] ?? $row['weakest_component'] }}
                        </a>
                        <span class="block text-xs text-gray-400 mt-1">
                            {{ $row['below_target_count'] }} student{{ $row['below_target_count'] === 1 ? '' : 's' }} below target
                        </span>
                    @elseif($row['weakest_component'])
                        <span class="text-xs text-gray-400">All on track</span>
                    @else
                        <span class="text-xs text-gray-300">No data</span>
                    @endif
                </td>
                <td class="text-center tbl-num">
                    @if($row['failure_rate'] === null)
                        <span class="text-gray-300 text-xs">No grades yet</span>
                    @else
                        <span class="{{ $row['failure_rate'] > 0 ? 'text-status-failing font-medium' : 'text-status-ontrack' }}">
                            {{ number_format($row['failure_rate'], 1) }}%
                        </span>
                        <span class="block text-xs text-gray-400">{{ $row['failing_count'] }} of {{ $row['graded_count'] }}</span>
                    @endif
                </td>
                <td class="text-center tbl-num">
                    @if($row['at_risk_count'] > 0)
                        <a href="{{ route('principal.students', ['subject_id' => $row['subject']->id]) }}"
                           class="text-status-risk font-medium hover:underline">{{ $row['at_risk_count'] }}</a>
                    @else
                        <span class="text-gray-400">0</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7">
                    <x-empty-state icon="bi-bar-chart" message="No assessment evidence for this scope yet."
                        hint="This fills in once advisers upload and import assessment forms for their sections, or try clearing the section/term filters." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</x-panel>

@endsection
