@props(['title', 'description' => null, 'icon' => 'bi-arrow-right-circle', 'tone' => 'brand', 'href' => null, 'count' => null, 'cta' => null])
{{-- "What Needs Your Attention Now" item: soft icon, count, title, one-line
     description, and a link — an actionable card, not a bullet point. --}}
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'action-card' . ($href ? '' : ' hover:translate-y-0')]) }}>
    <div class="icon-box icon-box-{{ $tone }}" aria-hidden="true"><i class="bi {{ $icon }}"></i></div>
    <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-ink leading-snug">
            @if($count !== null)<span class="text-xl font-bold mr-1 tabular-nums">{{ $count }}</span>@endif{{ $title }}
        </p>
        @if($description)<p class="text-xs text-muted mt-1">{{ $description }}</p>@endif
        @if($cta)<p class="text-xs font-medium text-brand-700 mt-2">{{ $cta }} <i class="bi bi-arrow-right" aria-hidden="true"></i></p>@endif
    </div>
</{{ $tag }}>
