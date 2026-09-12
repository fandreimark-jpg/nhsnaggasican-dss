@extends('layouts.app')

@section('title', 'Assessment Evidence')
@section('subtitle', $section
    ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level
    : 'No section assigned')

@section('content')

@if(!$section)
    <div class="bg-white rounded-xl shadow-sm">
        <x-empty-state icon="bi-exclamation-circle" message="No section assigned to your account."
            hint="An Admin assigns sections to advisers — contact the admin to get one assigned." />
    </div>
@else

@php
    $isTermOpen = $openTerm === $selectedPeriod;
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    $componentColors = ['written_work' => 'bg-blue-100 text-blue-700', 'performance_task' => 'bg-purple-100 text-purple-700', 'examination' => 'bg-orange-100 text-orange-700'];
    $inTermStatusColors = ['On Track' => 'bg-status-ontrack/10 text-status-ontrack', 'Needs Attention' => 'bg-status-attention/10 text-status-attention', 'At Risk' => 'bg-status-risk/10 text-status-risk'];
@endphp

@include('partials.stale-risk-warning')

@if(session('error'))
<div class="bg-red-100 text-red-700 text-sm p-4 rounded-lg mb-4">{{ session('error') }}</div>
@endif

@if(session('success') && !session('edit_summary'))
<div class="bg-green-100 text-green-700 text-sm p-4 rounded-lg mb-4">{{ session('success') }}</div>
@endif

@include('partials.import-result')

{{-- "Do not import formula results blindly" -- the Grade 12 workbook's OWN
     computed Term Grade vs GradingEngine's independent result, for the SAME
     imported raw scores. Never resolved automatically in either direction —
     see Grade12DiscrepancyChecker. Only ever populated right after a Grade
     12 import that actually found a mismatch. --}}
