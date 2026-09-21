@extends('layouts.app')

@section('title', 'Verify Assessment Columns')
@section('subtitle', $subject->name . ' — Term ' . $gradingPeriod . ' — ' . $originalName)

@section('content')

{{-- Detected ECR Format -- shown whenever a real DepEd/school class-record
     template was recognized (never for a plain flat CSV/XLSX upload, which
     has no "format" to name). See AssessmentUploadService::lastDetectedFormat(). --}}
@if(($detectedFormat ?? null) === 'sshs')
<div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm p-3 rounded-lg mb-4">
    <i class="bi bi-file-earmark-check"></i> Detected ECR Format: <strong>Strengthened SHS E-Class Record</strong>
</div>
@elseif(($detectedFormat ?? null) === 'grade12')
<div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm p-3 rounded-lg mb-4">
    <i class="bi bi-file-earmark-check"></i> Detected ECR Format: <strong>Grade 12 Class Record</strong>
</div>
@endif

{{-- Grade 12's template carries no LRN at all -- a roster name that
     couldn't be confidently matched to one existing student in this
     section is EXCLUDED from the import, never given a fabricated LRN.
     Shown even when other rows imported fine, since a silently-skipped
     student is exactly the kind of gap that must never be silent. --}}
@if(!empty($unresolvedLearnerNames ?? []))
<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4 rounded-lg mb-4">
    <p class="font-medium"><i class="bi bi-exclamation-triangle-fill"></i> {{ count($unresolvedLearnerNames) }} learner name(s) in the file could not be matched to a student already enrolled in this section — excluded from this import, not guessed:</p>
    <ul class="list-disc list-inside mt-1">
        @foreach($unresolvedLearnerNames as $name)
            <li>{{ $name }}</li>
        @endforeach
    </ul>
</div>
@endif

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

{{-- "ECR alignment" work order, PART 5f — a signal, never a block, and
     never an overwrite: see EcrReaderService::checkWeightMismatch(). Only
     ever populated for a file actually read through the DepEd ECR profile. --}}
@if($weightMismatch ?? null)
<div id="weightMismatchNotice" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4 rounded-lg mb-4 flex items-start justify-between gap-3">
    <div>
        <p class="font-medium"><i class="bi bi-exclamation-triangle-fill"></i> Weight mismatch — not corrected automatically</p>
        <p class="mt-1">{{ $weightMismatch }}</p>
    </div>
    <button type="button" onclick="document.getElementById('weightMismatchNotice').remove()" aria-label="Dismiss" class="shrink-0 text-amber-500 hover:text-amber-700">
        <i class="bi bi-x-lg"></i>
    </button>
</div>
@endif

{{-- The workbook could not CONFIRM part of the selection, because the
     teacher left that INPUT DATA cover cell blank. A workbook that
     CONTRADICTS the selection never reaches this screen at all — it is
     refused outright in AssessmentController::detect() and re-checked in
     preview()/import(). See EcrSubjectTermResolver for why "does not say"
     and "says something different" are handled differently. --}}
@if($metadataMismatch ?? null)
<div id="metadataMismatchNotice" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4 rounded-lg mb-4 flex items-start justify-between gap-3">
    <div>
        <p class="font-medium"><i class="bi bi-exclamation-triangle-fill"></i> Workbook could not be fully verified</p>
        <p class="mt-1">{{ $metadataMismatch }}</p>
    </div>
    <button type="button" onclick="document.getElementById('metadataMismatchNotice').remove()" aria-label="Dismiss" class="shrink-0 text-amber-500 hover:text-amber-700">
        <i class="bi bi-x-lg"></i>
    </button>
</div>
@endif

{{-- Pre-demo hardening, Phase 2 (2026-09-21) — a BLOCK, not a notice, and
     deliberately not dismissible: the classification the adviser just
     confirmed contradicts an item already recorded for this subject and
     term. Importing would rewrite that item's stored classification and
     re-weight every score under it. The selections below are the ones
     they submitted; the conflicting rows are highlighted. See
     AssessmentController::verifyScreenWithConflicts() and
     AssessmentItemConflictDetector. --}}
