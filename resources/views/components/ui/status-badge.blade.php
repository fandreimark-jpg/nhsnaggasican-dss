@props([
    // One vocabulary for every status pill in the app. `tone` picks the
    // semantic colour; `label` is always shown, so status is never colour-only.
    // Tones: success | warning | danger | info | gray | brand | outline
    'tone' => 'gray',
    'label',
    'icon' => null,
    'dot' => false,
])
<span {{ $attributes->merge(['class' => 'badge badge-' . $tone . ($dot ? ' badge-dot' : '')]) }}>@if($icon)<i class="bi {{ $icon }}" aria-hidden="true"></i>@endif{{ $label }}</span>
