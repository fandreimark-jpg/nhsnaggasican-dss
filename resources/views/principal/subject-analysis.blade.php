@extends('layouts.app')

@section('title', 'Subject Analysis')
@section('subtitle', 'Assessment component performance by subject — read-only')

@section('content')

@php
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
@endphp

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <div class="px-6 py-4 border-b">
        <h3 class="font-semibold text-gray-800 text-sm">Subject Analysis</h3>
        <p class="text-xs text-gray-500 mt-1">
            Average score per assessment component, across every student with evidence this school year.
            A subject can look fine overall while one component quietly needs attention.
        </p>
    </div>

    {{-- TASK 7c of "clarity, progress, and visual design pass" — sticky
         header on the scrollable table, same pattern as every other
         table in this app. --}}
    <div class="max-h-[60vh] overflow-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide sticky top-0 z-10">
            <tr>
                <th class="text-left px-6 py-3">Subject</th>
                <th class="text-center px-4 py-3">Written Work</th>
                <th class="text-center px-4 py-3">Performance Task</th>
                <th class="text-center px-4 py-3">Examination</th>
                <th class="text-left px-4 py-3">Weakest Component</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($summaries as $row)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-3 font-medium text-gray-800">{{ $row['subject']->name }}</td>
                @foreach(['written_work', 'performance_task', 'examination'] as $key)
                    @php $c = $row['components'][$key]; @endphp
                    <td class="px-4 py-3 text-center">
                        @if($c === null)
                            <span class="text-gray-300 text-xs">No data</span>
                        @else
                            <a href="{{ route('principal.students', ['subject_id' => $row['subject']->id, 'focus' => $key]) }}"
                               class="{{ $c['status'] === 'On Track' ? 'text-green-700' : 'text-red-600 font-medium' }} hover:underline"
                               title="See the students behind this number">
                                {{ number_format($c['avg_percentage'], 1) }}%
                            </a>
                            <span class="block text-xs text-gray-400">{{ $c['student_count'] }} student{{ $c['student_count'] === 1 ? '' : 's' }}</span>
                        @endif
                    </td>
                @endforeach
                <td class="px-4 py-3">
                    @if($row['weakest_component'] && ($row['components'][$row['weakest_component']]['status'] ?? null) === 'Needs Attention')
                        <a href="{{ route('principal.students', ['subject_id' => $row['subject']->id, 'focus' => $row['weakest_component']]) }}"
                           class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 hover:bg-red-200">
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
            </tr>
            @empty
            <tr>
                <td colspan="5">
                    <x-empty-state icon="bi-bar-chart" message="No assessment evidence uploaded yet this school year."
                        hint="This fills in once advisers upload and import assessment forms for their sections." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

@endsection
