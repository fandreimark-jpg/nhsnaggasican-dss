@extends('layouts.app')

@section('title', 'Adviser Dashboard')
@section('subtitle', $section
    ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level
      . ' | ' . ($section->track->name ?? '')
      . ' — ' . ($section->specialization->name ?? '')
    : 'No section assigned')

@section('content')

@if(!$section)
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl">
        <x-empty-state message="No section assigned yet." hint="An Admin assigns sections to advisers — contact the admin to get one assigned to your account." />
    </div>
@else

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

{{-- "Decision flow, report scoping, and dashboard pass" TASK 5b — the
     top of this page used to be two static paragraphs (what In-Term
     Status vs. Risk Level mean) that never change and say nothing about
     THIS section right now. Replaced with a checklist of only the
     things that actually require action; the static definitions moved
     into the collapsible below rather than being deleted. --}}
@php
    $attentionItems = collect([
        [
            'count' => $unacknowledgedInterventions->count(),
            'label' => 'intervention' . ($unacknowledgedInterventions->count() === 1 ? '' : 's') . ' awaiting your acknowledgement',
            'link'  => route('adviser.interventions'),
        ],
        [
            'count' => $acknowledgedNotDelivered,
            'label' => 'intervention' . ($acknowledgedNotDelivered === 1 ? '' : 's') . ' acknowledged but not yet delivered',
            'link'  => route('adviser.interventions'),
        ],
        [
            'count' => $subjectsWithIncompleteEvidence->count(),
            'label' => 'subject' . ($subjectsWithIncompleteEvidence->count() === 1 ? '' : 's') . ' with incomplete assessment evidence in Term ' . $openTerm,
            'link'  => route('adviser.assessments'),
        ],
        [
            'count' => $gradesComputedNotVerified,
            'label' => 'grade' . ($gradesComputedNotVerified === 1 ? '' : 's') . ' computed but not yet verified',
            'link'  => route('adviser.assessments'),
        ],
    ])->filter(fn($item) => $item['count'] > 0)->values();
@endphp
<x-panel title="What Needs Your Attention Now" class="mb-4">
    @if($attentionItems->isEmpty())
        <div class="text-sm text-gray-500">
            <i class="bi bi-check-circle text-status-ontrack"></i> Nothing needs your attention right now.
        </div>
    @else
        <ul class="divide-y divide-gray-100 -mx-4 -mb-4">
            @foreach($attentionItems as $item)
            <li class="px-4 py-3 flex items-center justify-between gap-3 text-sm">
                <span class="text-gray-700"><span class="font-semibold">{{ $item['count'] }}</span> {{ $item['label'] }}</span>
                <a href="{{ $item['link'] }}" class="text-xs text-brand-700 hover:underline shrink-0">Review &rarr;</a>
            </li>
            @endforeach
        </ul>
    @endif
</x-panel>

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
    subtitle="Decisions the Principal has recorded for your students that you haven't acknowledged yet" class="mb-6">
    <x-slot:action>
        <a href="{{ route('adviser.interventions') }}" class="text-sm text-brand-600 hover:underline whitespace-nowrap">
            View all →
        </a>
    </x-slot:action>
    @if($unacknowledgedInterventions->isEmpty())
        <div class="text-sm text-gray-400">
            <i class="bi bi-check-circle"></i> No unacknowledged interventions right now.
        </div>
    @else
        <div class="text-xs text-orange-600 bg-orange-50 -mx-4 -mt-4 mb-4 px-4 py-3 border-b">
            <i class="bi bi-exclamation-circle-fill"></i>
            {{ $unacknowledgedInterventions->count() }} intervention{{ $unacknowledgedInterventions->count() === 1 ? '' : 's' }} awaiting your acknowledgement.
        </div>
        <ul class="divide-y divide-gray-100 -mx-4 -mb-4">
            @foreach($unacknowledgedInterventions->take(5) as $iv)
            <li class="px-4 py-3 text-sm flex justify-between items-center gap-3">
                <div>
                    <span class="font-medium text-gray-800">{{ $iv->student->last_name }}, {{ $iv->student->first_name }}</span>
                    <span class="text-gray-500"> — {{ $ivTypeLabels[$iv->recommended_type] ?? ucfirst(str_replace('_', ' ', $iv->recommended_type)) }}</span>
                    @if($iv->subject)<span class="text-gray-400"> ({{ $iv->subject->name }})</span>@endif
                </div>
                <form method="POST" action="{{ route('adviser.interventions.acknowledge', $iv->id) }}">
                    @csrf
                    <button type="submit" class="text-xs text-brand-700 border border-brand-200 rounded px-2 py-1 hover:bg-brand-50 whitespace-nowrap">
                        Mark as Acknowledged
                    </button>
                </form>
            </li>
            @endforeach
        </ul>
        @if($unacknowledgedInterventions->count() > 5)
            <p class="px-4 py-2 -mx-4 -mb-4 text-xs text-gray-400 border-t">And {{ $unacknowledgedInterventions->count() - 5 }} more — see the Interventions page.</p>
        @endif
    @endif
