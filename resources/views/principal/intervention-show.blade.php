@extends('layouts.app')

@section('title', 'Intervention #' . $intervention->id)
@section('subtitle', ($intervention->student ? $intervention->student->last_name . ', ' . $intervention->student->first_name : 'Learner') . ' — ' . ($intervention->subject?->name ?? 'No subject') . ($intervention->grading_period ? ' — Term ' . $intervention->grading_period : ''))

@section('content')
@php
    $decisionLabels = ['recommended' => 'Not yet approved', 'in_review' => 'In Review', 'approved' => 'Approved', 'in_progress' => 'In Progress',
        'completed' => 'Completed (closed)', 'monitoring' => 'Monitoring', 'expired' => 'Expired — Not completed', 'cancelled' => 'Cancelled'];
    // "Completed" closes the intervention: for a scheduled one it needs a
    // recorded completion first (the server refuses it too).
    $completionMissing = $intervention->isScheduled() && !$intervention->completed_at;
@endphp
<p class="mb-3"><a href="{{ route('principal.interventions') }}" class="text-sm text-brand-700 hover:underline"><i class="bi bi-arrow-left" aria-hidden="true"></i> All interventions</a></p>

<div class="card mb-4">
    <div class="card-header"><h2 class="card-title">Learner and concern</h2></div>
    <div class="card-body text-sm grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
        <div><span class="text-muted">Learner:</span> <span class="font-medium text-ink">{{ $intervention->student?->last_name }}, {{ $intervention->student?->first_name }}</span>
            @if($intervention->student)<span class="text-xs text-muted">LRN {{ $intervention->student->lrn }}</span>@endif</div>
        <div><span class="text-muted">Section:</span> {{ $intervention->section ? 'Grade ' . $intervention->section->grade_level . ' — ' . $intervention->section->name : '—' }} ({{ $intervention->school_year }})</div>
        <div><span class="text-muted">Type:</span> {{ \App\Models\Intervention::typeLabel($intervention->recommended_type) }}
            @if($intervention->recommendation_reason)<span class="block text-xs text-muted">{{ $intervention->recommendation_reason }}</span>@endif</div>
        <div><span class="text-muted">Your decision:</span> <span class="font-medium" data-decision-status>{{ $decisionLabels[$intervention->status] ?? $intervention->status }}</span>
            @if($intervention->decidedBy)<span class="block text-xs text-muted">{{ $intervention->decidedBy->name }}, {{ \App\Services\InterventionScheduleService::fmt($intervention->decided_at) }}</span>@endif
            @if($intervention->principal_notes)<span class="block text-xs text-muted italic">"{{ $intervention->principal_notes }}"</span>@endif</div>
        <div><span class="text-muted">Adviser acknowledgement:</span> {{ $intervention->acknowledged_at ? 'Acknowledged ' . \App\Services\InterventionScheduleService::fmt($intervention->acknowledged_at) : 'Not yet acknowledged' }}</div>
        <div><span class="text-muted">Linked academic concern:</span>
            @if($intervention->academicConcern)
                #{{ $intervention->academicConcern->id }} — {{ $intervention->academicConcern->status === 'open' ? 'still open' : 'resolved' }}
                <span class="block text-xs text-muted">The concern follows the academic evidence only: completing, expiring or cancelling this intervention never resolves it.</span>
            @else none @endif</div>
    </div>
</div>

@unless(in_array($intervention->status, ['expired', 'cancelled'], true))
<form method="POST" action="{{ route('principal.interventions.update', $intervention) }}" class="card mb-4" aria-labelledby="decTitle">
    @csrf
    @method('PUT')
    <input type="hidden" name="return_to" value="show">
    <div class="card-header"><h2 id="decTitle" class="card-title">Decision</h2>
        <p class="card-subtitle">Your decision on this intervention. Recording completion (by the responsible staff) does not change it; you close it here after reviewing the outcome.</p></div>
    <div class="card-body grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label for="decStatus" class="form-label">Status</label>
            <select id="decStatus" name="status" class="form-select w-full">
                @foreach(\App\Models\Intervention::EDITABLE_STATUSES as $st)
                    <option value="{{ $st }}" @selected($intervention->status === $st) @disabled($st === 'completed' && $completionMissing && $intervention->status !== 'completed')>{{ $decisionLabels[$st] ?? $st }}{{ $st === 'completed' && $completionMissing && $intervention->status !== 'completed' ? ' — needs a recorded completion' : '' }}</option>
                @endforeach
            </select></div>
        <div><label for="decNotes" class="form-label">Notes (optional)</label>
            <input id="decNotes" name="principal_notes" class="form-input w-full" value="{{ $intervention->principal_notes }}"></div>
    </div>
    <div class="card-body pt-0 flex justify-end"><button class="btn btn-secondary">Save decision</button></div>
