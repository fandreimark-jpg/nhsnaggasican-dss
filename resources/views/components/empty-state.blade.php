@props(['message', 'hint' => null, 'icon' => null])
<div {{ $attributes->class(['px-6 py-10 text-center text-gray-400']) }}>
    @if($icon)
        <i class="{{ $icon }} text-2xl block mb-2"></i>
    @endif
    <p class="text-gray-500 text-sm font-medium">{{ $message }}</p>
    @if($hint)
        <p class="text-xs text-gray-400 mt-1">{{ $hint }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
