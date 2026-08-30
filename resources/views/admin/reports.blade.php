@extends('layouts.app')

@section('title', 'Reports')
@section('subtitle', 'Section submission status and grade overview')

@section('content')

{{-- Filters — narrows down the list once there are many grade levels
     and sections, instead of always rendering every single one.
     Auto-submits via initAutoSubmitFilter() in search-filter.js — no
     separate "Filter" button needed. --}}
<form method="GET" id="reportFilterForm" class="bg-white rounded-xl shadow-sm mb-4 px-5 py-3 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Grade Level</label>
        <select name="grade_level"
                class="border rounded-md text-sm px-2 py-1.5 min-w-[140px]">
            <option value="">All grade levels</option>
            @foreach($gradeLevels as $gl)
                <option value="{{ $gl }}" {{ request('grade_level') == $gl ? 'selected' : '' }}>
                    Grade {{ $gl }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Section name</label>
        <input type="text" name="section_search" value="{{ request('section_search') }}"
               placeholder="e.g. Narra" autocomplete="off"
               class="border rounded-md text-sm px-2 py-1.5">
    </div>
    @if(request('grade_level') || request('section_search'))
        <a href="{{ route('admin.reports') }}" class="text-sm text-gray-500 hover:underline pb-1.5">Clear</a>
    @endif
</form>

@forelse($sections as $section)
<div class="bg-white rounded-xl shadow-sm mb-4">

    {{-- Section Header --}}
    <div class="px-5 py-3 border-b flex justify-between items-center">
        <div>
            <h3 class="font-semibold text-gray-800">
                Section {{ $section->name }}
            </h3>
            <p class="text-xs text-gray-500 mt-0.5">
                Grade {{ $section->grade_level }}
                @if($section->track) — {{ $section->track->name }} @endif
                @if($section->specialization) — {{ $section->specialization->name }} @endif
                | Adviser: {{ $section->adviser
                    ? $section->adviser->last_name . ', ' . $section->adviser->first_name
                    : 'Unassigned' }}
            </p>
        </div>
        <span class="text-xs text-gray-400">{{ $section->students->count() }} students</span>
    </div>

    {{-- Submission Status --}}
    <div class="px-5 py-3 border-b bg-gray-50">
        <div class="flex gap-6">
            @foreach([1, 2, 3] as $period)
            @php
                $submission = $section->reportSubmissions->firstWhere('grading_period', $period);
            @endphp
            <div class="flex items-center gap-1.5 text-xs">
                @if($submission)
                    <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                    <span class="text-gray-700 font-medium">Term {{ $period }}</span>
                    <span class="text-gray-400">{{ $submission->submitted_at->format('M d') }}</span>
                @else
                    <span class="w-2 h-2 rounded-full bg-gray-300 shrink-0"></span>
                    <span class="text-gray-400">Term {{ $period }} — Not submitted</span>
                @endif
            </div>
            @endforeach
        </div>
    </div>

    {{-- Students Table --}}
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-white text-gray-500 border-b">
                <tr>
                    <th class="text-left px-5 py-2">Student</th>
                    <th class="text-center px-3 py-2">Term 1</th>
                    <th class="text-center px-3 py-2">Term 2</th>
                    <th class="text-center px-3 py-2">Term 3</th>
                    <th class="text-center px-3 py-2">Overall Avg</th>
                    <th class="text-center px-3 py-2">Risk Level</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($section->students as $student)
                @php
                    $g1 = $student->grades->where('grading_period', 1)->avg('grade');
                    $g2 = $student->grades->where('grading_period', 2)->avg('grade');
                    $g3 = $student->grades->where('grading_period', 3)->avg('grade');
                    $filledPeriods = collect([$g1, $g2, $g3])->filter();
                    $overallAvg    = $filledPeriods->count() ? round($filledPeriods->avg(), 2) : null;
                    $latestRisk    = $student->riskResults->sortByDesc('grading_period')->first();
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-5 py-2 font-medium text-gray-800">
                        {{ $student->last_name }}, {{ $student->first_name }}
                    </td>
                    <td class="px-3 py-2 text-center {{ $g1 && $g1 < 75 ? 'text-red-600 font-semibold' : 'text-gray-700' }}">
                        {{ $g1 ? number_format($g1, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-center {{ $g2 && $g2 < 75 ? 'text-red-600 font-semibold' : 'text-gray-700' }}">
                        {{ $g2 ? number_format($g2, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-center {{ $g3 && $g3 < 75 ? 'text-red-600 font-semibold' : 'text-gray-700' }}">
                        {{ $g3 ? number_format($g3, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-center font-medium {{ $overallAvg && $overallAvg < 75 ? 'text-red-600' : 'text-gray-800' }}">
                        {{ $overallAvg ? number_format($overallAvg, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        @if($latestRisk)
                            @if($latestRisk->risk_level === 'high')
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-red-100 text-red-700">High</span>
                            @elseif($latestRisk->risk_level === 'moderate')
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-yellow-100 text-yellow-700">Moderate</span>
                            @else
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-green-100 text-green-700">Low</span>
                            @endif
                        @else
                            <span class="text-xs text-gray-400">No data</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-5 py-4 text-center text-gray-400 text-xs">
                        No students in this section.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@empty
<div class="bg-white rounded-xl shadow-sm p-8 text-center text-gray-400">
    No sections found.
</div>
@endforelse

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initAutoSubmitFilter('reportFilterForm');
    });
</script>
@endpush