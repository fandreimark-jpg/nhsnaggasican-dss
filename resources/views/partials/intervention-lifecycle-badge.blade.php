{{-- Schedule status (Intervention::scheduleState()). Always a text label; the colour only repeats it. --}}
@php
    $lc = $iv->scheduleState($now ?? null);
    $cls = ['awaiting_decision' => 'badge-warning', 'unscheduled' => 'badge-outline', 'scheduled' => 'badge-info', 'ongoing' => 'badge-brand',
        'completed' => 'badge-success', 'expired' => 'badge-danger', 'cancelled' => 'badge-gray'][$lc];
@endphp
<span class="badge {{ $cls }}" data-lifecycle="{{ $lc }}">{{ \App\Models\Intervention::LIFECYCLE_LABELS[$lc] }}</span>
