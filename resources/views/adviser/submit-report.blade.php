@extends('layouts.app')

@section('title', 'Submit Report')
@section('subtitle', $section
    ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level
    : 'No section assigned')

@section('content')

@if(!$section)
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl">
        <x-empty-state message="No section assigned yet." hint="An Admin assigns sections to advisers — contact the admin to get one assigned to your account." />
    </div>
@else

{{-- Submission Status per Term --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    @foreach([1, 2, 3] as $period)
    @php
        $termInfo      = $termStatus[$period];
        $submission    = $termInfo['submission'];
        $gradeCount    = $termInfo['encoded'];
        $totalExpected = $termInfo['expected'];
        $isComplete    = $termInfo['complete'];
        $isSubmitted   = $termInfo['submitted'];
        $evidence      = $termInfo['assessment_evidence'];
    @endphp
    <div class="bg-white rounded-xl shadow-sm p-5 border-t-4
        {{ $isSubmitted ? 'border-green-500' : ($isComplete ? 'border-brand-500' : 'border-gray-300') }}">

        <div class="flex justify-between items-start mb-3">
            <div>
                <p class="text-sm font-semibold text-gray-700">Term {{ $period }}</p>
                <p class="text-xs text-gray-400 mt-0.5">
                    {{ $gradeCount }}/{{ $totalExpected }} grades encoded
                </p>
                @if($evidence['has_any_evidence'])
                    <p class="text-xs mt-0.5 {{ $evidence['ready'] ? 'text-green-600' : 'text-orange-500' }}"
                       title="Students with a complete Written Work + Performance Task + Examination computed grade — informational only, does not block submission">
                        <i class="bi bi-clipboard-data"></i>
                        {{ $evidence['complete'] }}/{{ $evidence['expected'] }} assessment evidence complete
                    </p>
                @endif
            </div>
            @if($isSubmitted)
                <span class="text-xs px-2 py-1 rounded-full font-medium bg-green-100 text-green-700">
                    Submitted
                </span>
            @else
                <span class="text-xs px-2 py-1 rounded-full font-medium bg-gray-100 text-gray-500">
                    Not Submitted
                </span>
            @endif
        </div>

        @if($isSubmitted)
            <p class="text-xs text-gray-400 mb-3">
                Submitted: {{ $submission->submitted_at->format('M d, Y h:i A') }}
            </p>

            @if($isComplete)
                <div class="border-t pt-3 mt-1">
                    <p class="text-xs text-gray-400 mb-2">
                        Found an error? You can correct grades and re-submit.
                    </p>
                    <div class="flex gap-2">
                        <a href="{{ route('adviser.grades') }}?period={{ $period }}"
                           class="flex-1 text-center border border-brand-600 text-brand-600 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-brand-50">
                            Edit Grades
                        </a>
                        <form method="POST"
                              action="{{ route('adviser.submit.report.post') }}"
                              class="flex-1"
                              data-resubmit="Re-submit Term {{ $period }} report? This will update the risk classification.">
                            @csrf
                            <input type="hidden" name="grading_period" value="{{ $period }}">
                            <input type="hidden" name="resubmit" value="1">
                            <button type="submit"
                                    class="w-full bg-yellow-500 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-yellow-600">
                                Re-submit
                            </button>
                        </form>
                    </div>
                </div>
            @endif

        @else
            @if($isComplete)
                <form method="POST" action="{{ route('adviser.submit.report.post') }}">
                    @csrf
                    <input type="hidden" name="grading_period" value="{{ $period }}">
                    <button type="submit"
                            class="w-full mt-2 bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                        Submit Term {{ $period }} Report
                    </button>
                </form>
            @else
                <p class="text-xs text-yellow-600 mt-2">
                    ⚠ Complete all grades before submitting
                    ({{ $totalExpected - $gradeCount }} remaining)
                </p>
            @endif
        @endif
    </div>
    @endforeach
</div>

{{-- Grade Summary Table --}}
<div class="bg-white rounded-xl shadow-sm">
    <div class="px-6 py-4 border-b">
        <h3 class="font-semibold text-gray-800">Grade Summary</h3>
        <p class="text-sm text-gray-500">Overview of encoded grades per student</p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500">
                <tr>
                    <th class="text-left px-6 py-3">Student</th>
                    <th class="text-center px-4 py-3">Term 1</th>
                    <th class="text-center px-4 py-3">Term 2</th>
                    <th class="text-center px-4 py-3">Term 3</th>
                    <th class="text-center px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($gradeSummary as $row)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 font-medium text-gray-800">
                        {{ $row['student']->last_name }}, {{ $row['student']->first_name }}
                    </td>
                    {{-- "Decision flow, report scoping, and dashboard
                         pass" TASK 2e — same marking treatment as the
                         Reports page: a term's grades stay visible once
                         verified, but a term that hasn't been SUBMITTED
                         yet is marked, not presented identically to one
                         that has. --}}
                    @foreach([1, 2, 3] as $period)
                    @php
                        $termValue = $row['term' . $period];
                        $termSubmitted = $termStatus[$period]['submitted'] ?? false;
                    @endphp
                    <td class="px-4 py-3 text-center {{ !$termSubmitted ? 'text-gray-400 italic' : ($termValue && $termValue < 75 ? 'text-red-600 font-semibold' : 'text-gray-700') }}"
                        @unless($termSubmitted) title="Verified but Term {{ $period }} has not been submitted yet." @endunless>
                        {{ $termValue ? number_format($termValue, 2) : '—' }}
                    </td>
                    @endforeach
                    <td class="px-4 py-3 text-center">
                        @if($row['term1'] && $row['term2'] && $row['term3'])
                            <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-700">Complete</span>
                        @else
                            <span class="text-xs px-2 py-1 rounded-full bg-yellow-100 text-yellow-700">Incomplete</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5">
                        <x-empty-state message="No students in this section yet." hint="An Admin adds students and assigns them to your section." />
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endif

@endsection