@if(!empty($metadataConflicts ?? []))
<div class="bg-red-50 border border-red-300 text-red-800 text-sm p-4 rounded-lg mb-4" data-metadata-conflict-notice role="alert">
    <p class="font-medium">
        <i class="bi bi-x-octagon-fill"></i>
        This upload cannot be imported as classified — {{ count($metadataConflicts) }} {{ count($metadataConflicts) === 1 ? 'conflict' : 'conflicts' }} with items already recorded for {{ $subject->name }}, Term {{ $gradingPeriod }}
    </p>
    <ul class="mt-2 space-y-1">
        @foreach($metadataConflicts as $conflict)
        <li class="flex flex-wrap items-center gap-2" data-metadata-conflict="{{ $conflict['field'] }}">
            <span class="font-medium">{{ $conflict['item'] }}</span>
            <span class="badge badge-danger">{{ $conflict['field_label'] }} conflict</span>
            <span>Stored: <strong>{{ $conflict['stored_label'] }}</strong></span>
            <span aria-hidden="true">&middot;</span>
            <span>Upload: <strong>{{ $conflict['incoming_label'] }}</strong></span>
        </li>
        @endforeach
    </ul>
    <p class="mt-2">
        Nothing was changed. Correct the highlighted classifications below to match what is recorded and press Preview again —
        or, if the recorded item itself is wrong, cancel and use <strong>Edit</strong> on that item first.
        The file's classification is never applied over a recorded item automatically.
    </p>
</div>
@endif

<div class="card p-6">
    <p class="text-sm text-muted mb-1">
        Found <strong>{{ count($columns) }}</strong> assessment column{{ count($columns) === 1 ? '' : 's' }}
        and <strong>{{ $rowCount }}</strong> student row{{ $rowCount === 1 ? '' : 's' }} in the uploaded file.
    </p>
    <p class="text-sm text-muted mb-1">
        @if($maxRowPresent)
            <i class="bi bi-check-circle text-green-600"></i>
            Maximum scores were read from a <strong>MAX</strong> row in the file and prefilled below — review them and edit any that need correcting.
        @else
            <i class="bi bi-info-circle text-gray-400"></i>
            This file has no MAX row — enter each column's maximum score by hand below.
        @endif
    </p>
    <p class="text-sm text-muted mb-6">
        Review the detected classification for each column below and set its maximum score.
        <span class="text-orange-600 font-medium">Columns marked "Unclassified" could not be guessed automatically — you must choose one before importing.</span>
        Check <strong>Additional Support</strong> for a column that is within-term additional support (a re-teach
        quiz, an extra activity) rather than a regular planned item — this only affects how the item is labelled
        elsewhere, never how it's scored or weighted.
    </p>

    <form method="POST" action="{{ route('adviser.assessments.preview') }}" data-loading="Validating scores...">
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
                <tr @if(!empty($col['has_conflict'])) class="bg-red-50" data-conflict-row @endif>
                    <td class="font-medium text-ink">
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
                        @if($col['file_max_score'] !== null && ($col['max_score_from_file'] ?? true))
                            <span class="block text-xs text-muted mt-0.5">from file</span>
                        @endif
                    </td>
                    <td>
                        {{-- "Workflow completion pass" TASK 3b — display
                             only: never inferred from the column's name.
                             Defaults unchecked; the Adviser decides. --}}
                        <label class="inline-flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                            <input type="checkbox" name="columns[{{ $i }}][is_additional_support]" value="1"
                                   {{ !empty($col['is_additional_support']) ? 'checked' : '' }}
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
               class="px-4 py-2 text-sm text-muted">Cancel</a>
            <button type="submit"
                    class="btn btn-primary">
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
