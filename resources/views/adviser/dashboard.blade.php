@extends('layouts.app')

@section('title', 'Adviser Dashboard')
@section('subtitle', $section
    ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level
      . ' | ' . ($section->track->name ?? '')
      . ' — ' . ($section->specialization->name ?? '')
    : 'No section assigned')

@section('content')

@if(!$section)
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
        <p class="text-yellow-700 font-medium">No section assigned yet.</p>
        <p class="text-yellow-600 text-sm mt-1">Please contact the admin to assign you a section.</p>
    </div>
@else

{{-- Summary Cards --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-5 shadow-sm border-t-4 border-brand-500">
        <p class="text-sm text-gray-500">Total Students</p>
        <p class="text-3xl font-bold text-brand-700 mt-1">{{ $totalStudents }}</p>
    </div>
    <div class="bg-white rounded-xl p-5 shadow-sm border-t-4 border-green-500">
        <p class="text-sm text-gray-500">Grades Encoded</p>
        <p class="text-3xl font-bold text-green-600 mt-1">{{ $totalGradesEncoded }}</p>
    </div>
    <div class="bg-white rounded-xl p-5 shadow-sm border-t-4 border-yellow-400">
        <p class="text-sm text-gray-500">Pending Submission</p>
        <p class="text-3xl font-bold text-yellow-500 mt-1">{{ $pendingCount }}</p>
    </div>
    <div class="bg-white rounded-xl p-5 shadow-sm border-t-4 border-purple-500">
        <p class="text-sm text-gray-500">Terms Submitted</p>
        <p class="text-3xl font-bold text-purple-600 mt-1">{{ $submissions->count() }}</p>
    </div>
</div>

{{-- Term Submission Status --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    @foreach([1, 2, 3] as $term)
    @php
        $submission  = $submissions[$term] ?? null;
        $termCount   = match($term) {
            1 => $term1Count,
            2 => $term2Count,
            3 => $term3Count,
        };
        $isComplete  = $totalExpectedPerTerm > 0 && $termCount >= $totalExpectedPerTerm;
        $isSubmitted = $submission !== null;
    @endphp
    <div class="bg-white rounded-xl shadow-sm p-5 border-t-4
        {{ $isSubmitted ? 'border-green-500' : ($isComplete ? 'border-brand-500' : 'border-gray-200') }}">
        <div class="flex justify-between items-start mb-2">
            <p class="text-sm font-semibold text-gray-700">Term {{ $term }}</p>
            @if($isSubmitted)
                <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-700 font-medium">
                    Submitted
                </span>
            @elseif($isComplete)
                <span class="text-xs px-2 py-1 rounded-full bg-brand-100 text-brand-700 font-medium">
                    Ready
                </span>
            @else
                <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-500 font-medium">
                    Incomplete
                </span>
            @endif
        </div>
        <p class="text-xs text-gray-400">
            {{ $termCount }}/{{ $totalExpectedPerTerm }} grades encoded
        </p>
        @if($isSubmitted)
            <p class="text-xs text-gray-400 mt-1">
                {{ $submission->submitted_at->format('M d, Y h:i A') }}
            </p>
        @elseif($isComplete)
            <a href="{{ route('adviser.submit.report') }}"
               class="block w-full mt-3 text-center bg-brand-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-brand-800">
                Submit Now →
            </a>
        @else
            <a href="{{ route('adviser.grades') }}?period={{ $term }}"
               class="block w-full mt-3 text-center border border-gray-300 text-gray-600 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-gray-50">
                Encode Grades →
            </a>
        @endif
    </div>
    @endforeach
</div>

{{-- Students Overview --}}
<div class="bg-white rounded-xl shadow-sm">
    <div class="px-6 py-4 border-b flex justify-between items-center">
        <div>
            <h3 class="font-semibold text-gray-800">My Students</h3>
            <p class="text-sm text-gray-500">Risk level overview</p>
        </div>
        <a href="{{ route('adviser.students') }}"
           class="text-sm text-brand-600 hover:underline">
            View all →
        </a>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500">
            <tr>
                <th class="text-left px-6 py-3">Student</th>
                <th class="text-center px-4 py-3">Risk Level</th>
                <th class="text-center px-4 py-3">Status</th>
                <th class="text-center px-4 py-3">Trend</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($students as $student)
            @php
                $latestRisk = $student->riskResults->first();
            @endphp
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-3 font-medium text-gray-800">
                    {{ $student->last_name }}, {{ $student->first_name }}
                </td>
                <td class="px-4 py-3 text-center">
                    @if($latestRisk)
                        @if($latestRisk->risk_level === 'high')
                            <span class="px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700">High</span>
                        @elseif($latestRisk->risk_level === 'moderate')
                            <span class="px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">Moderate</span>
                        @else
                            <span class="px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">Low</span>
                        @endif
                    @else
                        <span class="text-xs text-gray-400">No data</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-center">
                    @if($latestRisk)
                        @if($latestRisk->risk_level === 'high')
                            <span class="text-xs text-red-600"><i class="bi bi-exclamation-triangle-fill"></i> Needs immediate attention</span>
                        @elseif($latestRisk->risk_level === 'moderate')
                            <span class="text-xs text-yellow-600"><i class="bi bi-graph-up text-warning"></i> Needs monitoring</span>
                        @else
                            <span class="text-xs text-green-600"><i class="bi bi-check-circle-fill text-success"></i> Performing well</span>
                        @endif
                    @else
                        <span class="text-xs text-gray-400">Submit report to generate</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-center">
                    @php $trend = $trends[$student->id] ?? null; @endphp
                    @if($trend === 'improving')
                        <span class="text-xs text-green-600 font-medium">&uarr; Improving</span>
                    @elseif($trend === 'declining')
                        <span class="text-xs text-red-600 font-medium">&darr; Declining</span>
                    @elseif($trend === 'stable')
                        <span class="text-xs text-gray-500 font-medium">&rarr; Stable</span>
                    @else
                        <span class="text-xs text-gray-300">—</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="px-6 py-6 text-center text-gray-400">
                    No students found in this section.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@endif

@endsection