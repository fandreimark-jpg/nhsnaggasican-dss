@props(['title' => null, 'subtitle' => null, 'action' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg shadow-sm']) }}>
    @if($title)
        <div class="px-4 py-3 border-b border-gray-100 flex items-start justify-between gap-4">
            <div>
                <h3 class="font-semibold text-gray-800 text-sm">{{ $title }}</h3>
                @if($subtitle)<p class="text-xs text-gray-500 mt-0.5">{{ $subtitle }}</p>@endif
            </div>
            @if($action)<div class="text-xs shrink-0">{{ $action }}</div>@endif
        </div>
    @endif
    <div class="p-4">{{ $slot }}</div>
</div>
