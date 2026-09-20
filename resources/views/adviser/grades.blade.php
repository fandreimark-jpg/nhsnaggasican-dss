@extends('layouts.app')

@section('title', 'Encode Grades')
@section('subtitle', $section
    ? implode(' | ', array_filter([
        'Section ' . $section->name . ' — Grade ' . $section->grade_level,
        // Track and specialization only when they actually apply — an
        // SSHS section has no strand, and a blank "—" would misread as
        // missing data ("Subject applicability" refactor, Part 12).
        trim(($section->track->name ?? '') . ($section->specialization ? ' — ' . $section->specialization->name : '')) ?: null,
        'SY ' . $section->school_year,
    ]))
    : 'No section assigned')

@section('content')
@include('partials.validation-errors')

@include('partials.section-school-year-context')

@if(!$section)
    <div class="card">
        <x-empty-state icon="bi-exclamation-circle" message="No section assigned to your account."
            hint="An Admin assigns sections to advisers — contact the admin to get one assigned." />
    </div>
@else

@php
    $isTermOpen = $openTerm === $selectedPeriod;
@endphp

@if(session('error'))
<div class="alert alert-danger mb-4">
    {{ session('error') }}
</div>
@endif

@include('partials.import-result')

<div class="card overflow-x-auto">

    {{-- Term Selector --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div class="flex items-center gap-3">
            <span class="text-sm font-semibold text-gray-700">Term:</span>
            <div class="flex gap-2">
                @foreach([1, 2, 3] as $t)
                <a href="{{ route('adviser.grades') }}?period={{ $t }}"
                    class="px-4 py-1.5 rounded-full text-sm font-medium border transition flex items-center gap-1
                        {{ $selectedPeriod == $t
                            ? 'bg-brand-800 text-white border-brand-800'
                            : 'bg-white text-gray-600 border-gray-300 hover:bg-surface' }}">
                    Term {{ $t }}
                    @if($openTerm !== $t)
                        <i class="bi bi-lock-fill text-xs {{ $selectedPeriod == $t ? 'text-white' : 'text-gray-400' }}"></i>
                    @endif
                </a>
                @endforeach
            </div>
        </div>

        @if($isTermOpen)
        <button type="button" onclick="openGradeImportModal()"
            class="bg-white border border-brand-700 text-brand-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-50 whitespace-nowrap">
            <i class="bi bi-upload"></i> Import Grades
        </button>
        @endif
    </div>

    @unless($isTermOpen)
    <div class="bg-gray-50 text-gray-500 text-sm px-6 py-3 border-b flex items-center gap-2">
        <i class="bi bi-lock-fill"></i>
        @if(!$section->isInActiveSchoolYear())
            <span class="badge badge-gray">Historical Record</span>
            School Year {{ $section->school_year }} is not the active school year — its records are read-only. You can view every term but not edit them.
        @else
            Term {{ $selectedPeriod }} is currently closed for encoding.
            @if($openTerm)
                Term {{ $openTerm }} is the open term right now — you can view Term {{ $selectedPeriod }} but not edit it.
            @else
                No term is currently open. Contact the admin.
            @endif
        @endif
    </div>
    @endunless

    {{-- "Student identity and term-specific subject offerings" pass —
         subjects are assigned per academic term; an empty term is a real
         answer about THIS term, not a broken section. --}}
    @if($subjects->isEmpty())
        <div class="p-4">
            <x-empty-state icon="bi-book" message="No subjects are assigned to {{ $section->name }} for Term {{ $selectedPeriod }}."
                hint="An Admin assigns subjects to a section one academic term at a time (Admin > Sections > Subjects). Switch the term above to see another term's subjects." />
        </div>
    @else
    {{-- Grade Table --}}
    <form method="POST" action="{{ route('adviser.grades.store') }}">
        @csrf
        <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">

        <div class="tbl-scroll">
        <table class="tbl tbl-sticky">
            <thead>
                <tr>
                    <th scope="col" class="sticky left-0 bg-gray-50">Student</th>
                    @foreach($subjects as $subject)
                        <th scope="col" class="text-center min-w-[120px]">
                            <span class="block font-medium text-gray-600">{{ $subject->name }}</span>
                            @if($subject->type === 'elective')
                                <span class="block text-xs text-orange-500 font-normal normal-case">Elective</span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($students as $studentIndex => $student)
                <tr>
                    <td class="font-medium text-ink sticky left-0 bg-white whitespace-nowrap">
                        {{ $student->last_name }}, {{ $student->first_name }} {{$student->middle_name}}
                    </td>
                    @foreach($subjects as $subjectIndex => $subject)
                        @php
                            $key       = $student->id . '_' . $subject->id;
                            $existing  = $grades[$key] ?? null;
                            $isFailing = $existing && $existing->grade < 75;
                            $inputIndex = ($studentIndex * 1000) + $subjectIndex;
                        @endphp
                        <td class="text-center">
                            <input type="hidden"
                                name="grades[{{ $inputIndex }}][student_id]"
                                value="{{ $student->id }}">
                            <input type="hidden"
                                name="grades[{{ $inputIndex }}][subject_id]"
                                value="{{ $subject->id }}">
                            <input
                                type="number"
                                name="grades[{{ $inputIndex }}][grade]"
                                value="{{ $existing ? number_format($existing->grade, 2, '.', '') : '' }}"
                                min="60" max="100" step="0.01"
                                {{ $isTermOpen ? '' : 'disabled' }}
                                class="w-20 border rounded-lg px-2 py-1.5 text-center text-sm
                                       focus:outline-none focus:ring-2 focus:ring-brand-400
                                       {{ $isFailing
                                            ? 'border-status-risk/40 bg-status-risk/5 text-status-risk'
                                            : 'border-line hover:border-gray-300' }}
                                       {{ $isTermOpen ? '' : 'bg-gray-50 text-gray-400 cursor-not-allowed' }}"
                                placeholder="—"
                            >
                            @if($existing && $existing->is_provisional)
                                <span class="block text-[10px] text-amber-600 mt-0.5" title="Computed using the {{ \App\Services\TransmutationService::schemeLabel($existing->provisional_scheme) }} table because the real scheme's bands are not yet entered.">
                                    <i class="bi bi-exclamation-triangle-fill"></i> Provisional
                                </span>
                            @endif
                        </td>
                    @endforeach
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $subjects->count() + 1 }}">
                        <x-empty-state icon="bi-people" message="No students in this section yet."
                            hint="An Admin adds students and assigns them to your section." />
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if($isTermOpen)
        <div class="px-5 py-4 border-t border-line flex justify-end">
            <button type="submit"
                class="btn btn-primary">
                <i class="bi bi-floppy"></i> Save All Grades
            </button>
        </div>
        @endif
    </form>
    @endif
</div>

{{-- IMPORT GRADES MODAL --}}
<div id="gradeImportModal"
     class="{{ $errors->gradeImport->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->gradeImport->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Import Grades — Term {{ $selectedPeriod }}</h3>
            <button type="button" onclick="closeGradeImportModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->gradeImport->any())
            <div class="alert alert-danger mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->gradeImport->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-sm text-muted mb-4">
            <a href="{{ route('adviser.grades.template') }}?period={{ $selectedPeriod }}" class="text-brand-700 underline">
                Download the pre-filled template
            </a>
            for this term — it already lists your students. Just fill in the grade columns and upload it back here.
        </p>

        <form method="POST" action="{{ route('adviser.grades.import') }}"
              enctype="multipart/form-data" class="space-y-4" data-loading="Importing grades...">
            @csrf
            <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeGradeImportModal()"
                        class="px-4 py-2 text-sm text-muted">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
                    Upload & Import
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    window.openGradeImportModal = () => window.showModal('gradeImportModal');
    window.closeGradeImportModal = () => window.hideModal('gradeImportModal');
    document.addEventListener('DOMContentLoaded', function () {
        window.bindModalOverlayClose('gradeImportModal');
    });
</script>
@endpush

@endif

@endsection