@extends('layouts.app')

@section('title', 'Verify Assessment Columns')
@section('subtitle', $subject->name . ' — Term ' . $gradingPeriod . ' — ' . $originalName)

@section('content')

{{-- TASK 3a of "dashboard structure and upload safeguards" — a signal,
     never a block: see AssessmentUploadService::detectFilenameSubjectMismatch().
     Dismissible, and the Preview button below stays enabled either way. --}}
@if($filenameMismatch)
<div id="filenameMismatchNotice" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4 rounded-lg mb-4 flex items-start justify-between gap-3">
    <div>
        <p class="font-medium"><i class="bi bi-exclamation-triangle-fill"></i> This file might be for a different subject</p>
        <p class="mt-1">
            You selected <strong>{{ $subject->name }}</strong>, but the filename "<strong>{{ $originalName }}</strong>"
            looks like it may be for <strong>{{ $filenameMismatch->name }}</strong> instead. Double-check before
            importing — scores imported under the wrong subject affect that subject's component analysis, Focus
            Area, and every intervention decision downstream.
        </p>
    </div>
    <button type="button" onclick="document.getElementById('filenameMismatchNotice').remove()" aria-label="Dismiss" class="shrink-0 text-amber-500 hover:text-amber-700">
        <i class="bi bi-x-lg"></i>
    </button>
</div>
@endif

<div class="bg-white rounded-xl shadow-sm p-6">
    <p class="text-sm text-gray-500 mb-1">
        Found <strong>{{ count($columns) }}</strong> assessment column{{ count($columns) === 1 ? '' : 's' }}
        and <strong>{{ $rowCount }}</strong> student row{{ $rowCount === 1 ? '' : 's' }} in the uploaded file.
    </p>
    <p class="text-sm text-gray-500 mb-1">
        @if($maxRowPresent)
            <i class="bi bi-check-circle text-green-600"></i>
            Maximum scores were read from a <strong>MAX</strong> row in the file and prefilled below — review them and edit any that need correcting.
        @else
            <i class="bi bi-info-circle text-gray-400"></i>
            This file has no MAX row — enter each column's maximum score by hand below.
        @endif
    </p>
    <p class="text-sm text-gray-500 mb-6">
        Review the detected classification for each column below and set its maximum score.
        <span class="text-orange-600 font-medium">Columns marked "Unclassified" could not be guessed automatically — you must choose one before importing.</span>
        Check <strong>Additional Support</strong> for a column that is within-term additional support (a re-teach
        quiz, an extra activity) rather than a regular planned item — this only affects how the item is labelled
        elsewhere, never how it's scored or weighted.
    </p>

    <form method="POST" action="{{ route('adviser.assessments.preview') }}">
        @csrf
        <input type="hidden" name="subject_id" value="{{ $subject->id }}">
        <input type="hidden" name="grading_period" value="{{ $gradingPeriod }}">
        <input type="hidden" name="stored_filename" value="{{ $storedFilename }}">
        <input type="hidden" name="original_filename" value="{{ $originalName }}">

        <div class="tbl-scroll mb-6">
        <table class="tbl">
            <thead>
                <tr>
                    <th scope="col">Column (from file)</th>
                    <th scope="col">Classify as</th>
                    <th scope="col">Exam Role</th>
                    <th scope="col">Max Score</th>
                    <th scope="col">Additional Support</th>
                </tr>
            </thead>
            <tbody>
                @foreach($columns as $i => $col)
                <tr>
                    <td class="font-medium text-gray-800">
                        {{ $col['name'] }}
                        <input type="hidden" name="columns[{{ $i }}][name]" value="{{ $col['name'] }}">
                    </td>
                    <td>
                        <select name="columns[{{ $i }}][component]" required
                                onchange="toggleExamRoleField(this)"
                                class="w-48 border rounded-lg text-sm pl-3 pr-8 py-1.5 {{ $col['guessed_component'] ? '' : 'border-orange-400 bg-orange-50' }}">
                            <option value="" disabled {{ $col['guessed_component'] ? '' : 'selected' }} {{ $col['guessed_component'] ? 'hidden' : '' }}>
                                {{ $col['guessed_component'] ? '' : 'Unclassified — choose one' }}
                            </option>
                            <option value="written_work" {{ $col['guessed_component'] === 'written_work' ? 'selected' : '' }}>Written Work</option>
                            <option value="performance_task" {{ $col['guessed_component'] === 'performance_task' ? 'selected' : '' }}>Performance Task</option>
                            <option value="examination" {{ $col['guessed_component'] === 'examination' ? 'selected' : '' }}>Examination</option>
                        </select>
                    </td>
                    <td>
                        {{-- Under DO 015, s. 2026 the Examination component
                             splits between two Summative Tests and a Term
                             Examination, each worth a different share (see
                             ExamRoleShare) — only relevant when this column
                             is classified as Examination. Optional: left
                             blank, the item falls back to equal weighting
                             within the component (see
                             GradingEngine::examinationPercentage()). --}}
                        <select name="columns[{{ $i }}][exam_role]"
                                class="w-40 border rounded-lg text-sm pl-3 pr-8 py-1.5 {{ $col['guessed_component'] === 'examination' ? '' : 'hidden' }}"
                                data-exam-role-field>
                            <option value="">— No role —</option>
                            <option value="st1" {{ ($col['guessed_exam_role'] ?? null) === 'st1' ? 'selected' : '' }}>Summative Test 1</option>
                            <option value="st2" {{ ($col['guessed_exam_role'] ?? null) === 'st2' ? 'selected' : '' }}>Summative Test 2</option>
                            <option value="term_exam" {{ ($col['guessed_exam_role'] ?? null) === 'term_exam' ? 'selected' : '' }}>Term Examination</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" name="columns[{{ $i }}][max_score]" min="0.01" step="0.01" required
                               placeholder="e.g. 20"
                               value="{{ $col['file_max_score'] ?? '' }}"
                               class="w-24 border rounded-lg px-3 py-1.5 text-sm">
                        @if($col['file_max_score'] !== null)
                            <span class="block text-xs text-gray-400 mt-0.5">from file</span>
                        @endif
                    </td>
                    <td>
                        {{-- "Workflow completion pass" TASK 3b — display
                             only: never inferred from the column's name.
                             Defaults unchecked; the Adviser decides. --}}
                        <label class="inline-flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                            <input type="checkbox" name="columns[{{ $i }}][is_additional_support]" value="1"
                                   class="rounded border-gray-300 text-brand-700 focus:ring-brand-400">
                            Additional support
                        </label>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id]) }}"
               class="px-4 py-2 text-sm text-gray-500">Cancel</a>
            <button type="submit"
                    class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                <i class="bi bi-eye"></i> Preview
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
    // Exam Role only makes sense for a column classified as Examination —
    // hidden (and cleared, so a stale role never silently submits under a
    // different component) for anything else.
    function toggleExamRoleField(select) {
        const row = select.closest('tr');
        const examRoleField = row.querySelector('[data-exam-role-field]');
        if (select.value === 'examination') {
            examRoleField.classList.remove('hidden');
        } else {
            examRoleField.classList.add('hidden');
            examRoleField.value = '';
        }
    }
</script>
@endpush

@endsection
