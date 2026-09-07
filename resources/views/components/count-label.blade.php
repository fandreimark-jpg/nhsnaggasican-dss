@props(['count', 'noun', 'total' => false])
@php
    $label = \Illuminate\Support\Str::plural($noun, (int) $count);
@endphp
@if($total){{ $count }} total {{ $label }}@else{{ $count }} {{ $label }}@endif
