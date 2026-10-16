@extends('layouts.app')

@section('title', 'Intervention')
@section('subtitle', ($intervention->student ? $intervention->student->last_name . ', ' . $intervention->student->first_name : 'Learner') . ' — ' . ($intervention->subject?->name ?? 'No subject') . ($intervention->grading_period ? ' — Term ' . $intervention->grading_period : ''))

@section('content')
<p class="mb-3"><a href="{{ route('adviser.interventions') }}" class="text-sm text-brand-700 hover:underline"><i class="bi bi-arrow-left" aria-hidden="true"></i> Interventions</a></p>

@php
    $decisionLabels = ['recommended' => 'Not yet approved', 'in_review' => 'In Review', 'approved' => 'Approved', 'in_progress' => 'In Progress',
        'completed' => 'Completed (closed by the Principal)', 'monitoring' => 'Monitoring', 'expired' => 'Expired — Not completed', 'cancelled' => 'Cancelled'];
@endphp
<div class="card mb-4" data-intervention-info>
    <div class="card-header"><h2 class="card-title">Intervention</h2>
        <p class="card-subtitle">The Principal's decision, the reason it was recorded, and the learner's academic concern (which follows the scores and grades only).</p></div>
    <div class="card-body text-sm grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
        <div><span class="text-muted">Learner:</span> <span class="font-medium text-ink">{{ $intervention->student?->last_name }}, {{ $intervention->student?->first_name }}</span>
            <span class="block text-xs text-muted">{{ $intervention->section ? 'Grade ' . $intervention->section->grade_level . ' — ' . $intervention->section->name : '' }} ({{ $intervention->school_year }})</span></div>
        <div><span class="text-muted">Type:</span> {{ \App\Models\Intervention::typeLabel($intervention->recommended_type) }}
            @if($intervention->recommendation_reason)<span class="block text-xs text-muted">{{ $intervention->recommendation_reason }}</span>@endif</div>
        <div><span class="text-muted">Principal decision:</span> <span class="font-medium" data-decision-status>{{ $decisionLabels[$intervention->status] ?? $intervention->status }}</span>
            @if($intervention->decidedBy)<span class="block text-xs text-muted">{{ $intervention->decidedBy->name }}, {{ \App\Services\InterventionScheduleService::fmt($intervention->decided_at) }}</span>@endif
            @if($intervention->principal_notes)<span class="block text-xs text-muted italic">"{{ $intervention->principal_notes }}"</span>@endif</div>
        <div><span class="text-muted">Adviser acknowledgement:</span> {{ $intervention->acknowledged_at ? 'Acknowledged ' . \App\Services\InterventionScheduleService::fmt($intervention->acknowledged_at) . ($intervention->acknowledgedBy ? ' by ' . $intervention->acknowledgedBy->name : '') : 'Not yet acknowledged' }}</div>
        <div><span class="text-muted">Academic concern:</span>
            @if($intervention->academicConcern) {{ $intervention->academicConcern->status === 'open' ? 'Still open (For Review)' : 'Resolved by the evidence' }}
            @else none linked @endif</div>
    </div>
</div>

@foreach($termNotices as $notice)
    <div class="alert alert-warning mb-3 text-sm" data-term-notice>{{ $notice }}</div>
@endforeach

<div class="card mb-4">
    <div class="card-header"><h2 class="card-title">Schedule</h2>
        <p class="card-subtitle">Manila time. The server clock decides: after the deadline, completion can no longer be recorded normally. Recording completion does not change any score, grade or academic concern.</p></div>
    <div class="card-body">@include('partials.intervention-schedule-summary')</div>
</div>

@php
    // Each form has its own error bag and a _form marker, so one rejected
    // submission shows its errors (and keeps its input) once, in its own form.
    $actions = $availability['actions'];
    $o = fn(string $form, string $key, $default = null) => old('_form') === $form ? old($key, $default) : $default;
    $dt = fn($t) => $t ? $t->format('Y-m-d\TH:i') : null;
    $fmtT = fn($t) => \App\Services\InterventionScheduleService::fmt($t);
