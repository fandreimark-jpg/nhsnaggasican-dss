@extends('layouts.app')

@section('title', 'Interventions')
@section('subtitle', $section ? 'Section ' . $section->name . ' — decisions recorded by the Principal' : 'No section assigned')

@section('content')

@include('partials.section-school-year-context')

@if(session('success'))
<div class="alert alert-success mb-4">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="alert alert-danger mb-4">{{ session('error') }}</div>
@endif

@if(!$section)
    <div class="card border-amber-200">
        <x-empty-state message="No section assigned yet." hint="An Admin assigns sections to advisers — contact the admin to get one assigned to your account." />
    </div>
@else

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
    $ivFilterKeys = ['subject_id', 'grading_period'];
    $ivFiltersActive = collect($ivFilterKeys)->contains(fn($k) => request($k));
    $unacknowledgedCount = $interventions->whereNull('acknowledged_at')->count();

    // TASK 4 of "UI cleanup and correctness pass" — one-click starting
    // points for the delivery note, never a substitute for it: the
    // Adviser still types (or edits) the note and confirms every time —
    // see Adviser\InterventionController::markDelivered()'s docblock on
    // why bulk delivery must never exist. Falls back to 'other' for any
    // type not listed here.
    $deliveryNoteChips = [
        'remediation' => ['Gave an additional written activity', 'Re-taught the topic in class', 'One-on-one review session'],
        'additional_learning_activity' => ['Gave an additional written activity', 'Re-taught the topic in class', 'One-on-one review session'],
        'additional_performance_task' => ['Gave an additional performance task', 'Reviewed the rubric with the student', 'Extended the deadline and monitored progress'],
        'teacher_monitoring' => ['Checked in with the student this week', 'Observed the student in class', 'Followed up with the student directly'],
        'attendance_monitoring' => ['Followed up on the student\'s attendance', 'Contacted the guardian about attendance', 'Monitored attendance this week'],
        'parent_conference' => ['Held a conference with the parent/guardian', 'Called the parent/guardian to discuss progress', 'Sent a note home to the parent/guardian'],
        'other' => ['Discussed the situation with the student', 'Followed up as recommended', 'Monitored the situation this week'],
    ];

    $acknowledgedCount = $interventions->whereNotNull('acknowledged_at')->count();
    $deliveredCount    = $interventions->whereNotNull('delivered_at')->count();
@endphp

{{-- Group-delivery association form — deliberately EMPTY of its own
     visible fields. Every checkbox in the table below, and the note
     textarea + Confirm button inside #groupDeliverModal, use the HTML
     form="groupDeliverForm" attribute to submit here regardless of where
     they sit in the DOM — this is what lets a checkbox live inside a
     table row that ALSO contains its own per-student delivery <form>
     (see below) without illegally nesting one form inside another. --}}
<form id="groupDeliverForm" method="POST" action="{{ route('adviser.interventions.deliver-group') }}">
    @csrf
</form>

