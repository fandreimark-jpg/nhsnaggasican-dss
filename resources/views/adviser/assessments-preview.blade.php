@extends('layouts.app')

@section('title', 'Preview Import')
@section('subtitle', $subject->name . ' — Term ' . $gradingPeriod . ' — nothing is saved yet')

@section('content')

{{-- TASK 3b of "dashboard structure and upload safeguards" — a signal,
     never a block: zero LRN matches is almost certainly the wrong file
     or wrong section, but the adviser may have a good reason. Dismissible,
     and Confirm & Import below stays enabled either way. --}}
@if($rosterMismatch)
<div id="rosterMismatchNotice" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4 rounded-lg mb-4 flex items-start justify-between gap-3">
    <div>
        <p class="font-medium"><i class="bi bi-exclamation-triangle-fill"></i> None of the LRNs in this file matched a student in your section</p>
        <p class="mt-1">
            This file may belong to another section, or the wrong file may have been uploaded. Every row below will
            be skipped as unmatched unless this is corrected.
        </p>
    </div>
    <button type="button" onclick="document.getElementById('rosterMismatchNotice').remove()" aria-label="Dismiss" class="shrink-0 text-amber-500 hover:text-amber-700">
        <i class="bi bi-x-lg"></i>
    </button>
</div>
@endif

<div class="bg-white rounded-xl shadow-sm p-6 mb-4">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs text-gray-500">Rows in file</p>
            <p class="text-xl font-bold text-gray-800">{{ $preview['total_rows'] }}</p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs text-gray-500">Matched to a student</p>
            <p class="text-xl font-bold text-gray-800">{{ $preview['matched_rows'] }}</p>
        </div>
        <div class="bg-green-50 rounded-lg p-3">
            <p class="text-xs text-gray-500">Valid scores</p>
            <p class="text-xl font-bold text-green-700">{{ $preview['total_valid_cells'] }}</p>
        </div>
        <div class="bg-red-50 rounded-lg p-3">
            <p class="text-xs text-gray-500">Will be skipped</p>
            <p class="text-xl font-bold text-red-600">{{ $preview['total_invalid_cells'] }}</p>
        </div>
    </div>

    @if($preview['total_invalid_cells'] > 0)
        <p class="text-sm text-orange-600 mb-4">
            <i class="bi bi-exclamation-triangle"></i>
            Rows/cells marked below will be skipped and reported after import — nothing invalid gets saved silently.
        </p>
    @endif

    @foreach($preview['column_stats'] ?? [] as $colName => $stat)
        @if($stat['suspicious_max'])
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 text-sm p-3 rounded-lg mb-2">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>{{ $colName }}</strong> — the highest score in this file is
            {{ rtrim(rtrim(number_format($stat['highest'], 2), '0'), '.') }}
            out of a declared maximum of
            {{ rtrim(rtrim(number_format($stat['max_score'], 2), '0'), '.') }}
            ({{ number_format($stat['highest'] / $stat['max_score'] * 100, 0) }}%).
            Check that the maximum score is correct before importing.
        </div>
        @endif
    @endforeach

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left px-4 py-2">Row</th>
                    <th class="text-left px-4 py-2">LRN</th>
                    <th class="text-left px-4 py-2">Student</th>
                    @foreach($columns as $col)
                        <th class="text-center px-4 py-2">{{ $col['name'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($preview['rows'] as $row)
                <tr class="{{ !$row['matched'] || $row['duplicate'] ? 'bg-red-50' : '' }}">
                    <td class="px-4 py-2 text-gray-400">{{ $row['excel_row'] }}</td>
                    <td class="px-4 py-2 font-mono text-xs">{{ $row['lrn'] }}</td>
                    <td class="px-4 py-2">
                        @if($row['student_name'])
                            {{ $row['student_name'] }}
                            @if($row['duplicate'])
                                <span class="text-xs text-red-500 block">Duplicate LRN — skipped</span>
                            @endif
                        @else
                            <span class="text-xs text-red-500">No matching student</span>
                        @endif
                    </td>
                    @foreach($row['cells'] as $cell)
                        <td class="px-4 py-2 text-center">
                            @if($cell['status'] === 'ok')
                                <span class="text-green-700 font-medium">{{ $cell['value'] }}</span>
                            @elseif($cell['status'] === 'blank')
                                <span class="text-gray-300">—</span>
                            @else
                                <span class="text-red-600" title="{{ $cell['message'] }}">
                                    {{ $cell['value'] }} <i class="bi bi-x-circle"></i>
                                </span>
                            @endif
                        </td>
                    @endforeach
                </tr>
                @empty
                <tr>
                    <td colspan="{{ count($columns) + 3 }}" class="px-4 py-6 text-center text-gray-400">
                        No data rows found in this file.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<form method="POST" action="{{ route('adviser.assessments.import') }}">
    @csrf
    <input type="hidden" name="subject_id" value="{{ $subject->id }}">
    <input type="hidden" name="grading_period" value="{{ $gradingPeriod }}">
    <input type="hidden" name="stored_filename" value="{{ $storedFilename }}">
    <input type="hidden" name="original_filename" value="{{ $originalName }}">
    @foreach($columns as $i => $col)
        <input type="hidden" name="columns[{{ $i }}][name]" value="{{ $col['name'] }}">
        <input type="hidden" name="columns[{{ $i }}][component]" value="{{ $col['component'] }}">
        <input type="hidden" name="columns[{{ $i }}][exam_role]" value="{{ $col['exam_role'] ?? '' }}">
        <input type="hidden" name="columns[{{ $i }}][is_additional_support]" value="{{ !empty($col['is_additional_support']) ? '1' : '' }}">
        <input type="hidden" name="columns[{{ $i }}][max_score]" value="{{ $col['max_score'] }}">
    @endforeach

    <div class="flex justify-end gap-3">
        <a href="{{ route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id]) }}"
           class="px-4 py-2 text-sm text-gray-500">Cancel</a>
        <button type="submit"
                class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
            <i class="bi bi-check-circle"></i> Confirm & Import {{ $preview['total_valid_cells'] }} Score(s)
        </button>
    </div>
</form>

@endsection
