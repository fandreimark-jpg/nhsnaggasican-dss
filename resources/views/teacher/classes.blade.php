@extends('layouts.app')

@section('title', 'My Classes')
@section('subtitle', $schoolYear ? 'School Year ' . $schoolYear : 'No classes assigned')

@section('content')
@if(count($schoolYears) > 1)
<form method="GET" action="{{ route('teacher.classes') }}" class="card mb-4" data-no-loading>
    <div class="card-body flex flex-wrap items-end gap-3">
        <div>
            <label for="mcYear" class="form-label">School Year</label>
            <select id="mcYear" name="school_year" class="form-select" onchange="this.form.submit()">
                @foreach($schoolYears as $y)
                    <option value="{{ $y }}" @selected($y === $schoolYear)>{{ $y }}{{ $y === \App\Models\Section::activeSchoolYear() ? ' (active)' : '' }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button class="btn btn-outline btn-sm">Show</button></noscript>
    </div>
</form>
@endif

@if($advisory->isNotEmpty())
<div class="card mb-4">
    <div class="card-header"><h2 class="card-title">Advisory</h2>
        <p class="card-subtitle">You can view every subject of your advisory section. You can encode only the subjects you are assigned to teach.</p></div>
    <div class="card-body flex flex-wrap gap-2">
        @foreach($advisory as $s)
            <a class="btn btn-secondary btn-sm" href="{{ route('teacher.class-record', ['section_id' => $s->id, 'period' => $openTerm ?? 1]) }}">
                <i class="bi bi-journal-text" aria-hidden="true"></i> Grade {{ $s->grade_level }} — {{ $s->name }} (all subjects)
            </a>
            <a class="btn btn-outline btn-sm" href="{{ route('adviser.advisory-overview', ['school_year' => $s->school_year]) }}">
                <i class="bi bi-people" aria-hidden="true"></i> Advisory Overview
            </a>
        @endforeach
    </div>
</div>
@endif

<div class="card">
    <div class="card-header"><h2 class="card-title">Subjects I teach</h2>
        <p class="card-subtitle">Recorded by the Admin (Teaching Assignments). You can encode scores only in these.</p></div>
    <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>Section</th><th>Subject</th><th>Terms</th><th class="text-right">Open</th></tr></thead>
            <tbody>
            @forelse($classes as $c)
                <tr>
                    <td>Grade {{ $c['section']->grade_level }} — {{ $c['section']->name }}</td>
                    <td class="font-medium text-ink">{{ $c['subject']->name }}</td>
                    <td>{{ implode(', ', array_map(fn($t) => 'Term ' . $t, $c['terms'])) }}</td>
                    <td class="text-right whitespace-nowrap">
                        @foreach($c['terms'] as $t)
                            <a class="btn btn-outline btn-xs" href="{{ route('teacher.class-record', ['section_id' => $c['section']->id, 'subject_id' => $c['subject']->id, 'period' => $t]) }}">
                                Term {{ $t }}@if($openTerm === $t && $c['section']->isInActiveSchoolYear()) (open)@endif
                            </a>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="4"><x-empty-state icon="bi-journal-x" message="No teaching assignments for this school year."
                    hint="Ask the Admin to record which subjects you teach (Admin > Teaching Assignments)." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
