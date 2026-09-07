@extends('layouts.app')

@section('title', 'Students')
@section('subtitle', 'Per-student assessment evidence — read-only')

@section('content')

@include('partials.stale-risk-warning')

@php
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    $inTermStatusColors = ['On Track' => 'bg-status-ontrack/10 text-status-ontrack', 'Needs Attention' => 'bg-status-attention/10 text-status-attention', 'At Risk' => 'bg-status-risk/10 text-status-risk'];
    $riskLevelColors = ['low' => 'bg-status-ontrack/10 text-status-ontrack', 'moderate' => 'bg-status-attention/10 text-status-attention', 'high' => 'bg-status-risk/10 text-status-risk'];
    $baseParams = request()->except(['sort', 'dir', 'page']);

    $sortHeader = function (string $key, string $label) use ($sortKey, $sortDir, $baseParams) {
        $isActive = $sortKey === $key;
        $nextDir = $isActive && $sortDir === 'asc' ? 'desc' : 'asc';
        $url = route('principal.students', array_merge($baseParams, ['sort' => $key, 'dir' => $nextDir]));
        return ['url' => $url, 'active' => $isActive, 'icon' => $sortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill'];
    };
@endphp

{{-- WORK ORDER Part 6a item 4 — the one consistent filter-bar shape,
     standalone above the results panel rather than nested as its header
     row. Same field names/IDs throughout, so the cascading-select JS
     binding is untouched. --}}
<div class="bg-white rounded-lg shadow-sm p-3 mb-4">
    <div class="flex flex-col md:flex-row md:items-center gap-3">
        {{-- Term Selector — same pattern as the Adviser Assessments screen. --}}
        <div class="flex items-center gap-3 pb-3 border-b border-gray-100 md:pb-0 md:border-b-0 md:border-r md:pr-5 md:mr-1">
            <span class="text-sm font-semibold text-gray-700">Term:</span>
            <div class="flex gap-2">
                @foreach([1, 2, 3] as $t)
                <a href="{{ route('principal.students', array_merge(request()->except('page'), ['period' => $t])) }}"
                    class="px-4 py-1.5 rounded-full text-sm font-medium border transition
                        {{ $gradingPeriod == $t
                            ? 'bg-brand-700 text-white border-brand-700 shadow-sm'
                            : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50' }}">
                    Term {{ $t }}
                </a>
                @endforeach
            </div>
        </div>

        <form method="GET" id="psFilterForm" class="flex flex-wrap gap-2 items-end">
            <input type="hidden" name="period" value="{{ $gradingPeriod }}">
            @if($focus)<input type="hidden" name="focus" value="{{ $focus }}">@endif

            {{-- TASK 1 of "subject scoping and bulk threshold" — scoped to
                 the selected section via Subject::forSection() (see
                 Principal\StudentController::index()), same lookup the
                 adviser's own Assessments page trusts. Grouped by grade
                 level when no section is selected, so the full list is at
                 least navigable rather than one long flat list. --}}
            <div>
                <label class="block text-xs text-gray-500 mb-1">Subject</label>
                <select name="subject_id" class="border rounded-md text-sm px-2 py-1.5 min-w-[180px]">
                    @if($subjects->isEmpty())
                        <option value="">No subjects yet</option>
                    @elseif($resolvedSection)
                        <option value="">— Select Subject —</option>
                        @foreach($subjects as $s)
                            <option value="{{ $s->id }}" {{ ($subject->id ?? null) === $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                        @endforeach
                    @else
                        <option value="">— Select Subject —</option>
                        @foreach($subjects->groupBy('grade_level') as $gl => $group)
                            <optgroup label="Grade {{ $gl }}">
                                @foreach($group as $s)
                                    <option value="{{ $s->id }}" {{ ($subject->id ?? null) === $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    @endif
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Grade Level</label>
                <select name="grade_level" id="psGradeLevelSelect" class="border rounded-md text-sm px-2 py-1.5 min-w-[120px]">
                    <option value="">All grade levels</option>
                    @foreach($gradeLevels as $gl)
                        <option value="{{ $gl }}" {{ request('grade_level') == $gl ? 'selected' : '' }}>Grade {{ $gl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Section</label>
                <select name="section_id" id="psSectionSelect" class="border rounded-md text-sm px-2 py-1.5 min-w-[130px]">
                    <option value="">All sections</option>
                    @foreach($sections as $sec)
                        <option value="{{ $sec->id }}" data-grade-level="{{ $sec->grade_level }}"
                                {{ request('section_id') == $sec->id ? 'selected' : '' }}>
                            {{ $sec->name }} (Grade {{ $sec->grade_level }})
                        </option>
                    @endforeach
                </select>
            </div>
            {{-- "The Failing layer" TASK 2e — one status filter spanning
                 both signals, visually grouped so the two kinds are never
                 confused. --}}
            <div>
                <label class="block text-xs text-gray-500 mb-1">Status</label>
                <select name="status_filter" class="border rounded-md text-sm px-2 py-1.5 min-w-[190px]">
                    <option value="">All students</option>
                    <optgroup label="In-Term Status (evidence, during the term)">
                        <option value="On Track" {{ request('status_filter') === 'On Track' ? 'selected' : '' }}>On Track</option>
                        <option value="Needs Attention" {{ request('status_filter') === 'Needs Attention' ? 'selected' : '' }}>Needs Attention</option>
                        <option value="At Risk" {{ request('status_filter') === 'At Risk' ? 'selected' : '' }}>At Risk</option>
                    </optgroup>
                    <optgroup label="Official Grade (after verification)">
                        <option value="Failing" {{ request('status_filter') === 'Failing' ? 'selected' : '' }}>Failing (74 and below)</option>
                    </optgroup>
                </select>
            </div>
            {{-- TASK 4a of "clarity, progress, and visual design pass" — a
                 VIEW only, never a truth change: In-Term Status stays
                 computed fresh from evidence regardless of this checkbox
                 (see CLAUDE.md, INTERVENTION section). Clearing it always
                 restores the full list in one click. --}}
            <div class="flex items-center gap-1.5 pb-1.5">
                <input type="checkbox" name="hide_active_intervention" id="psHideActiveIntervention" value="1"
                       {{ request()->boolean('hide_active_intervention') ? 'checked' : '' }}
                       onchange="this.form.submit()">
                <label for="psHideActiveIntervention" class="text-xs text-gray-600">Hide learners with an active intervention</label>
            </div>
            @if(request('grade_level') || request('section_id') || request('status_filter') || request()->boolean('hide_active_intervention'))
                <a href="{{ route('principal.students', ['period' => $gradingPeriod, 'subject_id' => $subject->id ?? null]) }}" class="text-sm text-gray-500 hover:underline pb-1.5">Clear</a>
            @endif
        </form>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">

    {{-- TASK 4 of "close the intervention loop": reduce the clicks, never
         the decision — every row is still reviewed and confirmed in the
         dialog before anything is created (see storeBulk()).

         TASK 2 of "subject scoping and bulk threshold": the trigger no
         longer means "At Risk only" unconditionally — the dialog itself
         defaults to that threshold, but can be widened — so the button
         is renamed to something the threshold doesn't contradict, and
         this banner names both counts up front. --}}
    @if($bulkCandidates->isNotEmpty())
    @php
        $bulkAtRiskCount = $bulkCandidates->where('status', 'At Risk')->count();
        $bulkNeedsAttentionCount = $bulkCandidates->where('status', 'Needs Attention')->count();
        $bulkFailingCount = $bulkCandidates->where('status', 'Failing')->count();
    @endphp
    <div class="px-6 py-3 border-b bg-red-50 flex items-center justify-between gap-3">
        <span class="text-sm text-red-800">
            <i class="bi bi-exclamation-triangle-fill"></i>
            {{ $bulkAtRiskCount }} At Risk, {{ $bulkNeedsAttentionCount }} Needs Attention, {{ $bulkFailingCount }} Failing student{{ ($bulkAtRiskCount + $bulkNeedsAttentionCount + $bulkFailingCount) === 1 ? '' : 's' }} in this view.
        </span>
        <button type="button" onclick="window.showModal('bulkInterventionModal'); window.updateBulkInterventionSummary();"
                class="bg-red-700 text-white text-xs px-3 py-1.5 rounded-lg font-medium hover:bg-red-800 whitespace-nowrap">
            <i class="bi bi-clipboard2-plus"></i> Record interventions in bulk
        </button>
    </div>
    @endif

    @if($focus)
    <div class="bg-brand-50 text-brand-800 text-sm px-6 py-3 border-b flex items-center justify-between gap-3">
        <span>
            <i class="bi bi-funnel"></i>
            Showing students weakest in <strong>{{ $focusLabel }}</strong>{{ $subject ? ' for ' . $subject->name : '' }}, sorted lowest first.
        </span>
        <a href="{{ route('principal.students', request()->except(['focus', 'sort', 'dir', 'page'])) }}" class="text-brand-700 hover:underline whitespace-nowrap">
            <i class="bi bi-x-circle"></i> Remove filter
        </a>
    </div>
    @endif

    @if($subject && $students && $students->isNotEmpty())
    {{-- "Correctness and interface pass" TASK 4c — always-visible summary
         shortened to exactly three lines; everything else (the reasoning
         this system's documentation quotes) moved into the collapsed
         "How to read this table" panel below, corrected but not reworded
         beyond what TASK 4 specifically calls for. --}}
    <div class="mt-1 mx-6 text-xs text-gray-500 space-y-0.5">
        <p><strong>Computed</strong> is the raw weighted evidence. <strong>Report card</strong> is the grade after DepEd transmutation.</p>
        <p><strong>In-Term Status</strong> is based on Computed, not the report card grade.</p>
        <p>A learner can be At Risk while their report card still passes — that is intentional.</p>
    </div>
    <details class="mt-1 mx-6 text-xs text-gray-500">
        <summary class="cursor-pointer select-none font-medium text-gray-600">How to read this table</summary>
        <p class="mt-2">
            <strong>Report card</strong> is the reported grade — DepEd DO 8, s. 2015 for Grade 12, or DO 015, s. 2026's
            adjusted table for Grade 11 starting SY 2026-2027 — <strong>Computed</strong> is the raw weighted evidence
            behind it, unchanged by transmutation.
        </p>
        {{-- Standing property of the output, not a notification — never
             dismissible. See the "live in-term risk + stale data guard"
             prompt: these two signals must never look interchangeable. --}}
        <p class="mt-2">
            <strong>In-Term Status</strong> reflects only the assessment evidence collected so far this term, for this one
            subject — it updates as more is entered and is not a prediction of the final grade.
            <strong>Risk Level</strong> comes from the submitted term report, covers every subject, and factors in the
            trend across terms. They answer different questions and are shown separately on purpose.
        </p>
        {{-- "Correctness and interface pass" TASK 4a — BUG FIX: 60% is
             DO 8, s. 2015's passing anchor only. Under DO 015, s. 2026
             (every Grade 11 learner this school year) the anchor is 70 —
             the old wording was wrong for half the learners on screen.
             Worded so it never hardcodes either number. --}}
        <p class="mt-2">
            <strong>In-Term Status is based on the Computed grade, not the report card grade</strong> — transmutation can
            lift a failing raw score into a passing reported grade, and a status built on the report card grade
            would only flag a learner once their raw mastery had already fallen below the passing anchor of their
            curriculum — well past the point where support within the term could still help. A student flagged here
            with a report card grade already at 75+ is marked <strong>passing on paper</strong> below.
        </p>
        {{-- "Workflow completion pass" TASK 2e — states the OUTCOME vs.
             EARLY-WARNING distinction explicitly, not just what each
             signal happens to mean. --}}
        <p class="mt-2">
            <strong>Failing</strong> is an outcome recorded after the official grade is encoded. <strong>At
            Risk</strong> and <strong>Needs Attention</strong> are early warnings from assessment evidence during the
            term, and are the ones to act on. A student can show At Risk while their official grade is still
            passing, and that is intentional — it is an early warning, not a prediction of the final grade.
        </p>
        {{-- "Correctness and interface pass" TASK 4b — the state this
             covered (a grade with complete evidence but no published
             transmutation band) cannot currently occur: both schemes now
             have all 41 bands seeded (see CLAUDE.md's "Known limitations").
             Kept here, briefly, only because a future curriculum with no
             table entered yet could still produce it. --}}
        <p class="mt-2 text-gray-400">
            A grade shown as <strong>Not available</strong> would mean complete evidence but no transmutation table
            entered yet for that curriculum — not currently possible for Grade 11 or 12, only if a new curriculum is
            introduced before its table is seeded.
        </p>
    </details>
    @endif

    @if($subjects->isEmpty() && $sectionMissingTrackOrSpec)
        {{-- TASK 1 of "subject scoping and bulk threshold" — a real data
             fault, named explicitly rather than presenting as a plain
             empty dropdown: Subject::forSection() can never match an
             elective without both a track and a specialization set. --}}
        <x-empty-state icon="bi-exclamation-triangle" message="This section has no subjects because its Track and/or Specialization is not set."
            hint="Set {{ $resolvedSection->name }}'s Track and Specialization under Admin → Sections, then select it again here." />
    @elseif($subjects->isEmpty() && $resolvedSection)
        <x-empty-state icon="bi-book" message="This section has no subjects." hint="No core subjects exist for Grade {{ $resolvedSection->grade_level }}, and no elective matches its track/specialization. Subjects are created under Admin → Subjects." />
    @elseif($subjects->isEmpty())
        <x-empty-state icon="bi-book" message="No subjects exist yet." hint="Subjects are created under Admin → Subjects." />
    @elseif(!$subject)
        {{-- TASK 1 of "subject scoping and bulk threshold" — reached when
             a section change just cleared an invalid subject pairing
             (see StudentController::index()): say so plainly rather than
             the generic "no students match" text, which would misdescribe
             what actually happened. --}}
        <x-empty-state icon="bi-book" message="Select a subject to view students."
            hint="The Subject list above now shows only what this section takes." />
    @elseif(!$students || $students->isEmpty())
        <x-empty-state icon="bi-people" message="No students match the current filters."
            hint="Try clearing the Grade Level/Section filters, or check that this subject's section has students assigned." />
    @else
    {{-- TASK 5 of "terminology, transmutation, and interface cleanup" —
         row count above the table, and the body scrolls within a fixed-
         height container (header stays pinned) instead of the whole page
         scrolling once there are more than a handful of rows. --}}
    <p class="text-xs text-gray-500 px-6 pt-1 pb-2">
        <x-count-label :count="$students->total()" noun="student" />
    </p>
    <div class="tbl-scroll">
    <table class="tbl tbl-sticky">
        <thead>
            <tr>
                @php $h = $sortHeader('name', 'Student'); @endphp
                <th scope="col">
                    <a href="{{ $h['url'] }}" class="inline-flex items-center gap-1 hover:text-brand-700">Student @if($h['active'])<i class="bi {{ $h['icon'] }} text-[10px]"></i>@endif</a>
                </th>
                <th scope="col">LRN</th>
                {{-- "Correctness and interface pass" TASK 5c — explicit
                     widths so numeric columns don't shift width between
                     pages, and right-aligned like every other number in
                     this row (names stay left-aligned). --}}
                <th scope="col" class="tbl-num w-16">Grade</th>
                <th scope="col">Section</th>
                <th scope="col">Track / Specialization</th>
                <th scope="col">Adviser</th>
                @foreach(['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'] as $key => $label)
                    @php $h = $sortHeader($key, $label); @endphp
                    <th scope="col" class="tbl-num w-28">
                        <a href="{{ $h['url'] }}" class="inline-flex items-center gap-1 hover:text-brand-700">{{ $label }} @if($h['active'])<i class="bi {{ $h['icon'] }} text-[10px]"></i>@endif</a>
                    </th>
                @endforeach
                {{-- "Workflow completion pass" TASK 4b. TASK 1a of "clarity,
                     progress, and visual design" — "evidence"/"transmuted"
                     sub-labels so the reader knows which number to trust
                     without opening "How to read this table"; the sort key
                     ($h['url']) is unchanged, only the visible label moved. --}}
                @php $h = $sortHeader('computed_grade', 'Computed'); @endphp
                <th scope="col" class="tbl-num w-24" title="Raw weighted evidence. This is what In-Term Status uses.">
                    <a href="{{ $h['url'] }}" class="inline-flex flex-col items-end hover:text-brand-700">
                        <span class="inline-flex items-center gap-1">Computed @if($h['active'])<i class="bi {{ $h['icon'] }} text-[10px]"></i>@endif</span>
                        <span class="normal-case font-normal text-gray-400 text-[10px] tracking-normal">evidence</span>
                    </a>
                </th>
                @php $h = $sortHeader('transmuted_grade', 'Report Card'); @endphp
                <th scope="col" class="tbl-num w-24" title="The reported grade after DepEd's transmutation table. Can be higher than the computed grade.">
                    <a href="{{ $h['url'] }}" class="inline-flex flex-col items-end hover:text-brand-700">
                        <span class="inline-flex items-center gap-1">Report Card @if($h['active'])<i class="bi {{ $h['icon'] }} text-[10px]"></i>@endif</span>
                        <span class="normal-case font-normal text-gray-400 text-[10px] tracking-normal">transmuted</span>
                    </a>
                </th>
                {{-- TASK 2 of "dashboard structure and upload safeguards" —
                     "(this subject)" contrasts deliberately with the
                     adviser dashboard's "Overall In-Term Status" (worst
                     across every subject) — the two are different numbers
                     on purpose, see Adviser\DashboardController::buildInTermRows(). --}}
                @php $h = $sortHeader('in_term_status', 'In-Term Status'); @endphp
                <th scope="col">
                    <a href="{{ $h['url'] }}" class="inline-flex items-center gap-1 hover:text-brand-700">In-Term Status (this subject) @if($h['active'])<i class="bi {{ $h['icon'] }} text-[10px]"></i>@endif</a>
                </th>
                <th scope="col">Focus Area</th>
                <th scope="col">Risk Level</th>
                <th scope="col" class="text-center">Intervention</th>
            </tr>
        </thead>
        <tbody>
            @foreach($students as $row)
            <tr>
                <td class="font-medium text-gray-800 whitespace-nowrap">
                    <a href="{{ route('principal.students.show', ['student' => $row['student']->id, 'period' => $gradingPeriod]) }}" class="hover:underline hover:text-brand-700">
                        {{ $row['student']->last_name }}, {{ $row['student']->first_name }}
                    </a>
                    @if($row['has_additional_support'])
                        {{-- "Workflow completion pass" TASK 3a — neutral,
                             informational, never a judgment. See CLAUDE.md's
                             "Known limitations" open policy question this
                             exists to make visible. --}}
                        <span class="block text-[10px] font-normal text-gray-400" title="At least one within-term additional-support item (marked by the Adviser) contributed to this grade.">
                            <i class="bi bi-info-circle"></i> includes additional practice
                        </span>
                    @endif
                </td>
                <td class="text-gray-500 whitespace-nowrap">{{ $row['student']->lrn }}</td>
                <td class="tbl-num text-gray-600">{{ $row['section']->grade_level }}</td>
                <td class="text-gray-600 whitespace-nowrap">{{ $row['section']->name }}</td>
                <td class="text-xs text-gray-600 whitespace-nowrap">
                    {{ $row['section']->track->name ?? 'Not set' }}
                    <span class="block text-gray-400">{{ $row['section']->specialization->name ?? 'Not set' }}</span>
                </td>
                <td class="text-gray-600 whitespace-nowrap">{{ $row['section']->adviser?->name ?? 'Unassigned' }}</td>
                @foreach(['written_work', 'performance_task', 'examination'] as $key)
                    @php $c = $row['components'][$key]; @endphp
                    <td class="tbl-num">
                        @if($c['percentage'] === null)
                            <span class="text-gray-300 text-xs">—</span>
                        @else
                            <span class="{{ $c['status'] === 'On Track' ? 'text-status-ontrack' : 'text-status-risk font-medium' }}">
                                {{ number_format($c['percentage'], 2) }}%
                            </span>
                            <span class="block text-xs text-gray-400">
                                {{ $c['gap'] >= 0 ? '+' : '' }}{{ number_format($c['gap'], 1) }}
                            </span>
                        @endif
                    </td>
                @endforeach
                <td class="tbl-num {{ $row['complete'] ? 'text-gray-800' : 'text-gray-300' }}">
                    @if($row['complete'])
                        {{ number_format($row['computed_grade'], 2) }}
                        {{-- TASK 1b of "clarity, progress, and visual design" —
                             the gap that makes an At Risk/Needs Attention badge
                             self-explanatory without reasoning through
                             transmutation first. --}}
                        @if($row['computed_grade'] < 75)
                            <span class="block text-[10px] text-gray-400 font-normal">{{ number_format(75 - $row['computed_grade'], 2) }} below the 75 target</span>
                        @endif
                    @else
                        —
                    @endif
                </td>
                <td class="tbl-num text-xs {{ $row['complete'] && $row['transmuted_grade'] !== null ? 'text-gray-500' : 'text-gray-300' }}">
                    @if(!$row['complete'])
                        —
                    @elseif($row['official_grade'] && $row['official_grade']->is_verified && $row['official_grade']->is_provisional)
                        {{-- "The Failing layer" 2b — a verified but PROVISIONAL
                             grade is never Failing: it came from a fallback
                             scheme, not the subject's real one, so it is not
                             yet an official grade. --}}
                        <span class="text-gray-400 font-normal text-xs" title="Computed using the {{ \App\Services\TransmutationService::schemeLabel($row['official_grade']->provisional_scheme) }} table because the real scheme's bands are not yet entered — not yet an official grade.">
                            Awaiting official grade
                        </span>
                    @elseif($row['transmuted_grade'] !== null)
                        {{ number_format($row['transmuted_grade'], 2) }}
                        @if($row['transmuted_grade'] >= 75 && $row['computed_grade'] < 75)
                            {{-- "Workflow completion pass" TASK 4a — a
                                 persistent marker right where the number
                                 lifted above 75 is actually read, not only
                                 in the In-Term Status column. --}}
                            <span class="block text-[10px] text-gray-500" title="Transmutation lifted the reported grade above 75 — the raw evidence (Computed) is still below the 75% target.">
                                <i class="bi bi-arrow-up-short"></i> lifted from {{ number_format($row['computed_grade'], 2) }}
                            </span>
                        @endif
                        @if($row['is_failing'])
                            {{-- "The Failing layer" 2a — shown here, next to
                                 the Official Grade, NEVER in the In-Term
                                 Status column: these are different signals
                                 and must not look like one. --}}
                            <span class="block text-[10px] font-bold text-status-failing" title="Official grade of {{ number_format($row['official_grade']->grade, 2) }} — 74 or below.">
                                <i class="bi bi-x-octagon-fill"></i> Failing
                            </span>
                        @endif
                        @if($row['transmutation_provisional'])
                            <span class="block text-[10px] text-amber-600" title="Provisional — computed using the {{ \App\Services\TransmutationService::schemeLabel($row['transmutation_fallback_scheme']) }} table because the {{ \App\Services\TransmutationService::schemeLabel($row['transmutation_scheme']) }} bands are not yet entered.">
                                <i class="bi bi-exclamation-triangle-fill"></i> Provisional
                            </span>
                        @endif
                    @else
                        <span class="text-[10px] text-amber-600" title="No transmuted grade is available yet for the {{ $row['transmutation_scheme'] }} scheme at this computed grade — the transmutation table for this curriculum isn't fully entered yet.">
                            <i class="bi bi-exclamation-triangle-fill"></i> Not available
                        </span>
                    @endif
                </td>
                <td>
                    @include('partials.in-term-status-badge', ['its' => $row['in_term_status'], 'transmutedGrade' => $row['transmuted_grade']])
                </td>
                <td>
                    @if($row['weakest_component'] && ($row['components'][$row['weakest_component']]['status'] ?? null) === 'Needs Attention')
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-status-attention/10 text-status-attention">
                            {{ $componentLabels[$row['weakest_component']] ?? $row['weakest_component'] }}
                        </span>
                    @elseif($row['weakest_component'])
                        <span class="text-xs text-gray-400">On track</span>
                    @else
                        <span class="text-xs text-gray-300">No data yet</span>
                    @endif
                </td>
                <td>
                    @if($row['risk_level'] ?? null)
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $riskLevelColors[$row['risk_level']] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($row['risk_level']) }}
                        </span>
                    @else
                        <span class="text-xs text-gray-300" title="No term report submitted yet for this term.">Not yet available</span>
                    @endif
                </td>
                <td class="text-center">
                    @if($row['existing_intervention'])
                        <a href="{{ route('principal.interventions') }}"
                           class="inline-flex items-center gap-1 text-xs text-gray-600 hover:text-brand-700 hover:underline whitespace-nowrap"
                           title="An open intervention already exists for this student and subject — see Interventions.">
                            <i class="bi bi-clipboard2-check"></i> {{ ucfirst(str_replace('_', ' ', $row['existing_intervention']->status)) }}
                        </a>
                    @else
                        @php
                            $modalPayload = [
                                'student_id'              => $row['student']->id,
                                'student_name'             => $row['student']->last_name . ', ' . $row['student']->first_name,
                                'section_name'             => $row['section']->name,
                                'subject_id'               => $subject->id,
                                'subject_name'             => $subject->name,
                                'grading_period'           => $gradingPeriod,
                                'components'               => $row['components'],
                                'weakest_component'        => $row['weakest_component'],
                                'weakest_component_label'  => $row['weakest_component'] ? ($componentLabels[$row['weakest_component']] ?? $row['weakest_component']) : null,
                                'has_risk_result'          => $row['risk_result_exists'],
                                'recommendation'           => $row['recommendation'],
                            ];
                        @endphp
                        <button type="button" onclick='openRecordInterventionModal(@json($modalPayload))'
                                class="inline-flex items-center gap-1 text-xs text-brand-700 border border-brand-200 rounded px-2 py-1 hover:bg-brand-50 whitespace-nowrap">
                            <i class="bi bi-clipboard2-plus"></i> Record
                        </button>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    @if($students->hasPages())
    <div class="px-6 py-4 border-t flex flex-col items-center gap-2 text-sm text-gray-500">
        <div class="flex items-center gap-1">
            @if($students->onFirstPage())
                <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">← Prev</span>
            @else
                <a href="{{ $students->previousPageUrl() }}" class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">← Prev</a>
            @endif
            <span class="px-3 py-1 rounded border bg-brand-700 text-white font-medium">{{ $students->currentPage() }}</span>
            @if($students->hasMorePages())
                <a href="{{ $students->nextPageUrl() }}" class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">Next →</a>
            @else
                <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">Next →</span>
            @endif
        </div>
        <span class="text-xs">Showing {{ $students->firstItem() }}–{{ $students->lastItem() }} of <x-count-label :count="$students->total()" noun="student" /></span>
    </div>
    @endif
    @endif
</div>

{{-- RECORD INTERVENTION MODAL — one shared modal, populated per row by
     openRecordInterventionModal() below (same pattern as
     openUserEditModal() etc. elsewhere in this app: row data passed as
     JSON to the onclick handler rather than one modal per row). --}}
<div id="recordInterventionModal" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Record Intervention</h3>
            <button type="button" onclick="closeRecordInterventionModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <div class="bg-gray-50 rounded-lg p-3 mb-4 text-sm">
            <p class="font-medium text-gray-800" id="rivStudentName"></p>
            <p class="text-xs text-gray-500" id="rivContext"></p>
        </div>

        <div class="grid grid-cols-3 gap-2 mb-3 text-center text-xs">
            <div class="bg-gray-50 rounded p-2">
                <p class="text-gray-400">Written Work</p>
                <p class="font-semibold text-gray-700" id="rivWW">—</p>
            </div>
            <div class="bg-gray-50 rounded p-2">
                <p class="text-gray-400">Performance Task</p>
                <p class="font-semibold text-gray-700" id="rivPT">—</p>
            </div>
            <div class="bg-gray-50 rounded p-2">
                <p class="text-gray-400">Examination</p>
                <p class="font-semibold text-gray-700" id="rivExam">—</p>
            </div>
        </div>
        <p class="text-xs text-gray-500 mb-4">
            Focus Area: <span class="font-medium text-gray-700" id="rivFocusArea">—</span>
        </p>

        <div id="rivRecommendationBox" class="hidden bg-brand-50 border border-brand-100 text-brand-800 text-xs p-3 rounded-lg mb-4">
            <p class="font-medium">DSS Recommendation: <span id="rivRecommendationType"></span></p>
            <p class="mt-1" id="rivRecommendationReason"></p>
        </div>
        <p id="rivNoRiskResultNote" class="hidden text-xs text-gray-500 mb-4">
            <i class="bi bi-info-circle"></i> The DSS recommendation becomes available once the adviser submits the term report. You can still record an intervention now, from this assessment evidence.
        </p>

        <form method="POST" action="{{ route('principal.interventions.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="student_id" id="rivStudentId">
            <input type="hidden" name="subject_id" id="rivSubjectId">
            <input type="hidden" name="grading_period" id="rivGradingPeriod">
            <input type="hidden" name="recommendation_reason" id="rivRecommendationReasonField">

            <div>
                <label class="block text-sm text-gray-600 mb-1">Intervention Type</label>
                <select name="recommended_type" id="rivTypeSelect" required
                        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    @foreach($interventionTypes as $t)
                        <option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Notes <span class="text-gray-400 text-xs">(optional)</span></label>
                <textarea name="principal_notes" rows="3"
                          class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400"
                          placeholder="Any additional context for this decision..."></textarea>
            </div>

            {{-- "Decision flow, report scoping, and dashboard pass" TASK
                 1b — unticked by default: confirming this form IS the
                 Principal's decision (see store()), reviewing this
                 student is the normal case. Ticking this defers that
                 decision instead — the adviser cannot act on it until
                 it's later approved from the Interventions page. --}}
            <label class="flex items-start gap-2 text-xs text-gray-600 bg-gray-50 rounded-lg p-3 cursor-pointer">
                <input type="checkbox" name="recommendation_only" value="1" class="mt-0.5">
                <span>Record as a recommendation only — I will decide later</span>
            </label>
            <p class="text-xs text-gray-500">
                Confirming records this as an approved decision and sends it to the adviser to act on. Tick the box above
                if you want to decide later instead.
            </p>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeRecordInterventionModal()" class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit" class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                    Record Intervention
                </button>
            </div>
        </form>
    </div>
</div>

{{-- RECORD INTERVENTIONS IN BULK MODAL — TASK 4 of "close the
     intervention loop". Built server-side from $bulkCandidates
     (Principal\StudentController::buildBulkInterventionCandidates()),
     covering the WHOLE currently filtered set, not just this page. Every
     row is reviewed here — nothing is created until Confirm actually
     submits this form; Cancel/closing reaches no route at all.

     TASK 1 of "bulk dialog and intervention closure": Confirm must never
     be an enabled button with nothing to confirm.

     TASK 2 of "subject scoping and bulk threshold": a threshold toggle
     (At Risk only / At Risk + Needs Attention) decides, CLIENT-SIDE,
     which of the rows below are in scope — see applyBulkThreshold().
     "At Risk only" is the default on every load; nothing here changes
     that default or auto-widens it.

     "The Failing layer" TASK 3a — a genuine THIRD threshold, "Failing
     only," not a combination of the other two: selecting it shows ONLY
     students where isFailing() is true, regardless of In-Term Status.
     Each candidate row's own 'status' (set in
     buildBulkInterventionCandidates()) is exactly one of 'Failing',
     'At Risk', or 'Needs Attention' — a row that is both Failing and At
     Risk is bucketed under Failing only, so it is never double-counted
     across thresholds. --}}
@if($bulkCandidates->isNotEmpty())
@php
    $atRiskRows = $bulkCandidates->where('status', 'At Risk')->values();
    $matchesAtRisk = $atRiskRows->count();
    $skippedAtRisk = $atRiskRows->where('has_existing_intervention', true)->count();
    $recordableAtRisk = $matchesAtRisk - $skippedAtRisk;
    $failingCount = $bulkCandidates->where('status', 'Failing')->count();
    // The widest possible threshold's recordable count — only when THIS
    // is zero is there truly nothing to confirm under any threshold.
    $recordableAll = $bulkCandidates->where('has_existing_intervention', false)->count();
    $skippedAll = $bulkCandidates->where('has_existing_intervention', true)->count();
@endphp
<div id="bulkInterventionModal" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-lg w-full max-w-2xl p-6 transition-all duration-200 max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Record Interventions in Bulk</h3>
            <button type="button" onclick="window.hideModal('bulkInterventionModal')" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($recordableAll === 0)
            {{-- Nothing recordable under ANY threshold — there is nothing
                 to confirm here, so no create action is offered at all
                 (not merely disabled). --}}
            <div class="flex-1 flex flex-col items-center justify-center text-center py-10 border rounded-lg mb-2">
                <i class="bi bi-check2-circle text-2xl text-gray-300 mb-2"></i>
                <p class="text-sm text-gray-600 max-w-sm">All {{ $skippedAll }} At Risk/Needs Attention/Failing student{{ $skippedAll === 1 ? '' : 's' }} in this view already {{ $skippedAll === 1 ? 'has' : 'have' }} an open intervention for this subject in Term {{ $gradingPeriod }}.</p>
                <a href="{{ route('principal.interventions') }}" class="text-sm text-brand-700 hover:underline mt-3">
                    <i class="bi bi-clipboard2-pulse"></i> Go to Interventions
                </a>
            </div>
            <div class="flex justify-end pt-2">
                <button type="button" onclick="window.hideModal('bulkInterventionModal')" class="px-4 py-2 text-sm text-gray-500">Close</button>
            </div>
        @else
            <p class="text-xs text-gray-500 mb-3">
                {{ $subject->name ?? '' }} — Term {{ $gradingPeriod }}. Review each student below: uncheck anyone who should not be
                included, adjust the type if needed, then confirm once. Confirming records these interventions as approved
                decisions and sends them to the adviser to act on. Tick "record as a recommendation only" below if you want
                to decide later instead.
            </p>

            <div class="flex items-center gap-4 mb-3 text-sm border rounded-lg px-3 py-2 bg-gray-50 flex-wrap">
                <span class="text-gray-600 font-medium shrink-0">Include:</span>
                <label class="flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" name="bivThresholdChoice" value="narrow" checked onchange="window.applyBulkThreshold()">
                    At Risk only
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" name="bivThresholdChoice" value="wide" onchange="window.applyBulkThreshold()">
                    At Risk and Needs Attention
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" name="bivThresholdChoice" value="failing" onchange="window.applyBulkThreshold()">
                    Failing only (official grade 74 and below)
                </label>
            </div>

            <form method="POST" action="{{ route('principal.interventions.bulk-store') }}" class="flex flex-col flex-1 min-h-0">
                @csrf
                <input type="hidden" name="subject_id" value="{{ $subject->id ?? '' }}">
                <input type="hidden" name="grading_period" value="{{ $gradingPeriod }}">

                <p id="bivSummary" class="text-xs text-gray-600 mb-2">
                    {{ $matchesAtRisk }} match, {{ $skippedAtRisk }} skipped (already has an open intervention in Term {{ $gradingPeriod }}), {{ $recordableAtRisk }} will be recorded.
                </p>

                <div class="flex-1 overflow-y-auto border rounded-lg divide-y divide-gray-100 mb-4">
                    @foreach($bulkCandidates as $row)
                    @php
                        // "The Failing layer" TASK 3a/3c — a row's own
                        // 'status' is exactly one of these three, so the
                        // slug below always matches exactly one threshold.
                        $statusSlug = ['At Risk' => 'at_risk', 'Needs Attention' => 'needs_attention', 'Failing' => 'failing'][$row['status']] ?? 'at_risk';
                        $isAtRisk = $statusSlug === 'at_risk';
                        $isDisabled = $row['has_existing_intervention'] || !$isAtRisk;
                        $statusColor = ['at_risk' => 'text-status-risk', 'needs_attention' => 'text-status-attention', 'failing' => 'text-status-failing font-semibold'][$statusSlug];
                    @endphp
                    <div class="p-3 flex items-center gap-3 {{ $row['has_existing_intervention'] ? 'bg-gray-50' : '' }} biv-row"
                         data-status="{{ $statusSlug }}"
                         data-skipped="{{ $row['has_existing_intervention'] ? '1' : '0' }}"
                         {{ $isAtRisk ? '' : 'hidden' }}>
                        <input type="checkbox" name="included[]" value="{{ $row['student_id'] }}"
                               id="bivInclude{{ $row['student_id'] }}"
                               {{ $isDisabled ? 'disabled' : 'checked' }}
                               onchange="window.updateBulkInterventionSummary()"
                               class="mt-0.5 biv-checkbox">
                        <div class="flex-1 min-w-0">
                            <label for="bivInclude{{ $row['student_id'] }}" class="text-sm font-medium text-gray-800 block">
                                {{ $row['student_name'] }}
                                <span class="text-xs font-normal {{ $statusColor }}">({{ $row['status'] }})</span>
                            </label>
                            @if($row['has_existing_intervention'])
                                <p class="text-xs text-gray-400 mt-0.5">
                                    <i class="bi bi-info-circle"></i> Already has an open intervention for this subject in Term {{ $gradingPeriod }} — skipped.
                                </p>
                            @elseif($row['weakest_component_label'])
                                <p class="text-xs text-gray-400 mt-0.5">Focus Area: {{ $row['weakest_component_label'] }}</p>
                            @endif
                        </div>
                        @unless($row['has_existing_intervention'])
                        <input type="hidden" name="statuses[{{ $row['student_id'] }}]" value="{{ $row['status'] }}">
                        <select name="types[{{ $row['student_id'] }}]" class="border rounded-md text-xs px-2 py-1.5 shrink-0">
                            @foreach($interventionTypes as $t)
                                <option value="{{ $t }}" {{ $t === $row['suggested_type'] ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
                            @endforeach
                        </select>
                        @endunless
                    </div>
                    @endforeach
                </div>

                <div>
                    <label class="block text-sm text-gray-600 mb-1">Notes <span class="text-gray-400 text-xs">(optional, applied to every intervention recorded here)</span></label>
                    <textarea name="notes" rows="2"
                              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400"
                              placeholder="Any additional context for this batch..."></textarea>
                </div>

                {{-- "Decision flow, report scoping, and dashboard pass"
                     TASK 1b — unticked by default, same meaning as the
                     single-record modal's checkbox, applied to the whole
                     batch. --}}
                <label class="flex items-start gap-2 text-xs text-gray-600 bg-gray-50 rounded-lg p-2.5 mt-3 cursor-pointer">
                    <input type="checkbox" name="recommendation_only" value="1" class="mt-0.5">
                    <span>Record as a recommendation only — I will decide later</span>
                </label>

                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="window.hideModal('bulkInterventionModal')" class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                    <button type="submit" id="bivConfirmBtn" {{ $recordableAtRisk === 0 ? 'disabled' : '' }}
                            class="bg-red-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-red-800 disabled:bg-gray-300 disabled:text-gray-500 disabled:cursor-not-allowed disabled:hover:bg-gray-300">
                        {{ $recordableAtRisk === 0 ? 'Nothing to record' : 'Record ' . $recordableAtRisk . ' intervention' . ($recordableAtRisk === 1 ? '' : 's') }}
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
    const RIV_TYPE_LABELS = {
        remediation: 'Additional Practice and Re-teaching',
        additional_learning_activity: 'Additional Learning Activity',
        additional_performance_task: 'Additional Performance Task',
        teacher_monitoring: 'Teacher Monitoring',
        attendance_monitoring: 'Attendance Monitoring',
        parent_conference: 'Parent/Guardian Conference',
        other: 'Other',
    };

    function rivFormatComponent(c) {
        if (!c || c.percentage === null || c.percentage === undefined) return '—';
        return Number(c.percentage).toFixed(2) + '%';
    }

    window.openRecordInterventionModal = function (data) {
        document.getElementById('rivStudentName').textContent = data.student_name;
        document.getElementById('rivContext').textContent =
            data.section_name + ' — ' + data.subject_name + ', Term ' + data.grading_period;

        document.getElementById('rivWW').textContent = rivFormatComponent(data.components.written_work);
        document.getElementById('rivPT').textContent = rivFormatComponent(data.components.performance_task);
        document.getElementById('rivExam').textContent = rivFormatComponent(data.components.examination);
        document.getElementById('rivFocusArea').textContent = data.weakest_component_label || 'No data yet';

        document.getElementById('rivStudentId').value = data.student_id;
        document.getElementById('rivSubjectId').value = data.subject_id;
        document.getElementById('rivGradingPeriod').value = data.grading_period;

        const recBox = document.getElementById('rivRecommendationBox');
        const noRiskNote = document.getElementById('rivNoRiskResultNote');
        const typeSelect = document.getElementById('rivTypeSelect');
        const reasonField = document.getElementById('rivRecommendationReasonField');

        if (data.has_risk_result && data.recommendation) {
            recBox.classList.remove('hidden');
            noRiskNote.classList.add('hidden');
            document.getElementById('rivRecommendationType').textContent = RIV_TYPE_LABELS[data.recommendation.type] || data.recommendation.type;
            document.getElementById('rivRecommendationReason').textContent = data.recommendation.reason;
            typeSelect.value = data.recommendation.type;
            reasonField.value = data.recommendation.reason;
        } else {
            recBox.classList.add('hidden');
            noRiskNote.classList.remove('hidden');
            typeSelect.selectedIndex = 0;
            reasonField.value = '';
        }

        window.showModal('recordInterventionModal');
    };

    window.closeRecordInterventionModal = function () {
        window.hideModal('recordInterventionModal');
    };

    // TASK 2 of "subject scoping and bulk threshold" — the threshold
    // radios decide which rows are IN SCOPE (visible + eligible to be
    // checked); this never touches rows already disabled for their own
    // reason (an existing open intervention). "At Risk only" stays
    // checked/selected by default on every load — this only runs when
    // the Principal explicitly picks a radio.
    //
    // "The Failing layer" TASK 3a — 'failing' is a genuine THIRD choice:
    // it shows ONLY rows tagged data-status="failing", never a union
    // with At Risk/Needs Attention rows.
    window.applyBulkThreshold = function () {
        const choice = document.querySelector('input[name="bivThresholdChoice"]:checked')?.value || 'narrow';

        document.querySelectorAll('#bulkInterventionModal .biv-row').forEach(function (row) {
            const status = row.dataset.status;
            const inScope = choice === 'failing'
                ? status === 'failing'
                : (status === 'at_risk' || (choice === 'wide' && status === 'needs_attention'));
            const alreadySkipped = row.dataset.skipped === '1';

            row.hidden = !inScope;

            const checkbox = row.querySelector('.biv-checkbox');
            if (!checkbox) return;

            if (!inScope || alreadySkipped) {
                checkbox.disabled = true;
                checkbox.checked = false;
            } else {
                // Entering scope (or already was) and not skipped —
                // reset to checked, same "reviewed and can uncheck"
                // starting point as the At-Risk-only default.
                checkbox.disabled = false;
                checkbox.checked = true;
            }
        });

        window.updateBulkInterventionSummary();
    };

    // TASK 1 of "bulk dialog and intervention closure" — keeps the
    // Confirm button's enabled/disabled state and label in sync with
    // what will actually happen as the Principal checks/unchecks rows,
    // so a click always does exactly what the button says.
    //
    // TASK 2 of "subject scoping and bulk threshold" — "matches" and
    // "skipped" are now counted from rows currently IN SCOPE (not
    // [hidden]) rather than the whole candidate list, so the three
    // numbers shown always describe the currently-selected threshold.
    window.updateBulkInterventionSummary = function () {
        // Deliberately no "if empty, bail" guard — zero rows in scope
        // under the current threshold (e.g. no At Risk students at all,
        // only Needs Attention ones) is a real, valid state that still
        // needs the summary text and Confirm button to reflect it.
        const rows = document.querySelectorAll('#bulkInterventionModal .biv-row:not([hidden])');

        let matches = 0, skipped = 0, recordable = 0;
        rows.forEach(function (row) {
            matches++;
            if (row.dataset.skipped === '1') {
                skipped++;
                return;
            }
            const checkbox = row.querySelector('.biv-checkbox');
            if (checkbox && checkbox.checked) recordable++;
        });

        const summary = document.getElementById('bivSummary');
        if (summary) {
            // "Correctness and interface pass" TASK 1d — names the actual
            // blocking term rather than a generic "an open intervention",
            // read from the same hidden grading_period field the form itself
            // submits, so it can never drift from what the server checked.
            const term = document.querySelector('#bulkInterventionModal input[name="grading_period"]')?.value;
            summary.textContent = matches + ' match, ' + skipped + ' skipped (already has an open intervention in Term ' + term + '), ' + recordable + ' will be recorded.';
        }

        const btn = document.getElementById('bivConfirmBtn');
        if (btn) {
            btn.disabled = recordable === 0;
            btn.textContent = recordable === 0 ? 'Nothing to record' : ('Record ' + recordable + ' intervention' + (recordable === 1 ? '' : 's'));
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        initCascadingSelect('psGradeLevelSelect', 'psSectionSelect');
        initAutoSubmitFilter('psFilterForm');
        window.bindModalOverlayClose('recordInterventionModal');
        window.bindModalOverlayClose('bulkInterventionModal');
    });
</script>
@endpush