@endphp

@php
    $shownForms = array_filter(['schedule' => (bool) array_intersect(['set', 'reschedule'], $actions), 'extend' => in_array('extend', $actions, true),
        'complete' => in_array('complete', $actions, true), 'reopen' => in_array('reopen', $actions, true), 'correction' => in_array('correction', $actions, true)]);
@endphp
@if(session('error_inline') && !isset($shownForms[old('_form')]))
    {{-- The refused action's form is no longer offered (the state changed): say why here. --}}
    <div class="alert alert-danger mb-4 text-sm" role="alert" data-form-error>{{ session('error') }}</div>
@endif
@if($availability['blocked'])
    <div class="alert alert-info mb-4 text-sm" data-blocked-reason>{{ $availability['blocked'] }}</div>
@endif

@if(array_intersect(['set', 'reschedule'], $actions))
@php $first = in_array('set', $actions, true); $b = $errors->getBag('schedule'); @endphp
<form method="POST" action="{{ route('adviser.interventions.schedule', $intervention) }}" class="card mb-4" aria-labelledby="schedTitle" data-form="schedule">
    @csrf
    <input type="hidden" name="_form" value="schedule">
    <input type="hidden" name="schedule_version" value="{{ $intervention->schedule_version }}">
    <div class="card-header"><h2 id="schedTitle" class="card-title">{{ $first ? 'Set schedule' : 'Reschedule' }}</h2>
        <p class="card-subtitle">You are the responsible adviser: the schedule takes effect when you save it (no approval step). The start cannot be in the past.@unless($first) A reason is required; the change is kept in the history.@endunless</p></div>
    @include('partials.intervention-form-error', ['form' => 'schedule'])
    <div class="card-body grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2"><label for="scTitle" class="form-label">Activity</label>
            <input id="scTitle" name="title" class="form-input w-full" maxlength="255" value="{{ $o('schedule', 'title', $intervention->title ?: \App\Models\Intervention::typeLabel($intervention->recommended_type)) }}">
            @if($b->has('title'))<p class="text-xs text-red-700 mt-1">{{ $b->first('title') }}</p>@endif</div>
        <div><label for="scStart" class="form-label">Start (date and time)</label>
            <input id="scStart" type="datetime-local" name="starts_at" class="form-input w-full" required value="{{ $o('schedule', 'starts_at', $dt($intervention->starts_at)) }}">
            @if($b->has('starts_at'))<p class="text-xs text-red-700 mt-1">{{ $b->first('starts_at') }}</p>@endif</div>
        <div><label for="scDeadline" class="form-label">Deadline (date and time)</label>
            <input id="scDeadline" type="datetime-local" name="deadline_at" class="form-input w-full" required value="{{ $o('schedule', 'deadline_at', $dt($intervention->deadline_at)) }}">
            @if($b->has('deadline_at'))<p class="text-xs text-red-700 mt-1">{{ $b->first('deadline_at') }}</p>@endif</div>
        <div class="sm:col-span-2"><label for="scInstr" class="form-label">Instructions (optional)</label>
            <textarea id="scInstr" name="instructions" rows="2" class="form-input w-full" maxlength="5000">{{ $o('schedule', 'instructions', $intervention->instructions) }}</textarea></div>
        <div class="sm:col-span-2"><label for="scCrit" class="form-label">Completion criteria (optional)</label>
            <textarea id="scCrit" name="completion_criteria" rows="2" class="form-input w-full" maxlength="5000">{{ $o('schedule', 'completion_criteria', $intervention->completion_criteria) }}</textarea></div>
        @unless($first)
        <div class="sm:col-span-2"><label for="scReason" class="form-label">Reason for rescheduling</label>
            <textarea id="scReason" name="reason" rows="2" class="form-input w-full" required minlength="5" maxlength="2000">{{ $o('schedule', 'reason') }}</textarea>
            @if($b->has('reason'))<p class="text-xs text-red-700 mt-1">{{ $b->first('reason') }}</p>@endif</div>
        @endunless
    </div>
    <div class="card-body pt-0 flex justify-end"><button class="btn btn-primary">{{ $first ? 'Set schedule' : 'Save new schedule' }}</button></div>
</form>
@endif

@if(in_array('extend', $actions, true))
@php $b = $errors->getBag('extend'); @endphp
<form method="POST" action="{{ route('adviser.interventions.extend', $intervention) }}" class="card mb-4" aria-labelledby="extTitle" data-form="extend">
    @csrf
    <input type="hidden" name="_form" value="extend">
    <input type="hidden" name="schedule_version" value="{{ $intervention->schedule_version }}">
    <div class="card-header"><h2 id="extTitle" class="card-title">Extend deadline</h2>
        <p class="card-subtitle">Current deadline: {{ $fmtT($intervention->deadline_at) }}. The new deadline must be later than it and than now; the start ({{ $fmtT($intervention->starts_at) }}) stays. Takes effect when saved.</p></div>
    @include('partials.intervention-form-error', ['form' => 'extend'])
    <div class="card-body grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label for="exDeadline" class="form-label">New deadline (date and time)</label>
            <input id="exDeadline" type="datetime-local" name="deadline_at" class="form-input w-full" required value="{{ $o('extend', 'deadline_at') }}">
            @if($b->has('deadline_at'))<p class="text-xs text-red-700 mt-1">{{ $b->first('deadline_at') }}</p>@endif</div>
        <div class="sm:col-span-2"><label for="exReason" class="form-label">Reason for the extension</label>
            <textarea id="exReason" name="reason" rows="2" class="form-input w-full" required minlength="5" maxlength="2000">{{ $o('extend', 'reason') }}</textarea>
            @if($b->has('reason'))<p class="text-xs text-red-700 mt-1">{{ $b->first('reason') }}</p>@endif</div>
    </div>
    <div class="card-body pt-0 flex justify-end"><button class="btn btn-secondary">Extend deadline</button></div>
</form>
@endif

@if(in_array('complete', $actions, true))
@php $b = $errors->getBag('complete'); @endphp
<form method="POST" action="{{ route('adviser.interventions.complete', $intervention) }}" class="card mb-4" aria-labelledby="compTitle" data-form="complete">
    @csrf
    <input type="hidden" name="_form" value="complete">
    <div class="card-header"><h2 id="compTitle" class="card-title">Record completion</h2>
        <p class="card-subtitle">You are the responsible adviser. Recording completion records the activity only — scores stay with the subject teacher's Class Record. Deadline: {{ $fmtT($intervention->deadline_at) }}.</p></div>
    @include('partials.intervention-form-error', ['form' => 'complete'])
    <div class="card-body grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label for="compAt" class="form-label">Completed on (date and time)</label>
            <input id="compAt" type="datetime-local" name="completed_at" class="form-input w-full" required value="{{ $o('complete', 'completed_at', now()->format('Y-m-d\TH:i')) }}">
            @if($b->has('completed_at'))<p class="text-xs text-red-700 mt-1">{{ $b->first('completed_at') }}</p>@endif</div>
        <div class="sm:col-span-2"><label for="compNotes" class="form-label">What was done (notes / evidence reference)</label>
            <textarea id="compNotes" name="notes" rows="3" class="form-input w-full" required minlength="5" maxlength="2000">{{ $o('complete', 'notes') }}</textarea>
            @if($b->has('notes'))<p class="text-xs text-red-700 mt-1">{{ $b->first('notes') }}</p>@endif</div>
    </div>
    <div class="card-body pt-0 flex justify-end"><button class="btn btn-primary">Record completion</button></div>
</form>
@elseif($state === 'scheduled' && !$availability['blocked'])
    <div class="alert alert-info mb-4 text-sm" data-completion-not-yet>Completion can be recorded once the start ({{ $fmtT($intervention->starts_at) }}) is reached.</div>
@endif

@if(in_array('reopen', $actions, true))
@php $b = $errors->getBag('reopen'); @endphp
<form method="POST" action="{{ route('adviser.interventions.reopen', $intervention) }}" class="card mb-4" aria-labelledby="reoTitle" data-form="reopen">
    @csrf
    <input type="hidden" name="_form" value="reopen">
    <input type="hidden" name="schedule_version" value="{{ $intervention->schedule_version }}">
    <div class="card-header"><h2 id="reoTitle" class="card-title">Reopen and set schedule</h2>
        <p class="card-subtitle">The deadline {{ $fmtT($intervention->deadline_at) }} passed with no completion recorded. Set a new start (not in the past) and a deadline later than that one and than now. The expiry stays in the history.</p></div>
    @include('partials.intervention-form-error', ['form' => 'reopen'])
    <div class="card-body grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label for="reStart" class="form-label">New start (date and time)</label>
            <input id="reStart" type="datetime-local" name="starts_at" class="form-input w-full" required value="{{ $o('reopen', 'starts_at') }}">
            @if($b->has('starts_at'))<p class="text-xs text-red-700 mt-1">{{ $b->first('starts_at') }}</p>@endif</div>
        <div><label for="reDeadline" class="form-label">New deadline (date and time)</label>
            <input id="reDeadline" type="datetime-local" name="deadline_at" class="form-input w-full" required value="{{ $o('reopen', 'deadline_at') }}">
            @if($b->has('deadline_at'))<p class="text-xs text-red-700 mt-1">{{ $b->first('deadline_at') }}</p>@endif</div>
        <div class="sm:col-span-2"><label for="reReason" class="form-label">Reason for reopening</label>
            <textarea id="reReason" name="reason" rows="2" class="form-input w-full" required minlength="5" maxlength="2000">{{ $o('reopen', 'reason') }}</textarea>
            @if($b->has('reason'))<p class="text-xs text-red-700 mt-1">{{ $b->first('reason') }}</p>@endif</div>
    </div>
    <div class="card-body pt-0 flex justify-end"><button class="btn btn-primary">Reopen</button></div>
</form>
@endif

@if(in_array('correction', $actions, true))
@php $b = $errors->getBag('correction'); @endphp
<form method="POST" action="{{ route('adviser.interventions.requests', $intervention) }}" class="card mb-4" aria-labelledby="lateTitle" data-form="correction">
    @csrf
    <input type="hidden" name="_form" value="correction">
    <input type="hidden" name="type" value="late_completion">
    <div class="card-header"><h2 id="lateTitle" class="card-title">Completed on time but not recorded</h2>
        <p class="card-subtitle">Only if the activity was actually done by the deadline ({{ $fmtT($intervention->expired_at ?? $intervention->deadline_at) }}). The Principal reviews this correction; until then it stays Expired. Work still to be done needs "Reopen and set schedule" instead.</p></div>
    @include('partials.intervention-form-error', ['form' => 'correction'])
    <div class="card-body grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label for="lateAt" class="form-label">Actually completed on (date and time)</label>
            <input id="lateAt" type="datetime-local" name="actual_completed_at" class="form-input w-full" required value="{{ $o('correction', 'actual_completed_at') }}">
            @if($b->has('actual_completed_at'))<p class="text-xs text-red-700 mt-1">{{ $b->first('actual_completed_at') }}</p>@endif</div>
        <div class="sm:col-span-2"><label for="lateReason" class="form-label">Explanation (why it was recorded late)</label>
            <textarea id="lateReason" name="reason" rows="2" class="form-input w-full" required minlength="5" maxlength="2000">{{ $o('correction', 'reason') }}</textarea>
            @if($b->has('reason'))<p class="text-xs text-red-700 mt-1">{{ $b->first('reason') }}</p>@endif</div>
        <div class="sm:col-span-2"><label for="lateEvidence" class="form-label">Evidence available (e.g. attendance sheet, output kept on file)</label>
            <textarea id="lateEvidence" name="evidence_notes" rows="2" class="form-input w-full" maxlength="2000">{{ $o('correction', 'evidence_notes') }}</textarea></div>
    </div>
    <div class="card-body pt-0 flex justify-end"><button class="btn btn-secondary">Send for review</button></div>
</form>
@endif

@include('partials.intervention-history')
@endsection
