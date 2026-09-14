@props(['title' => null, 'subtitle' => null, 'action' => null, 'padded' => true])

<div {{ $attributes->merge(['class' => 'card']) }}>
    @if($title)
        <div class="card-header">
            <div class="min-w-0">
                <h3 class="card-title">{{ $title }}</h3>
                @if($subtitle)<p class="card-subtitle">{{ $subtitle }}</p>@endif
            </div>
            @if($action)<div class="text-sm shrink-0">{{ $action }}</div>@endif
        </div>
    @endif
    <div class="{{ $padded ? 'card-body' : '' }}">{{ $slot }}</div>
</div>
