@extends('layouts.app')

@section('title', 'Principal Dashboard')
@section('subtitle', 'Academic Performance Overview — Read Only')

@section('content')

@include('partials.transmutation-fallback-banner')
@include('partials.stale-risk-warning')
@include('partials.in-term-vs-risk-help')
@include('partials.last-updated')

{{-- ============================================================
     ZONE 1 — Where the school stands now. TASK 1 of "dashboard
     structure and upload safeguards": everything here populates from
     assessment evidence and is what the Principal can act on today,
     grouped and labelled so it reads as deliberate, not a flat stack
     of eleven unrelated elements. Nothing below was deleted or
     recomputed differently — only reordered/regrouped and labelled.
     ============================================================ --}}
<div class="mb-3">
    <h2 class="text-base font-bold text-gray-800">1. Where the school stands now</h2>
    <p class="text-xs text-gray-500">From assessment evidence already on file — available today, before any term report is submitted.</p>
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
<div class="grid grid-cols-2 gap-3 mb-4 max-w-xl">
    <x-stat-card label="Total Students" :value="$totalStudents" accent="count-8"
        :note="'Across every section, Term '.$inTermTerm.', '.\App\Models\Section::activeSchoolYear()" />
    {{-- Moved here from the decisions row below — Assessment Completion is
         evidence-on-file, same as In-Term Status, not a decision the
         Principal is making. --}}
    <x-stat-card label="Assessment Completion" accent="count-8"
        :value="$assessment_completion['has_data'] ? $assessment_completion['percentage'].'%' : 'No data yet'">
        <x-slot:note>
            @if($assessment_completion['has_data'])
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden mt-1" role="progressbar" aria-valuenow="{{ $assessment_completion['percentage'] }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full bg-brand-700 rounded-full" style="width: {{ min(100, $assessment_completion['percentage']) }}%"></div>
                </div>
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
    <div class="flex h-6 rounded-full overflow-hidden bg-gray-100">
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
            On Track <strong class="text-gray-800 tabular-nums">{{ $inTermOnTrack }}</strong>
        </a>
        <a href="{{ route('principal.students', ['status_filter' => 'Needs Attention', 'period' => $inTermTerm]) }}" class="flex items-center gap-1.5 hover:underline">
            <span class="w-2.5 h-2.5 rounded-full bg-status-attention inline-block shrink-0"></span>
            Needs Attention <strong class="text-gray-800 tabular-nums">{{ $inTermNeedsAttention }}</strong>
        </a>
        <a href="{{ route('principal.students', ['status_filter' => 'At Risk', 'period' => $inTermTerm]) }}" class="flex items-center gap-1.5 hover:underline">
            <span class="w-2.5 h-2.5 rounded-full bg-status-risk inline-block shrink-0"></span>
            At Risk <strong class="text-gray-800 tabular-nums">{{ $inTermAtRisk }}</strong>
            @if($atRiskDelta !== null)
                <span class="text-gray-400">({{ $atRiskDelta > 0 ? 'up' : ($atRiskDelta < 0 ? 'down' : 'unchanged') }}{{ $atRiskDelta !== 0 ? ' from ' . $prevAtRisk . ' in Term ' . ($inTermTerm - 1) : ' since Term ' . ($inTermTerm - 1) }})</span>
            @endif
        </a>
    </div>
@else
<p class="text-xs text-gray-400">No assessment evidence uploaded yet this term — these counts populate as advisers import assessment scores.</p>
@endif
</x-panel>

{{-- TASK 6b of "correctness and interface pass" — "the single most
     useful thing the dashboard is currently missing": the SAME stacked-bar
     pattern above, once per term, so the Principal sees the whole school's
     direction across the year at a glance rather than only a snapshot of
     the current term. A term with no evidence yet renders as an empty
     (all-grey) bar, not a hidden one — absence is itself informative here. --}}
<x-panel title="Term-over-Term Trend" subtitle="On Track / Needs Attention / At Risk, across every subject, per term." class="mb-4">
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
            <p class="text-[11px] text-gray-400 mt-1 tabular-nums">{{ $t['onTrack'] }} &middot; {{ $t['needsAttention'] }} &middot; {{ $t['atRisk'] }}</p>
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
<div class="mb-3">
    <h2 class="text-base font-bold text-gray-800">2. What needs a decision</h2>
    <p class="text-xs text-gray-500">Interventions the Principal can act on right now.</p>
</div>
@if($needsDecisionTotal > 0)
{{-- TASK 7a of "clarity, progress, and visual design pass" — "Under
     Intervention" and "Awaiting Your Decision" are workflow-stage
     counts, not a status, so both stay neutral. "Ready to Close" keeps
     green deliberately: it literally reports that the student reached
     On Track, the one place on this row where the status table's
     meaning actually applies. --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    <x-stat-card label="Under Intervention" :value="$under_intervention" accent="count-8"
        :href="route('principal.interventions')" note="Approved, in progress, or being monitored" />
    {{-- "Master pass" PART 1.3b — "Recommended, not yet reviewed" implied
         every one of these was a DSS recommendation; most are
         Principal-recorded batches awaiting the Principal's own approval,
         not a review of something the system suggested. --}}
    <x-stat-card label="Awaiting Your Decision" :value="$awaiting_decision" accent="count-8"
        :href="route('principal.interventions', ['awaiting_decision' => 1])" note="Recorded but not yet approved" />
    {{-- TASK 2 of "bulk dialog and intervention closure" — a signal the
         Principal still has to act on (see
         InTermStatusService::isReadyForReview()), never an auto-close.
         "Ready to Close" keeps status-ontrack deliberately: it literally
         reports that the student reached On Track, the one place on this
         row where the status table's meaning actually applies. --}}
    <x-stat-card label="Ready to Close" :value="$ready_for_review" accent="status-ontrack"
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
@else
<div class="bg-white rounded-lg shadow-sm p-4 mb-4 text-sm text-gray-500">
    <i class="bi bi-check-circle text-status-ontrack"></i> No interventions need attention right now.
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
<div class="mb-3 mt-2">
    <h2 class="text-base font-bold text-gray-800">3. Trend and outcomes</h2>
    <p class="text-xs text-gray-500">From submitted term reports — the after-the-term view.</p>
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
    <x-stat-card label="Failed this term" :value="$failingCount" accent="status-failing"
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
<div class="mb-1">
    <h3 class="text-sm font-semibold text-gray-800">Risk Level — submitted term reports</h3>
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
    <x-stat-card label="Low Risk" :value="$lowRisk" accent="status-ontrack" />
    <x-stat-card label="Moderate Risk" :value="$moderateRisk" accent="status-attention"
        :href="route('principal.dashboard', ['ar_risk_level' => 'moderate']).'#atRiskResultsContainer'" />
    <x-stat-card label="High Risk" :value="$highRisk" accent="status-risk"
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
    class="bg-white rounded-lg shadow-sm mb-4">
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
        <div style="height:180px;" class="{{ $hasTrendData ? '' : 'flex items-center justify-center' }}">
            @if($hasTrendData)
                <canvas id="termTrendChart"></canvas>
            @else
                <p class="text-xs text-gray-400 text-center px-4">No student/subject yet has scored evidence in all three components for any term — this fills in as that evidence is entered.</p>
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
    <x-panel title="Risk Distribution" subtitle="Overall student risk levels">
        <div style="height:180px;">
            <canvas id="riskDonutChart"></canvas>
        </div>
    </x-panel>
    <x-panel title="At-Risk per Section" subtitle="Moderate + High risk per section">
        <div style="height:180px;" class="{{ $hasSectionRiskData ? '' : 'flex items-center justify-center' }}">
            @if($hasSectionRiskData)
                <canvas id="sectionRiskChart"></canvas>
            @else
                <p class="text-xs text-gray-400 text-center px-4">No section has a submitted term report yet — this fills in once term reports come in.</p>
            @endif
        </div>
    </x-panel>
</div>
@endif

{{-- Recommendations — the DSS provides these; the Principal makes the
     final decision (see Intervention, a later phase). --}}
<x-panel title="Recommendations" class="mb-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

        <div class="flex gap-3 p-3 bg-status-ontrack/5 rounded-lg border border-status-ontrack/20">
            <span class="w-2 h-2 rounded-full bg-status-ontrack block mt-1 shrink-0"></span>
            <div>
                <p class="text-xs font-semibold text-status-ontrack">
                    Low Risk — {{ $lowRisk }} {{ $lowRisk === 1 ? 'student' : 'students' }}
                </p>
                <p class="text-xs text-status-ontrack mt-0.5">
                    Students are performing well. Continue regular monitoring and maintain current academic support strategies.
                </p>
            </div>
        </div>

        <div class="flex gap-3 p-3 bg-status-attention/5 rounded-lg border border-status-attention/20">
            <span class="w-2 h-2 rounded-full bg-status-attention block mt-1 shrink-0"></span>
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

        <div class="flex gap-3 p-3 bg-status-risk/5 rounded-lg border border-status-risk/20">
            <span class="w-2 h-2 rounded-full bg-status-risk block mt-1 shrink-0"></span>
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
<div class="bg-white rounded-lg shadow-sm p-3 mb-4">
    <form method="GET" id="atRiskFilterForm" class="flex flex-wrap gap-2 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Grade Level</label>
            <select name="ar_grade_level" id="atRiskGradeLevelSelect"
                    class="border rounded-md text-sm px-2 py-1.5 min-w-[130px]">
                <option value="">All grade levels</option>
                @foreach($atRiskGradeLevels as $gl)
                    <option value="{{ $gl }}" {{ request('ar_grade_level') == $gl ? 'selected' : '' }}>
                        Grade {{ $gl }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Section</label>
            <select name="ar_section_search" id="atRiskSectionSelect"
                    class="border rounded-md text-sm px-2 py-1.5 min-w-[130px]">
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
            <label class="block text-xs text-gray-500 mb-1">Track</label>
            <p id="atRiskTrackDisplay" class="border rounded-md text-sm px-2 py-1.5 min-w-[130px] bg-gray-50 text-gray-600">
                {{ $selectedSection->track->name ?? '—' }}
            </p>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Specialization</label>
            <p id="atRiskSpecializationDisplay" class="border rounded-md text-sm px-2 py-1.5 min-w-[150px] bg-gray-50 text-gray-600">
                {{ $selectedSection->specialization->name ?? '—' }}
            </p>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Risk Level</label>
            <select name="ar_risk_level" class="border rounded-md text-sm px-2 py-1.5 min-w-[120px]">
                <option value="">All levels</option>
                <option value="high" {{ request('ar_risk_level') === 'high' ? 'selected' : '' }}>High</option>
                <option value="moderate" {{ request('ar_risk_level') === 'moderate' ? 'selected' : '' }}>Moderate</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Assessment Component</label>
            <select name="ar_component" class="border rounded-md text-sm px-2 py-1.5 min-w-[160px]">
                <option value="">All components</option>
                <option value="written_work" {{ request('ar_component') === 'written_work' ? 'selected' : '' }}>Written Work</option>
                <option value="performance_task" {{ request('ar_component') === 'performance_task' ? 'selected' : '' }}>Performance Task</option>
                <option value="examination" {{ request('ar_component') === 'examination' ? 'selected' : '' }}>Examination</option>
            </select>
        </div>
        @if($atRiskFiltersActive)
            <a href="{{ route('principal.dashboard') }}" class="text-sm text-gray-500 hover:underline pb-1.5">Clear</a>
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
    <div id="atRiskResultsContainer" class="-m-4">
        @include('principal.partials.at-risk-results')
    </div>
</x-panel>
@endif

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const RISK_DATA = {
        low:      {{ $lowRisk }},
        moderate: {{ $moderateRisk }},
        high:     {{ $highRisk }},
    };
    const TERM_TRENDS       = @json($termTrends);
    const SECTION_RISK_DATA = @json($sectionRiskData);
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
