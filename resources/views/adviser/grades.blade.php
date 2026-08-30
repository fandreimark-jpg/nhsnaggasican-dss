@extends('layouts.app')

@section('title', 'Encode Grades')
@section('subtitle', $section
    ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level .
      ' | ' . ($section->track->name ?? '') .
      ' — ' . ($section->specialization->name ?? '')
    : 'No section assigned')

@section('content')

@if(!$section)
    <div class="bg-white rounded-xl shadow-sm p-8 text-center text-gray-400">
        <i class="bi bi-exclamation-circle text-2xl block mb-2"></i>
        No section assigned to your account.
    </div>
@else

@php
    $isTermOpen = $openTerm === $selectedPeriod;
@endphp

@if(session('error'))
<div class="bg-red-100 text-red-700 text-sm p-4 rounded-lg mb-4">
    {{ session('error') }}
</div>
@endif

@if(session('warning'))
<div class="bg-yellow-100 text-yellow-800 text-sm p-4 rounded-lg mb-4">
    <p class="font-medium mb-1">{{ session('warning') }}</p>
    <ul class="list-disc list-inside">
        @foreach(session('import_errors', []) as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">

    {{-- Term Selector --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-6 py-4 border-b">
        <div class="flex items-center gap-3">
            <span class="text-sm font-semibold text-gray-700">Term:</span>
            <div class="flex gap-2">
                @foreach([1, 2, 3] as $t)
                <a href="{{ route('adviser.grades') }}?period={{ $t }}"
                    class="px-4 py-1.5 rounded-full text-sm font-medium border transition flex items-center gap-1
                        {{ $selectedPeriod == $t
                            ? 'bg-brand-700 text-white border-brand-700'
                            : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50' }}">
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
        Term {{ $selectedPeriod }} is currently closed for encoding.
        @if($openTerm)
            Term {{ $openTerm }} is the open term right now — you can view Term {{ $selectedPeriod }} but not edit it.
        @else
            No term is currently open. Contact the admin.
        @endif
    </div>
    @endunless

    {{-- Grade Table --}}
    <form method="POST" action="{{ route('adviser.grades.store') }}">
        @csrf
        <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">

        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left px-6 py-3 sticky left-0 bg-gray-50">Student</th>
                    @foreach($subjects as $subject)
                        <th class="px-4 py-3 text-center min-w-[120px]">
                            <span class="block font-medium text-gray-600">{{ $subject->name }}</span>
                            @if($subject->type === 'elective')
                                <span class="block text-xs text-orange-500 font-normal normal-case">Elective</span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($students as $studentIndex => $student)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 font-medium text-gray-800 sticky left-0 bg-white whitespace-nowrap">
                        {{ $student->last_name }}, {{ $student->first_name }} {{$student->middle_name}}
                    </td>
                    @foreach($subjects as $subjectIndex => $subject)
                        @php
                            $key       = $student->id . '_' . $subject->id;
                            $existing  = $grades[$key] ?? null;
                            $isFailing = $existing && $existing->grade < 75;
                            $inputIndex = ($studentIndex * 1000) + $subjectIndex;
                        @endphp
                        <td class="px-4 py-3 text-center">
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
                                            ? 'border-red-300 bg-red-50 text-red-600'
                                            : 'border-gray-200 hover:border-gray-300' }}
                                       {{ $isTermOpen ? '' : 'bg-gray-50 text-gray-400 cursor-not-allowed' }}"
                                placeholder="—"
                            >
                        </td>
                    @endforeach
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $subjects->count() + 1 }}"
                        class="px-6 py-8 text-center text-gray-400">
                        <i class="bi bi-people text-2xl block mb-2"></i>
                        No students found in this section.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if($isTermOpen)
        <div class="px-6 py-4 border-t flex justify-end">
            <button type="submit"
                class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                <i class="bi bi-floppy"></i> Save All Grades
            </button>
        </div>
        @endif
    </form>
</div>

{{-- IMPORT GRADES MODAL --}}
<div id="gradeImportModal"
     class="{{ $errors->gradeImport->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->gradeImport->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Import Grades — Term {{ $selectedPeriod }}</h3>
            <button type="button" onclick="closeGradeImportModal()" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->gradeImport->any())
            <div class="bg-red-100 text-red-700 text-sm p-3 rounded-lg mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->gradeImport->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-sm text-gray-500 mb-4">
            <a href="{{ route('adviser.grades.template') }}?period={{ $selectedPeriod }}" class="text-brand-700 underline">
                Download the pre-filled template
            </a>
            for this term — it already lists your students. Just fill in the grade columns and upload it back here.
        </p>

        <form method="POST" action="{{ route('adviser.grades.import') }}"
              enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeGradeImportModal()"
                        class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
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