</x-panel>

{{-- Summary Cards — TASK 7a of "clarity, progress, and visual design
     pass": none of these four is a status (At Risk/Needs Attention/On
     Track/Failing), so none carries a status colour — a quiet, neutral
     group (one shared count-* hue) instead of a rainbow of unrelated
     counts. --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <x-stat-card label="Total Students" :value="$totalStudents" accent="count-8" />
    {{-- "Decision flow, report scoping, and dashboard pass" TASK 6a — this
         is the count across ALL 3 terms, not one term; a reader could
         otherwise mistake it for "this term's" count. --}}
    <x-stat-card label="Grades Encoded" :value="$totalGradesEncoded" accent="count-8"
        :note="'of '.$totalExpectedAcrossTerms.' across 3 terms'" />
    <x-stat-card label="Pending Submission" :value="$pendingCount" accent="count-8" />
    <x-stat-card label="Terms Submitted" :value="$submissions->count()" accent="count-8" />
</div>

{{-- Term Submission Status — "correctness and interface pass" TASK 6f:
     "the most useful element on this screen" — the same three facts shown
     consistently across all three cards regardless of state — grades
     encoded, submission status, and a submission date (or an explicit
     "Not yet submitted" in its place, so the three cards read as one
     consistent set rather than each showing a different subset of
     information). --}}
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
    @endphp
    <div class="bg-white rounded-lg shadow-sm p-4 border-t-4
        {{ $isSubmitted ? 'border-green-500' : ($isComplete ? 'border-brand-500' : 'border-gray-200') }}">
        <div class="flex justify-between items-start mb-3">
            <p class="text-sm font-semibold text-gray-800">Term {{ $term }}</p>
            @if($isSubmitted)
                <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-700 font-medium">
                    Submitted
                </span>
            @elseif($isComplete)
                <span class="text-xs px-2 py-1 rounded-full bg-brand-100 text-brand-700 font-medium">
                    Ready
                </span>
            @elseif(!$isConfigured)
                <span class="text-xs px-2 py-1 rounded-full bg-orange-100 text-orange-700 font-medium">
                    Not Configured
                </span>
            @else
                <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-500 font-medium">
                    Incomplete
                </span>
            @endif
        </div>
        <p class="text-xs text-gray-400 tabular-nums">
            @if(!$isConfigured)
                Electives not yet assigned for this section
            @else
                {{ $termCount }}/{{ $termExpected }} grades encoded
            @endif
        </p>
        <p class="text-xs text-gray-400 mt-1">
            @if($isSubmitted)
                Submitted {{ $submission->submitted_at->format('M d, Y h:i A') }}
            @else
                Not yet submitted
            @endif
        </p>
        @if($isComplete && !$isSubmitted)
            <a href="{{ route('adviser.submit.report') }}"
               class="block w-full mt-3 text-center bg-brand-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-brand-800">
                Submit Now →
            </a>
        @elseif(!$isComplete)
            <a href="{{ route('adviser.grades') }}?period={{ $term }}"
               class="block w-full mt-3 text-center border border-gray-300 text-gray-600 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-gray-50">
                Encode Grades →
            </a>
        @endif
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
    <div class="tbl-scroll mt-2 -mx-4">
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
                <td class="font-medium text-gray-800">
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
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-status-risk/10 text-status-risk">
                            {{ $componentLabels[$row['in_term_status']['weakest_component']] ?? $row['in_term_status']['weakest_component'] }}
                        </span>
                        <span class="block text-xs text-gray-400 mt-1">{{ $row['focus_subject']->name }}</span>
                    @else
                        <span class="text-xs text-gray-300">—</span>
                    @endif
                </td>
                <td class="text-center">
                    @if($row['risk_level'])
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $riskLevelColors[$row['risk_level']] ?? 'bg-gray-100 text-gray-600' }}">
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
    <p class="px-4 py-2 -mx-4 -mb-4 text-xs text-gray-400 border-t">
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
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-lg w-full max-w-md p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800" id="ssmStudentName"></h3>
            <button type="button" onclick="window.hideModal('studentSubjectModal')" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <p class="text-xs text-gray-500 mb-4">In-Term Status per subject — <span id="ssmTermLabel"></span></p>
        <ul id="ssmSubjectList" class="divide-y divide-gray-100 text-sm"></ul>
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
                badge.className = 'px-2 py-0.5 rounded-full text-xs font-medium ' + (SSM_STATUS_COLORS[bs.status] || 'bg-gray-100 text-gray-600');
                badge.textContent = bs.status;
                wrap.appendChild(badge);

                const count = document.createElement('span');
                count.className = 'block text-xs text-gray-400 mt-0.5';
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