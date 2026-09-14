@extends('layouts.app')

@section('title', 'Reports')
@section('subtitle', 'Section submission status and grade overview — School Year ' . $schoolYear)

@section('content')

@foreach($staleRiskBySchoolYear ?? [] as $staleYear => $staleRiskTerms)
<div class="bg-red-50 border border-red-200 text-red-800 text-sm p-4 rounded-lg mb-4">
    <p class="font-medium">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Risk level{{ count($staleRiskTerms) === 1 ? '' : 's' }} may be out of date — School Year {{ $staleYear }}, Term{{ count($staleRiskTerms) === 1 ? '' : 's' }} {{ implode(', ', $staleRiskTerms) }}
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
{{-- "Multi-school-year academic history" work order, PART 10 — one
     school year at a time (the active one by default), narrowed by
     term, grade, section, and subject together. Every figure below is
     read from the selected year's own records. --}}
<form method="GET" id="reportFilterForm" class="filter-bar">
    <span class="text-muted text-sm mr-1 self-center hidden sm:inline" aria-hidden="true"><i class="bi bi-funnel"></i></span>
    <div class="filter-field">
        <label class="form-label">School Year</label>
        <select name="school_year" class="form-select-sm min-w-[140px]">
            @foreach($schoolYears as $sy)
                <option value="{{ $sy }}" {{ $schoolYear === $sy ? 'selected' : '' }}>{{ $sy }}{{ !$isHistoricalYear && $sy === $schoolYear ? ' (active)' : '' }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label">Academic Term</label>
        <select name="grading_period" class="form-select-sm min-w-[120px]">
            <option value="">All Terms</option>
            @foreach([1, 2, 3] as $t)
                <option value="{{ $t }}" {{ $gradingPeriod === $t ? 'selected' : '' }}>Term {{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label">Grade Level</label>
        <select name="grade_level"
                class="form-select-sm min-w-[140px]">
            <option value="">All Grades</option>
            @foreach($gradeLevels as $gl)
                <option value="{{ $gl }}" {{ request('grade_level') == $gl ? 'selected' : '' }}>
                    Grade {{ $gl }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label">Section</label>
        <select name="section_id" class="form-select-sm min-w-[140px]">
            <option value="">All Sections</option>
            @foreach($sectionOptions as $opt)
                <option value="{{ $opt->id }}" {{ $selectedSectionId === $opt->id ? 'selected' : '' }}>{{ $opt->name }} (Grade {{ $opt->grade_level }})</option>
            @endforeach
        </select>
    </div>
    <div class="filter-field">
        <label class="form-label">Subject</label>
        <select name="subject_id" class="form-select-sm min-w-[160px]">
            <option value="">All Subjects</option>
            @foreach($subjects as $subj)
                <option value="{{ $subj->id }}" {{ $subject && $subject->id === $subj->id ? 'selected' : '' }}>{{ $subj->name }} (Grade {{ $subj->grade_level }})</option>
            @endforeach
        </select>
    </div>
    @if(request('section_search'))
        <input type="hidden" name="section_search" value="{{ request('section_search') }}">
    @endif
    @if(request('grade_level') || request('section_search') || request('section_id') || request('subject_id') || request('grading_period') || $isHistoricalYear)
        <a href="{{ $clearRoute }}" class="btn btn-ghost btn-sm self-center">Clear filters</a>
    @endif
</form>

@php
    // Term open/closed state for the selected year — one query, shared by
    // every section card below (terms are per school year, not per section).
    $termRows = \App\Models\AcademicTerm::where('school_year', $schoolYear)->get()->keyBy('term');
@endphp
<div class="card px-4 py-3 mb-4 flex flex-wrap items-center gap-2 text-sm">
    <span class="text-xs font-semibold uppercase tracking-wider text-muted mr-1">Viewing</span>
    <span class="pill"><i class="bi bi-calendar3 text-brand-700" aria-hidden="true"></i> SY {{ $schoolYear }}</span>
    <span class="pill">{{ $gradingPeriod ? 'Term ' . $gradingPeriod : 'All Terms' }}</span>
    <span class="pill">{{ request('grade_level') ? 'Grade ' . request('grade_level') : 'All Grades' }}</span>
    @if($selectedSectionId && ($selOpt = $sectionOptions->firstWhere('id', $selectedSectionId)))
        <span class="pill">Section {{ $selOpt->name }}</span>
    @else
        <span class="pill">All Sections</span>
    @endif
    @if($subject)
        <span class="pill"><span class="pill-label">Subject</span> {{ $subject->name }}</span>
    @endif
    @if($isHistoricalYear)
        <x-ui.status-badge tone="gray" icon="bi-archive" label="Historical Record" />
        <span class="text-xs text-muted">This is not the active school year. Records are shown as submitted and cannot be changed.</span>
    @else
        <x-ui.status-badge tone="success" icon="bi-check-circle-fill" label="Active" />
    @endif
</div>

@php
    // Term columns actually rendered — one when a term is selected, all three otherwise.
    $periodsShown = $gradingPeriod ? [$gradingPeriod] : [1, 2, 3];
@endphp

@forelse($sections as $section)
<div class="card mb-4">

    {{-- Section Header --}}
    <div class="card-header items-center">
        <div class="flex items-start gap-3 min-w-0">
            <div class="icon-box icon-box-brand" aria-hidden="true"><i class="bi bi-grid-3x3-gap"></i></div>
            <div class="min-w-0">
                <h3 class="card-title">
                    Section {{ $section->name }}
                    <span class="badge badge-brand ml-1">Grade {{ $section->grade_level }}</span>
                    @if($isHistoricalYear)
                        <x-ui.status-badge tone="gray" icon="bi-archive" label="Historical Record" class="ml-1" />
                    @endif
                </h3>
                <p class="text-xs text-muted mt-1 flex flex-wrap gap-x-3 gap-y-1">
                    <span><i class="bi bi-calendar3" aria-hidden="true"></i> School Year {{ $section->school_year }}</span>
                    @if($section->track)<span><i class="bi bi-diagram-3" aria-hidden="true"></i> {{ $section->track->name }}</span>@endif
                    @if($section->specialization)<span><i class="bi bi-collection" aria-hidden="true"></i> {{ $section->specialization->name }}</span>@endif
                    <span><i class="bi bi-person-badge" aria-hidden="true"></i> Adviser: {{ $section->adviser->name ?? 'Unassigned' }}</span>
                </p>
            </div>
        </div>
        <span class="pill shrink-0"><i class="bi bi-people text-brand-700" aria-hidden="true"></i> <x-count-label :count="$section->enrolledStudents->count()" noun="student" /></span>
    </div>

    {{-- Submission Status — one badge per term: Submitted / Not Submitted
         (open or closed with grades on file) / Not Started / Locked. --}}
    <div class="px-5 py-3 border-b border-line bg-surface/60">
        <div class="flex gap-x-6 gap-y-2 flex-wrap">
            @foreach($periodsShown as $period)
            @php
                $submission = $section->reportSubmissions->firstWhere('grading_period', $period);
                $termRow = $termRows[$period] ?? null;
                $hasGrades = $section->enrolledStudents->contains(fn($st) => $st->grades->where('grading_period', $period)->isNotEmpty());
                if ($submission) {
                    $termBadge = ['success', 'bi-check-circle-fill', 'Submitted'];
                } elseif ($termRow && $termRow->is_open) {
                    $termBadge = ['warning', 'bi-unlock', 'Not Submitted'];
                } elseif ($hasGrades) {
                    $termBadge = [$isHistoricalYear || ($termRow && $termRow->closed_at) ? 'gray' : 'warning', 'bi-lock', $isHistoricalYear || ($termRow && $termRow->closed_at) ? 'Closed' : 'Not Submitted'];
                } elseif ($termRow && !$termRow->opened_at) {
                    $termBadge = ['gray', 'bi-lock', 'Locked'];
                } else {
                    $termBadge = ['outline', 'bi-dash-circle', 'Not Started'];
                }
            @endphp
            <div class="flex items-center gap-2 text-xs">
                <span class="text-ink font-medium">Term {{ $period }}</span>
                <x-ui.status-badge :tone="$termBadge[0]" :icon="$termBadge[1]" :label="$termBadge[2]" />
                @if($submission)
                    <span class="text-muted" title="Submitted by {{ $submission->adviser->name ?? 'adviser' }} on {{ $submission->submitted_at->format('M d, Y g:i A') }} — School Year {{ $submission->school_year }}">{{ $submission->submitted_at->format('M d, Y') }} · {{ $submission->adviser->name ?? '—' }}</span>
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
    <div class="tbl-scroll">
        <table class="tbl tbl-sticky">
            <thead>
                <tr>
                    <th scope="col">Student</th>
                    @foreach($periodsShown as $period)
                    <th scope="col" class="text-center">
                        Term {{ $period }}{{ $subject ? ' — ' . $subject->name : '' }}
                        @unless(in_array($period, $submittedPeriods, true))
                            <span class="text-gray-400 font-normal" title="Not yet submitted for this section — shown for reference, excluded from the overall average.">
                                <i class="bi bi-exclamation-circle"></i>
                            </span>
                        @endunless
                    </th>
                    @endforeach
                    <th scope="col" class="text-center">
                        {{ $subject ? 'Subject Avg' : 'Overall Avg' }}
                        <span class="block text-[10px] font-normal normal-case text-gray-400">({{ $submittedCount }} of 3 terms submitted)</span>
                    </th>
                    <th scope="col" class="text-center">Risk Level</th>
                </tr>
            </thead>
            <tbody>
                @forelse($section->enrolledStudents as $student)
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
                    // With a term filter, the average is that term only.
                    if ($gradingPeriod) {
                        $filledSubmitted = $filledSubmitted->only([$gradingPeriod]);
                    }
                    $overallAvg = $filledSubmitted->count() ? round($filledSubmitted->avg(), 2) : null;
                    $latestRisk = $gradingPeriod
                        ? $student->riskResults->firstWhere('grading_period', $gradingPeriod)
                        : $student->riskResults->sortByDesc('grading_period')->first();
                @endphp
                <tr>
                    <td class="font-medium text-ink">
                        @if(auth()->user()->role === 'principal')
                            <a href="{{ route('principal.students.show', $student->id) }}" class="hover:underline hover:text-brand-700">
                                {{ $student->last_name }}, {{ $student->first_name }}
                            </a>
                        @else
                            {{ $student->last_name }}, {{ $student->first_name }}
                        @endif
                    </td>
                    @foreach($periodsShown as $period)
                    @php
                        $g = $periodValues[$period];
                        $isSubmitted = in_array($period, $submittedPeriods, true);
                    @endphp
                    <td class="tbl-num text-center {{ !$isSubmitted ? 'text-gray-400 italic' : ($g && $g < 75 ? 'text-status-risk font-semibold' : 'text-gray-700') }}"
                        @unless($isSubmitted) title="Verified by the adviser but not yet part of a submitted term report. Not included in the overall average." @endunless>
                        {{ $g ? number_format($g, 2) : '—' }}
                    </td>
                    @endforeach
                    <td class="tbl-num text-center font-medium {{ $overallAvg && $overallAvg < 75 ? 'text-status-risk' : 'text-ink' }}">
                        {{ $overallAvg ? number_format($overallAvg, 2) : '—' }}
                    </td>
                    <td class="text-center">
                        @if($latestRisk)
                            @if($latestRisk->risk_level === 'high')
                                <x-ui.status-badge tone="danger" label="High" />
                            @elseif($latestRisk->risk_level === 'moderate')
                                <x-ui.status-badge tone="warning" label="Moderate" />
                            @else
                                <x-ui.status-badge tone="success" label="Low" />
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
                            <span class="text-xs text-muted" title="Risk levels are generated once the adviser submits this term's report.">
                                Not yet available
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ count($periodsShown) + 3 }}">
                        <x-empty-state message="No students enrolled in this section for School Year {{ $schoolYear }}." class="py-4 text-xs" />
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@empty
<div class="card">
    @if(request('grade_level') || request('section_search') || request('section_id'))
        <x-empty-state message="No sections found." hint="Try clearing the filters above." />
    @else
        <x-empty-state message="No sections for School Year {{ $schoolYear }}." hint="Sections are created under Admin → Sections." />
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