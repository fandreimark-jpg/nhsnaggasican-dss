@extends('layouts.app')

@section('title', 'Adviser Dashboard')
@section('subtitle', $section
    ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level
      . ' | ' . ($section->track->name ?? '')
      . ' — ' . ($section->specialization->name ?? '')
    : 'No section assigned')

@section('content')

@if(!$section)
    <div class="card">
        <x-empty-state message="No section assigned yet." icon="bi bi-person-badge" hint="An Admin assigns sections to advisers — contact the admin to get one assigned to your account." />
    </div>
@else

{{-- "UI modernization pass" — section context strip: everything an
     adviser needs to orient (section, grade, track, specialization,
     school year, open term), read from the same models the page already
     had. Replaces the plain-text context line. --}}
@php
    $openTermForSection = $section->isInActiveSchoolYear() ? \App\Models\AcademicTerm::currentOpenTerm($section->school_year) : null;
@endphp
<div class="card mb-4 px-5 py-4">
    <div class="flex flex-col lg:flex-row lg:items-center gap-4">
        <div class="flex items-center gap-3 min-w-0">
            <div class="icon-box icon-box-brand" aria-hidden="true"><i class="bi bi-grid-3x3-gap"></i></div>
            <div class="min-w-0">
                <p class="stat-label">Your section</p>
                <p class="text-xl font-bold text-ink leading-tight">{{ $section->name }} <span class="text-base font-medium text-muted">— Grade {{ $section->grade_level }}</span></p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 lg:ml-auto">
            <span class="pill"><span class="pill-label">Track</span> {{ $section->track->name ?? 'Not set' }}</span>
            <span class="pill"><span class="pill-label">Specialization</span> {{ $section->specialization->name ?? 'Not set' }}</span>
            <span class="pill"><i class="bi bi-calendar3 text-brand-700" aria-hidden="true"></i><span class="pill-label">School Year</span> {{ $section->school_year }}</span>
            @if($section->isInActiveSchoolYear())
                <span class="badge badge-success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Active school year</span>
                @if($openTermForSection)
                    <span class="badge badge-info"><i class="bi bi-unlock" aria-hidden="true"></i> Term {{ $openTermForSection }} open</span>
                @else
                    <span class="badge badge-gray"><i class="bi bi-lock" aria-hidden="true"></i> No term open</span>
                @endif
            @else
                <span class="badge badge-gray"><i class="bi bi-archive" aria-hidden="true"></i> Historical Record</span>
            @endif
        </div>
    </div>
    @unless($section->isInActiveSchoolYear())
        <p class="help-text mt-3">This section belongs to a completed school year. Its grades, assessments, and reports are read-only. You have no section assigned for the active school year ({{ \App\Models\Section::activeSchoolYear() }}) yet.</p>
    @endunless
</div>

@include('partials.transmutation-fallback-banner')
@include('partials.stale-risk-warning')

@php
    $inTermStatusColors = ['On Track' => 'bg-status-ontrack/10 text-status-ontrack', 'Needs Attention' => 'bg-status-attention/10 text-status-attention', 'At Risk' => 'bg-status-risk/10 text-status-risk'];
    $riskLevelColors = ['low' => 'bg-status-ontrack/10 text-status-ontrack', 'moderate' => 'bg-status-attention/10 text-status-attention', 'high' => 'bg-status-risk/10 text-status-risk'];
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    // TASK 1 of "terminology, transmutation, and interface cleanup" —
    // see the same map on the Interventions pages: 'remediation' is a
    // within-term action here, not DepEd's formal post-term remediation.
    $ivTypeLabels = ['remediation' => 'Additional Practice and Re-teaching'];
@endphp

{{-- "Decision flow, report scoping, and dashboard pass" TASK 5b — a
     checklist of only the things that actually require action, now as
     actionable cards (count + what + where to go). Same four signals,
     same links. --}}
