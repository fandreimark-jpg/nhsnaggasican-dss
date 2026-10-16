@props([
    // An App\Monitoring\MonitoringStatus (MonitoringClassificationService),
    // or null when no classification was computed for this learner.
    'status' => null,
    // compact = the badge, the open-concern count and the concern types
    // only — for rosters. The evidence lines stay on detail/report pages.
    'compact' => false,
])
{{-- The learner's CURRENT OVERALL MONITORING — derived from open academic
     concerns. Not academic performance (On Track / Below Target), not a
     severity and not a prediction. --}}
@if($status === null)
    <span class="text-xs text-gray-300">—</span>
@elseif($status->isForReview())
    <span {{ $attributes->merge(['class' => 'inline-block']) }} data-monitoring-status="for-review" data-open-concerns="{{ $status->openCount() }}">
        <x-ui.status-badge tone="warning" icon="bi-flag" :label="\App\Monitoring\MonitoringStatus::FOR_REVIEW . ($compact ? ' · ' . $status->openCount() . ' ' . ($status->openCount() === 1 ? 'concern' : 'concerns') : '')" />
        @if($compact)
        <span class="block text-[11px] text-gray-600 mt-0.5 leading-snug" data-concern-types>{{ implode(' · ', array_keys($status->reasons)) }}</span>
        @else
        <span class="block text-[11px] text-gray-600 mt-0.5 leading-snug">
            @if($status->openCount() > 1){{ $status->openCount() }} Open Concerns: @endif{{ $status->reasonsLine() }}
        </span>
        {{-- WHY, in words: one line per open concern, from its stored evidence. --}}
        @foreach($status->details as $detail)
            <span class="block text-[10px] text-gray-500 leading-snug" data-monitoring-evidence>{{ $detail }}</span>
        @endforeach
        @endif
    </span>
@else
    <span {{ $attributes->merge(['class' => 'inline-block']) }} data-monitoring-status="none">
        <x-ui.status-badge tone="gray" :label="\App\Monitoring\MonitoringStatus::NO_CURRENT_CONCERN" />
    </span>
@endif
