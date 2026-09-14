@props(['id' => null, 'method' => 'GET', 'action' => null, 'clear' => null, 'clearLabel' => 'Clear filters', 'showClear' => false])
{{-- Unified filter toolbar: a white card holding the page's <form> of
     selects/inputs. Fields go in the default slot as <div class="filter-field">
     blocks; an optional `trailing` slot renders on the right. --}}
<form method="{{ $method }}" @if($id) id="{{ $id }}" @endif @if($action) action="{{ $action }}" @endif
      {{ $attributes->merge(['class' => 'filter-bar']) }}>
    <span class="text-muted text-sm mr-1 self-center hidden sm:inline" aria-hidden="true"><i class="bi bi-funnel"></i></span>
    {{ $slot }}
    @if($showClear && $clear)
        <a href="{{ $clear }}" class="btn btn-ghost btn-sm self-center">{{ $clearLabel }}</a>
    @endif
    @isset($trailing)
        <div class="md:ml-auto flex items-center gap-2 self-center">{{ $trailing }}</div>
    @endisset
</form>
