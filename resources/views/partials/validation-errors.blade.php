{{-- Default-bag validation errors, rendered where a page has no field-level
     display of its own ("Pre-demo full-system audit", 2026-09-20 — five pages
     silently bounced a rejected form back with no message). The 'deletion'
     key is rendered by the layout itself; named bags (addItem, editItem)
     belong to their own modals and are left alone. --}}
@php $pageErrors = collect($errors->getMessages())->except('deletion')->flatten(); @endphp
@if($pageErrors->isNotEmpty())
    <div class="alert alert-danger mb-4" role="alert" data-validation-errors>
        <ul class="list-disc list-inside">
            @foreach($pageErrors as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    </div>
@endif
