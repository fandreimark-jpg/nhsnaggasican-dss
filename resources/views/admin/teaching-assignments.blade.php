@extends('layouts.app')

@section('title', 'Teaching Assignments')
@section('subtitle', 'Who teaches which subject in which section — School Year ' . $schoolYear)

@section('content')
@include('partials.validation-errors')
@if(session('success'))<div class="alert alert-success mb-4" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger mb-4" role="alert">{{ session('error') }}</div>@endif

<div class="alert alert-info mb-4 text-sm">
    A teaching assignment is what allows a teacher to encode and edit assessment scores for a subject in a section and term.
    Advising a section lets the adviser <strong>view</strong> every subject of that section, but <strong>not edit</strong> a subject
    they are not assigned to. One teacher per subject, section and term; an Adviser account can also be assigned to teach.
</div>

<form method="GET" action="{{ route('admin.teaching-assignments') }}" class="card mb-4" data-no-loading>
    <div class="card-body flex flex-wrap items-end gap-3">
        <div>
            <label for="taYear" class="form-label">School Year</label>
            <select id="taYear" name="school_year" class="form-select" onchange="this.form.submit()">
                @foreach($years as $y)
                    <option value="{{ $y }}" @selected($y === $schoolYear)>{{ $y }}{{ $y === \App\Models\Section::activeSchoolYear() ? ' (active)' : '' }}</option>
                @endforeach
            </select>
        </div>
        @unless($editable)
            <span class="badge badge-gray"><i class="bi bi-archive"></i> Historical — read-only</span>
        @endunless
    </div>
</form>

