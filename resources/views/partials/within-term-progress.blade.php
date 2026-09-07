{{--
    TASK 3 of "close the delivery loop" — compact before -> after display
    for ProgressMonitoringService::compareWithinTerm(). Shared by the
    adviser and principal Interventions pages so the two can never say
    something different about the same intervention.

    Reports the two numbers and lets the reader judge — never claims the
    intervention caused the change (see the service's own docblock).

    TASK 3 of "bulk dialog and intervention closure": shows the earned/
    possible POINTS behind each percentage, not just the percentage — a
    remedial item is added to the term's existing denominator, not
    swapped in for the score it targets, so a strong remedial result
    moves the percentage by less than its own score suggests. Showing
    the raw points (e.g. 8/20 -> 46/60) makes that arithmetic checkable
    by hand instead of something to take on faith.

    TASK 2 of "status clarity and progress consistency": the compared
    component is always the one the intervention's recorded reason
    actually named (never a recomputed "weakest now" guess) — a row with
    no recoverable component shows "focus component not recorded" rather
    than silently substituting a different one (see $p['not_recorded']).
    Also flags when a DIFFERENT component picked up new evidence since
    delivery — the case where an intervention looks ineffective only
    because it was never actually measured.

    @param array|null $p the compareWithinTerm() result for this row
--}}
@php
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    $fmtPts = fn($v) => $v === null ? '—' : (floor((float) $v) == (float) $v ? number_format((float) $v, 0) : number_format((float) $v, 1));
@endphp
@if(!$p)
    <span class="text-gray-300">No within-term comparison yet</span>
@elseif($p['not_recorded'] ?? false)
    <div class="text-gray-500 bg-gray-50 rounded-md px-2 py-1.5 max-w-[260px] whitespace-normal">
        <span class="italic">Focus component not recorded</span>
        <span class="block text-xs text-gray-400 mt-0.5">This intervention's recorded reason didn't name a specific component, so there's nothing to anchor a before/after comparison to.</span>
    </div>
@else
    @php $label = $componentLabels[$p['component']] ?? $p['component']; @endphp
    <div class="text-gray-600 bg-gray-50 rounded-md px-2 py-1.5 max-w-[260px] whitespace-normal">
        <span class="font-medium text-gray-700 block">{{ $label }}</span>
        <span class="block mt-0.5">Before: {{ $p['before_percentage'] !== null ? $fmtPts($p['before_earned']) . '/' . $fmtPts($p['before_max']) . ' (' . number_format($p['before_percentage'], 1) . '%)' : '—' }}, {{ $p['before_item_count'] }} item{{ $p['before_item_count'] === 1 ? '' : 's' }}</span>
        <span class="block">Now: {{ $p['after_percentage'] !== null ? $fmtPts($p['after_earned']) . '/' . $fmtPts($p['after_max']) . ' (' . number_format($p['after_percentage'], 1) . '%)' : '—' }}, {{ $p['after_item_count'] }} item{{ $p['after_item_count'] === 1 ? '' : 's' }}</span>
        @if($p['has_enough_evidence'] && $p['change'] !== null)
            <span class="block mt-1 font-medium {{ $p['change'] > 0 ? 'text-green-600' : ($p['change'] < 0 ? 'text-red-500' : 'text-gray-500') }}">Change: {{ $p['change'] >= 0 ? '+' : '' }}{{ number_format($p['change'], 1) }} points</span>
            <span class="block text-gray-400">({{ $p['new_item_count'] }} new item{{ $p['new_item_count'] === 1 ? '' : 's' }} since delivery)</span>
        @else
            <span class="block mt-1 text-gray-400 italic">Not enough evidence yet — {{ $p['new_item_count'] }} new item{{ $p['new_item_count'] === 1 ? '' : 's' }} recorded since delivery</span>
        @endif
        @if(!empty($p['other_components_with_new_evidence']))
            @php
                $otherLabels = collect($p['other_components_with_new_evidence'])
                    ->map(fn($key) => $componentLabels[$key] ?? $key)
                    ->implode(', ');
            @endphp
            <span class="block mt-1 pt-1 border-t border-gray-200 text-amber-700 text-[11px]">
                <i class="bi bi-exclamation-triangle"></i>
                New evidence was also added in {{ $otherLabels }} since delivery — this intervention is about {{ $label }}.
            </span>
        @endif
    </div>
@endif
