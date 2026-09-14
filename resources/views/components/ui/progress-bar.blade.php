@props([
    'value' => 0,          // 0-100, already computed by the backend/view — never invented here
    'tone'  => null,       // success | warning | danger | info | gray | null (brand)
    'auto'  => false,      // when true and no tone: 100 → success, >=50 → brand, >0 → warning, 0 → gray
    'label' => null,       // accessible label
    'size'  => 'md',       // sm | md
])
@php
    $pct = max(0, min(100, (float) $value));
    if ($auto && $tone === null) {
        $tone = $pct >= 100 ? 'success' : ($pct >= 50 ? null : ($pct > 0 ? 'warning' : 'gray'));
    }
    $barClass = match ($tone) {
        'success' => 'bg-success',
        'warning' => 'bg-warning',
        'danger'  => 'bg-danger',
        'info'    => 'bg-info',
        'gray'    => 'bg-gray-300',
        default   => 'bg-brand-600',
    };
@endphp
<div {{ $attributes->merge(['class' => 'progress' . ($size === 'sm' ? ' progress-sm' : '')]) }}
     role="progressbar" aria-valuenow="{{ round($pct) }}" aria-valuemin="0" aria-valuemax="100" @if($label) aria-label="{{ $label }}" @endif>
    <div class="progress-bar {{ $barClass }}" style="width: {{ $pct }}%"></div>
</div>
