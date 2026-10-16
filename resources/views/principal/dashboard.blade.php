@extends('layouts.app')

@section('title', 'Principal Dashboard')
@section('subtitle', 'Academic Performance Overview — Read Only')

@section('content')

@include('partials.transmutation-fallback-banner')
@include('partials.in-term-status-help')
@include('partials.last-updated')

{{-- "Multi-school-year academic history" work order, PART 13 — every
     figure on this page describes ONE school year. It defaults to the
     active year; a completed year can be selected here to review it,
     and is then labelled as historical rather than mixed in. --}}
<div class="card px-4 py-3 mb-4 flex flex-wrap items-center justify-between gap-3">
    <div class="text-sm text-gray-700 flex flex-wrap items-center gap-x-4 gap-y-1">
        <span><span class="text-xs text-gray-500">School Year:</span> <span class="font-semibold">{{ $schoolYear }}</span></span>
        <span><span class="text-xs text-gray-500">Term:</span> <span class="font-semibold">Term {{ $inTermTerm }}</span></span>
        @if($isHistoricalYear)
            <span class="badge badge-gray"><i class="bi bi-archive"></i> Historical Record</span>
            <span class="text-xs text-gray-500">Figures below are for {{ $schoolYear }} only — not the active school year.</span>
        @else
            <span class="badge badge-success"><i class="bi bi-check-circle-fill"></i> Active</span>
        @endif
    </div>
    @if($schoolYears->count() > 1)
    <form method="GET" class="flex items-center gap-2">
        <label class="form-label mb-0" for="dashboardSchoolYear">View school year</label>
        <select id="dashboardSchoolYear" name="school_year" onchange="this.form.submit()" class="form-select-sm">
            @foreach($schoolYears as $sy)
                <option value="{{ $sy }}" {{ $schoolYear === $sy ? 'selected' : '' }}>{{ $sy }}{{ $sy === \App\Models\Section::activeSchoolYear() ? ' (active)' : '' }}</option>
            @endforeach
        </select>
    </form>
    @endif
</div>

{{-- ============================================================
     ZONE 1 — Where the school stands now. TASK 1 of "dashboard
     structure and upload safeguards": everything here populates from
     assessment evidence and is what the Principal can act on today,
     grouped and labelled so it reads as deliberate, not a flat stack
     of eleven unrelated elements. Nothing below was deleted or
     recomputed differently — only reordered/regrouped and labelled.
     ============================================================ --}}
<div class="mb-3 flex items-center gap-3">
    <span class="w-8 h-8 rounded-full bg-brand-800 text-white text-sm font-bold flex items-center justify-center shrink-0" aria-hidden="true">1</span>
    <div>
        <h2 class="section-title">1. Where the school stands now</h2>
        <p class="section-subtitle">From assessment evidence already on file — available today, before any term report is submitted.</p>
    </div>
</div>

@php
    // "Correctness and interface pass" TASK 6a — the At Risk comparison
    // reads from the SAME getInTermStatusTrend() array the sparkline
    // below renders, so the two numbers can never silently disagree.
    $prevAtRisk = $previousTermCounts['atRisk'] ?? null;
    $atRiskDelta = $prevAtRisk !== null ? $inTermAtRisk - $prevAtRisk : null;
@endphp

