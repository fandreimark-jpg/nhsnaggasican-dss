{{-- "Performance by Grade and Section" (2026-10-07) — figures from
     SectionPerformanceService; see its docblock for every definition. --}}
@php
    $p = $performance;
    $filters = fn(array $extra = []) => array_filter(['school_year' => $schoolYear, 'perf_term' => $p['term'], 'perf_grade' => $p['grade'], 'perf_subject' => $p['subject']] + $extra, fn($v) => $v !== null);
    $stateLabel = ['none' => 'No verified grades yet', 'partial' => 'Partial data', 'complete' => 'All expected grades verified',
        'nothing_expected' => 'No expected grades', 'not_offered' => 'Subject not taught here this term'];
@endphp
<x-panel title="Performance by Grade and Section" class="mb-4 scroll-mt-6" id="performance-by-section"
    subtitle="Term {{ $p['term'] }}, {{ $schoolYear }}. Learner columns count each learner once; Passed/Failed count verified SUBJECT GRADES, not learners. For Review and Missing can overlap.">
    <form method="GET" action="{{ route('principal.dashboard') }}#performance-by-section" class="flex flex-wrap items-end gap-3 mb-3" data-no-loading>
        <input type="hidden" name="school_year" value="{{ $schoolYear }}">
        <div><label for="perfTerm" class="form-label">Term</label>
            <select id="perfTerm" name="perf_term" class="form-select-sm min-w-[7rem]">
                @foreach($p['terms'] as $t)<option value="{{ $t }}" @selected($p['term'] === $t)>Term {{ $t }}</option>@endforeach
            </select></div>
        <div><label for="perfGrade" class="form-label">Grade level</label>
            <select id="perfGrade" name="perf_grade" class="form-select-sm min-w-[7.5rem]">
                <option value="">All</option>
                @foreach($p['gradeLevels'] as $g)<option value="{{ $g }}" @selected($p['grade'] === $g)>Grade {{ $g }}</option>@endforeach
            </select></div>
        <div><label for="perfSubject" class="form-label">Subject</label>
            <select id="perfSubject" name="perf_subject" class="form-select-sm max-w-[16rem]">
                <option value="">All subjects</option>
                @foreach($p['subjects'] as $s)<option value="{{ $s->id }}" @selected($p['subject'] === $s->id)>{{ $s->name }} (G{{ $s->grade_level }})</option>@endforeach
            </select></div>
        <button class="btn btn-secondary btn-sm">Apply</button>
        <a class="btn btn-outline btn-sm" href="{{ route('principal.dashboard', ['school_year' => $schoolYear]) }}#performance-by-section" data-perf-reset>Reset</a>
    </form>

    @if(empty($p['rows']))
        <p class="text-sm text-muted" data-perf-empty>No sections in {{ $schoolYear }}{{ $p['grade'] ? ' for Grade ' . $p['grade'] : '' }}.</p>
    @else
    @if($p['totals']['unconfigured'] > 0)
        <div class="alert alert-warning text-sm mb-3" data-perf-unconfigured>{{ $p['totals']['unconfigured'] }} section(s) have electives available but none chosen (Admin › Sections): their expected count may be incomplete.</div>
    @endif
    <div class="tbl-scroll">
    <table class="tbl text-sm" data-perf-table>
        <thead><tr>
            <th scope="col">Grade</th><th scope="col">Section</th>
            <th scope="col" class="text-right">Enrolled<span class="block text-[11px] font-normal text-muted">learners</span></th>
            <th scope="col" class="text-right">For Review<span class="block text-[11px] font-normal text-muted">learners</span></th>
            <th scope="col" class="text-right">Missing<span class="block text-[11px] font-normal text-muted">learners</span></th>
            <th scope="col" class="text-right">Passed<span class="block text-[11px] font-normal text-muted">subject grades</span></th>
            <th scope="col" class="text-right">Failed<span class="block text-[11px] font-normal text-muted">subject grades</span></th>
            <th scope="col" class="min-w-[11rem]">Verification coverage<span class="block text-[11px] font-normal text-muted">verified / expected subject grades</span></th>
        </tr></thead>
        <tbody>
        @foreach($p['rows'] as $r)
            @php $state = \App\Services\SectionPerformanceService::coverageState($r); $pct = $r['expected'] ? round($r['verified'] / $r['expected'] * 100, 1) : 0; @endphp
            <tr data-perf-row="{{ $r['section']->id }}">
                <td class="whitespace-nowrap">Grade {{ $r['section']->grade_level }}</td>
                <td><a class="font-medium text-brand-700 hover:underline" href="{{ route('principal.academic-monitoring', array_filter(['school_year' => $schoolYear, 'term' => $p['term'], 'grade_level' => $r['section']->grade_level, 'section_id' => $r['section']->id, 'subject_id' => $p['subject']])) }}">{{ $r['section']->name }}<span class="sr-only"> — open in Academic Monitoring</span></a>@unless($r['configured'])<span class="block text-[11px] text-warning-text">Electives not set</span>@endunless</td>
                <td class="text-right tabular-nums">{{ $r['learners'] }}</td>
                <td class="text-right tabular-nums">{{ $r['for_review'] }}</td>
                <td class="text-right tabular-nums">{{ $r['missing'] }}</td>
                @if(in_array($state, ['none', 'nothing_expected', 'not_offered'], true))
                    <td class="text-right text-muted">—</td><td class="text-right text-muted">—</td>
                @else
                    <td class="text-right tabular-nums">{{ $r['passed'] }}</td><td class="text-right tabular-nums">{{ $r['failed'] }}</td>
                @endif
                <td>
                    @if(in_array($state, ['nothing_expected', 'not_offered'], true))
                        <span class="text-xs text-muted">{{ $stateLabel[$state] }}</span>
                    @else
                        <div class="flex items-center gap-2"><x-ui.progress-bar :value="$pct" :auto="true" size="sm" class="flex-1" :label="$r['section']->name . ' verification coverage'" />
                            <span class="tabular-nums text-xs whitespace-nowrap">{{ $r['verified'] }} / {{ $r['expected'] }}</span></div>
                        <span class="block text-[11px] {{ $state === 'complete' ? 'text-muted' : 'text-warning-text' }}" data-perf-state="{{ $state }}">{{ $stateLabel[$state] }}{{ $state === 'partial' ? ' — ' . $pct . '% verified' : '' }}</span>
                    @endif
                    @if($r['reverify'] > 0)<span class="block text-[11px] text-danger-text" data-perf-reverify>{{ $r['reverify'] }} verified grade(s) not counted: evidence changed — re-verify</span>@endif
                </td>
            </tr>
        @endforeach
        @php $t = $p['totals']; @endphp
            <tr class="font-semibold border-t-2 border-line" data-perf-total>
                <td colspan="2">Total</td>
                <td class="text-right tabular-nums">{{ $t['learners'] }}</td><td class="text-right tabular-nums">{{ $t['for_review'] }}</td><td class="text-right tabular-nums">{{ $t['missing'] }}</td>
                <td class="text-right tabular-nums">{{ $t['verified'] ? $t['passed'] : '—' }}</td><td class="text-right tabular-nums">{{ $t['verified'] ? $t['failed'] : '—' }}</td>
                <td class="tabular-nums text-xs" data-perf-total-coverage>{{ $t['verified'] }} / {{ $t['expected'] }}{{ $t['expected'] ? ' (' . round($t['verified'] / $t['expected'] * 100, 1) . '%)' : '' }}</td>
            </tr>
        </tbody>
    </table>
    </div>
    <p class="text-[11px] text-muted mt-2">Open a section by its name. Expected = enrolled learners × subjects taught to the section this term. Passed/Failed use official (verified) grades only; Failed is 74 and below. A grade not yet verified is not counted as passed or failed. For Review = learners with an open academic concern (No Current Concern is not the same as Passed).</p>
    @endif
</x-panel>