@if($editable)
<div class="card mb-4">
    <div class="card-header"><h2 class="card-title">Assign a teacher</h2></div>
    <form method="POST" action="{{ route('admin.teaching-assignments.store') }}" class="card-body grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4" data-loading="Saving assignment…">
        @csrf
        <div>
            <label for="taTeacher" class="form-label">Teacher</label>
            <select id="taTeacher" name="user_id" class="form-select" required>
                <option value="">— Select —</option>
                @foreach($teachers as $t)
                    <option value="{{ $t->id }}" @selected((int) old('user_id') === $t->id)>{{ $t->full_name }} ({{ $t->isAdviser() ? 'Adviser' : 'Subject Teacher' }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="taSection" class="form-label">Section ({{ $schoolYear }})</label>
            <select id="taSection" name="section_id" class="form-select" required>
                <option value="">— Select —</option>
                @foreach($sections as $s)
                    <option value="{{ $s->id }}" data-grade="{{ $s->grade_level }}" @selected((int) old('section_id') === $s->id)>Grade {{ $s->grade_level }} — {{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="taSubject" class="form-label">Subject</label>
            <select id="taSubject" name="subject_id" class="form-select" required>
                <option value="">— Select —</option>
                @foreach($subjects as $sub)
                    <option value="{{ $sub->id }}" data-grade="{{ $sub->grade_level }}" @selected((int) old('subject_id') === $sub->id)>Grade {{ $sub->grade_level }} — {{ $sub->name }}</option>
                @endforeach
            </select>
            <p class="form-help">The subject must be applicable to the section in each chosen term.</p>
        </div>
        <fieldset>
            <legend class="form-label">Terms</legend>
            <div class="flex flex-wrap gap-3">
                @foreach($terms as $t)
                    <label class="inline-flex items-center gap-1.5 text-sm">
                        <input type="checkbox" name="terms[]" value="{{ $t }}" @checked(in_array((string) $t, (array) old('terms', array_map('strval', $terms)), true)) class="rounded border-gray-300 text-brand-700">
                        Term {{ $t }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="md:col-span-2 lg:col-span-4 flex justify-end">
            <button type="submit" class="btn btn-primary"><i class="bi bi-person-check" aria-hidden="true"></i> Assign</button>
        </div>
    </form>
</div>
@endif

@if($unassigned->isNotEmpty())
<div class="card mb-4 border border-amber-200">
    <div class="card-header">
        <h2 class="card-title">Recorded evidence with no teaching assignment</h2>
        <p class="card-subtitle">These already hold assessment items but nobody is assigned to them, so nobody can edit them. Assign the responsible teacher above. The "entered by" names are shown for reference only — they are not used to assign anyone automatically.</p>
    </div>
    <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>Section</th><th>Subject</th><th>Term</th><th class="tbl-num">Items</th><th>Entered by</th></tr></thead>
            <tbody>
                @foreach($unassigned as $u)
                    <tr data-unassigned-row>
                        <td>{{ $u['section']?->name ?? '—' }}</td>
                        <td>{{ $u['subject']?->name ?? '—' }}</td>
                        <td>Term {{ $u['term'] }}</td>
                        <td class="tbl-num">{{ $u['items'] }}</td>
                        <td class="text-xs">{{ implode(', ', $u['uploaders']) ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header"><h2 class="card-title">Assignments — {{ $schoolYear }}</h2></div>
    <div class="tbl-scroll">
        <table class="tbl tbl-sticky">
            <thead><tr><th>Section</th><th>Adviser</th><th>Subject</th><th>Term</th><th>Teacher</th><th>Assigned by</th>@if($editable)<th class="text-right">Action</th>@endif</tr></thead>
            <tbody>
            @forelse($assignments as $a)
                <tr>
                    <td>Grade {{ $a->section->grade_level }} — {{ $a->section->name }}</td>
                    <td class="text-xs text-muted">{{ $a->section->adviser?->full_name ?? '—' }}</td>
                    <td class="font-medium text-ink">{{ $a->subject->name }}</td>
                    <td>Term {{ $a->grading_period }}</td>
                    <td>{{ $a->teacher->full_name }} <span class="text-xs text-muted">({{ $a->teacher->isAdviser() ? 'Adviser' : 'Subject Teacher' }})</span></td>
                    <td class="text-xs text-muted">{{ $a->assignedBy?->full_name ?? '—' }}</td>
                    @if($editable)
                    <td class="text-right whitespace-nowrap">
                        <a href="{{ route('admin.teaching-assignments.edit', $a->id) }}" class="btn btn-secondary btn-xs" data-edit-assignment>Edit / Change Teacher</a>
                        <details class="inline-block text-left align-top">
                            <summary class="btn btn-danger-outline btn-xs cursor-pointer list-none">Remove</summary>
                            <form method="POST" action="{{ route('admin.teaching-assignments.destroy', $a->id) }}" class="mt-2 w-72 space-y-2 bg-white border border-line rounded-lg p-3 shadow-card">
                                @csrf @method('DELETE')
                                <p class="text-xs text-muted">Removing ends {{ $a->teacher->full_name }}'s responsibility for this class. Scores and assessments are never deleted, but nobody can encode this class until another teacher is assigned. To replace the teacher, use Edit / Change Teacher instead.</p>
                                <label class="form-label text-xs" for="rmReason{{ $a->id }}">Reason</label>
                                <input id="rmReason{{ $a->id }}" name="reason" required minlength="5" maxlength="500" class="form-input text-sm">
                                <button type="submit" class="btn btn-danger btn-xs">Remove assignment</button>
                            </form>
                        </details>
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7"><x-empty-state icon="bi-person-workspace" message="No teaching assignments recorded for {{ $schoolYear }}." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="card mt-4">
    <div class="card-header"><h2 class="card-title">Assignment history — {{ $schoolYear }}</h2>
        <p class="card-subtitle">Every assignment, teacher change, correction and removal, newest first. Kept permanently.</p></div>
    <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>When</th><th>Class</th><th>Change</th><th>From</th><th>To</th><th>By</th><th>Reason</th></tr></thead>
            <tbody>
            @forelse($history as $h)
                <tr data-history-row>
                    <td class="whitespace-nowrap">{{ $h->changed_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $h->subject?->name }} — {{ $h->section?->name }}, Term {{ $h->grading_period }}</td>
                    <td>{{ \App\Models\TeachingAssignmentHistory::LABELS[$h->action] ?? $h->action }}</td>
                    <td>{{ $h->oldTeacher?->full_name ?? '—' }}</td>
                    <td>{{ $h->newTeacher?->full_name ?? '—' }}</td>
                    <td>{{ $h->changedBy?->full_name ?? '—' }}</td>
                    <td class="text-xs">{{ $h->reason ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted">No assignment changes recorded for {{ $schoolYear }}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
