@props([
    'label',
    'value',
    'accent' => 'count-8',  // count-1..8, or status-ontrack etc.
    'icon'   => null,       // bootstrap-icon name without the "bi-" prefix
    'href'   => null,       // makes the whole card a link
    'note'   => null,
])

@php $tag = $href ? 'a' : 'div'; @endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif
   {{ $attributes->merge(['class' =>
      'block bg-white rounded-lg p-4 shadow-sm border-t-4 border-'.$accent.
      ($href ? ' hover:shadow-md hover:-translate-y-0.5 transition' : '')]) }}>
    <p class="text-xs text-gray-500 flex items-center gap-1.5">
        @if($icon)<i class="bi bi-{{ $icon }} text-gray-400"></i>@endif
        {{ $label }}
    </p>
    <p class="text-2xl font-bold text-gray-800 mt-1">{{ $value }}</p>
    @if($note)<p class="text-xs text-gray-400 mt-0.5">{{ $note }}</p>@endif
</{{ $tag }}>
