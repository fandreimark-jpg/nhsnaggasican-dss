@extends('layouts.app')

{{-- Add / Edit Assessment — DETAILS ONLY. Scores and Missing marks are
     entered in the Class Record grid, never here
     (Teacher\AssessmentFormController refuses them). --}}
@php
    $isEdit = $assessment !== null;
    $bag = $isEdit ? 'editItem' : 'addItem';
    $componentValue = old('component', $isEdit ? $assessment->component : $prefillComponent);
    $backUrl = route('teacher.class-record', ['section_id' => $section->id, 'subject_id' => $subject->id, 'period' => $term]);
    $weights = ['written_work' => $profile['ww_weight'], 'performance_task' => $profile['pt_weight'], 'examination' => $profile['ex_weight']];
@endphp

@section('title', $isEdit ? 'Edit Assessment' : 'Add Assessment')
@section('subtitle', $subject->name . ' — Grade ' . $section->grade_level . ' ' . $section->name . ' — Term ' . $term . ' — ' . $section->school_year)

@section('content')
<div class="mb-4">
    <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to the Class Record</a>
</div>

@if($errors->{$bag}->any())
    <div class="alert alert-danger mb-4" role="alert" id="assessmentFormErrors" tabindex="-1">
        <p class="font-medium">Nothing was saved. Please correct the following:</p>
        <ul class="list-disc list-inside">
            @foreach($errors->{$bag}->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
        @if($isEdit && old('loaded_fingerprint') && old('loaded_fingerprint') !== $fingerprint)
            <p class="mt-1"><a class="underline font-medium" href="{{ route('teacher.assessments.edit', $assessment->id) }}">Reload the current details</a> before saving again.</p>
        @endif
    </div>
@endif
@include('partials.validation-errors')

<form method="POST" id="assessmentForm"
      action="{{ $isEdit ? route('teacher.assessments.update', $assessment->id) : route('teacher.assessments.store') }}"
      data-loading="{{ $isEdit ? 'Saving changes…' : 'Saving assessment…' }}">
    @csrf
    @if($isEdit)
        @method('PUT')
        <input type="hidden" name="loaded_fingerprint" value="{{ old('loaded_fingerprint', $fingerprint) }}">
    @else
        <input type="hidden" name="section_id" value="{{ $section->id }}">
        <input type="hidden" name="subject_id" value="{{ $subject->id }}">
        <input type="hidden" name="grading_period" value="{{ $term }}">
        <input type="hidden" name="submission_token" value="{{ old('submission_token', $submissionToken) }}">
    @endif

    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Assessment details</h2>
            <p class="card-subtitle">This creates the assessment column only. Enter learners' scores in the Class Record after saving — no score, zero or Missing mark is recorded here.</p>
        </div>
        <div class="card-body grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div><span class="form-label">School Year</span><p class="form-readonly">{{ $section->school_year }}</p></div>
            <div><span class="form-label">Section</span><p class="form-readonly">Grade {{ $section->grade_level }} — {{ $section->name }}</p></div>
            <div><span class="form-label">Subject</span><p class="form-readonly">{{ $subject->name }}</p></div>
            <div><span class="form-label">Term</span><p class="form-readonly">Term {{ $term }}</p></div>

            <div class="sm:col-span-2">
                <label class="form-label" for="afName">Assessment name</label>
                @if($isEdit)
                    <p class="form-readonly" id="afName">{{ $assessment->name }}</p>
                    <p class="form-help">The name identifies the assessment and is not changed here.</p>
                @else
                    <input type="text" name="item_name" id="afName" required maxlength="255" value="{{ old('item_name') }}" class="form-input"
                           placeholder="e.g. Quiz 1, Activity 1, Performance Task 1">
                    @if($errors->addItem->has('item_name'))<p class="form-error">{{ $errors->addItem->first('item_name') }}</p>@endif
                @endif
            </div>
            <div>
                <label class="form-label" for="afComponent">Component</label>
                <select name="component" id="afComponent" required class="form-select" data-exam-component>
                    <option value="" @selected(!$componentValue) disabled>— Select —</option>
                    @foreach(\App\Services\ClassRecordService::COMPONENTS as $key => $label)
                        @if($key === 'examination' && !$examProfile['has_examination'] && !($isEdit && $assessment->component === 'examination'))
                            <option value="{{ $key }}" disabled>{{ $label }} — not in this subject's grading profile</option>
                        @else
                            <option value="{{ $key }}" @selected($componentValue === $key)>{{ $label }}{{ $weights[$key] !== null ? ' (' . \App\Services\ClassRecordService::fmt($weights[$key]) . '%)' : '' }}</option>
                        @endif
                    @endforeach
                </select>
                @if($errors->{$bag}->has('component'))<p class="form-error">{{ $errors->{$bag}->first('component') }}</p>@endif
                @if($isEdit && $scoreCount > 0)<p class="form-help">Changing the component re-weights the {{ $scoreCount }} recorded score(s).</p>@endif
            </div>
            <div>
                <label class="form-label" for="afDate">Date given <span class="text-gray-400 text-xs">(optional)</span></label>
                <input type="date" name="assessment_date" id="afDate" class="form-input"
                       value="{{ old('assessment_date', $isEdit ? $assessment->assessment_date?->format('Y-m-d') : '') }}">
            </div>
            <div>
                <label class="form-label" for="afMax">Highest Possible Score</label>
                <input type="number" name="max_score" id="afMax" step="0.01" min="0.01" required class="form-input"
                       value="{{ old('max_score', $isEdit ? \App\Services\ClassRecordService::fmt((float) $assessment->max_score) : '') }}">
                @if($errors->{$bag}->has('max_score'))<p class="form-error">{{ $errors->{$bag}->first('max_score') }}</p>@endif
                @if($isEdit && $highestScore !== null)<p class="form-help">Cannot be lower than the highest recorded score ({{ \App\Services\ClassRecordService::fmt((float) $highestScore) }}).</p>@endif
            </div>
            <div class="sm:col-span-2 lg:col-span-1">
                @include('adviser.partials.examination-type-field', [
                    'prefix' => $isEdit ? 'eai' : 'aai', 'bag' => $bag,
                    'selected' => old('exam_role', $isEdit ? $assessment->exam_role : null),
                    'visible' => $componentValue === 'examination',
                ])
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                @if($isEdit)
                    <p class="text-sm text-gray-600">Within-term additional support: <strong>{{ $assessment->is_additional_support ? 'Yes' : 'No' }}</strong>
                        <span class="text-xs text-muted">(set when the assessment was created)</span></p>
                @else
                    <label class="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                        <input type="checkbox" name="is_additional_support" value="1" @checked(old('is_additional_support'))
                               class="rounded border-gray-300 text-brand-700 focus:ring-brand-400">
                        This is within-term additional support (a re-teach quiz, an extra activity)
                    </label>
                    <p class="form-help ml-6">It is recorded beside the original assessment and never replaces it. Most items are regular — leave unticked.</p>
                @endif
                @if($errors->{$bag}->has('scores'))<p class="form-error">{{ $errors->{$bag}->first('scores') }}</p>@endif
            </div>
        </div>
        <div class="card-footer flex flex-wrap items-center justify-end gap-3">
            <a href="{{ $backUrl }}" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary" id="afSubmit">
                <i class="bi bi-save" aria-hidden="true"></i> {{ $isEdit ? 'Save changes' : 'Save Assessment' }}
            </button>
        </div>
    </div>
</form>

@if($isEdit)
    <p class="text-xs text-muted">{{ $scoreCount }} score(s) recorded — change them in the Class Record.
        <a class="underline" href="{{ route('teacher.assessments.history', $assessment->id) }}">View this assessment's score history</a></p>
@endif
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('assessmentForm');
    if (!form) return;
    const component = form.querySelector('[data-exam-component]');
    const wrap = document.querySelector('[data-exam-type-wrap]');
    const role = wrap ? wrap.querySelector('[data-exam-role]') : null;
    let dirty = false;

    function syncExamType() {
        const isExam = component.value === 'examination';
        if (wrap) wrap.classList.toggle('hidden', !isExam);
        if (role) { role.disabled = !isExam; role.required = isExam; }
    }

    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; syncExamType(); });
    form.addEventListener('submit', () => { dirty = false; }, true);
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    syncExamType();
    document.getElementById('assessmentFormErrors')?.focus();
})();
</script>
@endpush