@php
    $attentionItems = collect([
        [
            'count' => $unacknowledgedInterventions->count(),
            'label' => 'intervention' . ($unacknowledgedInterventions->count() === 1 ? '' : 's') . ' awaiting your acknowledgement',
            'description' => 'The Principal has recorded a decision for one of your students.',
            'link'  => route('adviser.interventions'),
            'icon'  => 'bi-clipboard2-check', 'tone' => 'warning', 'cta' => 'Review interventions',
        ],
        [
            'count' => $acknowledgedNotDelivered,
            'label' => 'intervention' . ($acknowledgedNotDelivered === 1 ? '' : 's') . ' acknowledged but not yet delivered',
            'description' => 'Record what was actually done once the support has been given.',
            'link'  => route('adviser.interventions'),
            'icon'  => 'bi-clipboard2-pulse', 'tone' => 'info', 'cta' => 'Record delivery',
        ],
        [
            'count' => $subjectsWithIncompleteEvidence->count(),
            'label' => 'subject' . ($subjectsWithIncompleteEvidence->count() === 1 ? '' : 's') . ' with incomplete assessment evidence in Term ' . $openTerm,
            'description' => 'A component still has no scored items, so grades cannot be computed yet.',
            'link'  => route('adviser.assessments'),
            'icon'  => 'bi-clipboard-data', 'tone' => 'warning', 'cta' => 'Upload assessments',
        ],
        [
            'count' => $gradesComputedNotVerified,
            'label' => 'grade' . ($gradesComputedNotVerified === 1 ? '' : 's') . ' computed but not yet verified',
            'description' => 'Computed from evidence — review and verify before submission.',
            'link'  => route('adviser.assessments'),
            'icon'  => 'bi-patch-check', 'tone' => 'info', 'cta' => 'Verify grades',
        ],
    ])->filter(fn($item) => $item['count'] > 0)->values();
    // A term whose grades are complete but unsubmitted is surfaced on its
    // own term card below ("Ready to submit" + Submit Now), not here —
    // "Nothing needs your attention" keeps its established meaning
    // (AdviserAttentionNeededTest): no evidence gap, no pending
    // intervention step.
@endphp
<div class="mb-4">
    <div class="flex items-end justify-between gap-3 mb-3">
        <div>
            <h3 class="section-title">What Needs Your Attention Now</h3>
            <p class="section-subtitle">Only the items that need an action from you this term.</p>
        </div>
    </div>
    @if($attentionItems->isEmpty())
        <div class="card p-4 flex items-center gap-3 text-sm text-gray-600">
            <div class="icon-box icon-box-success" aria-hidden="true"><i class="bi bi-check-circle"></i></div>
            Nothing needs your attention right now.
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @foreach($attentionItems as $item)
                <x-ui.action-card :title="$item['label']" :count="$item['count']" :description="$item['description']"
                    :icon="$item['icon']" :tone="$item['tone']" :href="$item['link']" :cta="$item['cta']" />
            @endforeach
        </div>
    @endif
</div>

<details class="mb-6 text-xs text-gray-500">
    <summary class="cursor-pointer select-none font-medium text-gray-600 px-1">What's the difference between In-Term Status and Risk Level?</summary>
    <div class="mt-2">
        @include('partials.in-term-vs-risk-help')
    </div>
</details>

{{-- TASK 1c of "close the intervention loop": always visible, even with
     zero rows — an adviser must be able to tell "nothing assigned" apart
     from "this feature doesn't exist." --}}
<x-panel title="Interventions Needing Your Attention"
    subtitle="Decisions the Principal has recorded for your students that you haven't acknowledged yet" class="mb-6" :padded="false">
    <x-slot:action>
        <a href="{{ route('adviser.interventions') }}" class="btn-link text-xs whitespace-nowrap">
            View all →
        </a>
    </x-slot:action>
    @if($unacknowledgedInterventions->isEmpty())
        <div class="px-5 py-4 text-sm text-muted flex items-center gap-2">
            <i class="bi bi-check-circle text-success" aria-hidden="true"></i> No unacknowledged interventions right now.
        </div>
    @else
        <div class="alert alert-warning alert-row rounded-none border-x-0 border-t-0 text-xs">
            <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
            <span>{{ $unacknowledgedInterventions->count() }} intervention{{ $unacknowledgedInterventions->count() === 1 ? '' : 's' }} awaiting your acknowledgement.</span>
        </div>
        <ul class="divide-y divide-line">
            @foreach($unacknowledgedInterventions->take(5) as $iv)
            <li class="px-5 py-3 text-sm flex justify-between items-center gap-3">
                <div>
                    <span class="font-medium text-ink">{{ $iv->student->last_name }}, {{ $iv->student->first_name }}</span>
                    <span class="text-gray-500"> — {{ $ivTypeLabels[$iv->recommended_type] ?? ucfirst(str_replace('_', ' ', $iv->recommended_type)) }}</span>
                    @if($iv->subject)<span class="text-gray-400"> ({{ $iv->subject->name }})</span>@endif
                </div>
                <form method="POST" action="{{ route('adviser.interventions.acknowledge', $iv->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-xs">
                        Mark as Acknowledged
                    </button>
                </form>
            </li>
            @endforeach
        </ul>
        @if($unacknowledgedInterventions->count() > 5)
            <p class="px-5 py-2 text-xs text-muted border-t border-line">And {{ $unacknowledgedInterventions->count() - 5 }} more — see the Interventions page.</p>
        @endif
    @endif
