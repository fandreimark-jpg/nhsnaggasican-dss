@extends('layouts.app')

@section('title', 'Assessment Evidence')
@section('subtitle', $section
    ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level
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
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    $componentColors = ['written_work' => 'bg-blue-100 text-blue-700', 'performance_task' => 'bg-purple-100 text-purple-700', 'examination' => 'bg-orange-100 text-orange-700'];
@endphp

@if(session('error'))
<div class="bg-red-100 text-red-700 text-sm p-4 rounded-lg mb-4">{{ session('error') }}</div>
@endif

@if(session('success'))
<div class="bg-green-100 text-green-700 text-sm p-4 rounded-lg mb-4">{{ session('success') }}</div>
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
                <a href="{{ route('adviser.assessments') }}?period={{ $t }}&subject_id={{ $selectedSubject->id ?? '' }}"
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

        <div class="flex items-center gap-3">
            <form method="GET" action="{{ route('adviser.assessments') }}" class="flex items-center gap-2">
                <input type="hidden" name="period" value="{{ $selectedPeriod }}">
                <label class="text-sm text-gray-500">Subject:</label>
                <select name="subject_id" onchange="this.form.submit()"
                        class="border rounded-lg text-sm px-3 py-1.5">
                    @foreach($subjects as $subject)
                        <option value="{{ $subject->id }}" {{ ($selectedSubject->id ?? null) === $subject->id ? 'selected' : '' }}>
                            {{ $subject->name }}
                        </option>
                    @endforeach
                </select>
            </form>

            @if($isTermOpen && $selectedSubject)
            <button type="button" onclick="openAssessmentUploadModal()"
                class="bg-white border border-brand-700 text-brand-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-50 whitespace-nowrap">
                <i class="bi bi-upload"></i> Upload Assessment Form
            </button>
            @endif
        </div>
    </div>

    @unless($isTermOpen)
    <div class="bg-gray-50 text-gray-500 text-sm px-6 py-3 border-b flex items-center gap-2">
        <i class="bi bi-lock-fill"></i>
        Term {{ $selectedPeriod }} is currently closed for encoding.
        @if($openTerm)
            Term {{ $openTerm }} is the open term right now — you can view Term {{ $selectedPeriod }} but not upload to it.
        @else
            No term is currently open. Contact the admin.
        @endif
    </div>
    @endunless

    @if(!$selectedSubject)
        <div class="px-6 py-8 text-center text-gray-400">
            <i class="bi bi-book text-2xl block mb-2"></i>
            No subjects are offered to your section yet.
        </div>
    @else
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-6 py-3">Item</th>
                <th class="text-left px-4 py-3">Component</th>
                <th class="text-center px-4 py-3">Max Score</th>
                <th class="text-center px-4 py-3">Scores Entered</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($items as $item)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-3 font-medium text-gray-800">{{ $item->name }}</td>
                <td class="px-4 py-3">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $componentColors[$item->component] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ $componentLabels[$item->component] ?? $item->component }}
                    </span>
                </td>
                <td class="px-4 py-3 text-center text-gray-600">{{ number_format($item->max_score, 2) }}</td>
                <td class="px-4 py-3 text-center text-gray-600">{{ $item->scores_count }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="px-6 py-8 text-center text-gray-400">
                    <i class="bi bi-clipboard-data text-2xl block mb-2"></i>
                    No assessment items uploaded yet for {{ $selectedSubject->name }}, Term {{ $selectedPeriod }}.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    @endif
</div>

@if($performance->isNotEmpty())
<div class="bg-white rounded-xl shadow-sm mt-4 overflow-x-auto">
    <div class="px-6 py-4 border-b">
        <h3 class="font-semibold text-gray-800 text-sm">Student Performance — {{ $selectedSubject->name }}, Term {{ $selectedPeriod }}</h3>
        <p class="text-xs text-gray-500 mt-1">
            Component breakdown from assessment evidence — not just the final grade. A student can look fine
            overall while one component quietly needs attention.
        </p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-6 py-3">Student</th>
                <th class="text-center px-3 py-3">Written Work</th>
                <th class="text-center px-3 py-3">Performance Task</th>
                <th class="text-center px-3 py-3">Examination</th>
                <th class="text-center px-3 py-3">Computed Grade</th>
                <th class="text-left px-4 py-3">Focus Area</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($performance as $row)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-3 font-medium text-gray-800 whitespace-nowrap">
                    {{ $row['student']->last_name }}, {{ $row['student']->first_name }}
                </td>
                @foreach(['written_work', 'performance_task', 'examination'] as $key)
                    @php $c = $row['components'][$key]; @endphp
                    <td class="px-3 py-3 text-center">
                        @if($c['percentage'] === null)
                            <span class="text-gray-300 text-xs">No data</span>
                        @else
                            <span class="{{ $c['status'] === 'On Track' ? 'text-green-700' : 'text-red-600 font-medium' }}">
                                {{ number_format($c['percentage'], 2) }}%
                            </span>
                            <span class="block text-xs text-gray-400">
                                {{ $c['gap'] >= 0 ? '+' : '' }}{{ number_format($c['gap'], 1) }}
                            </span>
                        @endif
                    </td>
                @endforeach
                <td class="px-3 py-3 text-center font-semibold {{ $row['complete'] ? 'text-gray-800' : 'text-gray-300' }}">
                    {{ $row['complete'] ? number_format($row['computed_grade'], 2) : '—' }}
                </td>
                <td class="px-4 py-3">
                    @if($row['weakest_component'] && ($row['components'][$row['weakest_component']]['status'] ?? null) === 'Needs Attention')
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                            {{ $componentLabels[$row['weakest_component']] ?? $row['weakest_component'] }}
                        </span>
                    @elseif($row['weakest_component'])
                        <span class="text-xs text-gray-400">On track</span>
                    @else
                        <span class="text-xs text-gray-300">No data yet</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- UPLOAD MODAL --}}
@if($selectedSubject)
<div id="assessmentUploadModal" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Upload Assessment Form — {{ $selectedSubject->name }}, Term {{ $selectedPeriod }}</h3>
            <button type="button" onclick="closeAssessmentUploadModal()" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <p class="text-sm text-gray-500 mb-4">
            Expected columns: <code>lrn, last_name, first_name</code>, then one column per assessment item
            (e.g. <code>Quiz 1</code>, <code>Performance Task 1</code>, <code>Exam</code>). You'll verify how each
            column is classified — and set its maximum score — before anything is saved.
        </p>

        <form method="POST" action="{{ route('adviser.assessments.detect') }}"
              enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="hidden" name="subject_id" value="{{ $selectedSubject->id }}">
            <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeAssessmentUploadModal()"
                        class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                    Continue
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    window.openAssessmentUploadModal = () => window.showModal('assessmentUploadModal');
    window.closeAssessmentUploadModal = () => window.hideModal('assessmentUploadModal');
    document.addEventListener('DOMContentLoaded', function () {
        window.bindModalOverlayClose('assessmentUploadModal');
    });
</script>
@endpush
@endif

@endif

@endsection
