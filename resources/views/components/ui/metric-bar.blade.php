@props(['value' => null, 'target' => 75, 'suffix' => '%', 'decimals' => 1])
{{-- A value plus a small horizontal bar underneath (Subject Analysis
     component columns). Colour follows the existing 75-target rule:
     >= target green, within 5 below amber, further below red. The value
     itself is the backend's; nothing is recomputed here. --}}
@if($value === null)
    <span class="text-gray-300">—</span>
@else
    @php
        $pct = max(0, min(100, (float) $value));
        $tone = $pct >= $target ? 'success' : ($pct >= $target - 5 ? 'warning' : 'danger');
    @endphp
    <div class="min-w-[6rem]">
        <span class="text-sm font-semibold text-ink tabular-nums">{{ number_format($value, $decimals) }}{{ $suffix }}</span>
        <x-ui.progress-bar :value="$pct" :tone="$tone" size="sm" class="mt-1" :label="number_format($value, $decimals) . $suffix" />
    </div>
@endif