@if(session('grade_discrepancies'))
<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4 rounded-lg mb-4">
    <p class="font-medium"><i class="bi bi-exclamation-triangle-fill"></i> Term Grade discrepancy — the file's own computed grade disagrees with the system's</p>
    <p class="mt-1 mb-2">Not corrected automatically in either direction. Review before trusting either figure.</p>
    <table class="tbl text-xs">
        <thead><tr><th>Learner</th><th class="tbl-num">Excel Term Grade</th><th class="tbl-num">DSS Term Grade</th></tr></thead>
        <tbody>
            @foreach(session('grade_discrepancies') as $d)
                <tr><td>{{ $d['name'] }}</td><td class="tbl-num">{{ $d['excel_term_grade'] }}</td><td class="tbl-num">{{ $d['dss_term_grade'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- TASK 3 of "add an assessment item by hand" — before/after component
     percentages for the students an item edit actually touched, so a
     max-score correction shows its own effect instead of just "Updated." --}}
@if(session('edit_summary'))
<div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm p-4 rounded-lg mb-4">
    <p class="font-medium mb-2">{{ session('success') }}</p>
    @if(count(session('edit_summary')) > 0)
    <table class="w-full text-xs">
        <thead class="text-blue-700">
            <tr>
                <th class="text-left py-1">Student</th>
                <th class="text-center py-1">Before</th>
                <th class="text-center py-1">After</th>
                <th class="text-center py-1">Change</th>
            </tr>
        </thead>
        <tbody>
            @foreach(session('edit_summary') as $row)
            <tr class="border-t border-blue-100">
                <td class="py-1">{{ $row['name'] }}</td>
                <td class="text-center py-1">{{ $row['before'] === null ? '—' : number_format($row['before'], 2) . '%' }}</td>
                <td class="text-center py-1">{{ $row['after'] === null ? '—' : number_format($row['after'], 2) . '%' }}</td>
                <td class="text-center py-1">
                    @if($row['before'] !== null && $row['after'] !== null)
                        {{ ($row['after'] - $row['before']) >= 0 ? '+' : '' }}{{ number_format($row['after'] - $row['before'], 2) }}
                    @else
                        —
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="text-xs text-blue-700">No student had a score on this item, so no percentage changed.</p>
    @endif
</div>
@endif

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">

    {{-- Term Selector — "Correctness and interface pass" TASK 5a — clearer
         spacing between the term pills, and a visible divider from the
         filters (a bottom border when stacked on narrow screens, a right
         border once they sit side by side) instead of the two groups
         reading as one run-on row. --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 px-6 py-4 border-b">
        <div class="flex items-center gap-3 pb-3 border-b border-gray-100 md:pb-0 md:border-b-0 md:border-r md:pr-5 md:mr-1">
            <span class="text-sm font-semibold text-gray-700">Term:</span>
            <div class="flex gap-2.5">
                @foreach([1, 2, 3] as $t)
                <a href="{{ route('adviser.assessments') }}?period={{ $t }}&subject_id={{ $selectedSubject->id ?? '' }}"
                    class="px-4 py-1.5 rounded-full text-sm font-medium border transition flex items-center gap-1
                        {{ $selectedPeriod == $t
                            ? 'bg-brand-700 text-white border-brand-700 shadow-sm'
                            : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50' }}">
                    Term {{ $t }}
                    @if($openTerm !== $t)
                        <i class="bi bi-lock-fill text-xs {{ $selectedPeriod == $t ? 'text-white' : 'text-gray-400' }}"></i>
                    @endif
                </a>
                @endforeach
            </div>
        </div>

        <div class="flex items-center gap-3">
            <form method="GET" action="{{ route('adviser.assessments') }}" id="assessmentsFilterForm" class="flex items-center gap-2">
                <input type="hidden" name="period" value="{{ $selectedPeriod }}">
                <label class="text-sm text-gray-500">Subject:</label>
                <select name="subject_id" onchange="this.form.submit()"
                        class="w-48 border rounded-lg text-sm pl-3 pr-8 py-1.5">
                    @foreach($subjects as $subject)
                        <option value="{{ $subject->id }}" {{ ($selectedSubject->id ?? null) === $subject->id ? 'selected' : '' }}>
                            {{ $subject->name }}
                        </option>
                    @endforeach
                </select>
            </form>

            @if($isTermOpen && $selectedSubject)
            <button type="button" onclick="openAssessmentUploadModal()"
                class="bg-white border border-brand-700 text-brand-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-50 whitespace-nowrap">
                <i class="bi bi-upload"></i> Upload Assessment Form
            </button>
            <button type="button" onclick="openAddAssessmentItemModal()"
                class="bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-800 whitespace-nowrap">
                <i class="bi bi-plus-circle"></i> Add Assessment Item
            </button>
            @endif
        </div>
    </div>

    @unless($isTermOpen)
    <div class="bg-gray-50 text-gray-500 text-sm px-6 py-3 border-b flex items-center gap-2">
        <i class="bi bi-lock-fill"></i>
        Term {{ $selectedPeriod }} is currently closed for encoding.
        @if($openTerm)
            Term {{ $openTerm }} is the open term right now — you can view Term {{ $selectedPeriod }} but not upload to it.
        @else
            No term is currently open. Contact the admin.
        @endif
    </div>
    @endunless

    @if(!$selectedSubject)
        <x-empty-state icon="bi-book" message="No subjects are offered to your section yet."
            hint="An Admin assigns subjects to your section's track and grade level." />
    @else
    {{-- TASK 2 of "DO 015 grading weights" — GradingEngine already
         combines same-role items correctly, but two Examination items
         both naming the same role is very likely a mistake (e.g. two
         columns both marked Summative Test 1), worth flagging. --}}
    @if($duplicateExamRoleWarnings->isNotEmpty())
    <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4 rounded-lg mx-6 mt-3">
        <p class="font-medium"><i class="bi bi-exclamation-triangle-fill"></i> More than one item is set to the same Examination role</p>
        <ul class="list-disc list-inside mt-1">
            @foreach($duplicateExamRoleWarnings as $role => $names)
                <li>{{ ['st1' => 'Summative Test 1', 'st2' => 'Summative Test 2', 'term_exam' => 'Term Examination'][$role] ?? $role }}: {{ $names }}</li>
            @endforeach
        </ul>
        <p class="mt-1 text-amber-700">These will be combined as one score for that role — edit an item's role if this isn't intended.</p>
    </div>
    @endif

    {{-- TASK 5 of "terminology, transmutation, and interface cleanup" —
         row count above the table, body scrolls within a fixed-height
         container instead of the whole page once there are many items. --}}
    <p class="text-xs text-gray-500 px-6 pt-3">
        <x-count-label :count="$items->count()" noun="item" />
    </p>
    <div class="tbl-scroll mt-2">
    <table class="tbl tbl-sticky">
        <thead>
            <tr>
                <th scope="col">Item</th>
                <th scope="col">Component</th>
                <th scope="col" class="tbl-num">Max Score</th>
                <th scope="col" class="tbl-num">Scores Entered</th>
                @if($isTermOpen)
                <th scope="col" class="text-center">Actions</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td class="font-medium text-gray-800">{{ $item->name }}</td>
                <td>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $componentColors[$item->component] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ $componentLabels[$item->component] ?? $item->component }}
                    </span>
                    @if($item->component === 'examination' && $item->exam_role)
                        <span class="block text-[10px] text-gray-400 mt-0.5">
                            {{ ['st1' => 'Summative Test 1', 'st2' => 'Summative Test 2', 'term_exam' => 'Term Examination'][$item->exam_role] ?? $item->exam_role }}
                        </span>
                    @endif
                </td>
                <td class="tbl-num text-gray-600">{{ number_format($item->max_score, 2) }}</td>
                <td class="tbl-num text-gray-600">{{ $item->scores_count }}</td>
                @if($isTermOpen)
                <td class="text-center">
                    @php
                        $editItemData = [
                            'id' => $item->id,
                            'name' => $item->name,
                            'componentLabel' => $componentLabels[$item->component] ?? $item->component,
                            'maxScore' => (float) $item->max_score,
                            'scores' => ($itemScoresByAssessment[$item->id] ?? collect())->map(fn($s) => (float) $s),
                        ];
                    @endphp
                    <button type="button"
                        onclick='openEditAssessmentItemModal(@json($editItemData))'
                        class="text-brand-700 hover:text-brand-900 text-xs font-medium">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                </td>
                @endif
            </tr>
            @empty
            <tr>
                <td colspan="{{ $isTermOpen ? 5 : 4 }}">
                    <x-empty-state icon="bi-clipboard-data"
                        message="No assessment items uploaded yet for {{ $selectedSubject->name }}, Term {{ $selectedPeriod }}."
                        hint='Use "Upload Assessment Form" or "Add Assessment Item" above.' />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @endif
</div>

{{-- "The Failing layer" TASK 2e — gated on the FULL (pre-filter) set so
     the status filter itself never disappears just because the CURRENTLY
     selected filter happens to match zero students. --}}
@if($performanceByStudentId->isNotEmpty())
<div class="bg-white rounded-xl shadow-sm mt-4 overflow-x-auto">
    <div class="px-6 py-4 border-b">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-gray-800 text-sm">Student Performance — {{ $selectedSubject->name }}, Term {{ $selectedPeriod }}</h3>
                <p class="text-xs text-gray-500 mt-1">
                    Component breakdown from assessment evidence — not just the final grade. A student can look fine
                    overall while one component quietly needs attention.
                </p>
            </div>
            {{-- "The Failing layer" TASK 2e — one status filter spanning
                 both signals, visually grouped so the two kinds are never
                 confused. Submits via the same form as the Subject select
                 above (see @push('scripts') below). --}}
            <div>
                <label class="block text-xs text-gray-500 mb-1">Status</label>
                <select name="status_filter" form="assessmentsFilterForm" onchange="this.form.submit()"
                        class="border rounded-md text-sm px-2 py-1.5 min-w-[190px]">
                    <option value="">All students</option>
                    <optgroup label="In-Term Status (evidence, during the term)">
                        <option value="On Track" {{ $statusFilter === 'On Track' ? 'selected' : '' }}>On Track</option>
                        <option value="Needs Attention" {{ $statusFilter === 'Needs Attention' ? 'selected' : '' }}>Needs Attention</option>
                        <option value="At Risk" {{ $statusFilter === 'At Risk' ? 'selected' : '' }}>At Risk</option>
                    </optgroup>
                    <optgroup label="Official Grade (after verification)">
                        <option value="Failing" {{ $statusFilter === 'Failing' ? 'selected' : '' }}>Failing (74 and below)</option>
                    </optgroup>
                </select>
            </div>
        </div>
        {{-- "Correctness and interface pass" TASK 4c — always-visible
             summary shortened to exactly three lines; everything else
             moved into the collapsed panel below, corrected but not
             reworded beyond what TASK 4 specifically calls for. Same
             content as principal/students.blade.php's copy of this block
             — kept consistent since both describe the same reasoning. --}}
        <div class="mt-2 text-xs text-gray-500 space-y-0.5">
            <p><strong>Computed</strong> is the raw weighted evidence. <strong>Report card</strong> is the grade after DepEd transmutation.</p>
            <p><strong>In-Term Status</strong> is based on Computed, not the report card grade.</p>
            <p>A learner can be At Risk while their report card still passes — that is intentional.</p>
        </div>
        <details class="mt-1 text-xs text-gray-500">
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
                <strong>In-Term Status</strong> reflects only the assessment evidence collected so far this term — it
                updates as more is entered and is not a prediction of the final grade. It is not a Risk Level: a Risk
                Level comes from the submitted term report, covers every subject, and factors in the trend across terms.
            </p>
            {{-- "Correctness and interface pass" TASK 4a — BUG FIX: 60% is
                 DO 8, s. 2015's passing anchor only; DO 015, s. 2026's is
                 70. Worded so it never hardcodes either number. --}}
            <p class="mt-2">
                <strong>In-Term Status is based on the Computed grade, not the report card grade</strong> — transmutation
                can lift a failing raw score into a passing reported grade, and a status built on the report card grade
                would only flag a learner once their raw mastery had already fallen below the passing anchor of their
                curriculum — well past the point where support within the term could still help. A student flagged here
                with a report card grade already at 75+ is marked <strong>passing on paper</strong> below.
            </p>
            {{-- "Workflow completion pass" TASK 2e — states the OUTCOME
                 vs. EARLY-WARNING distinction explicitly, not just what
                 each signal happens to mean. --}}
            <p class="mt-2">
                <strong>Failing</strong> is an outcome recorded after the official grade is encoded. <strong>At
                Risk</strong> and <strong>Needs Attention</strong> are early warnings from assessment evidence during
                the term, and are the ones to act on. A student can show At Risk while their official grade is still
                passing, and that is intentional — it is an early warning, not a prediction of the final grade.
            </p>
            {{-- "Correctness and interface pass" TASK 4b — this state
                 cannot currently occur (both schemes have all 41 bands
                 seeded — see CLAUDE.md's "Known limitations"); kept only
                 because a future curriculum with no table entered yet
                 could still produce it. --}}
            <p class="mt-2 text-gray-400">
                A grade shown as <strong>Not available</strong> would mean complete evidence but no transmutation table
                entered yet for that curriculum — not currently possible for Grade 11 or 12, only if a new curriculum is
                introduced before its table is seeded.
            </p>
        </details>
    </div>
    {{-- TASK 5 of "terminology, transmutation, and interface cleanup" —
         row count above the table, and the body scrolls within a fixed-
         height container (header stays pinned) instead of the whole page
         scrolling once there are more than a handful of rows. --}}
    <p class="text-xs text-gray-500 px-6 pt-3">
        <x-count-label :count="$performance->count()" noun="student" />
    </p>
    <div class="tbl-scroll mt-2">
    <table class="tbl tbl-sticky">
        <thead>
            <tr>
                <th scope="col">Student</th>
                {{-- "Correctness and interface pass" TASK 5c — explicit
                     widths so numeric columns don't shift width between
                     pages, and right-aligned like every other number in
                     this row (names stay left-aligned). --}}
                <th scope="col" class="tbl-num w-28">Written Work</th>
                <th scope="col" class="tbl-num w-28">Performance Task</th>
                <th scope="col" class="tbl-num w-28">Examination</th>
                {{-- "Workflow completion pass" TASK 4b — the reader was
                     seeing 77 in Transmuted and concluding "above 75,"
                     missing that Computed (what In-Term Status actually
                     uses) can be well below it. --}}
                <th scope="col" class="tbl-num w-24" title="Raw weighted evidence. This is what In-Term Status uses.">
                    Computed
                    <span class="block normal-case font-normal text-gray-400 text-[10px] tracking-normal">evidence</span>
                </th>
                <th scope="col" class="tbl-num w-24" title="The reported grade after DepEd's transmutation table. Can be higher than the computed grade.">
                    Report Card
                    <span class="block normal-case font-normal text-gray-400 text-[10px] tracking-normal">transmuted</span>
                </th>
                <th scope="col">In-Term Status</th>
                <th scope="col">Focus Area</th>
                {{-- "Decision flow, report scoping, and dashboard pass"
                     TASK 3a/3c — the bulk action lives in the SAME visual
                     column as the per-row "Verify & Use as Official"
                     links below it, and inside this sticky thead so it
                     never scrolls out of view on a 40-row section. --}}
                <th scope="col" class="text-center align-top">
                    <div>Official Grade</div>
                    @if($isTermOpen && $selectedSubject)
                    <button type="button" id="verifyAllRemainingBtn" disabled
                            data-preview-url="{{ route('adviser.grades.verify-all.preview', ['subject_id' => $selectedSubject->id, 'grading_period' => $selectedPeriod]) }}"
                            data-verify-url="{{ route('adviser.grades.verify-all') }}"
                            data-subject-id="{{ $selectedSubject->id }}"
                            data-grading-period="{{ $selectedPeriod }}"
                            class="mt-1 normal-case font-medium tracking-normal bg-brand-700 text-white text-[11px] px-2 py-1 rounded-md hover:bg-brand-800 whitespace-nowrap disabled:bg-gray-300 disabled:text-gray-500 disabled:cursor-not-allowed disabled:hover:bg-gray-300">
                        <i class="bi bi-check2-all"></i> <span data-var-btn-label>Verify All Remaining</span>
                    </button>
                    @endif
                </th>
            </tr>
        </thead>
        <tbody>
            @forelse($performance as $row)
            <tr data-performance-row data-student-id="{{ $row['student']->id }}">
                <td class="font-medium text-gray-800 whitespace-nowrap">
                    {{ $row['student']->last_name }}, {{ $row['student']->first_name }}
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
                @foreach(['written_work', 'performance_task', 'examination'] as $key)
                    @php $c = $row['components'][$key]; @endphp
                    <td class="tbl-num">
                        @if($c['percentage'] === null)
                            <span class="text-gray-300 text-xs">No data</span>
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
                <td class="text-center" data-official-grade-cell>
                    @if($row['official_grade'] && $row['official_grade']->is_provisional)
                        {{-- "The Failing layer" 2b — a provisional grade came
                             from a fallback scheme, not the subject's real
                             one, so it is not yet an official grade at all.
                             Shown INSTEAD OF the number — never alongside a
                             Failing badge. --}}
                        <span class="text-gray-400 text-xs" title="Computed using the {{ \App\Services\TransmutationService::schemeLabel($row['official_grade']->provisional_scheme) }} table because the real scheme's bands are not yet entered — not yet an official grade.">
                            Awaiting official grade
                        </span>
                    @elseif($row['official_grade'])
                        <span class="font-medium text-gray-700">{{ number_format($row['official_grade']->grade, 2) }}</span>
                        @if($row['official_grade']->is_verified)
                            <span class="block text-[10px] text-green-600"><i class="bi bi-check-circle-fill"></i> Verified from evidence</span>
                        @endif
                        @if($row['is_failing'])
                            {{-- "The Failing layer" 2a — next to the Official
                                 Grade, never in the In-Term Status column. --}}
                            <span class="block text-[10px] font-bold text-status-failing" title="Official grade of {{ number_format($row['official_grade']->grade, 2) }} — 74 or below.">
                                <i class="bi bi-x-octagon-fill"></i> Failing
                            </span>
                        @endif
                    @else
                        {{-- "The Failing layer" 2c — no grade encoded yet;
                             Failing does not apply. --}}
                        <span class="text-gray-300 text-xs">Not encoded</span>
                    @endif

                    @if($row['complete'] && $isTermOpen && $row['transmuted_grade'] !== null)
                        <form method="POST" action="{{ route('adviser.grades.verify') }}" class="mt-1" data-grade-verify-form
                              data-resubmit="Set the official grade for {{ $row['student']->last_name }}, {{ $row['student']->first_name }} to {{ number_format($row['transmuted_grade'], 2) }}{{ $row['transmutation_provisional'] ? ' (PROVISIONAL — using the ' . \App\Services\TransmutationService::schemeLabel($row['transmutation_fallback_scheme']) . ' table because the ' . \App\Services\TransmutationService::schemeLabel($row['transmutation_scheme']) . ' bands are not yet entered)' : '' }} (transmuted from a computed grade of {{ number_format($row['computed_grade'], 2) }}) based on verified assessment evidence?{{ $row['official_grade'] ? ' This will REPLACE the current official grade of ' . number_format($row['official_grade']->grade, 2) . '.' : '' }}">
                            @csrf
                            <input type="hidden" name="student_id" value="{{ $row['student']->id }}">
                            <input type="hidden" name="subject_id" value="{{ $selectedSubject->id }}">
                            <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">
                            <button type="submit" class="text-xs text-brand-700 underline hover:text-brand-900">
                                Verify &amp; Use as Official
                            </button>
                        </form>
                    @elseif($row['complete'] && $isTermOpen)
                        <span class="block text-[10px] text-amber-600 mt-1">Cannot verify — transmuted grade not available</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9">
                    <x-empty-state icon="bi-funnel" message="No students match the selected status filter."
                        hint="Try a different In-Term Status filter above, or clear it to see every student." class="py-6 text-sm" />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
@endif

{{-- UPLOAD MODAL --}}
@if($selectedSubject)
<div id="assessmentUploadModal" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Upload Assessment Form — {{ $selectedSubject->name }}, Term {{ $selectedPeriod }}</h3>
            <button type="button" onclick="closeAssessmentUploadModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <p class="text-sm text-gray-500 mb-4">
            Expected columns: <code>lrn, last_name, first_name</code>, then one column per assessment item
            (e.g. <code>Quiz 1</code>, <code>Performance Task 1</code>, <code>Exam</code>). You'll verify how each
            column is classified — and set its maximum score — before anything is saved.
        </p>

        <form method="POST" action="{{ route('adviser.assessments.detect') }}"
              enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="hidden" name="subject_id" value="{{ $selectedSubject->id }}">
            <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full border rounded-lg px-3 py-2 text-sm">

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeAssessmentUploadModal()"
                        class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                    Continue
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    window.openAssessmentUploadModal = () => window.showModal('assessmentUploadModal');
    window.closeAssessmentUploadModal = () => window.hideModal('assessmentUploadModal');
    document.addEventListener('DOMContentLoaded', function () {
        window.bindModalOverlayClose('assessmentUploadModal');
        window.initGradeVerifyForms('[data-grade-verify-form]');
        window.bindModalOverlayClose('verifyAllRemainingModal');
        window.initVerifyAllRemaining('#verifyAllRemainingBtn');
    });
</script>
@endpush

{{-- ADD ASSESSMENT ITEM MODAL — TASK 1 of "add an assessment item by
     hand". $modalStudents is the whole section roster, EXCEPT when
     reached via the "from_intervention" link on the adviser Interventions
     page (TASK 2), where it's narrowed to just the students who have an
     intervention for this exact subject/term — see AssessmentController::index(). --}}
<div id="addAssessmentItemModal"
     class="{{ $errors->addItem->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->addItem->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-2xl p-6 transition-all duration-200 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Add Assessment Item — {{ $selectedSubject->name }}, Term {{ $selectedPeriod }}</h3>
            <button type="button" onclick="closeAddAssessmentItemModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->addItem->any())
            <div class="bg-red-100 text-red-700 text-sm p-3 rounded-lg mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->addItem->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($modalStudents->count() < $sectionStudents->count())
            <p class="text-xs text-gray-500 mb-4 bg-gray-50 border rounded-lg px-3 py-2">
                Showing only the {{ $modalStudents->count() }} student(s) with an intervention for this subject and term —
                <a href="{{ route('adviser.assessments', ['period' => $selectedPeriod, 'subject_id' => $selectedSubject->id]) }}#" onclick="closeAddAssessmentItemModal(); window.aaiShowAllStudents(); return false;" class="text-brand-700 underline">show the whole section instead</a>.
            </p>
        @endif

        <form method="POST" action="{{ route('adviser.assessments.item.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="subject_id" value="{{ $selectedSubject->id }}">
            <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Item Name</label>
                    <input type="text" name="item_name" required value="{{ old('item_name') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400"
                           placeholder="e.g. Remedial Quiz 1">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Max Score</label>
                    <input type="number" name="max_score" step="0.01" min="0.01" required value="{{ old('max_score') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Component</label>
                <select name="component" id="aaiComponent" required
                        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <option value="" {{ old('component', $prefillComponent) ? '' : 'selected' }} disabled>— Select —</option>
                    @foreach($componentLabels as $key => $label)
                        <option value="{{ $key }}" {{ old('component', $prefillComponent) === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- "Workflow completion pass" TASK 3b — display only: never
                 inferred from the item's name. Defaults unchecked. --}}
            <div>
                <label class="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                    <input type="checkbox" name="is_additional_support" value="1" {{ old('is_additional_support') ? 'checked' : '' }}
                           class="rounded border-gray-300 text-brand-700 focus:ring-brand-400">
                    This is within-term additional support (a re-teach quiz, an extra activity)
                </label>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-sm text-gray-600">Scores <span class="text-gray-400 text-xs">(leave blank if a student did not take this item)</span></label>
                </div>
                <div class="border rounded-lg divide-y max-h-72 overflow-y-auto" id="aaiFullRoster">
                    @foreach($sectionStudents as $student)
                    <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm {{ $modalStudents->contains('id', $student->id) ? '' : 'hidden' }}"
                         data-aai-roster-row data-student-id="{{ $student->id }}">
                        <div>
                            <span class="text-gray-700">{{ $student->last_name }}, {{ $student->first_name }}</span>
                            @if($performanceByStudentId->has($student->id))
                                @php $c = $performanceByStudentId[$student->id]['components']; @endphp
                                <span class="block text-xs text-gray-400" data-aai-current-pct>
                                    @foreach($componentLabels as $key => $label)
                                        <span class="{{ $key === old('component', $prefillComponent) ? '' : 'hidden' }}" data-aai-pct-for="{{ $key }}">
                                            Current {{ $label }}: {{ $c[$key]['percentage'] === null ? 'No data' : number_format($c[$key]['percentage'], 2) . '%' }}
                                        </span>
                                    @endforeach
                                </span>
                            @else
                                <span class="block text-xs text-gray-300">No data yet</span>
                            @endif
                        </div>
                        <input type="number" name="scores[{{ $student->id }}]" step="0.01" min="0"
                               value="{{ old('scores.'.$student->id) }}"
                               class="w-24 border rounded-lg px-2 py-1 text-sm text-right focus:outline-none focus:ring-2 focus:ring-brand-400">
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeAddAssessmentItemModal()"
                        class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                    Save Item
                </button>
            </div>
        </form>
    </div>
</div>

{{-- EDIT ASSESSMENT ITEM MODAL — TASK 3. Item name and component are
     display-only here (never submitted) — both are part of the same
     updateOrCreate key the Add form and the file-upload path share, so
     changing either would silently create a second item instead of
     correcting this one. Shared by every row's Edit button; populated
     per click by openEditAssessmentItemModal() below, or — on a
     validation-error redisplay — server-rendered from old('assessment_id'). --}}
@php
    $editAssessment = old('assessment_id') ? \App\Models\Assessment::find(old('assessment_id')) : null;
@endphp
<div id="editAssessmentItemModal"
     class="{{ $errors->editItem->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box {{ $errors->editItem->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-2xl p-6 transition-all duration-200 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">
                Edit Assessment Item
                <span id="eaiTitleSuffix" class="text-gray-400 font-normal">{{ $editAssessment ? '— ' . $editAssessment->name : '' }}</span>
            </h3>
            <button type="button" onclick="closeEditAssessmentItemModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->editItem->any())
            <div class="bg-red-100 text-red-700 text-sm p-3 rounded-lg mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->editItem->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-sm text-gray-500 mb-4">
            Item: <strong id="eaiItemName">{{ $editAssessment->name ?? '' }}</strong> —
            Component: <span id="eaiComponent">{{ $componentLabels[$editAssessment->component ?? ''] ?? '' }}</span>
            <span class="block text-xs text-gray-400 mt-0.5">Item name and component cannot be changed here — they identify which item this is. Add a new item instead if either is wrong.</span>
        </p>

        <form method="POST" id="editAssessmentItemForm"
              action="{{ $editAssessment ? route('adviser.assessments.item.update', $editAssessment->id) : '#' }}"
              class="space-y-4">
            @csrf
            @method('PUT')
            <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">
            <input type="hidden" name="assessment_id" value="{{ old('assessment_id', $editAssessment->id ?? '') }}">

            <div>
                <label class="block text-sm text-gray-600 mb-1">Max Score</label>
                <input type="number" name="max_score" id="eaiMaxScore" step="0.01" min="0.01" required
                       value="{{ old('max_score', $editAssessment->max_score ?? '') }}"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Scores <span class="text-gray-400 text-xs">(leave blank to clear — the student is treated as not having taken this item)</span></label>
                <div class="border rounded-lg divide-y max-h-72 overflow-y-auto" id="eaiRoster">
                    @foreach($sectionStudents as $student)
                    <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <span class="text-gray-700">{{ $student->last_name }}, {{ $student->first_name }}</span>
                        <input type="number" name="scores[{{ $student->id }}]" data-student-score="{{ $student->id }}" step="0.01" min="0"
                               value="{{ old('scores.'.$student->id) }}"
                               class="w-24 border rounded-lg px-2 py-1 text-sm text-right focus:outline-none focus:ring-2 focus:ring-brand-400">
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeEditAssessmentItemModal()"
                        class="px-4 py-2 text-sm text-gray-500">Cancel</button>
                <button type="submit"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

{{-- VERIFY ALL REMAINING MODAL — "Workflow completion pass" TASK 1d.
     Populated entirely by JS from the preview endpoint's JSON (see
     resources/js/verify-all-remaining.js) — nothing here is server-
     rendered per-row, since the eligible/excluded sets can change the
     moment new evidence is imported. Everything the confirmation
     requires (1d) must be visible before Confirm is ever clickable. --}}
<div id="verifyAllRemainingModal" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200 max-h-[85vh] flex flex-col">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Verify All Remaining</h3>
            <button type="button" onclick="window.hideModal('verifyAllRemainingModal')" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <div class="overflow-y-auto flex-1 min-h-0 space-y-3">
            <p class="text-sm text-gray-700 font-medium" id="varTitle"></p>
            <p class="text-xs text-gray-500" id="varContext"></p>
            <p class="text-xs text-gray-500">
                The computed grade becomes the official grade for each of these students, using the subject's real
                transmutation table. This does not touch any student with an existing official grade — changing one
                of those still requires its own individual Confirm Re-submit.
            </p>

            <div id="varExcludedWrap" class="hidden space-y-2">
                <p class="text-xs font-medium text-gray-600">Excluded from this batch:</p>
                <div id="varExcludedAlreadyEncoded" class="hidden text-xs text-gray-500">
                    <p class="font-medium text-gray-600">Already has an official grade (unaffected — re-submit individually to change one):</p>
                    <ul class="list-disc list-inside" data-name-list></ul>
                </div>
                <div id="varExcludedIncomplete" class="hidden text-xs text-gray-500">
                    <p class="font-medium text-gray-600">Incomplete evidence:</p>
                    <ul class="list-disc list-inside" data-name-list></ul>
                </div>
                <div id="varExcludedNoTransmutation" class="hidden text-xs text-gray-500">
                    <p class="font-medium text-gray-600">No transmutation band available:</p>
                    <ul class="list-disc list-inside" data-name-list></ul>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
            <button type="button" onclick="window.hideModal('verifyAllRemainingModal')" class="px-4 py-2 text-sm text-gray-500">Cancel</button>
            <button type="button" id="varConfirmBtn"
                    class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800 disabled:bg-gray-300 disabled:cursor-not-allowed">
                Verify
            </button>
        </div>
    </div>
</div>

@push('scripts')
<script>
    window.openAddAssessmentItemModal = () => window.showModal('addAssessmentItemModal');
    window.closeAddAssessmentItemModal = () => window.hideModal('addAssessmentItemModal');

    // TASK 2's narrowed roster (from_intervention) starts applied — this
    // lets the adviser fall back to the whole section without leaving the
    // page, since the narrowing is a convenience, not a restriction (the
    // POST itself never trusts which rows were visible client-side).
    window.aaiShowAllStudents = function () {
        document.querySelectorAll('#aaiFullRoster [data-aai-roster-row]').forEach((row) => row.classList.remove('hidden'));
        window.showModal('addAssessmentItemModal');
    };

    // Swaps which component's "Current %" line is visible per student as
    // the adviser picks a Component — every component's percentage is
    // already rendered server-side (see the loop over $componentLabels
    // above); this just shows the one that matches the current selection.
    window.aaiUpdateCurrentPercentages = function () {
        const key = document.getElementById('aaiComponent').value;
        document.querySelectorAll('[data-aai-pct-for]').forEach((el) => {
            el.classList.toggle('hidden', el.dataset.aaiPctFor !== key);
        });
    };

    window.openEditAssessmentItemModal = function (data) {
        document.getElementById('editAssessmentItemForm').action = '/adviser/assessments/item/' + data.id;
        document.getElementById('editAssessmentItemForm').querySelector('[name="assessment_id"]').value = data.id;
        document.getElementById('eaiItemName').textContent = data.name;
        document.getElementById('eaiTitleSuffix').textContent = '— ' + data.name;
        document.getElementById('eaiComponent').textContent = data.componentLabel;
        document.getElementById('eaiMaxScore').value = data.maxScore;

        document.querySelectorAll('#eaiRoster [data-student-score]').forEach((input) => {
            const studentId = input.dataset.studentScore;
            input.value = (data.scores && data.scores[studentId] !== undefined) ? data.scores[studentId] : '';
        });

        window.showModal('editAssessmentItemModal');
    };
    window.closeEditAssessmentItemModal = () => window.hideModal('editAssessmentItemModal');

    document.addEventListener('DOMContentLoaded', function () {
        window.bindModalOverlayClose('addAssessmentItemModal');
        window.bindModalOverlayClose('editAssessmentItemModal');

        const aaiComponentSelect = document.getElementById('aaiComponent');
        if (aaiComponentSelect) {
            aaiComponentSelect.addEventListener('change', window.aaiUpdateCurrentPercentages);
        }

        @if($autoOpenAddItem)
            window.openAddAssessmentItemModal();
        @endif
    });
</script>
@endpush
@endif

@endif

@endsection