</x-panel>

{{-- Summary Cards — none of these four is a status, so none carries a
     status colour: one neutral tint for the group. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
    <x-stat-card label="Total Students" :value="$totalStudents" accent="count-8" icon="people" />
    {{-- "Decision flow, report scoping, and dashboard pass" TASK 6a — this
         is the count across ALL 3 terms, not one term; a reader could
         otherwise mistake it for "this term's" count. --}}
    <x-stat-card label="Grades Encoded" :value="$totalGradesEncoded" accent="count-8" icon="pencil-square"
        :note="'of '.$totalExpectedAcrossTerms.' across 3 terms'" />
    <x-stat-card label="Pending Submission" :value="$pendingCount" accent="count-8" icon="hourglass-split" />
    <x-stat-card label="Terms Submitted" :value="$submissions->count()" accent="count-8" icon="send-check" />
</div>

{{-- Term cards — "correctness and interface pass" TASK 6f: the same
     three facts on all three cards (grades encoded, submission status,
     submission date), now with the term's open/closed state and a
     completion bar computed from the SAME encoded/expected figures the
     controller already supplies — never a fabricated percentage. --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    @foreach([1, 2, 3] as $term)
    @php
        $submission  = $submissions[$term] ?? null;
        $termCount   = match($term) {
            1 => $term1Count,
            2 => $term2Count,
            3 => $term3Count,
        };
        $termExpected = $expectedPerTerm[$term];
        $isComplete  = $termExpected > 0 && $termCount >= $termExpected;
        $isSubmitted = $submission !== null;
        $isOpen      = $openTermForSection === $term;
        $pct         = $termExpected > 0 ? min(100, (int) round($termCount / $termExpected * 100)) : null;
        $termState = $isSubmitted ? ['Submitted', 'success', 'bi-check-circle-fill']
            : ($isOpen ? ['Open', 'info', 'bi-unlock']
            : ($section->isInActiveSchoolYear() && $openTermForSection && $term > $openTermForSection ? ['Locked', 'gray', 'bi-lock']
            : ['Closed', 'gray', 'bi-lock']));
    @endphp
    <div class="card p-5 flex flex-col {{ $isOpen ? 'ring-2 ring-brand-500/30' : '' }}">
        <div class="flex justify-between items-start mb-3">
            <div>
                <p class="card-title">Term {{ $term }}</p>
                <p class="text-xs text-muted mt-0.5">
                    @if($isSubmitted)
                        Submitted {{ $submission->submitted_at->format('M d, Y h:i A') }}
                    @elseif($termState[0] === 'Locked')
                        Waiting for the previous term
                    @else
                        Not yet submitted
                    @endif
                </p>
            </div>
            <x-ui.status-badge :tone="$termState[1]" :label="$termState[0]" :icon="$termState[2]" />
        </div>

        @if(!$isConfigured)
            <x-ui.status-badge tone="warning" label="Not Configured" class="self-start" />
            <p class="text-xs text-muted mt-2">Electives not yet assigned for this section</p>
        @else
            <div class="flex items-end justify-between gap-2">
                <p class="text-xs text-muted tabular-nums">{{ $termCount }}/{{ $termExpected }} grades encoded</p>
                @if($pct !== null)<p class="text-sm font-semibold text-ink tabular-nums">{{ $pct }}%</p>@endif
            </div>
            <x-ui.progress-bar :value="$pct ?? 0" :tone="$isSubmitted ? 'success' : ($pct === null || $pct === 0 ? 'gray' : null)" class="mt-1.5" :label="'Term ' . $term . ' encoding progress'" />
            @if($isComplete && !$isSubmitted)
                <x-ui.status-badge tone="brand" label="Ready to submit" class="self-start mt-2" />
            @elseif(!$isComplete && $termExpected > 0)
                <x-ui.status-badge tone="warning" label="Incomplete" class="self-start mt-2" />
            @endif
        @endif

        <div class="mt-auto pt-4">
            @if($isComplete && !$isSubmitted)
                <a href="{{ route('adviser.submit.report') }}" class="btn btn-primary btn-sm w-full">
                    Submit Now <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            @elseif(!$isComplete && !$isSubmitted)
                <a href="{{ route('adviser.grades') }}?period={{ $term }}" class="btn btn-outline btn-sm w-full">
                    Encode Grades <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            @else
                <a href="{{ route('adviser.grades') }}?period={{ $term }}" class="btn btn-ghost btn-sm w-full">
                    View grades
                </a>
            @endif
        </div>
    </div>
    @endforeach
</div>

{{-- Students Overview — TASK 2 of "close the intervention loop": drawn
     from InTermStatusService (assessment evidence), not risk_results, so
     this has something real to show from the first upload onward instead
     of "No data — Submit report to generate" on every row. --}}
<x-panel title="My Students">
    <x-slot:subtitle>
        Overall In-Term Status — Term {{ $openTerm }}, combining every subject this section takes into one worst-case status per student
        {{-- TASK 5c of "clarity, progress, and visual design pass" — the
             whole picture without scrolling, since the table below now
             shows only the top 10 highest-priority rows. --}}
        @if($students->isNotEmpty())
        <span class="block text-xs text-gray-500 mt-1">
            <span class="text-status-risk font-medium">{{ $inTermStatusCounts['At Risk'] }} At Risk</span>,
            <span class="text-status-attention font-medium">{{ $inTermStatusCounts['Needs Attention'] }} Needs Attention</span>,
            <span class="text-status-ontrack font-medium">{{ $inTermStatusCounts['On Track'] }} On Track</span>
        </span>
        @endif
    </x-slot:subtitle>
    <x-slot:action>
        <a href="{{ route('adviser.students') }}" class="text-sm text-brand-600 hover:underline whitespace-nowrap">
            View all →
        </a>
    </x-slot:action>
    @if($students->isNotEmpty())
    <p class="text-xs text-gray-500">
        <strong>Overall In-Term Status</strong> is the WORST status among every subject with evidence so far this
        term — one weak subject is enough to flag a student here, even if their other subjects are fine. Click a
        student's name for the subject-by-subject breakdown, or see the
        <a href="{{ route('adviser.assessments') }}" class="text-brand-700 underline hover:text-brand-800">Assessments</a>
        page for one subject at a time. <strong>Risk Level</strong> appears once the adviser submits the term report;
        until then it shows "Not yet available."
    </p>
    {{-- TASK 1 of "status clarity and progress consistency" — the status
         badge below is based on the WORST subject's Computed grade, not
         its Transmuted one, for the same reason it's based on Computed
         everywhere else in this app: transmutation can lift a failing
         raw score into a passing reported grade, and a status built on
         that would only flag a student once real mastery had already
         fallen below the passing anchor of their curriculum (60% for DO
         8, s. 2015; 70% for DO 015, s. 2026 — see "correctness and
         interface pass" TASK 4a). A worst subject already passing on the
         report card is marked <strong>passing on paper</strong> below. --}}
    <p class="text-xs text-gray-500 pt-1">
        The status below is based on that worst subject's <strong>Computed</strong> grade (the raw weighted
        evidence), not its Transmuted (report-card) grade — see <strong>passing on paper</strong> below when they disagree.
    </p>
    @endif
    {{-- TASK 5b of "clarity, progress, and visual design pass" — fixed
         max height + internal scroll + sticky header, same pattern as
         the Assessments/Interventions/Students tables elsewhere in this
         app, now that the panel can hold more than 10 rows worth of
         scroll potential is moot (it's capped at 10) but this keeps the
         header pinned if a section ever has fewer than 10 and the panel
         is short, and keeps the pattern consistent app-wide. --}}
    <div class="tbl-scroll mt-2 -mx-5">
    <table class="tbl tbl-sticky">
        <thead>
            <tr>
                <th scope="col">Student</th>
                <th scope="col">Overall In-Term Status</th>
                <th scope="col">Focus Area</th>
                <th scope="col" class="text-center">Risk Level</th>
                <th scope="col" class="text-center">Trend</th>
            </tr>
        </thead>
        <tbody>
            @forelse($inTermRows as $row)
            @php
                $drillPayload = [
                    'student_name' => $row['student']->last_name . ', ' . $row['student']->first_name,
                    'term'         => $openTerm,
                    'by_subject'   => collect($row['by_subject'])->map(fn($bs) => [
                        'subject_name' => $bs['subject']->name,
                        'status'       => $bs['status']['status'],
                        'item_count'   => $bs['status']['item_count'],
                    ])->values(),
                ];
            @endphp
            <tr>
                <td class="font-medium text-ink">
                    <button type="button" onclick='openStudentSubjectModal(@json($drillPayload))'
                            class="hover:underline hover:text-brand-700 text-left">
                        {{ $row['student']->last_name }}, {{ $row['student']->first_name }}
                    </button>
                </td>
                <td>
                    @if($row['in_term_status'])
                        @include('partials.in-term-status-badge', ['its' => $row['in_term_status'], 'transmutedGrade' => $row['transmuted_grade']])
                    @else
                        <span class="text-xs text-gray-300">No evidence yet</span>
                    @endif
                </td>
                <td>
                    @if($row['focus_subject'] && $row['in_term_status']['weakest_component'] ?? null)
                        <span class="badge bg-status-risk/10 text-status-risk">
                            {{ $componentLabels[$row['in_term_status']['weakest_component']] ?? $row['in_term_status']['weakest_component'] }}
                        </span>
                        <span class="block text-xs text-muted mt-1">{{ $row['focus_subject']->name }}</span>
                    @else
                        <span class="text-xs text-gray-300">—</span>
                    @endif
                </td>
                <td class="text-center">
                    @if($row['risk_level'])
                        <span class="badge {{ $riskLevelColors[$row['risk_level']] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($row['risk_level']) }}
                        </span>
                    @else
                        <span class="text-xs text-gray-300">Not yet available</span>
                    @endif
                </td>
                <td class="text-center">
                    @php $trend = $trends[$row['student']->id] ?? null; @endphp
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
                <td colspan="5">
                    <x-empty-state message="No students in this section yet." hint="An Admin adds students and assigns them to your section." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @if($allInTermRows->count() > $inTermRows->count())
    <p class="px-4 py-2 -mx-4 -mb-4 text-xs text-muted border-t">
        Showing the {{ $inTermRows->count() }} highest-priority of {{ $allInTermRows->count() }} students —
        <a href="{{ route('adviser.students') }}" class="text-brand-600 hover:underline">view all →</a>
    </p>
    @endif
</x-panel>

{{-- TASK 2 of "dashboard structure and upload safeguards" — the
     subject-by-subject breakdown behind the single "Overall" status
     above, so a reader can see for themselves which subject(s) drove it,
     one shared modal populated per row (same pattern as the Record
     Intervention modal elsewhere in this app). --}}
<div id="studentSubjectModal" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-md p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink" id="ssmStudentName"></h3>
            <button type="button" onclick="window.hideModal('studentSubjectModal')" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <p class="text-xs text-gray-500 mb-4">In-Term Status per subject — <span id="ssmTermLabel"></span></p>
        <ul id="ssmSubjectList" class="divide-y divide-line text-sm"></ul>
    </div>
</div>

@endif

@endsection

@push('scripts')
<script>
    const SSM_STATUS_COLORS = {
        'On Track': 'bg-status-ontrack/10 text-status-ontrack',
        'Needs Attention': 'bg-status-attention/10 text-status-attention',
        'At Risk': 'bg-status-risk/10 text-status-risk',
    };

    window.openStudentSubjectModal = function (data) {
        document.getElementById('ssmStudentName').textContent = data.student_name;
        document.getElementById('ssmTermLabel').textContent = 'Term ' + data.term;

        const list = document.getElementById('ssmSubjectList');
        list.innerHTML = '';

        data.by_subject.forEach(function (bs) {
            const li = document.createElement('li');
            li.className = 'py-2 flex items-center justify-between gap-3';

            const name = document.createElement('span');
            name.className = 'text-gray-700';
            name.textContent = bs.subject_name;
            li.appendChild(name);

            if (bs.item_count === 0) {
                const badge = document.createElement('span');
                badge.className = 'text-xs text-gray-300';
                badge.textContent = 'No evidence yet';
                li.appendChild(badge);
            } else {
                const wrap = document.createElement('span');
                wrap.className = 'text-right';

                const badge = document.createElement('span');
                badge.className = 'badge ' + (SSM_STATUS_COLORS[bs.status] || 'bg-gray-100 text-gray-600');
                badge.textContent = bs.status;
                wrap.appendChild(badge);

                const count = document.createElement('span');
                count.className = 'block text-xs text-muted mt-0.5';
                count.textContent = bs.item_count + (bs.item_count === 1 ? ' item' : ' items') + ' scored';
                wrap.appendChild(count);

                li.appendChild(wrap);
            }

            list.appendChild(li);
        });

        window.showModal('studentSubjectModal');
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.bindModalOverlayClose('studentSubjectModal');
    });
</script>
@endpush