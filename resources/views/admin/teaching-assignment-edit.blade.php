@extends('layouts.app')

@section('title', 'Edit Teaching Assignment')
@section('subtitle', $assignment->subject->name . ' — ' . $assignment->section->name . ' — Term ' . $assignment->grading_period . ' — ' . $assignment->school_year)

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.teaching-assignments', ['school_year' => $assignment->school_year]) }}" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Teaching Assignments</a>
</div>

@if(session('error'))<div class="alert alert-danger mb-4" role="alert">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger mb-4" role="alert">
        <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="card mb-4">
    <div class="card-header"><h2 class="card-title">Current assignment</h2></div>
    <div class="card-body grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
        <div><span class="form-label">School Year</span><p class="form-readonly">{{ $assignment->school_year }}</p></div>
        <div><span class="form-label">Section</span><p class="form-readonly">Grade {{ $assignment->section->grade_level }} — {{ $assignment->section->name }}</p></div>
        <div><span class="form-label">Subject</span><p class="form-readonly">{{ $assignment->subject->name }}</p></div>
        <div><span class="form-label">Term</span><p class="form-readonly">Term {{ $assignment->grading_period }}</p></div>
        <div><span class="form-label">Current teacher</span><p class="form-readonly" data-current-teacher>{{ $assignment->teacher->full_name }}</p></div>
        <div><span class="form-label">Assessment evidence</span>
            <p class="form-readonly" data-evidence-count="{{ $evidence }}">{{ $evidence > 0 ? $evidence . ' assessment(s) recorded' : 'None recorded yet' }}</p></div>
    </div>
</div>

<form method="POST" action="{{ route('admin.teaching-assignments.update', $assignment->id) }}" class="card mb-4" data-loading="Saving…">
    @csrf @method('PUT')
    <input type="hidden" name="version" value="{{ old('version', \App\Http\Controllers\Admin\TeachingAssignmentController::versionOf($assignment)) }}">
    <div class="card-header">
        <h2 class="card-title">Change teacher</h2>
        <p class="card-subtitle">The new teacher can encode this class from now on; the previous teacher loses encoding access for it. Recorded scores stay exactly as they are and keep who entered them. A closed term is not reopened.</p>
    </div>
    <div class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="taeTeacher" class="form-label">Teacher</label>
            <select id="taeTeacher" name="user_id" class="form-select" required>
                @foreach($teachers as $t)
                    <option value="{{ $t->id }}" @selected((int) old('user_id', $assignment->user_id) === $t->id)>{{ $t->full_name }} ({{ $t->isAdviser() ? 'Adviser' : 'Subject Teacher' }})</option>
                @endforeach
            </select>
        </div>
        <div class="md:col-span-2">
            <label for="taeReason" class="form-label">Reason for the change</label>
            <textarea id="taeReason" name="reason" rows="2" required minlength="5" maxlength="500" class="form-input" placeholder="e.g. Teacher reassigned by the school head on 28 Sept">{{ old('reason') }}</textarea>
            <p class="form-help">Kept in the assignment history with your name and the time.</p>
        </div>

        <fieldset class="md:col-span-2 border border-line rounded-lg p-3">
            <legend class="text-sm font-semibold px-1">Correct the class {{ $evidence > 0 ? '(locked)' : '' }}</legend>
            @if($evidence > 0)
                <p class="text-xs text-muted mb-2" data-scope-locked>This class already holds {{ $evidence }} assessment(s). Changing its section, subject or term would relabel recorded scores, so these fields are locked. Only the teacher can change.</p>
            @else
                <p class="text-xs text-muted mb-2">No evidence is recorded for this class yet, so a wrongly entered section, subject or term can be corrected here. Other terms are not changed.</p>
            @endif
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label for="taeSection" class="form-label">Section</label>
                    <select id="taeSection" name="section_id" class="form-select" @disabled($evidence > 0)>
                        @foreach($sections as $s)<option value="{{ $s->id }}" @selected((int) old('section_id', $assignment->section_id) === $s->id)>Grade {{ $s->grade_level }} — {{ $s->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="taeSubject" class="form-label">Subject</label>
                    <select id="taeSubject" name="subject_id" class="form-select" @disabled($evidence > 0)>
                        @foreach($subjects as $sub)<option value="{{ $sub->id }}" @selected((int) old('subject_id', $assignment->subject_id) === $sub->id)>Grade {{ $sub->grade_level }} — {{ $sub->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="taeTerm" class="form-label">Term</label>
                    <select id="taeTerm" name="grading_period" class="form-select" @disabled($evidence > 0)>
                        @foreach($terms as $t)<option value="{{ $t }}" @selected((int) old('grading_period', $assignment->grading_period) === $t)>Term {{ $t }}</option>@endforeach
                    </select>
                </div>
            </div>
            @if($evidence > 0)
                {{-- Disabled fields are not submitted: send the current scope unchanged. --}}
                <input type="hidden" name="section_id" value="{{ $assignment->section_id }}">
                <input type="hidden" name="subject_id" value="{{ $assignment->subject_id }}">
                <input type="hidden" name="grading_period" value="{{ $assignment->grading_period }}">
            @endif
        </fieldset>
    </div>
    <div class="card-footer flex justify-end gap-3">
        <a href="{{ route('admin.teaching-assignments', ['school_year' => $assignment->school_year]) }}" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-person-check" aria-hidden="true"></i> Save change</button>
    </div>
</form>

<div class="card">
    <div class="card-header"><h2 class="card-title">History of this assignment</h2></div>
    <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>When</th><th>Change</th><th>From</th><th>To</th><th>By</th><th>Reason</th></tr></thead>
            <tbody>
            @forelse($history as $h)
                <tr>
                    <td class="whitespace-nowrap">{{ $h->changed_at->format('Y-m-d H:i') }}</td>
                    <td>{{ \App\Models\TeachingAssignmentHistory::LABELS[$h->action] ?? $h->action }}</td>
                    <td>{{ $h->oldTeacher?->full_name ?? '—' }}</td>
                    <td>{{ $h->newTeacher?->full_name ?? '—' }}</td>
                    <td>{{ $h->changedBy?->full_name ?? '—' }}</td>
                    <td class="text-xs">{{ $h->reason ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No recorded changes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
