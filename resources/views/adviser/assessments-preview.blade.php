@extends('layouts.app')

@section('title', 'Preview Import')
@section('subtitle', $subject->name . ' — Term ' . $gradingPeriod . ' — nothing is saved yet')

@section('content')

{{-- TASK 3b of "dashboard structure and upload safeguards" — a signal,
     never a block: zero LRN matches is almost certainly the wrong file
     or wrong section, but the adviser may have a good reason. Dismissible,
     and Confirm & Import below stays enabled either way. --}}
{{-- Final pre-demo audit (2026-09-20) — a re-upload updates existing
     items and REPLACES recorded scores; say so before the adviser confirms. --}}
@if($existingItemNames->isNotEmpty())
<div id="existingItemsNotice" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4 rounded-lg mb-4 flex items-start justify-between gap-3" data-existing-items-notice>
    <div>
        <p class="font-medium"><i class="bi bi-exclamation-triangle-fill"></i> {{ $existingItemNames->count() }} of these {{ count($columns) }} assessment {{ count($columns) === 1 ? 'item is' : 'items are' }} already recorded for {{ $subject->name }}, Term {{ $gradingPeriod }}</p>
        <p class="mt-1">{{ $existingItemNames->implode(', ') }}</p>
        <p class="mt-1">
            Importing will update {{ $existingItemNames->count() === 1 ? 'that item' : 'those items' }} and <strong>replace each learner's recorded score with the value in this file</strong>.
            A learner whose cell is blank in this file keeps the score already recorded. If this is not a corrected re-upload, cancel now.
        </p>
    </div>
    <button type="button" onclick="document.getElementById('existingItemsNotice').remove()" aria-label="Dismiss" class="shrink-0 text-amber-500 hover:text-amber-700">
        <i class="bi bi-x-lg"></i>
    </button>
</div>
@endif

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

<div class="card p-6 mb-4">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs text-gray-500">Rows in file</p>
            <p class="text-xl font-bold text-ink">{{ $preview['total_rows'] }}</p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs text-gray-500">Matched to a student</p>
            <p class="text-xl font-bold text-ink">{{ $preview['matched_rows'] }}</p>
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
        <div class="alert alert-warning mb-2">
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

    <div class="tbl-scroll">
        <table class="tbl">
            <thead>
                <tr>
                    <th scope="col">Row</th>
                    <th scope="col">LRN</th>
                    <th scope="col">Student</th>
                    @foreach($columns as $col)
                        <th scope="col" class="text-center">{{ $col['name'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($preview['rows'] as $row)
                <tr class="{{ !$row['matched'] || $row['duplicate'] ? 'bg-red-50' : '' }}">
                    <td class="text-gray-400">{{ $row['excel_row'] }}</td>
                    <td class="font-mono text-xs">{{ $row['lrn'] }}</td>
                    <td>
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
                        <td class="text-center">
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
                    <td colspan="{{ count($columns) + 3 }}">
                        <x-empty-state icon="bi-file-earmark-excel" message="No data rows found in this file."
                            hint="Check the file has a header row followed by student rows, then re-upload." class="py-6 text-sm" />
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<form method="POST" action="{{ route('adviser.assessments.import') }}" data-loading="Importing E-Class Record...">
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
           class="px-4 py-2 text-sm text-muted">Cancel</a>
        <button type="submit"
                class="btn btn-primary">
            <i class="bi bi-check-circle"></i> Confirm & Import {{ $preview['total_valid_cells'] }} Score(s)
        </button>
    </div>
</form>

@endsection
