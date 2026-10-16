{{-- A refused intervention action, shown inside the form that was submitted
     (persistent, once) instead of the auto-dismissing toast. --}}
@if(session('error_inline') && old('_form') === $form)
    <div class="alert alert-danger mx-4 mt-3 text-sm" role="alert" data-form-error>{{ session('error') }}</div>
@endif