{{-- "Master pass" PART 1.3a — recommendations/records the Principal has
     not yet decided on. Shown, never hidden — the adviser should see
     what's coming — but with no acknowledge/deliver action offered,
     since CLAUDE.md is explicit: the DSS recommends, the Principal
     decides, and neither step here can happen before that decision
     does. Wording is chosen PER GROUP by who actually created the
     record (see Intervention::isSystemGenerated() and
     Adviser\InterventionController::index()'s partition()) — a
     Principal-recorded batch is not truthfully described as a DSS
     recommendation the Principal hasn't reviewed yet; the Principal
     wrote it themselves and simply hasn't approved it. --}}
@if($awaitingPrincipalOrigin->isNotEmpty())
<div class="bg-amber-50 border border-amber-200 rounded-xl mb-4">
    <div class="px-5 py-4 border-b border-line border-amber-200">
        <h3 class="font-semibold text-amber-900 text-sm">
            <i class="bi bi-hourglass-split"></i> Recorded by the Principal, not yet approved
            <span class="font-normal text-amber-700">({{ $awaitingPrincipalOrigin->count() }})</span>
        </h3>
        <p class="text-xs text-amber-700 mt-1">
            The Principal has recorded these but has not marked them as decided yet. There is nothing to
            acknowledge or deliver until that happens.
        </p>
    </div>
    <div class="max-h-64 overflow-auto">
    <table class="w-full text-sm">
        <thead class="bg-amber-100/50 text-amber-800 text-xs uppercase tracking-wide sticky top-0">
            <tr>
                <th class="text-left px-6 py-2">Student</th>
                <th class="text-left px-3 py-2">Subject</th>
                <th class="text-left px-4 py-2">Recommended Type</th>
                <th class="text-left px-4 py-2">Term</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-amber-100">
            @foreach($awaitingPrincipalOrigin as $iv)
            <tr>
                <td class="px-6 py-2 font-medium text-amber-900 whitespace-nowrap">{{ $iv->student->last_name }}, {{ $iv->student->first_name }}</td>
                <td class="px-3 py-2 text-amber-800 whitespace-nowrap">{{ $iv->subject->name ?? '—' }}</td>
                <td class="px-4 py-2 text-amber-800">{{ $typeLabels[$iv->recommended_type] ?? $iv->recommended_type }}</td>
                <td class="px-4 py-2 text-amber-800 whitespace-nowrap">{{ $iv->grading_period ? 'Term ' . $iv->grading_period : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</div>
@endif

{{-- No code path sets origin => 'system' today (see the hard constraint
     in the "master pass" prompt) — this group is therefore always empty
     right now. Kept so the interface tells the truth the moment such a
     source exists, instead of needing a second pass to add it. --}}
@if($awaitingSystemOrigin->isNotEmpty())
<div class="bg-amber-50 border border-amber-200 rounded-xl mb-4">
    <div class="px-5 py-4 border-b border-line border-amber-200">
        <h3 class="font-semibold text-amber-900 text-sm">
            <i class="bi bi-hourglass-split"></i> Recommended by the DSS, awaiting the Principal's decision
            <span class="font-normal text-amber-700">({{ $awaitingSystemOrigin->count() }})</span>
        </h3>
        <p class="text-xs text-amber-700 mt-1">
            The system has flagged these learners from assessment evidence. The Principal has not yet reviewed
            them.
        </p>
    </div>
    <div class="max-h-64 overflow-auto">
    <table class="w-full text-sm">
        <thead class="bg-amber-100/50 text-amber-800 text-xs uppercase tracking-wide sticky top-0">
            <tr>
                <th class="text-left px-6 py-2">Student</th>
                <th class="text-left px-3 py-2">Subject</th>
                <th class="text-left px-4 py-2">Recommended Type</th>
                <th class="text-left px-4 py-2">Term</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-amber-100">
            @foreach($awaitingSystemOrigin as $iv)
            <tr>
                <td class="px-6 py-2 font-medium text-amber-900 whitespace-nowrap">{{ $iv->student->last_name }}, {{ $iv->student->first_name }}</td>
                <td class="px-3 py-2 text-amber-800 whitespace-nowrap">{{ $iv->subject->name ?? '—' }}</td>
                <td class="px-4 py-2 text-amber-800">{{ $typeLabels[$iv->recommended_type] ?? $iv->recommended_type }}</td>
                <td class="px-4 py-2 text-amber-800 whitespace-nowrap">{{ $iv->grading_period ? 'Term ' . $iv->grading_period : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</div>
@endif

<div class="card overflow-x-auto">
    <div class="px-5 py-4 border-b border-line">
        <h3 class="card-title">Interventions for {{ $section->name }}</h3>
        <p class="text-xs text-gray-500 mt-1">
            These are decisions the Principal already made for your students. You can read them and mark that you've
            seen one — only the Principal moves an intervention's status forward.
        </p>

        {{-- TASK 3 of "terminology, transmutation, and interface cleanup"
             — Subject (scoped to this section, same Subject::forSection()
             lookup the Assessments screen trusts) and Term filters,
             Term defaulting to the currently open one. --}}
        <div class="flex flex-wrap items-end justify-between gap-3 mt-3 pt-3 border-t">
            <form method="GET" id="ivFilterForm" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="form-label">Subject</label>
                    <select name="subject_id" class="form-select-sm min-w-[150px]">
                        <option value="">All subjects</option>
                        @foreach($subjects as $subj)
                            <option value="{{ $subj->id }}" {{ (string) request('subject_id') === (string) $subj->id ? 'selected' : '' }}>{{ $subj->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Term</label>
                    <select name="grading_period" class="form-select-sm min-w-[110px]">
                        <option value="">All terms</option>
                        @foreach([1, 2, 3] as $t)
                            <option value="{{ $t }}" {{ $gradingPeriod == $t ? 'selected' : '' }}>Term {{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                @if($ivFiltersActive)
                    <a href="{{ route('adviser.interventions') }}" class="text-sm text-muted hover:underline pb-1.5">Clear</a>
                @endif
            </form>

            {{-- TASK 4 of "terminology, transmutation, and interface
                 cleanup" — acknowledgement is a receipt, not a decision
                 (see Adviser\InterventionController's docblock), so
                 acknowledging everything currently visible under the
                 active filters is one confirmed action instead of one
                 click per row. The count shown here — and in the confirm
                 popup below — is scoped to the SAME filtered set, and
                 only ever counts rows not already acknowledged. --}}
            @if($unacknowledgedCount > 0)
            <button type="button" onclick="window.showModal('acknowledgeAllModal')"
                    class="btn btn-primary btn-sm shrink-0">
                <i class="bi bi-eye"></i> Acknowledge all ({{ $unacknowledgedCount }})
            </button>
            @endif
            {{-- TASK 3 of "clarity, progress, and visual design pass" — the
                 ONE exception to "delivery is never bulk": a genuine group
                 activity covering several learners at once. Disabled until
                 at least one eligible row is checked (see
                 resources/js/group-delivery.js); opening the dialog is
                 never itself a delivery — Confirm still requires typing a
                 real (20+ character) note describing what was actually
                 done, same as any individual delivery. --}}
            <button type="button" id="groupDeliverTriggerBtn" disabled
                    class="bg-white border border-brand-700 text-brand-700 text-xs px-3 py-1.5 rounded-lg font-medium hover:bg-brand-50 whitespace-nowrap shrink-0 disabled:border-gray-300 disabled:text-gray-400 disabled:hover:bg-white disabled:cursor-not-allowed">
                <i class="bi bi-people"></i> Mark selected as delivered together
            </button>
        </div>

        @include('partials.last-updated')
    </div>

    <p class="text-xs text-gray-500 px-6 pt-3">
        <x-count-label :count="$interventions->count()" noun="intervention" />
        @if($acknowledgedCount > 0)
            <span id="deliveryProgressCounter" class="ml-2 text-gray-400" data-acknowledged-count="{{ $acknowledgedCount }}" data-delivered-count="{{ $deliveredCount }}">
                &middot;
                @if($deliveredCount >= $acknowledgedCount)
                    <span class="text-green-600"><i class="bi bi-check-circle-fill"></i> All {{ $acknowledgedCount }} delivered</span>
                @else
                    {{ $deliveredCount }} of {{ $acknowledgedCount }} delivered
                @endif
            </span>
        @endif
    </p>
    <div class="tbl-scroll mt-2">
    <table class="tbl tbl-sticky">
        <thead>
            <tr>
                <th scope="col">Student</th>
                <th scope="col">Subject</th>
                <th scope="col">Type &amp; Reason</th>
                <th scope="col">Status</th>
                <th scope="col">Decided By</th>
                <th scope="col">Delivery</th>
                <th scope="col">Within-Term Progress</th>
            </tr>
        </thead>
        <tbody>
            @forelse($interventions as $iv)
            <tr class="align-top" data-intervention-row data-student-name="{{ $iv->student->last_name }}, {{ $iv->student->first_name }}">
                <td class="font-medium text-ink whitespace-nowrap">
                    {{ $iv->student->last_name }}, {{ $iv->student->first_name }}
                </td>
                <td class="text-gray-600 whitespace-nowrap">{{ $iv->subject->name ?? '—' }}</td>
                <td class="max-w-xs">
                    <span class="font-medium text-gray-700">{{ $typeLabels[$iv->recommended_type] ?? $iv->recommended_type }}</span>
                    @if($iv->recommendation_reason)
                        <p class="text-xs text-gray-500 mt-1">{{ $iv->recommendation_reason }}</p>
                    @endif
                    @if($iv->principal_notes)
                        <p class="text-xs text-muted mt-1 italic">"{{ $iv->principal_notes }}"</p>
                    @endif
                </td>
                <td>
                    <span class="badge {{ $statusColors[$iv->status] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ $statusLabels[$iv->status] ?? $iv->status }}
                    </span>
                </td>
                <td class="text-xs text-gray-500 whitespace-nowrap">
                    @if($iv->decidedBy)
                        {{ $iv->decidedBy->name }}
                        <span class="block text-gray-400">{{ $iv->decided_at?->format('M d, Y') }}</span>
                    @else
                        <span class="text-gray-300">Not yet decided</span>
                    @endif
                </td>
                <td class="text-xs whitespace-nowrap">
                    {{-- Not yet acknowledged -> Acknowledged -> Delivered.
                         Delivery is only ever offered once acknowledged —
                         see Adviser\InterventionController::markDelivered(). --}}
                    @if($iv->delivered_at)
                        <span class="inline-flex items-center gap-1 text-green-700">
                            <i class="bi bi-check-circle-fill"></i> Delivered
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
                            <p class="text-gray-500 italic mt-1 max-w-xs whitespace-normal">"{{ $iv->delivery_notes }}"</p>
                        @endif
                        {{-- TASK 2 of "add an assessment item by hand" —
                             delivering additional practice and then having
                             to build a whole spreadsheet to record two
                             scores was the exact problem this task exists
                             to fix.
                             Subject/term are nullable on older interventions
                             (see the migration notes) — no link without both. --}}
                        @if($iv->subject_id && $iv->grading_period)
                        <a href="{{ route('adviser.assessments', array_filter([
                                'period'            => $iv->grading_period,
                                'subject_id'        => $iv->subject_id,
                                'add_item'          => 1,
                                'component'         => $iv->focusComponent(),
                                'from_intervention' => 1,
                            ])) }}"
                           class="inline-flex items-center gap-1 text-brand-700 border border-brand-200 rounded px-2 py-1 hover:bg-brand-50 mt-1">
                            <i class="bi bi-plus-circle"></i> Add Assessment Item
                        </a>
                        @endif
                    @elseif($iv->acknowledged_at)
                        <span class="inline-flex items-center gap-1 text-blue-700">
                            <i class="bi bi-eye-fill"></i> Acknowledged
                        </span>
                        <span class="block text-gray-400 mt-0.5 mb-1">{{ $iv->acknowledged_at->format('M d, Y h:i A') }}</span>

                        {{-- TASK 3a of "clarity, progress, and visual
                             design pass" — opts this row into the group-
                             delivery batch; see #groupDeliverForm above.
                             Checking this NEVER delivers anything by
                             itself — only Confirm inside the modal (which
                             always requires its own real note) does. --}}
                        <label class="inline-flex items-center gap-1.5 text-[11px] text-gray-500 mb-1.5 cursor-pointer">
                            <input type="checkbox" form="groupDeliverForm" name="intervention_ids[]" value="{{ $iv->id }}"
                                   data-group-deliver-checkbox
                                   data-student-name="{{ $iv->student->last_name }}, {{ $iv->student->first_name }}"
                                   class="rounded border-gray-300 text-brand-700 focus:ring-brand-400">
                            Include in group delivery
                        </label>

                        {{-- TASK 4 of "UI cleanup and correctness pass" —
                             a native <details> disclosure, not a centered
                             modal: clicking the summary opens the note
                             field IN THIS ROW with no overlay, dimming, or
                             scroll jump, and still works with no JS at all
                             (the form posts and redirects normally). JS
                             (resources/js/intervention-delivery.js) only
                             upgrades the submit to fetch() and adds the
                             cursor-focus/keyboard/chip niceties — never
                             bulk, one note per intervention every time. --}}
                        <details data-deliver-row>
                            <summary class="cursor-pointer select-none list-none inline-flex items-center gap-1 text-brand-700 border border-brand-200 rounded px-2 py-1 hover:bg-brand-50">
                                <i class="bi bi-check2-square"></i> Mark as Delivered
                            </summary>
                            <form method="POST" action="{{ route('adviser.interventions.deliver', $iv->id) }}"
                                  data-deliver-form data-no-loading class="mt-2 w-64 whitespace-normal"
                                  data-add-item-url="{{ ($iv->subject_id && $iv->grading_period) ? route('adviser.assessments', array_filter([
                                        'period'            => $iv->grading_period,
                                        'subject_id'        => $iv->subject_id,
                                        'add_item'          => 1,
                                        'component'         => $iv->focusComponent(),
                                        'from_intervention' => 1,
                                    ])) : '' }}">
                                @csrf
                                <textarea name="delivery_notes" data-deliver-textarea rows="2" required
                                          class="w-full border rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-400"
                                          placeholder="What was done? (required)"></textarea>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($deliveryNoteChips[$iv->recommended_type] ?? $deliveryNoteChips['other'] as $chip)
                                        <button type="button" data-deliver-chip
                                                class="text-[10px] leading-tight px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200">{{ $chip }}</button>
                                    @endforeach
                                </div>
                                <div class="flex justify-end gap-2 mt-2">
                                    <button type="button" data-deliver-cancel class="text-xs text-gray-500 hover:text-gray-700">Cancel</button>
                                    <button type="submit" data-deliver-submit class="btn btn-primary btn-xs">
                                        Confirm Delivered
                                    </button>
                                </div>
                            </form>
                        </details>
                    @else
                        <span class="block text-gray-300 mb-1">Not yet acknowledged</span>
                        <form method="POST" action="{{ route('adviser.interventions.acknowledge', $iv->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1 text-brand-700 border border-brand-200 rounded px-2 py-1 hover:bg-brand-50">
                                <i class="bi bi-eye"></i> Mark as Acknowledged
                            </button>
                        </form>
                    @endif
                </td>
                <td class="text-xs whitespace-nowrap">
                    @include('partials.within-term-progress', ['p' => $iv->withinTermProgress ?? null])
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7">
                    @if($awaitingPrincipalDecision->isNotEmpty())
                        <x-empty-state icon="bi-clipboard2-pulse" message="Nothing to acknowledge or deliver yet."
                            hint="See the group above — nothing moves here until the Principal approves it." />
                    @else
                        <x-empty-state icon="bi-clipboard2-pulse" message="No interventions have been recorded for your students yet."
                            hint="The Principal records interventions from the Students page — nothing to acknowledge until then." />
                    @endif
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    {{-- TASK 3 of "bulk dialog and intervention closure" — same wording
         as the Principal Interventions page, so the two never explain
         this differently. --}}
    @if($interventions->isNotEmpty())
    <p class="text-xs text-gray-500 px-6 py-3 border-t">
        An additional assessment item given during the term is added to the term's total points, not swapped in for
        the score it's meant to improve — that's why a strong result on it moves the affected component by less than
        its own percentage suggests. Within-Term Progress above shows the earned/possible points behind each
        percentage so the arithmetic can be checked by hand. This is ordinary within-term academic support, distinct
        from DepEd's formal Summer Remedial Class (DO 8, s. 2015 / DO 015, s. 2026), which runs after Final Grades
        are computed and produces a separate Remedial Class Mark — that post-term programme is not implemented here.
    </p>
    @endif
</div>

{{-- ACKNOWLEDGE ALL MODAL — TASK 4 of "terminology, transmutation, and
     interface cleanup". Submits to acknowledgeAll(), scoped server-side
     to this adviser's own section AND re-applying the same Subject/Term
     filters currently on the page (via the hidden fields below), so what
     gets stamped always matches what the count here promised. --}}
@if($unacknowledgedCount > 0)
<div id="acknowledgeAllModal" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-md p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Acknowledge All</h3>
            <button type="button" onclick="window.hideModal('acknowledgeAllModal')" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <p class="text-sm text-gray-600 mb-4">
            This will mark <strong>{{ $unacknowledgedCount }}</strong> not-yet-acknowledged intervention{{ $unacknowledgedCount === 1 ? '' : 's' }}
            @if($ivFiltersActive)
                matching the current Subject/Term filters
            @else
                (every one currently listed)
            @endif
            as acknowledged. This only records that you've seen them — it does not mark anything as delivered.
        </p>
        <form method="POST" action="{{ route('adviser.interventions.acknowledge-all') }}">
            @csrf
            <input type="hidden" name="subject_id" value="{{ request('subject_id') }}">
            <input type="hidden" name="grading_period" value="{{ request('grading_period', $gradingPeriod) }}">
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="window.hideModal('acknowledgeAllModal')" class="px-4 py-2 text-sm text-muted">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    Acknowledge {{ $unacknowledgedCount }}
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- GROUP DELIVER MODAL — TASK 3 of "clarity, progress, and visual
     design pass". The textarea and Confirm button below both carry
     form="groupDeliverForm" so they submit the checkboxes checked in
     the table above (see that form's own comment). Populated entirely
     client-side (resources/js/group-delivery.js) from whichever
     checkboxes are currently checked — nothing here is server-rendered
     per-row, since the selection changes as the Adviser clicks. --}}
<div id="groupDeliverModal" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Mark Selected as Delivered Together</h3>
            <button type="button" onclick="window.hideModal('groupDeliverModal')" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <p class="text-sm text-gray-700 mb-3">
            This records <strong>one activity</strong> delivered to all <strong><span id="gdCount">0</span></strong>
            selected learner(s). Describe what was actually done.
        </p>
        <ul id="gdNamesList" class="text-xs text-gray-500 max-h-28 overflow-y-auto list-disc list-inside mb-4 bg-gray-50 rounded-lg p-3"></ul>

        <textarea id="gdNoteText" form="groupDeliverForm" name="delivery_notes" rows="3" minlength="20" required
                  placeholder="Describe the shared activity — at least 20 characters (e.g. &quot;Ran a group re-teaching session on quadratic equations for these students.&quot;)"
                  class="form-input"></textarea>
        <p class="text-xs text-muted mt-1">Minimum 20 characters, not counting leading/trailing spaces.</p>

        <div class="flex justify-end gap-3 pt-4">
            <button type="button" onclick="window.hideModal('groupDeliverModal')" class="px-4 py-2 text-sm text-muted">Cancel</button>
            <button type="submit" id="gdConfirmBtn" form="groupDeliverForm" disabled
                    class="btn btn-primary disabled:bg-gray-300 disabled:cursor-not-allowed">
                Confirm — Mark Delivered
            </button>
        </div>
    </div>
</div>

@endif

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        window.bindModalOverlayClose('acknowledgeAllModal');
        window.bindModalOverlayClose('groupDeliverModal');
        initAutoSubmitFilter('ivFilterForm');
        window.initInterventionDelivery('[data-deliver-row]');
        window.initGroupDelivery();
    });
</script>
@endpush
