@extends('layouts.app')

@section('title', 'Reports')
@section('subtitle', 'Submitted term reports — School Year ' . $schoolYear)

@section('content')

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
        <span class="text-xs text-muted">This is not the active school year. Only reports submitted that year are shown.</span>
    @else
        <x-ui.status-badge tone="success" icon="bi-check-circle-fill" label="Active" />
    @endif
</div>

@php
    // Term columns actually rendered — one when a term is selected, all three otherwise.
    $periodsShown = $gradingPeriod ? [$gradingPeriod] : [1, 2, 3];
@endphp

<div class="alert alert-info text-sm mb-4" data-reports-scope>
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    Submitted term reports only: every figure below is the version the adviser explicitly submitted, frozen at submission.
    A term not yet submitted shows no figures here. Current (live) academic information is on
    @if(auth()->user()->role === 'principal')<a class="underline" href="{{ route('principal.academic-monitoring') }}">Academic Monitoring</a> and <a class="underline" href="{{ route('principal.students') }}">Students</a>@else the Principal's monitoring pages @endif.
</div>

@forelse($sections as $section)
@php
    $subs = $section->reportSubmissions->keyBy(fn($r) => (int) $r->grading_period);
    // The contents as submitted (latest version), per term; null = not submitted, or submitted before versions existed.
    $snapshots = collect($periodsShown)->mapWithKeys(fn($p) => [$p => $subs->get($p)?->latestVersion?->snapshot]);
    $submittedPeriods = $subs->keys()->all();
    // Rows: this year's roster plus anyone frozen in a shown snapshot (e.g. moved section since).
    $names = $section->enrolledStudents->mapWithKeys(fn($st) => [$st->id => $st->last_name . ', ' . $st->first_name]);
    foreach ($snapshots->filter() as $snap) {
        foreach ($snap['students'] ?? [] as $sid => $entry) {
            $names[(int) $sid] ??= $entry['name'];
        }
    }
