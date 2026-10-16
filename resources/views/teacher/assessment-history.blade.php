@extends('layouts.app')

@section('title', 'Assessment History')
@section('subtitle', $assessment->name . ' — ' . $assessment->subject->name . ' — ' . $section->name . ' — Term ' . $assessment->grading_period . ' — ' . $section->school_year)

@section('content')
<div class="mb-4">
    <a href="{{ route('teacher.class-record', ['section_id' => $section->id, 'subject_id' => $assessment->subject_id, 'period' => $assessment->grading_period]) }}" class="btn btn-ghost btn-sm">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to the Class Record
    </a>
</div>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Evidence change history</h2>
        <p class="card-subtitle">Every score recorded, changed or cleared and every Missing marker set or removed on this assessment, newest first. Recorded automatically; nothing here can be edited.</p>
    </div>
    <div class="tbl-scroll">
        <table class="tbl tbl-sticky">
            <thead><tr><th>When</th><th>Learner</th><th>Before</th><th>After</th><th>By</th><th>Source</th><th>Reason</th></tr></thead>
            <tbody>
            @forelse($audits as $a)
                @php
                    $st = $students->get($a->student_id);
                    $before = $a->old_status === 'completed' ? \App\Services\ClassRecordService::fmt((float) $a->old_score) : \App\Services\AssessmentStatusService::label($a->old_status);
                    $after = $a->new_status === 'completed' ? \App\Services\ClassRecordService::fmt((float) $a->new_score) : \App\Services\AssessmentStatusService::label($a->new_status);
                @endphp
                <tr>
                    <td class="whitespace-nowrap">{{ $a->changed_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $st ? $st->last_name . ', ' . $st->first_name : 'Learner #' . $a->student_id }}</td>
                    <td>{{ $before }}</td>
                    <td>{{ $after }}</td>
                    <td>{{ $a->changedBy?->full_name ?? 'System' }}</td>
                    <td class="text-xs text-muted">{{ $a->source ?? '—' }}</td>
                    <td class="text-xs">{{ $a->reason ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty-state icon="bi-clock-history" message="No changes have been recorded for this assessment since change history began." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
