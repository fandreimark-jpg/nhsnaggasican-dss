@props(['title' => null, 'subtitle' => null, 'icon' => null, 'padded' => true])
{{-- Card with an optional header row (icon + title + subtitle + `action` slot). --}}
<div {{ $attributes->merge(['class' => 'card']) }}>
    @if($title)
        <div class="card-header">
            <div class="flex items-start gap-3 min-w-0">
                @if($icon)<div class="icon-box icon-box-sm icon-box-brand" aria-hidden="true"><i class="bi {{ $icon }}"></i></div>@endif
                <div class="min-w-0">
                    <h3 class="card-title">{{ $title }}</h3>
                    @if($subtitle)<p class="card-subtitle">{{ $subtitle }}</p>@endif
                </div>
            </div>
            @isset($action)<div class="text-sm shrink-0">{{ $action }}</div>@endisset
        </div>
    @endif
    <div class="{{ $padded ? 'card-body' : '' }}">{{ $slot }}</div>
</div>
