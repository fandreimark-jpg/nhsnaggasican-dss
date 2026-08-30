@extends('layouts.app')

@section('title', 'Verify Assessment Columns')
@section('subtitle', $subject->name . ' — Term ' . $gradingPeriod . ' — ' . $originalName)

@section('content')

<div class="bg-white rounded-xl shadow-sm p-6">
    <p class="text-sm text-gray-500 mb-1">
        Found <strong>{{ count($columns) }}</strong> assessment column{{ count($columns) === 1 ? '' : 's' }}
        and <strong>{{ $rowCount }}</strong> student row{{ $rowCount === 1 ? '' : 's' }} in the uploaded file.
    </p>
    <p class="text-sm text-gray-500 mb-6">
        Review the detected classification for each column below and set its maximum score.
        <span class="text-orange-600 font-medium">Columns marked "Unclassified" could not be guessed automatically — you must choose one before importing.</span>
    </p>

    <form method="POST" action="{{ route('adviser.assessments.import') }}">
        @csrf
        <input type="hidden" name="subject_id" value="{{ $subject->id }}">
        <input type="hidden" name="grading_period" value="{{ $gradingPeriod }}">
        <input type="hidden" name="stored_filename" value="{{ $storedFilename }}">
        <input type="hidden" name="original_filename" value="{{ $originalName }}">

        <table class="w-full text-sm mb-6">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left px-4 py-3">Column (from file)</th>
                    <th class="text-left px-4 py-3">Classify as</th>
                    <th class="text-left px-4 py-3">Max Score</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($columns as $i => $col)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800">
                        {{ $col['name'] }}
                        <input type="hidden" name="columns[{{ $i }}][name]" value="{{ $col['name'] }}">
                    </td>
                    <td class="px-4 py-3">
                        <select name="columns[{{ $i }}][component]" required
                                class="border rounded-lg text-sm px-3 py-1.5 {{ $col['guessed_component'] ? '' : 'border-orange-400 bg-orange-50' }}">
                            <option value="" disabled {{ $col['guessed_component'] ? '' : 'selected' }}>
                                {{ $col['guessed_component'] ? '' : 'Unclassified — choose one' }}
                            </option>
                            <option value="written_work" {{ $col['guessed_component'] === 'written_work' ? 'selected' : '' }}>Written Work</option>
                            <option value="performance_task" {{ $col['guessed_component'] === 'performance_task' ? 'selected' : '' }}>Performance Task</option>
                            <option value="examination" {{ $col['guessed_component'] === 'examination' ? 'selected' : '' }}>Examination</option>
                        </select>
                    </td>
                    <td class="px-4 py-3">
                        <input type="number" name="columns[{{ $i }}][max_score]" min="0.01" step="0.01" required
                               placeholder="e.g. 20"
                               class="w-24 border rounded-lg px-3 py-1.5 text-sm">
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="flex justify-end gap-3">
            <a href="{{ route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id]) }}"
               class="px-4 py-2 text-sm text-gray-500">Cancel</a>
            <button type="submit"
                    class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                <i class="bi bi-check-circle"></i> Confirm & Import
            </button>
        </div>
    </form>
</div>

@endsection
