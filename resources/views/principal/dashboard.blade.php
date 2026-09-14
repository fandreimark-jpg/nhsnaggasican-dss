@extends('layouts.app')

@section('title', 'Principal Dashboard')
@section('subtitle', 'Academic Performance Overview — Read Only')

@section('content')

@include('partials.transmutation-fallback-banner')
@include('partials.stale-risk-warning')
@include('partials.in-term-vs-risk-help')
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
           title="Needs Attention: {{ $inTermNeedsAttention }} — see the filtered Students list"></a>
        @endif
        @if($inTermAtRisk > 0)
        <a href="{{ route('principal.students', ['status_filter' => 'At Risk', 'period' => $inTermTerm]) }}"
           class="bg-status-risk hover:opacity-90 transition-opacity" style="flex-grow: {{ $inTermAtRisk }}"
           title="At Risk: {{ $inTermAtRisk }} — see the filtered Students list"></a>
        @endif
    </div>
    <div class="flex flex-wrap gap-x-5 gap-y-1.5 mt-3 text-xs">
        <a href="{{ route('principal.students', ['status_filter' => 'On Track', 'period' => $inTermTerm]) }}" class="flex items-center gap-1.5 hover:underline">
            <span class="w-2.5 h-2.5 rounded-full bg-status-ontrack inline-block shrink-0"></span>
            On Track <strong class="text-ink tabular-nums">{{ $inTermOnTrack }}</strong>
        </a>
        <a href="{{ route('principal.students', ['status_filter' => 'Needs Attention', 'period' => $inTermTerm]) }}" class="flex items-center gap-1.5 hover:underline">
            <span class="w-2.5 h-2.5 rounded-full bg-status-attention inline-block shrink-0"></span>
            Needs Attention <strong class="text-ink tabular-nums">{{ $inTermNeedsAttention }}</strong>
        </a>
        <a href="{{ route('principal.students', ['status_filter' => 'At Risk', 'period' => $inTermTerm]) }}" class="flex items-center gap-1.5 hover:underline">
            <span class="w-2.5 h-2.5 rounded-full bg-status-risk inline-block shrink-0"></span>
            At Risk <strong class="text-ink tabular-nums">{{ $inTermAtRisk }}</strong>
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
<x-panel title="Term-over-Term Trend" subtitle="On Track / Needs Attention / At Risk, across every subject, per term." class="mb-4">
    {{-- "UI legibility pass" item 4 — the per-term numbers below are
         abbreviated (OT/NA/AR) to fit three counts on one line; the
         legend states the mapping once, in words, rather than relying on
         the reader carrying it over from the stacked bar's colours or
         this panel's own subtitle. --}}
    <p class="text-[11px] text-gray-400 mb-2">OT = On Track, NA = Needs Attention, AR = At Risk</p>
    <div class="grid grid-cols-3 gap-3">
        @foreach($inTermStatusTrend as $t)
        <div>
            <p class="text-xs font-medium text-gray-600 mb-1">Term {{ $t['term'] }}</p>
            @if($t['total'] > 0)
            <div class="flex h-4 rounded-full overflow-hidden bg-gray-100">
                @if($t['onTrack'] > 0)<span class="bg-status-ontrack" style="flex-grow: {{ $t['onTrack'] }}" title="On Track: {{ $t['onTrack'] }}"></span>@endif
                @if($t['needsAttention'] > 0)<span class="bg-status-attention" style="flex-grow: {{ $t['needsAttention'] }}" title="Needs Attention: {{ $t['needsAttention'] }}"></span>@endif
                @if($t['atRisk'] > 0)<span class="bg-status-risk" style="flex-grow: {{ $t['atRisk'] }}" title="At Risk: {{ $t['atRisk'] }}"></span>@endif
            </div>
            <p class="text-[11px] text-gray-400 mt-1 tabular-nums">{{ $t['onTrack'] }} OT &middot; {{ $t['needsAttention'] }} NA &middot; {{ $t['atRisk'] }} AR</p>
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
     treatment already established by the Risk Level block below.
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
                    <td><x-ui.status-badge tone="info" :label="$iv->recommended_type === 'remediation' ? 'Additional Practice and Re-teaching' : ucfirst(str_replace('_', ' ', $iv->recommended_type))" /></td>
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

{{-- ============================================================
     ZONE 3 — Trend and outcomes. TASK 1 of "dashboard structure and
     upload safeguards": the after-the-term view — Risk Level, the
     three charts, and Recommendations. Academic Honors was removed
     here by TASK 6 of "terminology, transmutation, and interface
     cleanup" — honor roll is a reporting function, not decision
     support, and competed for attention with the cards below that
     need action; see DashboardAnalyticsService::getSummaryData().
     ============================================================ --}}
<div class="mb-3 mt-2 flex items-center gap-3">
    <span class="w-8 h-8 rounded-full bg-brand-800 text-white text-sm font-bold flex items-center justify-center shrink-0" aria-hidden="true">3</span>
    <div>
        <h2 class="section-title">3. Trend and outcomes</h2>
        <p class="section-subtitle">From submitted term reports — the after-the-term view.</p>
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
        This is an outcome, not an early warning. It counts learners the term's support did not reach in time. Use
        In-Term Status above to act while there is still time.
    </p>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4 mt-2">
    <x-stat-card label="Failed this term" :value="$failingCount" accent="status-failing" icon="x-octagon"
        note="Official grade 74 and below. Verified grades only. Available after the Adviser encodes final grades." />
</div>

@php
    // Computed here (rather than after the charts, as before) because
    // TASK 1 of "close the delivery loop" needs it to gate the cards too:
    // risk_level is always low/moderate/high, so a zero across all three
    // means zero risk_results for the active school year — i.e. no term
    // report has ever been submitted yet.
    $hasRiskData = ($lowRisk + $moderateRisk + $highRisk) > 0;
    $hasTrendData = collect($termTrends)->filter(fn($v) => $v !== null)->isNotEmpty();
    $hasSectionRiskData = collect($sectionRiskData)->contains(fn($s) => ($s['low'] + $s['moderate'] + $s['high']) > 0);
@endphp

{{-- Risk cards — moved below In-Term Status. See DssComponentIntegrationTest
     etc.: Risk Level comes from the submitted term report + classifier,
     never from assessment evidence alone. --}}
@php
    // "UI legibility pass" — MASTER_PROMPT.md Part 0.8's "name the term"
    // rule, already applied on Admin/Principal Reports (per-row "as of
    // Term N"), extended to this dashboard-wide heading. $riskLevelTerms
    // (DashboardAnalyticsService::getSummaryData()) is the distinct,
    // sorted set of terms behind the counts below -- usually one, but a
    // real possibility of more than one if sections submit on different
    // schedules (see that variable's own docblock), reported honestly
    // rather than collapsed to just the latest.
    $riskLevelTermsList = $riskLevelTerms ?? [];
    $riskLevelTermSuffix = match (count($riskLevelTermsList)) {
        0       => '',
        1       => ' (as of Term ' . $riskLevelTermsList[0] . ')',
        default => ' (as of Term ' . implode(', ', $riskLevelTermsList) . ')',
    };
@endphp
<div class="mb-1">
    <h3 class="card-title">Risk Level — submitted term reports{{ $riskLevelTermSuffix }}</h3>
</div>
@if($hasRiskData)
{{-- TASK 6d of "correctness and interface pass" — Moderate/High link to
     the "Students Needing Attention" table further down THIS SAME page,
     pre-filtered to that risk level. Low Risk is deliberately NOT a link:
     getAtRiskStudentsData() only ever returns moderate/high students (see
     its own docblock) — there is no roster of low-risk students anywhere
     in this system to send a click to, so linking it would only ever land
     on an empty result with no explanation. --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4 mt-2">
    {{-- Low Risk is deliberately NOT a link — see the note above:
         getAtRiskStudentsData() only ever returns moderate/high students,
         so there is no roster to send a click to. --}}
    <x-stat-card label="Low Risk" :value="$lowRisk" accent="status-ontrack" icon="shield-check" note="Performing well — keep monitoring" />
    <x-stat-card label="Moderate Risk" :value="$moderateRisk" accent="status-attention" icon="exclamation-triangle" note="Needs academic attention"
        :href="route('principal.dashboard', ['ar_risk_level' => 'moderate']).'#atRiskResultsContainer'" />
    <x-stat-card label="High Risk" :value="$highRisk" accent="status-risk" icon="exclamation-octagon" note="Immediate intervention suggested"
        :href="route('principal.dashboard', ['ar_risk_level' => 'high']).'#atRiskResultsContainer'" />
</div>

{{-- Risk levels and per-student focus areas measure different things and
     run on different timelines (see PerformanceAnalysisService vs the
     Random Forest classifier) — without this line, "0/0/0 Risk" next to
     "99% Assessment Completion" above reads as a contradiction. --}}
<p class="text-xs text-gray-500 -mt-2 mb-4">
    Risk levels (above) appear once advisers submit their term reports — a single term has no trend to classify yet.
    Per-student <strong>Focus Areas</strong>, drawn from assessment evidence already on file, are available now on the
    <a href="{{ route('principal.students') }}" class="text-brand-700 underline hover:text-brand-800">Students</a> page — no need to wait for a submitted report.
</p>
@else
{{-- TASK 1 of "close the delivery loop": zero cards + three empty charts
     reads as broken, not "correctly zero" — a single explanatory block
     replaces all six elements. The markup and queries below (the @endif
     branch) are untouched — this is a conditional render, nothing was
     deleted, and it reappears exactly as it was the moment a term report
     is submitted (see PrincipalDashboardRiskCollapseTest). --}}
<x-empty-state icon="bi-hourglass-split"
    message="Risk Level appears once advisers submit their term reports. It covers every subject and factors in the trend across terms, produced by the classifier from the submitted report."
    class="card mb-4">
    <x-slot:hint>
        <strong>In-Term Status</strong> above is available now, from assessment evidence already on file — no need
        to wait for a submitted report.
    </x-slot:hint>
</x-empty-state>
@endif

{{-- Performance Trend — TASK 3 of "status clarity and progress
     consistency": unlike Risk Distribution and At-Risk per Section
     below, this does NOT depend on risk_results — it plots the average
     COMPUTED grade per term straight from assessment evidence (see
     DashboardAnalyticsService::computeAssessmentEvidenceTrend()), so it
     renders as soon as any evidence exists, even with zero submitted
     term reports. Moved out of the $hasRiskData-gated block below,
     which used to collapse this chart along with the two that
     genuinely need risk_results. --}}
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

{{-- Charts Row — TASK 1 of "close the delivery loop": collapsed into the
     single explanatory block above when $hasRiskData is false, so this
     row only renders once at least one risk result exists for the
     school year. Markup and queries untouched — same conditional as the
     cards above; only Performance Trend (above) was pulled out of it. --}}
@if($hasRiskData)
<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
    <x-panel title="Risk Distribution" subtitle="Share of learners per risk level — each learner's most recent submitted report">
        <div style="height:220px;">
            <canvas id="riskDonutChart" role="img" aria-label="Risk distribution: {{ $lowRisk }} low, {{ $moderateRisk }} moderate, {{ $highRisk }} high"></canvas>
        </div>
    </x-panel>
    <x-panel title="Risk by Section" subtitle="Moderate + High risk learners per section">
        <div style="height:220px;" class="{{ $hasSectionRiskData ? '' : 'flex items-center justify-center' }}">
            @if($hasSectionRiskData)
                <canvas id="sectionRiskChart" role="img" aria-label="Moderate and high risk learners per section"></canvas>
            @else
                <p class="text-xs text-muted text-center px-4">No section has a submitted term report yet — this fills in once term reports come in.</p>
            @endif
        </div>
    </x-panel>
</div>
@endif

{{-- Component Performance — average of each subject's component average
     (Written Work / Performance Task / Examination) across every subject
     with evidence this school year, from SubjectAnalysisService — the
     same figures the Subject Analysis page tabulates, summarised. Renders
     only when at least one subject has evidence. --}}
@if(!empty($componentPerformance))
<x-panel title="Component Performance" class="mb-4"
    subtitle="Average score per assessment component across every subject with evidence this school year (mean of the subject averages shown on Subject Analysis). Target: 75%.">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
        <div style="height:200px;">
            <canvas id="componentPerformanceChart" role="img" aria-label="Average per assessment component"></canvas>
        </div>
        <div class="space-y-4">
            @foreach($componentPerformance as $cp)
            <div>
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium text-ink">{{ $cp['label'] }}</span>
                    <span class="tabular-nums font-semibold {{ $cp['average'] >= 75 ? 'text-success-text' : ($cp['average'] >= 70 ? 'text-warning-text' : 'text-danger-text') }}">{{ number_format($cp['average'], 1) }}%</span>
                </div>
                <x-ui.progress-bar :value="$cp['average']" :tone="$cp['average'] >= 75 ? 'success' : ($cp['average'] >= 70 ? 'warning' : 'danger')" class="mt-1.5" :label="$cp['label'] . ' average'" />
                <p class="text-[11px] text-muted mt-1">{{ $cp['subjects'] }} subject{{ $cp['subjects'] === 1 ? '' : 's' }} with evidence</p>
            </div>
            @endforeach
        </div>
    </div>
</x-panel>
@endif

{{-- Recommendations — the DSS provides these; the Principal makes the
     final decision (see Intervention, a later phase). --}}
<x-panel title="Recommendations" class="mb-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

        <div class="flex gap-3 p-4 bg-success-soft/40 rounded-xl border border-green-200">
            <div class="icon-box icon-box-sm icon-box-success" aria-hidden="true"><i class="bi bi-shield-check"></i></div>
            <div>
                <p class="text-xs font-semibold text-status-ontrack">
                    Low Risk — {{ $lowRisk }} {{ $lowRisk === 1 ? 'student' : 'students' }}
                </p>
                <p class="text-xs text-status-ontrack mt-0.5">
                    Students are performing well. Continue regular monitoring and maintain current academic support strategies.
                </p>
            </div>
        </div>

        <div class="flex gap-3 p-4 bg-warning-soft/40 rounded-xl border border-amber-200">
            <div class="icon-box icon-box-sm icon-box-warning" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <p class="text-xs font-semibold text-status-attention">
                    Moderate Risk — {{ $moderateRisk }} {{ $moderateRisk === 1 ? 'student' : 'students' }}
                </p>
                <p class="text-xs text-status-attention mt-0.5">Students need academic attention. Suggested actions:</p>
                <ul class="text-xs text-status-attention mt-1 list-disc list-inside">
                    <li>Conduct parent-teacher conference</li>
                    <li>Provide remedial or tutorial sessions</li>
                    <li>Monitor performance closely each term</li>
                </ul>
            </div>
        </div>

        <div class="flex gap-3 p-4 bg-danger-soft/40 rounded-xl border border-red-200">
            <div class="icon-box icon-box-sm icon-box-danger" aria-hidden="true"><i class="bi bi-exclamation-octagon"></i></div>
            <div>
                <p class="text-xs font-semibold text-status-risk">
                    High Risk — {{ $highRisk }} {{ $highRisk === 1 ? 'student' : 'students' }}
                </p>
                <p class="text-xs text-status-risk mt-0.5">Immediate academic intervention suggested. Consider:</p>
                <ul class="text-xs text-status-risk mt-1 list-disc list-inside">
                    <li>Schedule immediate parent conference</li>
                    <li>Refer to guidance counselor</li>
                    <li>Enroll in intensive remedial program</li>
                    <li>Weekly academic progress monitoring</li>
                </ul>
            </div>
        </div>

    </div>
</x-panel>

@php
    $atRiskFilterKeys = ['ar_grade_level', 'ar_section_search', 'ar_risk_level', 'ar_component'];
    $atRiskFiltersActive = collect($atRiskFilterKeys)->contains(fn($k) => request($k));
    $selectedSection = $atRiskSections->firstWhere('name', request('ar_section_search'));
@endphp
{{-- At-Risk Students Table — shared partial with the Admin dashboard --}}
@if($atRiskStudentsTotal > 0 || $atRiskFiltersActive)
{{-- WORK ORDER Part 6a item 4 — the one consistent filter-bar shape,
     standalone above the panel it filters rather than nested as that
     panel's header row. --}}
<div class="mb-4">
    <form method="GET" id="atRiskFilterForm" class="filter-bar mb-0">
        <span class="text-muted text-sm mr-1 self-center hidden sm:inline" aria-hidden="true"><i class="bi bi-funnel"></i></span>
        {{-- PART 13 — the widget's AJAX refresh must stay on the same
             school year the rest of the page is showing. --}}
        <input type="hidden" name="school_year" value="{{ $schoolYear }}">
        <div>
            <label class="form-label">Grade Level</label>
            <select name="ar_grade_level" id="atRiskGradeLevelSelect"
                    class="form-select-sm min-w-[130px]">
                <option value="">All grade levels</option>
                @foreach($atRiskGradeLevels as $gl)
                    <option value="{{ $gl }}" {{ request('ar_grade_level') == $gl ? 'selected' : '' }}>
                        Grade {{ $gl }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Section</label>
            <select name="ar_section_search" id="atRiskSectionSelect"
                    class="form-select-sm min-w-[130px]">
                <option value="">All sections</option>
                @foreach($atRiskSections as $sec)
                    <option value="{{ $sec->name }}" data-grade-level="{{ $sec->grade_level }}"
                            data-track="{{ $sec->track->name ?? '' }}"
                            data-specialization="{{ $sec->specialization->name ?? '' }}"
                            {{ request('ar_section_search') === $sec->name ? 'selected' : '' }}>
                        {{ $sec->name }} (Grade {{ $sec->grade_level }})
                    </option>
                @endforeach
            </select>
        </div>
        {{-- Track/Specialization are NOT selectable — they are
             automatically derived from whichever Section is chosen
             above (CLAUDE.md: "must NOT be manually selectable
             dropdowns"), via the existing Section->track/
             specialization relationship. --}}
        <div>
            <label class="form-label">Track</label>
            <p id="atRiskTrackDisplay" class="form-select-sm min-w-[130px] bg-gray-50 text-gray-600">
                {{ $selectedSection->track->name ?? '—' }}
            </p>
        </div>
        <div>
            <label class="form-label">Specialization</label>
            <p id="atRiskSpecializationDisplay" class="form-select-sm min-w-[150px] bg-gray-50 text-gray-600">
                {{ $selectedSection->specialization->name ?? '—' }}
            </p>
        </div>
        <div>
            <label class="form-label">Risk Level</label>
            <select name="ar_risk_level" class="form-select-sm min-w-[120px]">
                <option value="">All levels</option>
                <option value="high" {{ request('ar_risk_level') === 'high' ? 'selected' : '' }}>High</option>
                <option value="moderate" {{ request('ar_risk_level') === 'moderate' ? 'selected' : '' }}>Moderate</option>
            </select>
        </div>
        <div>
            <label class="form-label">Assessment Component</label>
            <select name="ar_component" class="form-select-sm min-w-[160px]">
                <option value="">All components</option>
                <option value="written_work" {{ request('ar_component') === 'written_work' ? 'selected' : '' }}>Written Work</option>
                <option value="performance_task" {{ request('ar_component') === 'performance_task' ? 'selected' : '' }}>Performance Task</option>
                <option value="examination" {{ request('ar_component') === 'examination' ? 'selected' : '' }}>Examination</option>
            </select>
        </div>
        @if($atRiskFiltersActive)
            <a href="{{ route('principal.dashboard') }}" class="btn btn-ghost btn-sm self-center">Clear</a>
        @endif
    </form>
</div>
<x-panel title="Students Needing Attention">
    <x-slot:subtitle>
        <span class="flex flex-wrap items-center gap-4 text-xs text-gray-500 mt-1">
            <span class="flex items-center gap-1">
                <span class="w-2 h-2 rounded-full bg-red-600 inline-block"></span>
                Currently failing (below 75 this term)
            </span>
            <span class="flex items-center gap-1">
                <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
                Declining 5+ points vs. last term (may still be passing)
            </span>
        </span>
    </x-slot:subtitle>
    <div id="atRiskResultsContainer" class="-m-5">
        @include('principal.partials.at-risk-results')
    </div>
</x-panel>
@endif

@endsection

@push('scripts')
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
<script>
    const RISK_DATA = {
        low:      {{ $lowRisk }},
        moderate: {{ $moderateRisk }},
        high:     {{ $highRisk }},
    };
    const TERM_TRENDS       = @json($termTrends);
    const SECTION_RISK_DATA = @json($sectionRiskData);
    const COMPONENT_PERFORMANCE = @json($componentPerformance ?? []);
</script>
<script src="{{ asset('js/admin/dashboard.js') }}"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initCascadingSelect('atRiskGradeLevelSelect', 'atRiskSectionSelect');
        initSectionDerivedDisplay('atRiskSectionSelect', 'atRiskTrackDisplay', 'atRiskSpecializationDisplay');
        initAutoSubmitFilter('atRiskFilterForm', { ajaxTarget: 'atRiskResultsContainer' });
    });
</script>
@endpush
