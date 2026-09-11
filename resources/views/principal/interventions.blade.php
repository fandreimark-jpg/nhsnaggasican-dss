@extends('layouts.app')

@section('title', 'Intervention Tracking')
@section('subtitle', 'Decisions already recorded — status, decider, and progress over time')

@section('content')

@if(session('success'))
<div class="bg-green-100 text-green-700 text-sm p-4 rounded-lg mb-4">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="bg-red-100 text-red-700 text-sm p-4 rounded-lg mb-4">{{ session('error') }}</div>
@endif

{{-- "Master pass" PART 1.4a — a standing banner (shown on every
     unfiltered view of this page, not only after clicking through)
     whenever any intervention is still sitting at its as-created
     "recommendation only" state. Recording an intervention now decides
     it by default (see Principal\InterventionController::store()), so
     this can only ever be non-zero via the deliberate "record as a
     recommendation only" checkbox — surfaced here rather than silently
     left for the adviser to discover a stalled workflow. Worded as
     "approved," not "decided" — these are Principal-recorded batches
     awaiting the Principal's own approval, not DSS recommendations
     awaiting review (see PART 1.3). --}}
@if(($undecidedCount ?? 0) > 0 && !($awaitingDecisionOnly ?? false))
<div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4 flex items-center justify-between gap-4">
    <div>
        <p class="text-sm font-medium text-amber-900">
            <i class="bi bi-exclamation-circle"></i>
            {{ $undecidedCount }} intervention{{ $undecidedCount === 1 ? '' : 's' }} {{ $undecidedCount === 1 ? 'is' : 'are' }} recorded but not yet approved.
        </p>
        <p class="text-xs text-amber-700 mt-1">
            Advisers cannot act on {{ $undecidedCount === 1 ? 'it' : 'them' }} until you approve {{ $undecidedCount === 1 ? 'it' : 'them' }}.
        </p>
    </div>
    <a href="{{ route('principal.interventions', ['awaiting_decision' => 1]) }}"
       class="text-sm text-amber-800 font-medium hover:underline shrink-0">
        Review {{ $undecidedCount === 1 ? 'it' : 'them' }} →
    </a>
</div>
@endif

{{-- "Correctness and interface pass" TASK 2c, reworded per "master
     pass" PART 1.3/1.4 — reached from the dashboard's "Awaiting Your
     Decision" card. These are records with no Principal decision made
     on them at all — nothing has been acknowledged or delivered
     downstream (see Intervention::awaitingDecision()), so approving
     these is the single most useful thing to do on this screen right
     now. --}}
@if($awaitingDecisionOnly ?? false)
<div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4">
    <p class="text-sm font-medium text-amber-900"><i class="bi bi-clipboard2-check"></i> Approving these unblocks the adviser.</p>
    <p class="text-xs text-amber-700 mt-1">
        None of these have been acknowledged or delivered yet — nothing downstream can happen until you approve, decline,
        or otherwise move each one below off "Not yet approved."
    </p>
    {{-- "Decision flow, report scoping, and dashboard pass" TASK 1f —
         approves exactly the rows shown below (this filtered view IS
         the "selected" set), one activity log entry per intervention,
         all in one transaction. --}}
    @if($interventions->isNotEmpty())
    <form method="POST" action="{{ route('principal.interventions.approve-pending') }}" class="mt-3"
          onsubmit="return confirm('Approve all {{ $interventions->count() }} pending intervention(s) shown below? This records your decision for each.');">
        @csrf
        @foreach($interventions as $iv)
            <input type="hidden" name="ids[]" value="{{ $iv->id }}">
        @endforeach
        {{-- "Master pass" PART 1.4c — the SAME filters this page is
             currently showing, so the controller can re-derive (never
             just trust) that every submitted id actually belongs to
             this filtered view — see
             InterventionController::buildFilteredQuery(). --}}
        <input type="hidden" name="grade_level" value="{{ request('grade_level') }}">
        <input type="hidden" name="section_search" value="{{ request('section_search') }}">
        <input type="hidden" name="status" value="{{ request('status') }}">
        <input type="hidden" name="subject_id" value="{{ request('subject_id') }}">
        @if(request()->has('grading_period'))
            <input type="hidden" name="grading_period" value="{{ request('grading_period') }}">
        @endif
        <button type="submit" class="bg-amber-700 text-white text-sm px-4 py-2 rounded-lg font-medium hover:bg-amber-800">
            Approve All Pending ({{ $interventions->count() }})
        </button>
    </form>
    @endif
