{{-- The schedule of one intervention, set by its responsible staff (times in Manila). --}}
@php $fmt = fn($t) => \App\Services\InterventionScheduleService::fmt($t); @endphp
<dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm" data-schedule-summary>
    <div><dt class="text-muted">Activity</dt><dd class="font-medium text-ink">{{ $intervention->title ?: \App\Models\Intervention::typeLabel($intervention->recommended_type) }}</dd></div>
    <div><dt class="text-muted">Schedule status</dt><dd>@include('partials.intervention-lifecycle-badge', ['iv' => $intervention])</dd></div>
    <div><dt class="text-muted">Responsible staff</dt><dd>{{ $intervention->responsibleUser?->name ?? 'Not assigned' }}</dd></div>
    <div><dt class="text-muted">Start (Manila time)</dt><dd data-starts-at>{{ $intervention->starts_at ? $fmt($intervention->starts_at) : 'Schedule not set' }}</dd></div>
    <div><dt class="text-muted">Deadline (Manila time)</dt><dd data-deadline-at>{{ $intervention->deadline_at ? $fmt($intervention->deadline_at) : 'Schedule not set' }}</dd></div>
    @if($intervention->completed_at || $intervention->delivered_at)
        <div><dt class="text-muted">{{ $intervention->isScheduled() ? 'Completed (activity done)' : 'Delivered (adviser record — not scheduled)' }}</dt><dd>{{ $intervention->completed_at ? $fmt($intervention->completed_at) : 'actual time not recorded' }}
            <span class="block text-xs text-muted">recorded {{ $fmt($intervention->delivered_at) }}{{ $intervention->deliveredBy ? ' by ' . $intervention->deliveredBy->name : '' }}</span></dd></div>
    @endif
    @if($intervention->status !== 'expired' && $intervention->scheduleState() === 'expired')
        {{-- Effective expiry (server clock) before the background job has recorded it. --}}
        <div data-effective-expiry><dt class="text-muted">Expired</dt><dd>the deadline has passed
            <span class="block text-xs text-muted">Shown from the server clock; the system records the expiry in the history on its next scheduled run.</span></dd></div>
    @endif
    @if($intervention->expired_at)
        <div><dt class="text-muted">{{ $intervention->status === 'expired' ? 'Expired' : 'Previously expired (reopened or completion approved)' }}</dt><dd>deadline {{ $fmt($intervention->expired_at) }}<span class="block text-xs text-muted">recorded by the system {{ $fmt($intervention->expiry_processed_at) }}</span></dd></div>
    @endif
    @if($intervention->cancelled_at)
        <div><dt class="text-muted">Cancelled</dt><dd>{{ $fmt($intervention->cancelled_at) }}{{ $intervention->cancelledBy ? ' by ' . $intervention->cancelledBy->name : '' }}
            <span class="block text-xs text-muted">Reason: {{ $intervention->cancellation_reason }}</span></dd></div>
    @endif
    @if($intervention->instructions)
        <div class="sm:col-span-2"><dt class="text-muted">Instructions</dt><dd class="whitespace-pre-line">{{ $intervention->instructions }}</dd></div>
    @endif
    @if($intervention->completion_criteria)
        <div class="sm:col-span-2"><dt class="text-muted">Completion criteria</dt><dd class="whitespace-pre-line">{{ $intervention->completion_criteria }}</dd></div>
    @endif
    @if($intervention->delivery_notes && ($intervention->completed_at || $intervention->delivered_at))
        <div class="sm:col-span-2"><dt class="text-muted">Completion notes / evidence</dt><dd class="whitespace-pre-line">{{ $intervention->delivery_notes }}</dd></div>
    @endif
</dl>
