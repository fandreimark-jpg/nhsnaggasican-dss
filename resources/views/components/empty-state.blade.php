@props(['message', 'hint' => null, 'icon' => null])
{{-- Intentional empty state — an icon, a plain statement, and a short
     explanation of what will make data appear. Never looks like an error. --}}
<div {{ $attributes->class(['empty-state']) }}>
    <div class="empty-state-icon" aria-hidden="true">
        <i class="{{ $icon ?: 'bi bi-inbox' }}"></i>
    </div>
    <p class="empty-state-title">{{ $message }}</p>
    @if($hint)
        <p class="empty-state-hint">{{ $hint }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