</div>
@endif

@php
    // TASK 1 of "terminology, transmutation, and interface cleanup" —
    // 'remediation' stays the STORED enum value (existing rows and the
    // TYPES list are untouched) but is labelled for what this system
    // actually does: an additional summative item added DURING the term.
    // Formal DepEd remediation (DO 8, s. 2015 / DO 015, s. 2026) is a
    // distinct POST-term programme this system does not implement — see
    // CLAUDE.md's "Known limitations".
    $typeLabels = [
        'remediation' => 'Additional Practice and Re-teaching',
        'additional_learning_activity' => 'Additional Learning Activity',
        'additional_performance_task' => 'Additional Performance Task',
        'teacher_monitoring' => 'Teacher Monitoring',
        'attendance_monitoring' => 'Attendance Monitoring',
        'parent_conference' => 'Parent/Guardian Conference',
        'other' => 'Other',
    ];
    $statusLabels = [
        'recommended' => 'Recommended', 'in_review' => 'In Review', 'approved' => 'Approved',
        'in_progress' => 'In Progress', 'completed' => 'Completed', 'monitoring' => 'Monitoring',
    ];
    $statusColors = [
        'recommended' => 'bg-gray-100 text-gray-600', 'in_review' => 'bg-yellow-100 text-yellow-700',
        'approved' => 'bg-blue-100 text-blue-700', 'in_progress' => 'bg-purple-100 text-purple-700',
        'completed' => 'bg-green-100 text-green-700', 'monitoring' => 'bg-orange-100 text-orange-700',
    ];
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    $filterKeys = ['grade_level', 'section_search', 'status', 'ready_for_review', 'subject_id', 'grading_period', 'awaiting_decision'];
    $filtersActive = collect($filterKeys)->contains(fn($k) => request($k));
    $selectedSection = $sections->firstWhere('name', request('section_search'));