{{-- Summary Cards — TASK 7a of "clarity, progress, and visual design
     pass": neither of these is a status (At Risk/Needs Attention/On
     Track/Failing), so both stay neutral rather than borrowing the
     "On Track" green for a plain count.
     TASK 6a of "correctness and interface pass" — every figure now
     carries a comparison: Total Students names the scope it covers,
     Assessment Completion gets a visible progress bar alongside the
     percentage it was always showing as plain text. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4 max-w-3xl">
    <x-stat-card label="Total Students" :value="$totalStudents" accent="count-2" icon="people"
        :note="'Enrolled across every section, School Year '.$schoolYear" />
    {{-- Moved here from the decisions row below — Assessment Completion is
         evidence-on-file, same as In-Term Status, not a decision the
         Principal is making. --}}
    <x-stat-card label="Assessment Completion" accent="count-7" icon="clipboard-data"
        :value="$assessment_completion['has_data'] ? $assessment_completion['percentage'].'%' : 'No data yet'">
        <x-slot:note>
            @if($assessment_completion['has_data'])
                <x-ui.progress-bar :value="$assessment_completion['percentage']" size="sm" class="mt-1" label="Assessment completion" />
                <span class="block mt-1.5 tabular-nums">{{ $assessment_completion['actual'] }} of {{ $assessment_completion['expected'] }} expected scores entered</span>
            @else
                No assessment forms uploaded this school year
            @endif
        </x-slot:note>
    </x-stat-card>
</div>

{{-- Phase 2D — Academic Monitoring: open academic concerns (academic_concerns),
     never risk_results. A count of learners' concerns For Review, linking to
     the evidence; it predicts nothing and creates no intervention. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4" data-academic-monitoring-card>
    <x-stat-card label="Learners For Review" :value="$academicMonitoring['learners_for_review']" accent="count-4" icon="flag"
        :href="route('principal.academic-monitoring', ['school_year' => $schoolYear, 'term' => ''])"
        :note="'Learners with at least one open academic concern, School Year '.$schoolYear.' — Academic Monitoring'" />
    <x-stat-card label="Open Concerns" :value="$academicMonitoring['open_concerns']" accent="count-2" icon="list-check"
        :href="route('principal.academic-monitoring', ['school_year' => $schoolYear, 'term' => ''])"
        note="Every open concern (Subject Performance Concern, Missing Requirement); a learner can have several" />
    <x-stat-card label="Learners — Subject Performance" :value="$academicMonitoring['learners_by_rule'][\App\Monitoring\Rules\OfficialFailingSubjectRule::KEY] ?? 0" accent="count-5" icon="journal-x"
        :href="route('principal.academic-monitoring', ['school_year' => $schoolYear, 'term' => '', 'concern_type' => 'subject'])"
        note="Learners with at least one OFFICIAL failing subject grade — whatever their overall average" />
    <x-stat-card label="Learners — Missing Requirement" :value="$academicMonitoring['learners_by_rule'][\App\Monitoring\Rules\MissingRequirementRule::KEY] ?? 0" accent="count-6" icon="clipboard-x"
        :href="route('principal.academic-monitoring', ['school_year' => $schoolYear, 'term' => '', 'concern_type' => 'missing'])"
        note="Learners with an assessment recorded as Missing" />
</div>

{{-- TASK 3 of "close the intervention loop": populated the instant
     assessment evidence exists, unlike the Risk cards below — see
     DashboardAnalyticsService::getInTermStatusSummary(). Deliberately its
     own row, its own heading, and never merged into the Risk numbers —
     see the "live in-term risk + stale data guard" prompt: these two
     signals must never look interchangeable.

     TASK 6c of "correctness and interface pass" — three separate count
     cards replaced with ONE horizontal stacked bar: the relative size IS
     the message, and colour is never the only signal — every segment is
     also labelled with its number below the bar. TASK 6d — each segment
     links to the Students page filtered to that status for this term
     (the Students page is scoped to one subject at a time, so this lands
     on whichever subject it defaults to, filtered — not literally "every
     subject at once," which no single list on this system currently
     shows). --}}
<x-panel title="In-Term Status — Term {{ $inTermTerm }}"
    subtitle="From assessment evidence already on file, across every subject — available before any term report is submitted."
    class="mb-4">
@if($inTermTotal > 0)
    <div class="flex h-7 rounded-full overflow-hidden bg-gray-100 ring-1 ring-line">
        @if($inTermOnTrack > 0)
        <a href="{{ route('principal.students', ['status_filter' => 'On Track', 'period' => $inTermTerm]) }}"
           class="bg-status-ontrack hover:opacity-90 transition-opacity" style="flex-grow: {{ $inTermOnTrack }}"
           title="On Track: {{ $inTermOnTrack }} — see the filtered Students list"></a>
        @endif
        @if($inTermNeedsAttention > 0)
        <a href="{{ route('principal.students', ['status_filter' => 'Needs Attention', 'period' => $inTermTerm]) }}"
           class="bg-status-attention hover:opacity-90 transition-opacity" style="flex-grow: {{ $inTermNeedsAttention }}"
           title="Below Target: 1 Component: {{ $inTermNeedsAttention }} — see the filtered Students list"></a>
        @endif
        @if($inTermAtRisk > 0)
        <a href="{{ route('principal.students', ['status_filter' => 'At Risk', 'period' => $inTermTerm]) }}"
           class="bg-status-risk hover:opacity-90 transition-opacity" style="flex-grow: {{ $inTermAtRisk }}"
           title="Below Target: 2+ Components: {{ $inTermAtRisk }} — see the filtered Students list"></a>
        @endif
    </div>
    <div class="flex flex-wrap gap-x-5 gap-y-1.5 mt-3 text-xs">
        <a href="{{ route('principal.students', ['status_filter' => 'On Track', 'period' => $inTermTerm]) }}" class="flex items-center gap-1.5 hover:underline">
            <span class="w-2.5 h-2.5 rounded-full bg-status-ontrack inline-block shrink-0"></span>
            On Track <strong class="text-ink tabular-nums">{{ $inTermOnTrack }}</strong>
        </a>
        <a href="{{ route('principal.students', ['status_filter' => 'Needs Attention', 'period' => $inTermTerm]) }}" class="flex items-center gap-1.5 hover:underline">
            <span class="w-2.5 h-2.5 rounded-full bg-status-attention inline-block shrink-0"></span>
            Below Target: 1 Component <strong class="text-ink tabular-nums">{{ $inTermNeedsAttention }}</strong>
        </a>
        <a href="{{ route('principal.students', ['status_filter' => 'At Risk', 'period' => $inTermTerm]) }}" class="flex items-center gap-1.5 hover:underline">
            <span class="w-2.5 h-2.5 rounded-full bg-status-risk inline-block shrink-0"></span>
            Below Target: 2+ Components <strong class="text-ink tabular-nums">{{ $inTermAtRisk }}</strong>
            @if($atRiskDelta !== null)
                <span class="text-gray-400">({{ $atRiskDelta > 0 ? 'up' : ($atRiskDelta < 0 ? 'down' : 'unchanged') }}{{ $atRiskDelta !== 0 ? ' from ' . $prevAtRisk . ' in Term ' . ($inTermTerm - 1) : ' since Term ' . ($inTermTerm - 1) }})</span>
            @endif
        </a>
    </div>
@else
<p class="text-xs text-muted">No assessment evidence uploaded yet this term — these counts populate as advisers import assessment scores.</p>
@endif
</x-panel>

{{-- TASK 6b of "correctness and interface pass" — "the single most
     useful thing the dashboard is currently missing": the SAME stacked-bar
     pattern above, once per term, so the Principal sees the whole school's
     direction across the year at a glance rather than only a snapshot of
     the current term. A term with no evidence yet renders as an empty
     (all-grey) bar, not a hidden one — absence is itself informative here. --}}
<x-panel title="Term-over-Term Trend" subtitle="On Track / Below Target in 1 component / Below Target in 2+ components, across every subject, per term." class="mb-4">
    {{-- "UI legibility pass" item 4 — the per-term numbers below are
         abbreviated (OT/NA/AR) to fit three counts on one line; the
         legend states the mapping once, in words, rather than relying on
         the reader carrying it over from the stacked bar's colours or
         this panel's own subtitle. --}}
    <p class="text-[11px] text-gray-400 mb-2">OT = On Track, B1 = Below Target: 1 Component, B2 = Below Target: 2+ Components</p>
    <div class="grid grid-cols-3 gap-3">
        @foreach($inTermStatusTrend as $t)
        <div>
            <p class="text-xs font-medium text-gray-600 mb-1">Term {{ $t['term'] }}</p>
            @if($t['total'] > 0)
            <div class="flex h-4 rounded-full overflow-hidden bg-gray-100">
                @if($t['onTrack'] > 0)<span class="bg-status-ontrack" style="flex-grow: {{ $t['onTrack'] }}" title="On Track: {{ $t['onTrack'] }}"></span>@endif
                @if($t['needsAttention'] > 0)<span class="bg-status-attention" style="flex-grow: {{ $t['needsAttention'] }}" title="Below Target: 1 Component: {{ $t['needsAttention'] }}"></span>@endif
                @if($t['atRisk'] > 0)<span class="bg-status-risk" style="flex-grow: {{ $t['atRisk'] }}" title="Below Target: 2+ Components: {{ $t['atRisk'] }}"></span>@endif
            </div>
            <p class="text-[11px] text-gray-400 mt-1 tabular-nums">{{ $t['onTrack'] }} OT &middot; {{ $t['needsAttention'] }} B1 &middot; {{ $t['atRisk'] }} B2</p>
            @else
            <div class="h-4 rounded-full bg-gray-100"></div>
            <p class="text-[11px] text-gray-300 mt-1">No evidence yet</p>
            @endif
        </div>
        @endforeach
    </div>
</x-panel>

{{-- ============================================================
     ZONE 2 — What needs a decision. TASK 1 of "dashboard structure
     and upload safeguards": the three cards the Principal can act on
     RIGHT NOW, each linking straight to the filtered Interventions
     view for that state. Markup/queries unchanged from before — only
     moved up (out of the old 4-card row, which also held Assessment
     Completion, now in Zone 1 above) and given the collapse-when-empty
     treatment used elsewhere on this dashboard.
     ============================================================ --}}
@php
    $needsDecisionTotal = $under_intervention + $awaiting_decision + $ready_for_review;
@endphp
<div class="mb-3 mt-2 flex items-center gap-3">
    <span class="w-8 h-8 rounded-full bg-brand-800 text-white text-sm font-bold flex items-center justify-center shrink-0" aria-hidden="true">2</span>
    <div>
        <h2 class="section-title">2. What needs a decision</h2>
        <p class="section-subtitle">Interventions the Principal can act on right now.</p>
    </div>
</div>
@if($needsDecisionTotal > 0)
{{-- TASK 7a of "clarity, progress, and visual design pass" — "Under
     Intervention" and "Awaiting Your Decision" are workflow-stage
     counts, not a status, so both stay neutral. "Ready to Close" keeps
     green deliberately: it literally reports that the student reached
     On Track, the one place on this row where the status table's
     meaning actually applies. --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    <x-stat-card label="Under Intervention" :value="$under_intervention" accent="count-8" icon="clipboard2-pulse"
        :href="route('principal.interventions')" note="Approved, in progress, or being monitored" />
    {{-- "Master pass" PART 1.3b — "Recommended, not yet reviewed" implied
         every one of these was a DSS recommendation; most are
         Principal-recorded batches awaiting the Principal's own approval,
         not a review of something the system suggested. --}}
    <x-stat-card label="Awaiting Your Decision" :value="$awaiting_decision" accent="count-4" icon="hourglass-split"
        :href="route('principal.interventions', ['awaiting_decision' => 1])" note="Recorded but not yet approved" />
    {{-- TASK 2 of "bulk dialog and intervention closure" — a signal the
         Principal still has to act on (see
         InTermStatusService::isReadyForReview()), never an auto-close.
         "Ready to Close" keeps status-ontrack deliberately: it literally
         reports that the student reached On Track, the one place on this
         row where the status table's meaning actually applies. --}}
    <x-stat-card label="Ready to Close" :value="$ready_for_review" accent="status-ontrack" icon="check2-circle"
        :href="route('principal.interventions', ['ready_for_review' => 1])">
        {{-- TASK 6e of "correctness and interface pass" — a bare "0" here
             reads as "nothing is working," not "nothing needs it yet." --}}
        <x-slot:note>
            @if($ready_for_review > 0)
                Delivered — student is now On Track
            @else
                None yet — learners appear here once a delivered intervention brings them back On Track.
            @endif
        </x-slot:note>
    </x-stat-card>
</div>
@if($pendingInterventions->isNotEmpty())
<x-panel title="Pending Interventions" subtitle="The most recent recommendations still waiting for your decision" class="mb-4" :padded="false">
    <x-slot:action>
        <a href="{{ route('principal.interventions', ['awaiting_decision' => 1]) }}" class="btn-link text-xs">Review all →</a>
    </x-slot:action>
    <div class="tbl-scroll">
        <table class="tbl">
            <thead>
                <tr>
                    <th scope="col">Learner</th>
                    <th scope="col">Section</th>
                    <th scope="col">Subject</th>
                    <th scope="col">Recommendation</th>
                    <th scope="col">Term</th>
                    <th scope="col">Recorded</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pendingInterventions as $iv)
                <tr>
                    <td class="font-medium text-ink whitespace-nowrap">
                        <a href="{{ route('principal.students.show', $iv->student_id) }}" class="hover:underline">{{ $iv->student->last_name ?? '—' }}, {{ $iv->student->first_name ?? '' }}</a>
                    </td>
                    <td class="whitespace-nowrap">{{ $iv->section->name ?? '—' }}@if($iv->section) <span class="text-muted">(Grade {{ $iv->section->grade_level }})</span>@endif</td>
                    <td>{{ $iv->subject->name ?? '—' }}</td>
                    <td><x-ui.status-badge tone="info" :label="\App\Models\Intervention::typeLabel($iv->recommended_type)" /></td>
                    <td class="whitespace-nowrap">{{ $iv->grading_period ? 'Term ' . $iv->grading_period : '—' }}</td>
                    <td class="text-muted whitespace-nowrap">{{ $iv->created_at->format('M d, Y') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-panel>
@endif
@else
<div class="card p-4 mb-4 text-sm text-gray-600 flex items-center gap-3">
    <div class="icon-box icon-box-success" aria-hidden="true"><i class="bi bi-check-circle"></i></div>
    No interventions need attention right now.
</div>
@endif

@include('principal.partials.performance-by-section')

{{-- ============================================================
     ZONE 3 — Trend and outcomes: the official-grade Failing count and
     the evidence-based Performance Trend and Component Performance
     charts. (The classifier Risk Level cards, charts and fixed
     risk-level Recommendations were removed with the ML prototype —
     monitoring is rule-based now.) Academic Honors was removed
     here by TASK 6 of "terminology, transmutation, and interface
     cleanup" — honor roll is a reporting function, not decision
     support, and competed for attention with the cards below that
     need action; see DashboardAnalyticsService::getSummaryData().
     ============================================================ --}}
<div class="mb-3 mt-2 flex items-center gap-3">
    <span class="w-8 h-8 rounded-full bg-brand-800 text-white text-sm font-bold flex items-center justify-center shrink-0" aria-hidden="true">3</span>
    <div>
        <h2 class="section-title">3. Trend and outcomes</h2>
        <p class="section-subtitle">Official-grade outcomes and evidence-based trends across terms.</p>
    </div>
</div>

{{-- "Workflow completion pass" TASK 2 — moved out of the In-Term Status
     row above (TASK 4 of "the FAILING layer" originally placed it there,
     which turned out to be wrong): Failing is an OUTCOME, only knowable
     once the Adviser has encoded a final official grade at the end of
     the term. Presenting it alongside On Track / Needs Attention / At
     Risk — which are early warnings available WHILE there is still time
     to act — implied it was something to act on preventively. It isn't.
     It belongs with the rest of this after-the-term view. --}}
<div class="mb-1">
    <p class="text-xs text-gray-500">
        This is an outcome from official grades, counted after they are encoded. In-Term Status above describes the
        assessment evidence recorded during the term.
    </p>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4 mt-2">
    <x-stat-card label="Failed this term" :value="$failingCount" accent="status-failing" icon="x-octagon"
        note="Official grade 74 and below. Verified grades only. Available after the Adviser encodes final grades." />
</div>

@php
    $hasTrendData = collect($termTrends)->filter(fn($v) => $v !== null)->isNotEmpty();
@endphp

{{-- Performance Trend — plots the average COMPUTED grade per term
     straight from assessment evidence (see DashboardAnalyticsService::
     computeAssessmentEvidenceTrend()), so it renders as soon as any
     evidence exists, with or without a submitted term report. --}}
<div class="grid grid-cols-1 mb-4">
    <x-panel title="Performance Trend" subtitle="Average COMPUTED grade per term, from assessment evidence — not the official/transmuted grade, and not gated on a submitted term report.">
        <div style="height:220px;" class="{{ $hasTrendData ? '' : 'flex items-center justify-center' }}">
            @if($hasTrendData)
                <canvas id="termTrendChart" role="img" aria-label="Average computed grade per term"></canvas>
            @else
                <p class="text-xs text-muted text-center px-4">No student/subject yet has scored evidence in all three components for any term — this fills in as that evidence is entered.</p>
            @endif
        </div>
    </x-panel>
</div>

{{-- Component Performance — average of each subject's component average
     (Written Work / Performance Task / Examination) across every subject
     with evidence this school year, from SubjectAnalysisService — the
     same figures the Subject Analysis page tabulates, summarised. Renders
     only when at least one subject has evidence; a component with none
     reads "No data" (never 0). Scope: the whole school year, all terms —
     NOT the Performance table's term filter. 75% is the system's in-term
     reference point (SubjectAnalysisService::TARGET), not a passing grade. --}}
@if(collect($componentPerformance)->whereNotNull('average')->isNotEmpty())
<x-panel title="Assessment Component Summary" class="mb-4" id="component-summary"
    subtitle="Whole school year {{ $schoolYear }}, all terms and sections (not filtered by the Performance table above). Per component: the mean of each subject's average raw item score (score ÷ highest possible score). Not a grade, general average or passing rate. Reference point: 75% (the system's in-term target, not a DepEd passing mark).">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
        <div style="height:200px;">
            <canvas id="componentPerformanceChart" role="img" aria-label="Average per assessment component"></canvas>
        </div>
        <div class="space-y-4">
            @foreach($componentPerformance as $cp)
            <div data-component="{{ $cp['key'] }}">
                @if($cp['average'] === null)
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-ink">{{ $cp['label'] }}</span>
                        <span class="text-muted" data-component-no-data>No data</span>
                    </div>
                    <p class="text-[11px] text-muted mt-1">No score recorded for this component yet this school year.</p>
                @else
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-ink">{{ $cp['label'] }}</span>
                        <span class="tabular-nums font-semibold {{ $cp['average'] >= 75 ? 'text-success-text' : 'text-warning-text' }}">{{ number_format($cp['average'], 1) }}%</span>
                    </div>
                    <x-ui.progress-bar :value="$cp['average']" :tone="$cp['average'] >= 75 ? 'success' : 'warning'" class="mt-1.5" :label="$cp['label'] . ' average'" />
                    <p class="text-[11px] text-muted mt-1">{{ $cp['subjects'] }} subject{{ $cp['subjects'] === 1 ? '' : 's' }} with evidence · {{ $cp['average'] >= 75 ? 'at or above' : 'below' }} the 75% reference</p>
                @endif
            </div>
            @endforeach
        </div>
    </div>
</x-panel>
@endif


@endsection

@push('scripts')
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
<script>
    const TERM_TRENDS       = @json($termTrends);
    const COMPONENT_PERFORMANCE = @json($componentPerformance ?? []);
</script>
<script src="{{ asset('js/admin/dashboard.js') }}"></script>
@endpush
