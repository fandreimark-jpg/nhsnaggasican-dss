@props([
    'label',
    'value',
    'accent' => 'count-8',  // count-1..8, or status-ontrack etc. — drives the icon tint; a status token also colours the value
    'icon'   => null,       // bootstrap-icon name without the "bi-" prefix
    'href'   => null,       // makes the whole card a link
    'note'   => null,
])

@php
    // "UI modernization pass" — same public API as before (label/value/
    // accent/icon/href/note); the accent now tints a soft icon container
    // rather than a coloured top border, so eight cards in a row read as
    // one calm grid instead of a rainbow. A status.* accent (a genuine
    // DSS status figure) additionally colours the value itself.
    $tag = $href ? 'a' : 'div';
    $isStatus = str_starts_with($accent, 'status-');
    $iconTint = match (true) {
        $accent === 'status-ontrack' => 'bg-success-soft text-success-text',
        $accent === 'status-attention' => 'bg-warning-soft text-warning-text',
        $accent === 'status-risk', $accent === 'status-failing' => 'bg-danger-soft text-danger-text',
        // Cool "count" family, mapped to fixed soft tints (no runtime-built
        // class names, so nothing here can be purged by the build).
        'count-1' === $accent => 'bg-sky-100 text-sky-700',
        'count-2' === $accent => 'icon-box-info',
        'count-3' === $accent => 'bg-indigo-100 text-indigo-700',
        'count-4' === $accent => 'icon-box-violet',
        'count-5' === $accent => 'bg-purple-100 text-purple-700',
        'count-6' === $accent => 'icon-box-cyan',
        'count-7' === $accent => 'icon-box-teal',
        default => 'icon-box-slate',
    };
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif
   {{ $attributes->merge(['class' => 'stat-card ' . ($href ? 'cursor-pointer' : '')]) }}>
    @if($icon)
        <div class="icon-box {{ $iconTint }}" aria-hidden="true"><i class="bi bi-{{ $icon }}"></i></div>
    @endif
    <div class="min-w-0 flex-1">
        <p class="stat-label">{{ $label }}</p>
        <p class="stat-value {{ $isStatus ? 'text-' . $accent : '' }}">{{ $value }}</p>
        @if($note)<div class="stat-note">{{ $note }}</div>@endif
    </div>
    @if($href)<i class="bi bi-chevron-right text-gray-300 self-center" aria-hidden="true"></i>@endif
</{{ $tag }}>
