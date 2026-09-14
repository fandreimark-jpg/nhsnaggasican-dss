@extends('layouts.app')

@section('title', 'Import Learners from ECR — Preview')
@section('subtitle', $originalName . ' — Section ' . $section->name . ' (Grade ' . $section->grade_level . ')')

@section('content')

<div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm p-3 rounded-lg mb-4">
    <i class="bi bi-file-earmark-check"></i> Detected Format:
    <strong>{{ $format === 'sshs' ? 'Strengthened SHS E-Class Record' : 'Grade 12 Class Record' }}</strong>
</div>

<div class="card p-4 mb-4">
    <h3 class="card-title mb-2">Section &amp; Year Match</h3>
    <table class="text-sm">
        <tr>
            <td class="pr-4 text-gray-500">Uploaded:</td>
            <td class="font-medium">
                {{ $fileGradeLevel ? 'Grade ' . $fileGradeLevel : 'Grade —' }} / {{ $fileSectionName ?: '—' }}
                @if($fileSchoolYear) &middot; SY {{ $fileSchoolYear }} @endif
            </td>
        </tr>
        <tr>
            <td class="pr-4 text-gray-500">System (selected):</td>
            <td class="font-medium">Grade {{ $section->grade_level }} / {{ $section->name }} &middot; SY {{ $section->school_year }}</td>
        </tr>
        <tr>
            <td class="pr-4 text-gray-500">Matched:</td>
            <td>
                @if($sectionMatch['matched'])
                    <span class="text-status-ontrack font-medium"><i class="bi bi-check-circle-fill"></i> YES</span>
                @else
                    <span class="text-status-failing font-medium"><i class="bi bi-x-circle-fill"></i> NO — review before confirming</span>
                @endif
                @if($yearMatch === false)
                    <span class="block text-status-failing text-xs mt-1">School year in the file ({{ $fileSchoolYear }}) does not match the selected section's school year ({{ $section->school_year }}).</span>
                @endif
            </td>
        </tr>
    </table>
</div>

<div class="card p-4 mb-4">
    <h3 class="card-title mb-2">Summary</h3>
    <div class="flex flex-wrap gap-4 text-sm">
        <span class="text-status-ontrack font-medium">{{ $counts['insert'] }} to Insert</span>
        <span class="text-gray-500 font-medium">{{ $counts['existing'] }} Existing (no change)</span>
        <span class="text-status-attention font-medium">{{ $counts['conflict'] }} Conflict (skipped)</span>
        <span class="text-status-failing font-medium">{{ $counts['rejected'] }} Rejected (skipped)</span>
    </div>
</div>

<div class="card">
    <div class="px-5 py-4 border-b border-line">
        <h3 class="card-title">Row-by-Row Preview</h3>
        <p class="text-xs text-muted">Nothing has been saved yet. Only rows marked Insert will be written to the database when you confirm.</p>
    </div>
    <div class="tbl-scroll">
    <table class="tbl tbl-sticky">
        <thead>
            <tr>
                <th scope="col">Status</th>
                <th scope="col">LRN</th>
                <th scope="col">Last Name</th>
                <th scope="col">First Name</th>
                <th scope="col">Reason</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr>
                <td>
                    @php
                        $statusColors = [
                            'insert' => 'bg-status-ontrack/10 text-status-ontrack',
                            'existing' => 'bg-gray-100 text-gray-600',
                            'conflict' => 'bg-status-attention/10 text-status-attention',
                            'rejected' => 'bg-status-failing/10 text-status-failing',
                        ];
                    @endphp
                    <span class="badge {{ $statusColors[$row['status']] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ ucfirst($row['status']) }}
                    </span>
                </td>
                <td>{{ $row['lrn'] ?: '—' }}</td>
                <td class="font-medium text-ink">{{ $row['last_name'] }}</td>
                <td>{{ $row['first_name'] }}</td>
                <td class="text-xs text-gray-500">{{ $row['reason'] ?? '' }}</td>
            </tr>
            @empty
            <tr><td colspan="5"><x-empty-state icon="bi-people" message="No learner rows found in this file." /></td></tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <div class="px-5 py-4 border-t border-line flex justify-end gap-3">
        <a href="{{ route('admin.students') }}" class="btn btn-outline">Cancel</a>
        <form method="POST" action="{{ route('admin.students.import-from-ecr.confirm') }}"
              data-import-confirm="Insert {{ $counts['insert'] }} new learner(s) into {{ $section->name }}? Existing, Conflict, and Rejected rows will be skipped and will not be modified.">
            @csrf
            <button type="submit" {{ $counts['insert'] === 0 ? 'disabled' : '' }}
                    class="btn btn-primary disabled:opacity-50 disabled:cursor-not-allowed">
                Confirm — Insert {{ $counts['insert'] }} Learner(s)
            </button>
        </form>
    </div>
</div>

@endsection
