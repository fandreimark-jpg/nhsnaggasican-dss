@extends('layouts.app')

@section('title', 'Advisory Overview')
@section('subtitle', $section ? 'Grade ' . $section->grade_level . ' ' . $section->name . ' — Term ' . $term . ' — ' . $section->school_year : 'No advisory section')

@section('content')
<form method="GET" action="{{ route('adviser.advisory-overview') }}" class="card mb-4" data-no-loading>
    <div class="card-body flex flex-wrap items-end gap-3">
        <div>
            <label for="aoYear" class="form-label">School Year</label>
            <select id="aoYear" name="school_year" class="form-select" onchange="this.form.querySelector('[name=section_id]')?.setAttribute('disabled','disabled'); this.form.submit()">
                @foreach($years as $y)
                    <option value="{{ $y }}" @selected($y === $schoolYear)>{{ $y }}{{ $y === \App\Models\Section::activeSchoolYear() ? ' (active)' : '' }}</option>
                @endforeach
            </select>
        </div>
        @if($sections->count() > 1)
        <div>
            <label for="aoSection" class="form-label">Section</label>
            <select id="aoSection" name="section_id" class="form-select" onchange="this.form.submit()">
                @foreach($sections as $s)<option value="{{ $s->id }}" @selected($section && $s->id === $section->id)>{{ $s->name }}</option>@endforeach
            </select>
        </div>
        @endif
        <div>
            <label for="aoTerm" class="form-label">Term</label>
            <select id="aoTerm" name="period" class="form-select" onchange="this.form.submit()">
                @foreach($terms as $t)<option value="{{ $t }}" @selected($t === $term)>Term {{ $t }}</option>@endforeach
            </select>
        </div>
        <noscript><button class="btn btn-outline btn-sm">Show</button></noscript>
    </div>
</form>

@if(!$section)
    <div class="card"><x-empty-state icon="bi-people" message="You have no advisory section." hint="An Admin assigns advisers to sections." /></div>
@else
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
    <x-stat-card label="Learners" :value="$summary['learners']" accent="count-1" icon="people" />
    <x-stat-card label="Learners For Review" :value="$summary['for_review']" accent="status-attention" icon="flag" note="Distinct learners with at least one open concern" />
    <x-stat-card label="Open Concerns" :value="$summary['open_concerns']" accent="count-3" icon="list-check" note="One learner can have several" />
</div>

@if($subjects->isNotEmpty())
<div class="card mb-4" data-subject-record-links>
    <div class="card-body flex flex-wrap items-center gap-2 text-sm">
        <span class="text-muted mr-1">Subject evidence (Class Record):</span>
        @foreach($subjects as $subject)
            <a class="btn btn-outline btn-xs" href="{{ route('teacher.class-record', ['section_id' => $section->id, 'subject_id' => $subject->id, 'period' => $term]) }}">{{ $subject->name }}</a>
        @endforeach
        <a class="btn btn-ghost btn-xs ml-auto" href="{{ route('adviser.verify-grades', ['period' => $term]) }}"><i class="bi bi-patch-check" aria-hidden="true"></i> Verify Grades</a>
    </div>
</div>
@endif

<div class="alert alert-info mb-4 text-sm">
    <strong>How to read this.</strong> Each subject is judged on its own: one failed official subject makes the learner
    <strong>For Review</strong> however high the others are. <strong>No Current Concern</strong> means no open concern right now — it does
    not mean the learner passed or that all evidence is in; see the Evidence column. Provisional figures are computed from recorded
    scores and are not official grades. The 75% reference is informational only.
</div>

<div class="card">
    <div class="tbl-scroll">
        <table class="tbl tbl-sticky" data-advisory-overview>
            <thead>
                <tr>
                    <th scope="col">Learner</th>
                    <th scope="col">Subject results (Term {{ $term }})</th>
                    <th scope="col" class="tbl-num">Official Passed / Failed</th>
                    <th scope="col">Monitoring</th>
                    <th scope="col">Evidence</th>
                    <th scope="col">Intervention follow-up</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr data-learner-row="{{ $row['student']->id }}">
                    <th scope="row" class="font-medium text-ink align-top">
                        {{ $row['student']->last_name }}, {{ $row['student']->first_name }}
                        <span class="block text-[10px] text-muted font-normal">{{ $row['student']->lrn }}</span>
                        <a class="text-xs text-brand-700 underline font-normal" href="{{ route('teacher.learners.report', ['student' => $row['student']->id, 'school_year' => $section->school_year, 'period' => $term]) }}">Learner report</a>
                    </th>
                    <td class="align-top">
                        <ul class="space-y-0.5 text-xs">
                        @foreach($subjects as $subject)
                            @php $r = $row['results'][$subject->id] ?? null; @endphp
                            <li data-subject-result="{{ $r['state'] ?? 'none' }}">
                                <span class="text-ink">{{ $subject->name }}:</span>
                                @if($r && $r['grade'] !== null)<span class="font-semibold {{ $r['state'] === 'official_failed' ? 'text-status-failing' : '' }}">{{ number_format($r['grade'], 2) }}</span>@endif
                                <span class="{{ $r && $r['state'] === 'official_failed' ? 'text-status-failing font-semibold' : 'text-muted' }}">{{ $r['label'] ?? '—' }}</span>
                            </li>
                        @endforeach
                        </ul>
                    </td>
                    <td class="tbl-num align-top">
                        {{ $row['official']['passed'] }} / <span class="{{ $row['official']['failed'] ? 'text-status-failing font-semibold' : '' }}">{{ $row['official']['failed'] }}</span>
                        @if($row['official']['not_official'])<span class="block text-[10px] text-muted">{{ $row['official']['not_official'] }} not yet official</span>@endif
                        @if($row['official']['stale'])<span class="block text-[10px] font-medium" data-stale-official>{{ $row['official']['stale'] }} evidence changed — re-verify</span>@endif
                    </td>
                    <td class="align-top"><x-ui.monitoring-status :status="$row['monitoring']" /></td>
                    <td class="align-top text-xs">
                        <span class="font-medium">{{ $row['completeness']['label'] }}</span>
                        <span class="block text-[10px] text-muted">{{ $row['completeness']['official'] }} of {{ $row['completeness']['total'] }} official · {{ $row['completeness']['with_evidence'] }} with evidence</span>
                    </td>
                    <td class="align-top text-xs">
                        @forelse($row['interventions'] as $i)
                            <span class="block">{{ \App\Models\Intervention::typeLabel($i->recommended_type) }} — {{ $i->subject?->name ?? 'no subject' }}:
                                <span class="text-muted">{{ \App\Services\LearnerResultsService::followUp($i) }}</span>
                                {{-- Intervention schedule state — separate from the learner's academic classification (left). --}}
                                @if(\App\Models\Intervention::schedulingAvailable() && ($i->isScheduled() || in_array($i->status, ['expired', 'cancelled'], true)))
                                    <a href="{{ route('adviser.interventions.show', $i) }}" class="inline-flex items-center gap-1 mt-0.5" data-advisory-intervention-state>
                                        @include('partials.intervention-lifecycle-badge', ['iv' => $i])
                                        @if($i->deadline_at && in_array($i->lifecycle(), ['scheduled', 'ongoing'], true))<span class="text-[10px] text-muted">due {{ \App\Services\InterventionScheduleService::fmt($i->deadline_at) }}</span>@endif
                                    </a>
                                @endif</span>
                        @empty
                            <span class="text-muted">None this term</span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty-state icon="bi-people" message="No learners are enrolled in {{ $section->name }} for {{ $section->school_year }}." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