@endphp
<div class="card mb-4" data-report-section="{{ $section->id }}">

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

    {{-- Submission status per term: Submitted (version, date, by) or Not submitted. --}}
    <div class="px-5 py-3 border-b border-line bg-surface/60">
        <div class="flex gap-x-6 gap-y-2 flex-wrap">
            @foreach($periodsShown as $period)
            @php
                $submission = $subs->get($period);
                $version = $submission?->latestVersion;
                $termRow = $termRows[$period] ?? null;
                if ($submission) {
                    $termBadge = ['success', 'bi-check-circle-fill', 'Submitted' . ($version ? ' · v' . $version->version : '')];
                } elseif ($termRow && $termRow->is_open) {
                    $termBadge = ['warning', 'bi-unlock', 'Not Submitted'];
                } elseif ($isHistoricalYear || ($termRow && $termRow->closed_at)) {
                    $termBadge = ['gray', 'bi-lock', 'Not Submitted (term closed)'];
                } elseif ($termRow && !$termRow->opened_at) {
                    $termBadge = ['gray', 'bi-lock', 'Locked'];
                } else {
                    $termBadge = ['outline', 'bi-dash-circle', 'Not Started'];
                }
                $by = $version?->submitter?->name ?? $submission?->adviser?->name ?? '—';
                $at = $version?->submitted_at ?? $submission?->submitted_at;
            @endphp
            <div class="flex items-center gap-2 text-xs" data-term-status="{{ $period }}">
                <span class="text-ink font-medium">Term {{ $period }}</span>
                <x-ui.status-badge :tone="$termBadge[0]" :icon="$termBadge[1]" :label="$termBadge[2]" />
                @if($submission)
                    <span class="text-muted" title="Submitted by {{ $by }} on {{ $at->format('M d, Y g:i A') }} — School Year {{ $submission->school_year }}">{{ $at->format('M d, Y') }} · {{ $by }}</span>
                    @unless($version)
                        <span class="text-warning-text" data-legacy-submission title="Submitted before report contents were preserved (2026-10-07). Its original figures were not stored, so none are shown.">contents not preserved</span>
                    @endunless
                @endif
            </div>
            @endforeach
        </div>
    </div>

    <div class="tbl-scroll">
        <table class="tbl tbl-sticky">
            <thead>
                <tr>
                    <th scope="col">Student</th>
                    @foreach($periodsShown as $period)
                    <th scope="col" class="text-center">Term {{ $period }}{{ $subject ? ' — ' . $subject->name : '' }}
                        <span class="block text-[10px] font-normal normal-case text-gray-400">{{ in_array($period, $submittedPeriods, true) ? 'as submitted' : 'not submitted' }}</span></th>
                    @endforeach
                    <th scope="col" class="text-center">
                        {{ $subject ? 'Subject Avg' : 'Overall Avg' }}
                        <span class="block text-[10px] font-normal normal-case text-gray-400">({{ count($submittedPeriods) }} of 3 terms submitted)</span>
                    </th>
                    <th scope="col" title="Each subject's OFFICIAL grade on its own, as submitted — a passing overall average never hides a failed subject. A grade not yet verified is not an official result.">
                        Subject Results
                        <span class="block text-[10px] font-normal normal-case text-gray-400">official, per subject, as submitted{{ $gradingPeriod ? ', Term ' . $gradingPeriod : '' }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                @forelse($names as $studentId => $studentName)
                @php
                    $figures = $snapshots->filter()->map(fn($snap) => \App\Services\ReportSnapshotService::learnerFigures($snap['students'][$studentId] ?? $snap['students'][(string) $studentId] ?? null, $subject?->id));
                    $termAverages = $figures->map(fn($f) => $f['average'])->filter(fn($v) => $v !== null);
                    $overallAvg = $termAverages->isNotEmpty() ? round($termAverages->avg(), 2) : null;
                @endphp
                <tr>
                    <td class="font-medium text-ink">
                        @if(auth()->user()->role === 'principal')
                            <a href="{{ route('principal.students.show', $studentId) }}" class="hover:underline hover:text-brand-700">{{ $studentName }}</a>
                        @else
                            {{ $studentName }}
                        @endif
                    </td>
                    @foreach($periodsShown as $period)
                    @php $g = $figures->get($period)['average'] ?? null; @endphp
                    @if($figures->has($period))
                        <td class="tbl-num text-center {{ $g && $g < 75 ? 'text-status-risk font-semibold' : 'text-gray-700' }}">{{ $g !== null ? number_format($g, 2) : '—' }}</td>
                    @elseif(in_array($period, $submittedPeriods, true))
                        <td class="text-center text-xs text-muted" title="Submitted before report contents were preserved.">n/a</td>
                    @else
                        <td class="text-center text-xs text-gray-400" data-not-submitted>Not submitted</td>
                    @endif
                    @endforeach
                    <td class="tbl-num text-center font-medium {{ $overallAvg && $overallAvg < 75 ? 'text-status-risk' : 'text-ink' }}">
                        {{ $overallAvg !== null ? number_format($overallAvg, 2) : '—' }}
                    </td>
                    <td class="text-xs" data-subject-results>
                        @php
                            $subjects = $figures->sum('subjects'); $passed = $figures->sum('passed'); $failed = $figures->sum('failed'); $notOfficial = $figures->sum('not_official');
                            $stale = $figures->flatMap(fn($f) => $f['stale_subjects'])->unique()->sort()->values();
                        @endphp
                        @if($subjects === 0)
                            <span class="text-gray-300">—</span>
                        @else
                            <span class="block">Passed {{ $passed }} · <span class="{{ $failed ? 'text-status-failing font-semibold' : '' }}">Failed {{ $failed }}</span>@if($notOfficial) · {{ $notOfficial }} not yet official @endif</span>
                            @if($stale->isNotEmpty())<span class="block font-medium" data-stale-official>Evidence had changed since verification when submitted — re-verify: {{ $stale->implode(', ') }}</span>@endif
                            @foreach($figures as $period => $f)
                                @foreach($f['failed_subjects'] as $fs)
                                    <span class="block text-status-failing" data-failed-subject>{{ $fs['subject'] }} — {{ number_format($fs['grade'], 2) }} (Term {{ $period }})</span>
                                @endforeach
                            @endforeach
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