@endphp

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <div class="px-6 py-4 border-b">
        <h3 class="font-semibold text-gray-800 text-sm">Recorded Interventions</h3>
        <p class="text-xs text-gray-500 mt-1">
            Every row here is a decision the Principal already made — not a suggestion. Recommendations are still
            generated by the DSS, but they're recorded from the <a href="{{ route('principal.students') }}" class="text-brand-700 underline hover:text-brand-800">Students</a> page now.
        </p>
        {{-- Standing property of the output, not a notification — never
             dismissible. See the "honest model evaluation" prompt. --}}
        <p class="text-xs text-gray-400 mt-1">
            Risk levels are derived from grade thresholds; confidence reflects how much the model's decision trees agreed with each other, not predictive certainty about any individual student.
        </p>

        <div class="flex flex-wrap items-end gap-3 mt-3 pt-3 border-t">
            <form method="GET" id="interventionFilterForm" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Grade Level</label>
                    <select name="grade_level" id="ivGradeLevelSelect"
                            class="border rounded-md text-sm px-2 py-1.5 min-w-[130px]">
                        <option value="">All grade levels</option>
                        @foreach($gradeLevels as $gl)
                            <option value="{{ $gl }}" {{ request('grade_level') == $gl ? 'selected' : '' }}>
                                Grade {{ $gl }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Section</label>
                    <select name="section_search" id="ivSectionSelect"
                            class="border rounded-md text-sm px-2 py-1.5 min-w-[130px]">
                        <option value="">All sections</option>
                        @foreach($sections as $sec)
                            <option value="{{ $sec->name }}" data-grade-level="{{ $sec->grade_level }}"
                                    data-track="{{ $sec->track->name ?? 'Not set' }}"
                                    data-specialization="{{ $sec->specialization->name ?? 'Not set' }}"
                                    {{ request('section_search') === $sec->name ? 'selected' : '' }}>
                                {{ $sec->name }} (Grade {{ $sec->grade_level }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Track</label>
                    <p id="ivTrackDisplay" class="border rounded-md text-sm px-2 py-1.5 min-w-[130px] bg-gray-50 text-gray-600">
                        {{ $selectedSection->track->name ?? 'Not set' }}
                    </p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Specialization</label>
                    <p id="ivSpecializationDisplay" class="border rounded-md text-sm px-2 py-1.5 min-w-[150px] bg-gray-50 text-gray-600">
                        {{ $selectedSection->specialization->name ?? 'Not set' }}
                    </p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Status</label>
                    <select name="status" class="border rounded-md text-sm px-2 py-1.5 min-w-[140px]">
                        <option value="">All statuses</option>
                        @foreach($statuses as $s)
                            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ $statusLabels[$s] ?? $s }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- TASK 3 of "terminology, transmutation, and interface
                     cleanup" — options are only the subjects that
                     actually appear in the current (subject-filter-
                     excluded) result set, from
                     InterventionController::index() — never every
                     Subject in the system. --}}
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Subject</label>
                    <select name="subject_id" class="border rounded-md text-sm px-2 py-1.5 min-w-[150px]">
                        <option value="">All subjects</option>
                        @foreach($subjects as $subj)
                            <option value="{{ $subj->id }}" {{ (string) request('subject_id') === (string) $subj->id ? 'selected' : '' }}>{{ $subj->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Term</label>
                    <select name="grading_period" class="border rounded-md text-sm px-2 py-1.5 min-w-[110px]">
                        <option value="">All terms</option>
                        @foreach([1, 2, 3] as $t)
                            <option value="{{ $t }}" {{ $gradingPeriod == $t ? 'selected' : '' }}>Term {{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-1.5 pb-1.5">
                    <input type="checkbox" name="ready_for_review" id="ivReadyForReview" value="1"
                           {{ request()->boolean('ready_for_review') ? 'checked' : '' }}
                           onchange="this.form.submit()">
                    <label for="ivReadyForReview" class="text-xs text-gray-600">
                        Ready to close <span class="text-gray-400">({{ $readyForReviewCount }})</span>
                    </label>
                </div>
                @if($filtersActive)
                    <a href="{{ route('principal.interventions') }}" class="text-sm text-gray-500 hover:underline pb-1.5">Clear</a>
                @endif
            </form>
        </div>

        {{-- TASK 7 of "terminology, transmutation, and interface cleanup"
             — a manual Refresh (a plain reload of the current filtered
             URL) plus when that reload happened, rather than a
             websocket/broadcast build-out this page doesn't need. --}}
        @include('partials.last-updated')
    </div>

    {{-- TASK 5 of "terminology, transmutation, and interface cleanup" —
         row count above the table, and the body scrolls within a fixed-
         height container (header stays pinned) instead of the whole page
         scrolling once there are more than a handful of rows. --}}
    <p class="text-xs text-gray-500 px-6 pt-3">
        <x-count-label :count="$interventions->total()" noun="intervention" />
    </p>
    {{-- "Progress column honesty" work order, PART 1c — said once, here,
         rather than repeated as an excuse on every row that can't fill. --}}
    <p class="text-xs text-gray-400 px-6 pt-1">
        <strong>Term-over-Term Progress</strong> compares the focus component in the term the intervention was raised
        against the next term. Only available for interventions raised from a submitted term report.
    </p>
    <div class="tbl-scroll mt-2">
    <table class="tbl tbl-sticky">
        <thead>
            <tr>
                <th scope="col">Student</th>
                <th scope="col">Subject</th>
                <th scope="col">Type &amp; Reason</th>
                <th scope="col">Status</th>
                <th scope="col">Decided</th>
                <th scope="col">Acknowledged / Delivered</th>
                <th scope="col">Within-Term Progress</th>
                <th scope="col">Term-over-Term Progress</th>
            </tr>
        </thead>
        <tbody>
            @forelse($interventions as $iv)
            <tr class="align-top">
                <td class="font-medium text-gray-800 whitespace-nowrap">
                    <a href="{{ route('principal.students.show', $iv->student_id) }}" class="hover:underline hover:text-brand-700">
                        {{ $iv->student->last_name }}, {{ $iv->student->first_name }}
                    </a>
                    <span class="block text-xs text-gray-400 font-normal">
                        {{ $iv->student->section->name ?? '—' }}
                        @if($iv->student->section) (Grade {{ $iv->student->section->grade_level }}) @endif
                    </span>
                    <span class="block text-xs text-gray-400 font-normal">
                        {{ $iv->student->section->track->name ?? 'Not set' }} / {{ $iv->student->section->specialization->name ?? 'Not set' }}
                    </span>
                </td>
                <td class="text-gray-600 whitespace-nowrap">
                    {{ $iv->subject->name ?? '—' }}
                </td>
                <td class="max-w-xs">
                    <span class="font-medium text-gray-700">{{ $typeLabels[$iv->recommended_type] ?? $iv->recommended_type }}</span>
                    @if($iv->recommendation_reason)
                        <p class="text-xs text-gray-500 mt-1">{{ $iv->recommendation_reason }}</p>
                    @endif
                    @if($iv->principal_notes)
                        <p class="text-xs text-gray-400 mt-1 italic">"{{ $iv->principal_notes }}"</p>
                    @endif
                    @unless($iv->risk_result_id)
                        <span class="inline-block mt-1 text-[10px] text-gray-400" title="Recorded from assessment evidence — no submitted term report existed yet for this term.">
                            <i class="bi bi-clipboard-data"></i> From evidence, not a term report
                        </span>
                    @endunless
                    {{-- "Master pass" PART 1.3c/1.3e — a neutral, informational
                         indicator of WHO created this record, distinct from
                         the DSS-produced recommendation text/focus area
                         above it. See Intervention::isSystemGenerated(); no
                         code path sets 'system' today, so this always reads
                         "Recorded by you" in this codebase, but the row
                         never claims otherwise if that changes. --}}
                    <span class="inline-block mt-1 text-[10px] text-gray-400">
                        <i class="bi bi-person-fill"></i> {{ $iv->isSystemGenerated() ? 'Flagged by the DSS' : 'Recorded by you' }}
                    </span>
                </td>
                <td class="min-w-[220px]">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $statusColors[$iv->status] ?? 'bg-gray-100 text-gray-600' }}">
                        {{-- "Master pass" PART 1.3d — the stored value stays
                             'recommended'; only the DISPLAY label changes for
                             a Principal-origin row, since "Recommended" here
                             would (falsely) suggest the DSS produced this
                             record rather than the Principal simply not
                             having approved it yet. --}}
                        @if($iv->status === 'recommended' && !$iv->isSystemGenerated())
                            Not yet approved
                        @else
                            {{ $statusLabels[$iv->status] ?? $iv->status }}
                        @endif
                    </span>
                    <form method="POST" action="{{ route('principal.interventions.update', $iv->id) }}" class="flex items-end gap-2 mt-2">
                        @csrf
                        @method('PUT')
                        <select name="status" class="border rounded-md text-xs px-2 py-1">
                            @foreach($statuses as $s)
                                <option value="{{ $s }}" {{ $iv->status === $s ? 'selected' : '' }}>{{ $statusLabels[$s] ?? $s }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="bg-brand-700 text-white text-xs px-3 py-1 rounded-md">Update</button>
                    </form>
                    {{-- TASK 2b of "bulk dialog and intervention closure" —
                         a SIGNAL only, never an action: the student's
                         current In-Term Status recovered to On Track since
                         this was delivered, but nothing changes until the
                         Principal clicks one of these buttons themselves
                         (see InTermStatusService::isReadyForReview()). --}}
                    @if($iv->readyForReview ?? false)
                    <div id="rfr-{{ $iv->id }}" class="mt-2 bg-amber-50 border border-amber-200 rounded-md p-2 text-xs text-amber-800 max-w-[220px]">
                        <p class="font-medium"><i class="bi bi-arrow-up-circle-fill"></i> Student is now On Track. Close this intervention?</p>
                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                            <form method="POST" action="{{ route('principal.interventions.update', $iv->id) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="status" value="completed">
                                <button type="submit" class="bg-green-700 text-white px-2 py-1 rounded text-[11px] font-medium hover:bg-green-800">Completed</button>
                            </form>
                            <form method="POST" action="{{ route('principal.interventions.update', $iv->id) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="status" value="monitoring">
                                <button type="submit" class="bg-orange-600 text-white px-2 py-1 rounded text-[11px] font-medium hover:bg-orange-700">Monitoring</button>
                            </form>
                            <button type="button" onclick="document.getElementById('rfr-{{ $iv->id }}').remove()" class="text-amber-700 hover:underline">Leave it</button>
                        </div>
                    </div>
                    @elseif($iv->improvedButBelowTarget ?? false)
                    {{-- TASK 2c of "clarity, progress, and visual design" —
                         a read-only signal, never an action: the targeted
                         component genuinely rose since delivery but the
                         student isn't On Track yet. Distinguishes
                         "continue support" from "nothing happened," which
                         used to look identical. --}}
                    <div class="mt-2 bg-blue-50 border border-blue-200 rounded-md p-2 text-xs text-blue-800 max-w-[220px]">
                        <p class="font-medium"><i class="bi bi-graph-up-arrow"></i> Improved, still below target — continue support.</p>
                    </div>
                    @endif
                </td>
                <td class="text-xs text-gray-500 whitespace-nowrap">
                    @if($iv->decidedBy)
                        {{ $iv->decidedBy->name }}
                        <span class="block text-gray-400">{{ $iv->decided_at?->format('M d, Y') }}</span>
                    @else
                        <span class="text-gray-300">Not yet decided</span>
                    @endif
                    <span class="block text-gray-400 mt-1">Created {{ $iv->created_at->format('M d, Y') }}</span>
                </td>
                <td class="text-xs whitespace-nowrap max-w-[220px]">
                    @if($iv->acknowledged_at)
                        <span class="inline-flex items-center gap-1 text-blue-700">
                            <i class="bi bi-eye-fill"></i> Ack. by {{ $iv->acknowledgedBy->name ?? 'Adviser' }}
                        </span>
                        <span class="block text-gray-400 mt-0.5">{{ $iv->acknowledged_at->format('M d, Y h:i A') }}</span>
                    @else
                        <span class="text-gray-300" title="The adviser has not yet marked this as seen.">Not yet acknowledged</span>
                    @endif
                    {{-- TASK 2c of "close the delivery loop": whether the
                         decision was actually carried out, not only seen. --}}
                    @if($iv->delivered_at)
                        <span class="inline-flex items-center gap-1 text-green-700 mt-1">
                            <i class="bi bi-check-circle-fill"></i> Delivered by {{ $iv->deliveredBy->name ?? 'Adviser' }}
                        </span>
                        @if($iv->delivery_mode === 'group')
                            {{-- TASK 3d of "clarity, progress, and visual
                                 design pass" — never let a shared note read
                                 as if it were written for this one child. --}}
                            <span class="block text-[11px] text-gray-500 mt-0.5">
                                <i class="bi bi-people-fill"></i> Delivered as a group activity ({{ $iv->deliveryGroupSize ?? '2+' }} learners)
                            </span>
                        @endif
                        <span class="block text-gray-400 mt-0.5">{{ $iv->delivered_at->format('M d, Y h:i A') }}</span>
                        @if($iv->delivery_notes)
                            <p class="text-gray-500 italic mt-1 whitespace-normal">"{{ $iv->delivery_notes }}"</p>
                        @endif
                    @elseif($iv->acknowledged_at)
                        <span class="block text-gray-300 mt-1">Not yet delivered</span>
                    @endif
                </td>
                <td class="text-xs whitespace-nowrap">
                    @include('partials.within-term-progress', ['p' => $iv->withinTermProgress ?? null])
                </td>
                <td class="min-w-[220px]">
                    @php $p = $iv->progress; @endphp
                    @if($p['status'] === 'available' || $p['status'] === 'awaiting_evidence')
                        <div class="text-xs text-gray-500 bg-gray-50 rounded-md px-2 py-1.5">
                            <span class="font-medium text-gray-600">{{ $componentLabels[$p['component']] ?? $p['component'] }}:</span>
                            Term {{ $p['before_period'] }} was {{ number_format($p['before_percentage'], 1) }}%
                            @if($p['status'] === 'available')
                                , Term {{ $p['after_period'] }} is {{ number_format($p['after_percentage'], 1) }}%.
                                <span class="{{ $p['change'] > 0 ? 'text-green-600' : ($p['change'] < 0 ? 'text-red-500' : 'text-gray-500') }} font-medium">
                                    Change: {{ $p['change'] >= 0 ? '+' : '' }}{{ number_format($p['change'], 1) }} points.
                                </span>
                            @else
                                — no Term {{ $p['after_period'] }} evidence yet to compare against.
                            @endif
                        </div>
                    @elseif($p['status'] === 'no_later_term')
                        <span class="text-xs text-gray-300">No later term in this school year to compare against.</span>
                    @elseif($p['status'] === 'not_applicable')
                        {{-- PART 1b — origin, not the null itself, explains WHY:
                             every row today is 'principal' (recorded from
                             in-term evidence, so no term-report baseline ever
                             existed); 'system' is reserved for a future DSS-
                             generated source and would mean something else. --}}
                        @if(!$iv->isSystemGenerated())
                            <span class="text-xs text-gray-300">Term-over-term comparison applies only to interventions raised from a submitted term report — this one was raised from in-term evidence.</span>
                        @else
                            <span class="text-xs text-gray-300">No term-report baseline is linked to this recommendation.</span>
                        @endif
                    @else
                        <span class="text-xs text-gray-300">No comparison available</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8">
                    @if($filtersActive)
                        <x-empty-state icon="bi-clipboard2-pulse" message="No interventions match the current filter." hint="Try widening the filters above." />
                    @else
                        <x-empty-state icon="bi-clipboard2-pulse" message="No interventions have been recorded yet."
                            hint="Interventions are created from the Students page, not here.">
                            <x-slot:action>
                                <a href="{{ route('principal.students') }}" class="text-sm text-brand-700 hover:underline">
                                    <i class="bi bi-mortarboard"></i> Go to Students
                                </a>
                            </x-slot:action>
                        </x-empty-state>
                    @endif
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    @if($interventions->hasPages())
    <div class="px-6 py-4 border-t flex flex-col items-center gap-2 text-sm text-gray-500">
        <div class="flex items-center gap-1">
            @if($interventions->onFirstPage())
                <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">← Prev</span>
            @else
                <a href="{{ $interventions->previousPageUrl() }}" class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">← Prev</a>
            @endif
            <span class="px-3 py-1 rounded border bg-brand-700 text-white font-medium">{{ $interventions->currentPage() }}</span>
            @if($interventions->hasMorePages())
                <a href="{{ $interventions->nextPageUrl() }}" class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">Next →</a>
            @else
                <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">Next →</span>
            @endif
        </div>
        <span class="text-xs">Showing {{ $interventions->firstItem() }}–{{ $interventions->lastItem() }} of <x-count-label :count="$interventions->total()" noun="intervention" /></span>
    </div>
    @endif

    {{-- TASK 3 of "bulk dialog and intervention closure" — head off the
         "the system isn't registering the extra work" misreading before
         it happens, stated plainly, not apologetically: this is correct
         DepEd term-grading arithmetic, not a bug. --}}
    <p class="text-xs text-gray-500 px-6 py-3 border-t">
        An additional assessment item given during the term is added to the term's total points, not swapped in for
        the score it's meant to improve — that's why a strong result on it moves the affected component by less than
        its own percentage suggests. Within-Term Progress above shows the earned/possible points behind each
        percentage so the arithmetic can be checked by hand. This is ordinary within-term academic support, distinct
        from DepEd's formal Summer Remedial Class (DO 8, s. 2015 / DO 015, s. 2026), which runs after Final Grades
        are computed and produces a separate Remedial Class Mark — that post-term programme is not implemented here.
    </p>
</div>

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initCascadingSelect('ivGradeLevelSelect', 'ivSectionSelect');
        initSectionDerivedDisplay('ivSectionSelect', 'ivTrackDisplay', 'ivSpecializationDisplay');
        initAutoSubmitFilter('interventionFilterForm');
    });
</script>
@endpush
