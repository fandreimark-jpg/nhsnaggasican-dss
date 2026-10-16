{{--
    Examination Type (assessments.exam_role) for the Add and Edit item modals.
    Shown only while the Component is Examination; the select is DISABLED
    while hidden, so a non-Examination item never submits a type. The choices
    are the roles this subject's grading profile uses
    (App\Services\ExaminationRoles::forContext()); the server re-checks all of
    it (AssessmentController::examRoleProblem()).

    Expects: $prefix ('aai' | 'eai'), $bag, $selected, $visible, and the
    page's $examProfile / $takenExamRoles.
--}}
@php
    $roleLabels = \App\Services\ExaminationRoles::LABELS;
    $roleItemNames = \App\Services\ExaminationRoles::ITEM_NAMES;
    $showTaken = $prefix === 'aai';
@endphp
<div id="{{ $prefix }}ExamRoleWrap" class="{{ $visible ? '' : 'hidden' }}" data-exam-type-wrap>
    <label class="form-label" for="{{ $prefix }}ExamRole">Examination Type</label>
    @if($examProfile['roles'] === [])
        <p class="form-help" data-flat-exam>
            This subject is graded with one Examination score — no ST1 / ST2 / Term Exam split — so no type is needed.
        </p>
    @else
        <select name="exam_role" id="{{ $prefix }}ExamRole" class="form-input" data-exam-role
                @disabled(!$visible) @required($visible && $prefix === 'aai')>
            <option value="">— Select —</option>
            @foreach($examProfile['roles'] as $role)
                <option value="{{ $role }}" data-item-name="{{ $roleItemNames[$role] }}" @selected($selected === $role)>
                    {{ $roleLabels[$role] }}@if($showTaken && isset($takenExamRoles[$role])) — already added ({{ $takenExamRoles[$role] }})@endif
                </option>
            @endforeach
        </select>
        <p class="form-help">The type identifies the exam; the Item Name is only its label. One of each per term.</p>
    @endif
    @if($errors->{$bag}->has('exam_role'))<p class="form-error">{{ $errors->{$bag}->first('exam_role') }}</p>@endif
</div>