</form>
@endunless

@foreach($termNotices as $notice)
    <div class="alert alert-warning mb-3 text-sm" data-term-notice>{{ $notice }}</div>
@endforeach
@php [$adviser, $adviserProblem] = $sectionAdviser; $actionable = !in_array($state, ['completed', 'cancelled'], true); @endphp
@if($actionable && $adviserProblem)
    <div class="alert alert-danger mb-3 text-sm" data-adviser-problem>{{ $adviserProblem }} Until then no one can set the schedule or record completion.</div>
@elseif($responsibleEligible === false && $actionable)
    <div class="alert alert-danger mb-3 text-sm" data-responsible-ineligible>{{ $intervention->responsibleUser?->name }} is no longer this section's active adviser and cannot manage it. Transfer it to the current section adviser below; the history is kept.</div>
@endif

<div class="card mb-4">
    <div class="card-header"><h2 class="card-title">Schedule</h2><p class="card-subtitle">All times are Manila time (Asia/Manila). Expiry happens when the server time passes the deadline; completion at the deadline is on time.</p></div>
    <div class="card-body">@include('partials.intervention-schedule-summary')</div>
</div>

@if($state === 'awaiting_decision')
    <div class="alert alert-info mb-4 text-sm" data-blocked-reason>Not approved yet. Approve it (Decision above, or the Interventions list); the section adviser then sets the schedule. An unapproved recommendation is never scheduled or expired.</div>
@endif
@if($actionable)
<div class="card mb-4" data-responsible-card>
    <div class="card-header"><h2 class="card-title">Responsible adviser</h2>
        <p class="card-subtitle">Assigned automatically: the adviser of the learner's section ({{ $intervention->section?->name ?? 'no section' }}, {{ $intervention->school_year }}). They set, reschedule, extend and reopen the schedule and record completion — no approval step. To change who it is, change the section's adviser in Admin › Sections.</p></div>
    <div class="card-body text-sm flex flex-wrap items-center justify-between gap-3">
        <div><span class="text-muted">Responsible:</span> <span class="font-medium text-ink" data-responsible-name>{{ $intervention->responsibleUser?->name ?? 'Not assigned' }}</span>
            <span class="block text-xs text-muted">Section adviser now: {{ $adviser?->name ?? 'none valid' }}</span></div>
        @if($adviser && (int) $intervention->responsible_user_id !== $adviser->id)
            <form method="POST" action="{{ route('principal.interventions.responsible', $intervention) }}" data-form="responsible">
                @csrf
                <input type="hidden" name="schedule_version" value="{{ $intervention->schedule_version }}">
                <button class="btn btn-primary btn-sm">Transfer to {{ $adviser->name }}</button>
            </form>
        @endif
    </div>
</div>
@if(!$intervention->deadline_at && $state !== 'awaiting_decision')
    <div class="alert alert-info mb-4 text-sm" data-schedule-owner>{{ $intervention->responsibleUser ? 'Waiting for ' . $intervention->responsibleUser->name . ' to set the schedule.' : 'No responsible adviser yet, so no one can set the schedule.' }}</div>
@endif
@endif

@if(!in_array($state, ['completed', 'cancelled'], true))
@php $b = $errors->getBag('cancel'); @endphp
<form method="POST" action="{{ route('principal.interventions.cancel', $intervention) }}" class="card mb-4" aria-labelledby="cancelTitle" data-form="cancel">
    @csrf
    <div class="card-header"><h2 id="cancelTitle" class="card-title">Cancel intervention</h2>
        <p class="card-subtitle">Keeps the record, its history and any linked academic concern. A reason is required.</p></div>
    <div class="card-body"><label for="cancelReason" class="form-label">Reason</label>
        <textarea id="cancelReason" name="reason" rows="2" class="form-input w-full" required minlength="5" maxlength="2000"></textarea>
        @if($b->has('reason'))<p class="text-xs text-red-700 mt-1">{{ $b->first('reason') }}</p>@endif</div>
    <div class="card-body pt-0 flex justify-end"><button class="btn btn-outline">Cancel intervention</button></div>
</form>
@endif

@include('partials.intervention-history', ['decideRoute' => 'principal.intervention-requests.decide'])
@endsection
