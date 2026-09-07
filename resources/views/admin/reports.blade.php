@extends('layouts.app')

@section('title', 'Reports')
@section('subtitle', 'Section submission status and grade overview')

@section('content')

@foreach($staleRiskBySchoolYear ?? [] as $schoolYear => $staleRiskTerms)
<div class="bg-red-50 border border-red-200 text-red-800 text-sm p-4 rounded-lg mb-4">
    <p class="font-medium">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Risk level{{ count($staleRiskTerms) === 1 ? '' : 's' }} may be out of date — School Year {{ $schoolYear }}, Term{{ count($staleRiskTerms) === 1 ? '' : 's' }} {{ implode(', ', $staleRiskTerms) }}
    </p>
    <p class="mt-1 text-red-700">
        Risk levels are on record for these terms, but the grades they were computed from no longer exist.
        These numbers are stale — re-enter the grades and re-submit the term report to regenerate them. Nothing has been deleted automatically.
    </p>
</div>
@endforeach

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
        <a href="{{ $clearRoute }}" class="text-sm text-gray-500 hover:underline pb-1.5">Clear</a>
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
                | Adviser: {{ $section->adviser->name ?? 'Unassigned' }}
            </p>
        </div>
        <span class="text-xs text-gray-400"><x-count-label :count="$section->students->count()" noun="student" /></span>
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

    @php
        // "Decision flow, report scoping, and dashboard pass" TASK 2 —
        // submission is per SECTION, not per student, so this is computed
        // once here rather than repeated inside the student loop below.
        // Every average/qualifier in this table reads from this set, not
        // from whether a term column merely HAS a value (verified grades
        // exist before submission too — see AcademicTerm/ReportSubmission).
        $submittedPeriods = $section->reportSubmissions->pluck('grading_period')->map(fn($p) => (int) $p)->all();
        $submittedCount = count($submittedPeriods);
    @endphp

    {{-- Students Table — TASK 7c of "clarity, progress, and visual
         design pass": sticky header on the scrollable table, same
         pattern as every other table in this app. --}}
    <div class="max-h-[60vh] overflow-auto">
        <table class="w-full text-sm">
            <thead class="bg-white text-gray-500 border-b sticky top-0 z-10">
                <tr>
                    <th class="text-left px-5 py-2">Student</th>
                    @foreach([1, 2, 3] as $period)
                    <th class="text-center px-3 py-2">
                        Term {{ $period }}
                        @unless(in_array($period, $submittedPeriods, true))
                            <span class="text-gray-400 font-normal" title="Not yet submitted for this section — shown for reference, excluded from the overall average.">
                                <i class="bi bi-exclamation-circle"></i>
                            </span>
                        @endunless
                    </th>
                    @endforeach
                    <th class="text-center px-3 py-2">
                        Overall Avg
                        <span class="block text-[10px] font-normal normal-case text-gray-400">({{ $submittedCount }} of 3 terms submitted)</span>
                    </th>
                    <th class="text-center px-3 py-2">Risk Level</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($section->students as $student)
                @php
                    $g1 = $student->grades->where('grading_period', 1)->avg('grade');
                    $g2 = $student->grades->where('grading_period', 2)->avg('grade');
                    $g3 = $student->grades->where('grading_period', 3)->avg('grade');
                    // "Decision flow, report scoping, and dashboard pass"
                    // TASK 2a — the average must never silently include a
                    // term whose report hasn't been submitted, even though
                    // the adviser already verified those grades (they're
                    // real, just not yet part of a submitted report — see
                    // the marked cells below). Only periods in
                    // $submittedPeriods count toward this average.
                    $periodValues = [1 => $g1, 2 => $g2, 3 => $g3];
                    $filledSubmitted = collect($periodValues)
                        ->filter(fn($v, $p) => in_array($p, $submittedPeriods, true) && $v !== null);
                    $overallAvg = $filledSubmitted->count() ? round($filledSubmitted->avg(), 2) : null;
                    $latestRisk = $student->riskResults->sortByDesc('grading_period')->first();
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-5 py-2 font-medium text-gray-800">
                        @if(auth()->user()->role === 'principal')
                            <a href="{{ route('principal.students.show', $student->id) }}" class="hover:underline hover:text-brand-700">
                                {{ $student->last_name }}, {{ $student->first_name }}
                            </a>
                        @else
                            {{ $student->last_name }}, {{ $student->first_name }}
                        @endif
                    </td>
                    @foreach([1, 2, 3] as $period)
                    @php
                        $g = $periodValues[$period];
                        $isSubmitted = in_array($period, $submittedPeriods, true);
                    @endphp
                    <td class="px-3 py-2 text-center {{ !$isSubmitted ? 'text-gray-400 italic' : ($g && $g < 75 ? 'text-red-600 font-semibold' : 'text-gray-700') }}"
                        @unless($isSubmitted) title="Verified by the adviser but not yet part of a submitted term report. Not included in the overall average." @endunless>
                        {{ $g ? number_format($g, 2) : '—' }}
                    </td>
                    @endforeach
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
                            {{-- TASK 2d — a Risk Level shown on a row whose
                                 later term column is populated (submitted
                                 or not) must not silently imply it
                                 accounts for that term. --}}
                            @php
                                $hasLaterPopulatedTerm = collect($periodValues)
                                    ->filter(fn($v, $p) => $p > $latestRisk->grading_period && $v !== null)
                                    ->isNotEmpty();
                            @endphp
                            @if($hasLaterPopulatedTerm)
                                <span class="block text-[10px] text-gray-400 mt-0.5">as of Term {{ $latestRisk->grading_period }}</span>
                            @endif
                        @else
                            <span class="text-xs text-gray-400" title="Risk levels are generated once the adviser submits this term's report.">
                                Not yet available
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6">
                        <x-empty-state message="No students in this section." class="py-4 text-xs" />
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@empty
<div class="bg-white rounded-xl shadow-sm">
    @if(request('grade_level') || request('section_search'))
        <x-empty-state message="No sections found." hint="Try clearing the filters above." />
    @else
        <x-empty-state message="No sections yet." hint="Sections are created under Admin → Sections." />
    @endif
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