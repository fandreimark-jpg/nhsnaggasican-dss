@extends('layouts.app')

@section('title', 'Submit Report')
@section('subtitle', $section
    ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level
    : 'No section assigned')

@section('content')

@include('partials.section-school-year-context')

@if(!$section)
    <div class="card border-amber-200">
        <x-empty-state message="No section assigned yet." hint="An Admin assigns sections to advisers — contact the admin to get one assigned to your account." />
    </div>
@else

<div class="alert alert-info text-sm mb-4" data-draft-notice>
    <i class="bi bi-eye-slash" aria-hidden="true"></i>
    <strong>Draft preview — only you see this page.</strong> The figures below are your section's current grades.
    The Principal and Admin see a term only after you press <strong>Submit</strong>, and then exactly what you submitted (a frozen version).
    After that, changing grades does not change the submitted version; recipients keep seeing it until you <strong>Re-submit</strong>, which adds a new version and keeps the earlier one.
</div>

{{-- Submission Status per Term --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    @foreach([1, 2, 3] as $period)
    @php
        $termInfo      = $termStatus[$period];
        $submission    = $termInfo['submission'];
        $gradeCount    = $termInfo['encoded'];
        $totalExpected = $termInfo['expected'];
        $isComplete    = $termInfo['complete'];
        $isSubmitted   = $termInfo['submitted'];
        $evidence      = $termInfo['assessment_evidence'];
        $versions      = $submission && \App\Models\ReportSubmission::versioningAvailable() ? $submission->versions : collect();
        $versionCount  = $versions->count();
    @endphp
    <div class="card p-5 border-t-4
        {{ $isSubmitted ? 'border-green-500' : ($isComplete ? 'border-brand-500' : 'border-gray-300') }}">

        <div class="flex justify-between items-start mb-3">
            <div>
                <p class="text-sm font-semibold text-gray-700">Term {{ $period }}</p>
                <p class="text-xs text-muted mt-0.5">
                    {{ $gradeCount }}/{{ $totalExpected }} grades encoded
                </p>
                @if(!($evidence['configured'] ?? true))
                    <p class="text-xs mt-0.5 text-orange-500" title="This section's electives have not been assigned yet — contact the admin.">
                        <i class="bi bi-exclamation-circle"></i> Electives not yet assigned
                    </p>
                @elseif($evidence['has_any_evidence'])
                    <p class="text-xs mt-0.5 {{ $evidence['ready'] ? 'text-green-600' : 'text-orange-500' }}"
                       title="Students with a complete Written Work + Performance Task + Examination computed grade — informational only, does not block submission">
                        <i class="bi bi-clipboard-data"></i>
                        {{ $evidence['complete'] }}/{{ $evidence['expected'] }} assessment evidence complete
                    </p>
                @endif
            </div>
            @if($isSubmitted)
                <span class="badge badge-success">
                    Submitted{{ $versionCount ? ' · v' . $versionCount : '' }}
                </span>
            @else
                <span class="badge badge-gray">
                    Not Submitted
                </span>
            @endif
        </div>

        @if($isSubmitted)
            <p class="text-xs text-muted mb-1">
                Submitted: {{ $submission->submitted_at->format('M d, Y h:i A') }}
            </p>
            @if($versionCount)
                <ul class="text-[11px] text-muted mb-3 space-y-0.5" data-version-history>
                    @foreach($versions->reverse() as $v)
                        <li>v{{ $v->version }} — {{ $v->submitted_at->format('M d, Y g:i A') }} by {{ $v->submitter?->name ?? '—' }}{{ $loop->first ? ' (what recipients see)' : '' }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-[11px] text-warning-text mb-3" data-legacy-submission>Submitted before report contents were preserved; recipients see the submission date only.</p>
            @endif

            @if($isComplete)
                <div class="border-t pt-3 mt-1">
                    <p class="text-xs text-muted mb-2">
                        Found an error? You can correct grades and re-submit.
                    </p>
                    <div class="flex gap-2">
                        <a href="{{ route('adviser.grades') }}?period={{ $period }}"
                           class="btn btn-outline btn-sm flex-1">
                            Edit Grades
                        </a>
                        <form method="POST"
                              action="{{ route('adviser.submit.report.post') }}"
                              class="flex-1"
                              data-resubmit="Re-submit Term {{ $period }} report? This adds version {{ $versionCount + 1 }} with your current grades; the earlier version is kept, and recipients then see the new one.">
                            @csrf
                            <input type="hidden" name="grading_period" value="{{ $period }}">
                            <input type="hidden" name="expected_version" value="{{ $versionCount }}">
                            <input type="hidden" name="resubmit" value="1">
                            <button type="submit" class="btn btn-warning btn-sm w-full">
                                Re-submit
                            </button>
                        </form>
                    </div>
                </div>
            @endif

        @else
            @if($isComplete)
                <form method="POST" action="{{ route('adviser.submit.report.post') }}" data-loading="Submitting report...">
                    @csrf
                    <input type="hidden" name="grading_period" value="{{ $period }}">
                    <input type="hidden" name="expected_version" value="0">
                    <button type="submit"
                            class="w-full mt-2 btn btn-primary">
                        Submit Term {{ $period }} Report
                    </button>
                </form>
            @else
                <p class="text-xs text-yellow-600 mt-2">
                    ⚠ Complete all grades before submitting
                    ({{ $totalExpected - $gradeCount }} remaining)
                </p>
            @endif
        @endif
    </div>
    @endforeach
</div>

{{-- Official Term Results — subject by subject. The overall average is
     summary only; a failed subject is always named, however high the others
     are. Monitoring is DISPLAYED here (MonitoringClassificationService), never
     decided by this page. "View details" on My Students lands on a row here. --}}
<div class="card mb-4" data-official-term-results>
    <div class="px-5 py-4 border-b border-line flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="font-semibold text-ink">Official Term Results — Term {{ $resultsPeriod }}</h3>
            <p class="text-sm text-muted">Each subject's official grade on its own. Overall Average summarizes performance across subjects; it never hides a failed subject. A grade not yet verified is not an official result.</p>
        </div>
        <form method="GET" action="{{ route('adviser.submit.report') }}" class="flex items-center gap-2" data-no-loading>
            <label for="resultsTerm" class="text-xs text-muted">Term</label>
            <select id="resultsTerm" name="period" class="form-select-sm" onchange="this.form.submit()">
                @foreach([1, 2, 3] as $t)
                    <option value="{{ $t }}" @selected($t === $resultsPeriod)>Term {{ $t }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="tbl-scroll">
        <table class="tbl">
            <thead>
                <tr>
                    <th scope="col">Learner</th>
                    <th scope="col" class="tbl-num">Overall Average</th>
                    <th scope="col" class="tbl-num">Passed</th>
                    <th scope="col" class="tbl-num">Failed</th>
                    <th scope="col">Failed Subjects</th>
                    <th scope="col">Monitoring</th>
                </tr>
            </thead>
            <tbody>
                @forelse($gradeSummary as $row)
                @php
                    $student = $row['student'];
                    $r = $officialResults[$student->id] ?? null;
                @endphp
                <tr id="learner-{{ $student->id }}" data-learner-results="{{ $student->id }}">
                    <td class="font-medium text-ink">{{ $student->last_name }}, {{ $student->first_name }}</td>
                    @if(!$r || $r['subjects'] === 0)
                        <td class="tbl-num text-gray-300">—</td>
                        <td class="tbl-num text-gray-300">—</td>
                        <td class="tbl-num text-gray-300">—</td>
                        <td class="text-xs text-gray-300">No grades for Term {{ $resultsPeriod }} yet</td>
                    @else
                        <td class="tbl-num">{{ $r['overall_average'] !== null ? number_format($r['overall_average'], 2) : '—' }}</td>
                        <td class="tbl-num">{{ $r['passed'] }}</td>
                        <td class="tbl-num {{ $r['failed'] ? 'text-status-failing font-semibold' : '' }}">{{ $r['failed'] }}</td>
                        <td class="text-xs">
                            @forelse($r['failed_subjects'] as $f)
                                <span class="block text-status-failing" data-failed-subject>{{ $f['subject'] }} — {{ number_format($f['grade'], 2) }} — Failed</span>
                            @empty
                                <span class="text-muted">None</span>
                            @endforelse
                            @if($r['not_official'])
                                <span class="block text-muted">{{ $r['not_official'] }} not yet official</span>
                            @endif
                            @if($r['stale'])
                                <span class="block font-medium" data-stale-official>Evidence changed since verification — re-verify: {{ implode(', ', $r['stale_subjects']) }}</span>
                            @endif
                        </td>
                    @endif
                    <td><x-ui.monitoring-status :status="$monitoring->get($student->id)" /></td>
                </tr>
                @empty
                <tr>
                    <td colspan="6">
                        <x-empty-state message="No students in this section yet." hint="An Admin adds students and assigns them to your section." />
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Grade Summary Table --}}
<div class="card">
    <div class="px-5 py-4 border-b border-line">
        <h3 class="font-semibold text-ink">Grade Summary</h3>
        <p class="text-sm text-muted">Overview of encoded grades per student</p>
    </div>

    <div class="tbl-scroll">
        <table class="tbl">
            <thead>
                <tr>
                    <th scope="col">Student</th>
                    <th scope="col" class="text-center">Term 1</th>
                    <th scope="col" class="text-center">Term 2</th>
                    <th scope="col" class="text-center">Term 3</th>
                    <th scope="col" class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($gradeSummary as $row)
                <tr>
                    <td class="font-medium text-ink">
                        {{ $row['student']->last_name }}, {{ $row['student']->first_name }}
                    </td>
                    {{-- "Decision flow, report scoping, and dashboard
                         pass" TASK 2e — same marking treatment as the
                         Reports page: a term's grades stay visible once
                         verified, but a term that hasn't been SUBMITTED
                         yet is marked, not presented identically to one
                         that has. --}}
                    @foreach([1, 2, 3] as $period)
                    @php
                        $termValue = $row['term' . $period];
                        $termSubmitted = $termStatus[$period]['submitted'] ?? false;
                    @endphp
                    <td class="tbl-num text-center {{ !$termSubmitted ? 'text-gray-400 italic' : ($termValue && $termValue < 75 ? 'text-status-risk font-semibold' : 'text-gray-700') }}"
                        @unless($termSubmitted) title="Verified but Term {{ $period }} has not been submitted yet." @endunless>
                        {{ $termValue ? number_format($termValue, 2) : '—' }}
                    </td>
                    @endforeach
                    <td class="text-center">
                        @if($row['term1'] && $row['term2'] && $row['term3'])
                            <span class="badge badge-success">Complete</span>
                        @else
                            <span class="badge badge-warning">Incomplete</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5">
                        <x-empty-state message="No students in this section yet." hint="An Admin adds students and assigns them to your section." />
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endif

@endsection
