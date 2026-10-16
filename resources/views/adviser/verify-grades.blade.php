@extends('layouts.app')

@section('title', 'Verify Grades')
@section('subtitle', $section
    ? implode(' | ', array_filter([
        'Section ' . $section->name . ' — Grade ' . $section->grade_level,
        trim(($section->track->name ?? '') . ($section->specialization ? ' — ' . $section->specialization->name : '')) ?: null,
        'SY ' . $section->school_year,
    ]))
    : 'No section assigned')

{{-- Verify Grades ("Encode / Verify split", 2026-09-28) — ONE workspace:
     filters, instruction + Open Class Record, counts + the scoped bulk
     action, the Student Performance table with each row's own verify
     action, and the "How to read this table" help. Every figure comes from
     GradeVerificationBoard (GradingEngine); the verify endpoints re-check
     everything server-side. --}}
@section('content')
@include('partials.validation-errors')

@if(!$section)
    <div class="card">
        <x-empty-state icon="bi-exclamation-circle" message="No advisory section is assigned to your account."
            hint="Grades are verified by the adviser of a section. An Admin assigns advisers on Admin > Sections." />
    </div>
@else
@php
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    $scheme = fn($s) => \App\Services\TransmutationService::schemeLabel($s);
    $learnerName = fn($st) => $st->last_name . ', ' . $st->first_name;
@endphp

<section class="card" data-verify-workspace data-grade-verification aria-labelledby="verifyHeading">
    {{-- 1. Heading and context --}}
    <div class="px-5 pt-4 pb-3 border-b border-line">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 id="verifyHeading" class="card-title">
                    Student Performance — {{ $selectedSubject?->name ?? 'no subject' }}, Term {{ $selectedPeriod }}
                </h2>
                <p class="text-xs text-muted mt-0.5">
                    SY {{ $section->school_year }} · Grade {{ $section->grade_level }} — {{ $section->name }}
                    @unless($section->isInActiveSchoolYear()) · <span class="badge badge-gray">Historical Record</span>@endunless
                </p>
            </div>
            @if($lockReason)
                <span class="badge badge-gray" data-verify-locked><i class="bi bi-lock-fill" aria-hidden="true"></i> Read-only</span>
            @endif
        </div>

        {{-- 2. Filters --}}
        <form method="GET" action="{{ route('adviser.verify-grades') }}" id="verifyFilterForm" class="mt-3 grid grid-cols-2 xl:grid-cols-5 gap-2 items-end" data-no-loading aria-label="Choose the class to verify">
            <div class="min-w-0">
                <label class="form-label" for="vfYear">School Year</label>
                <select id="vfYear" name="school_year" class="form-select-sm w-full" data-vf-reset="section_id,subject_id">
                    @foreach($schoolYears as $year)
                        <option value="{{ $year }}" @selected($year === $schoolYear)>{{ $year }}{{ $year === \App\Models\Section::activeSchoolYear() ? ' (active)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-0">
                <label class="form-label" for="vfSection">Section</label>
                <select id="vfSection" name="section_id" class="form-select-sm w-full" data-vf-reset="subject_id">
                    @foreach($sections as $s)
                        <option value="{{ $s->id }}" @selected($s->id === $section->id)>Grade {{ $s->grade_level }} — {{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-0">
                <label class="form-label" for="vfTerm">Term</label>
                <select id="vfTerm" name="period" class="form-select-sm w-full" data-vf-reset="subject_id">
                    @foreach($terms as $t)
                        <option value="{{ $t }}" @selected($t === $selectedPeriod)>Term {{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-0 col-span-2 xl:col-span-1">
                <label class="form-label" for="vfSubject">Subject</label>
                <select id="vfSubject" name="subject_id" class="form-select-sm w-full" title="{{ $selectedSubject?->name }}" @disabled($subjects->isEmpty())>
                    @forelse($subjects as $sub)
                        <option value="{{ $sub->id }}" @selected($selectedSubject && $sub->id === $selectedSubject->id)>{{ $sub->name }}</option>
                    @empty
                        <option value="">No subject in this term</option>
                    @endforelse
                </select>
            </div>
            <div class="min-w-0 col-span-2 xl:col-span-1">
                <label class="form-label" for="vfStatus">Status</label>
                <select id="vfStatus" name="status_filter" class="form-select-sm w-full">
                    <option value="">All learners</option>
                    <optgroup label="Academic Performance (in-term evidence)">
                        <option value="On Track" @selected($statusFilter === 'On Track')>On Track</option>
                        <option value="Needs Attention" @selected($statusFilter === 'Needs Attention')>Below Target: 1 Component</option>
                        <option value="At Risk" @selected($statusFilter === 'At Risk')>Below Target: 2+ Components</option>
                        <option value="No Evidence Yet" @selected($statusFilter === 'No Evidence Yet')>No Evidence Yet</option>
                    </optgroup>
                    <optgroup label="Official Grade (after verification)">
                        <option value="Failing" @selected($statusFilter === 'Failing')>Failing (74 and below)</option>
                    </optgroup>
                </select>
            </div>
            <noscript><button type="submit" class="btn btn-outline btn-sm">Apply</button></noscript>
        </form>

        {{-- 3. Instruction + Open Class Record --}}
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-muted max-w-3xl">
                Verifying turns a learner's <strong>complete</strong> computed result for this subject into the <strong>official grade</strong>
                (the transmuted value). Nothing becomes official by adding assessments, saving scores, typing a grade or downloading.
                Scores are entered by the subject's assigned teacher in the Class Record.
            </p>
            @if($selectedSubject)
                <a class="btn btn-outline btn-sm whitespace-nowrap" data-open-class-record
                   href="{{ route('teacher.class-record', ['section_id' => $section->id, 'subject_id' => $selectedSubject->id, 'period' => $selectedPeriod]) }}">
                    <i class="bi bi-table" aria-hidden="true"></i> Open Class Record
                </a>
            @endif
        </div>

        @if(session('error'))
            <div class="alert alert-danger mt-3 text-sm" role="alert">{{ session('error') }}</div>
        @endif
        @if($lockReason)
            <div class="alert alert-info alert-row mt-3 py-2 text-sm" data-verify-lock-reason>
                <i class="bi bi-lock" aria-hidden="true"></i> <span>{{ $lockReason }} You can still read every figure.</span>
            </div>
        @endif
    </div>

    @if(!$selectedSubject)
        <div class="p-4">
            <x-empty-state icon="bi-book" message="No subjects are applicable to {{ $section->name }} in Term {{ $selectedPeriod }}."
                hint="Which subjects apply in each term is set by an Admin on Admin > Subjects (Terms Taught)." />
        </div>
    @elseif($itemCount === 0)
        <div class="p-4">
            <x-empty-state icon="bi-clipboard-data"
                message="No assessment evidence yet for {{ $selectedSubject->name }}, Term {{ $selectedPeriod }}."
                hint="The subject's assigned teacher adds assessments and enters scores in the Class Record. A grade can be verified once every required component has evidence." />
        </div>
    @else
        {{-- 4. Counts and the scoped bulk action --}}
        <div class="px-5 py-3 border-b border-line flex flex-wrap items-center gap-x-5 gap-y-2 text-sm" data-verify-counts>
            <span><span class="text-muted">Learners</span> <strong>{{ $counts['learners'] }}</strong></span>
            <span><span class="text-muted">Verified</span> <strong data-count-verified>{{ $counts['verified'] }}</strong></span>
            <span><span class="text-muted">Eligible to verify</span> <strong data-count-eligible>{{ $counts['eligible'] }}</strong></span>
            <span><span class="text-muted">Incomplete evidence</span> <strong>{{ $counts['incomplete'] }}</strong></span>
            @if($statusFilter)
                <span class="text-xs text-muted">Showing {{ $performance->count() }} learner(s) matching “{{ \App\Services\InTermStatusService::label($statusFilter) ?? $statusFilter }}”. Counts are for the whole class.</span>
            @endif
            @if($isTermOpen)
                <div class="ml-auto flex flex-col items-end gap-0.5">
                    <button type="button" id="verifyAllRemainingBtn" class="btn btn-primary btn-sm" @disabled($counts['eligible'] === 0)
                            data-preview-url="{{ route('adviser.grades.verify-all.preview', ['subject_id' => $selectedSubject->id, 'grading_period' => $selectedPeriod]) }}"
                            data-verify-url="{{ route('adviser.grades.verify-all') }}"
                            data-subject-id="{{ $selectedSubject->id }}"
                            data-grading-period="{{ $selectedPeriod }}">
                        <i class="bi bi-check2-all" aria-hidden="true"></i>
                        <span data-var-btn-label>{{ $counts['eligible'] === 0 ? 'No learner eligible to verify' : 'Verify all eligible (' . $counts['eligible'] . ')' }}</span>
                    </button>
                    <span class="text-[11px] text-muted" id="verifyAllScope">
                        Every eligible learner of {{ $section->name }} in this subject and term — not limited by the Status filter.
                    </span>
                </div>
            @endif
        </div>

        @if($performance->isEmpty())
            <div class="p-4">
                <x-empty-state icon="bi-funnel" message="No learners match the selected Status filter."
                    hint="Choose “All learners” in Status to see the whole class." class="py-6 text-sm" />
            </div>
        @else
        {{-- 5. The table (scrolls inside the workspace) --}}
        <div class="tbl-scroll overflow-x-auto">
        <table class="tbl tbl-sticky">
            <caption class="sr-only">Student Performance for {{ $selectedSubject->name }}, Term {{ $selectedPeriod }}, with each learner's verification state</caption>
            <thead>
                <tr>
                    <th scope="col">Learner</th>
                    <th scope="col" class="tbl-num w-28">Written Work</th>
                    <th scope="col" class="tbl-num w-28">Performance Task</th>
                    <th scope="col" class="tbl-num w-28">Examination</th>
                    <th scope="col" class="tbl-num w-24" title="Raw weighted evidence. This is what In-Term Status uses. The computed (initial) grade, weighted by the grading profile.">
                        Computed <span class="block normal-case font-normal text-gray-400 text-[10px] tracking-normal">initial grade</span>
                    </th>
                    <th scope="col" class="tbl-num w-28" title="The reported grade after DepEd transmutation of the computed grade. An assessment result — not official until verified.">
                        Transmuted <span class="block normal-case font-normal text-gray-400 text-[10px] tracking-normal">assessment result</span>
                    </th>
                    <th scope="col" class="min-w-[11rem]">Official grade &amp; verification</th>
                    <th scope="col" title="Current assessment evidence for this subject against the 75 reference point. Not the learner's overall monitoring.">
                        Academic Performance <span class="block normal-case font-normal text-gray-400 text-[10px] tracking-normal">this subject, in-term</span>
                    </th>
                    <th scope="col" title="Open Academic Concerns for this learner this term, across every subject.">
                        Monitoring <span class="block normal-case font-normal text-gray-400 text-[10px] tracking-normal">open concerns</span>
                    </th>
                    <th scope="col">Focus Area</th>
                </tr>
            </thead>
            <tbody>
            @foreach($performance as $row)
                @php $st = $row['student']; $og = $row['official_grade']; @endphp
                <tr data-performance-row data-student-id="{{ $st->id }}" id="learner-{{ $st->id }}" class="scroll-mt-24" data-verify-state="{{ $row['verify_state'] }}">
                    <th scope="row" class="font-medium text-ink whitespace-nowrap text-left">
                        {{ $learnerName($st) }}
                        @if($row['has_additional_support'])
                            <span class="block text-[10px] font-normal text-gray-500" title="At least one within-term additional-support item contributed to this grade.">
                                <i class="bi bi-info-circle" aria-hidden="true"></i> includes additional practice
                            </span>
                        @endif
                    </th>
                    @foreach(['written_work', 'performance_task', 'examination'] as $key)
                        @php $c = $row['components'][$key]; @endphp
                        <td class="tbl-num">
                            @if($c['percentage'] === null)
                                <span class="text-gray-400 text-xs">No data</span>
                            @else
                                <span class="{{ $c['status'] === 'On Track' ? 'text-status-ontrack' : 'text-status-risk font-medium' }}">{{ number_format($c['percentage'], 2) }}%</span>
                                <span class="block text-xs text-muted">{{ $c['gap'] >= 0 ? '+' : '' }}{{ number_format($c['gap'], 1) }}</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="tbl-num {{ $row['complete'] ? 'text-ink' : 'text-gray-400' }}">
                        {{ $row['complete'] ? number_format($row['computed_grade'], 2) : '—' }}
                    </td>
                    <td class="tbl-num text-xs text-gray-600">
                        @if(!$row['complete'])
                            <span class="text-gray-400">—</span>
                        @elseif($row['transmuted_grade'] !== null)
                            {{ number_format($row['transmuted_grade'], 2) }}
                            <span class="block text-[10px] text-gray-500">not official</span>
                            @if($row['transmuted_grade'] >= 75 && $row['computed_grade'] < 75)
                                <span class="block text-[10px] text-gray-500" title="Transmutation lifted the reported grade above 75 — the computed result is still below 75.">
                                    <i class="bi bi-arrow-up-short" aria-hidden="true"></i> lifted from {{ number_format($row['computed_grade'], 2) }}
                                </span>
                            @endif
                            @if($row['transmutation_provisional'])
                                <span class="block text-[10px] text-amber-700" title="Computed with the {{ $scheme($row['transmutation_fallback_scheme']) }} table because the {{ $scheme($row['transmutation_scheme']) }} bands are not yet entered.">
                                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Provisional
                                </span>
                            @endif
                        @else
                            <span class="text-[10px] text-amber-700"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Not available</span>
                        @endif
                    </td>
                    <td data-official-grade-cell>
                        {{-- The official value and what it is. --}}
                        @if($og && $og->is_provisional)
                            <span class="text-gray-500 text-xs">Awaiting official grade</span>
                        @elseif($og && $og->is_verified)
                            <span class="font-semibold text-ink">{{ number_format($og->grade, 2) }}</span>
                            <span class="badge badge-success text-[10px] ml-1"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Official · verified</span>
                        @elseif($og)
                            <span class="font-medium text-gray-700">{{ number_format($og->grade, 2) }}</span>
                            <span class="badge badge-gray text-[10px] ml-1" title="Typed or imported on Encode Grades — not yet official.">Typed · not official</span>
                        @else
                            <span class="text-gray-500 text-xs">No official grade</span>
                        @endif
                        @if($row['is_failing'])
                            <span class="block text-[10px] font-bold text-status-failing"><i class="bi bi-x-octagon-fill" aria-hidden="true"></i> Failing</span>
                        @endif

                        {{-- The row's verification state. --}}
                        @php
                            $canAct = $isTermOpen && in_array($row['verify_state'], ['eligible', 'verified_changed', 'provisional'], true);
                            $confirm = 'Set the official grade for ' . $learnerName($st) . ' in ' . $selectedSubject->name . ' (Term ' . $selectedPeriod . ') to '
                                . ($row['transmuted_grade'] !== null ? number_format($row['transmuted_grade'], 2) : '—')
                                . ($row['computed_grade'] !== null ? ', transmuted from a computed grade of ' . number_format($row['computed_grade'], 2) : '') . '?'
                                . ($row['transmutation_provisional'] ? ' This is PROVISIONAL: the ' . $scheme($row['transmutation_fallback_scheme']) . ' table is used because the ' . $scheme($row['transmutation_scheme']) . ' bands are not yet entered.' : '')
                                . ($og ? ' It replaces the current ' . ($og->is_verified ? 'official' : 'typed') . ' grade of ' . number_format($og->grade, 2) . '.' : '');
                        @endphp
                        @if($canAct)
                            <form method="POST" action="{{ route('adviser.grades.verify') }}" class="mt-1" data-grade-verify-form
                                  data-action-confirm="{{ $confirm }}" data-action-title="Verify official grade?"
                                  data-action-label="Yes, verify" data-action-icon="bi-patch-check" data-action-loading="Verifying...">
                                @csrf
                                <input type="hidden" name="student_id" value="{{ $st->id }}">
                                <input type="hidden" name="subject_id" value="{{ $selectedSubject->id }}">
                                <input type="hidden" name="grading_period" value="{{ $selectedPeriod }}">
                                @if($statusFilter)<input type="hidden" name="status_filter" value="{{ $statusFilter }}">@endif
                                <button type="submit" class="btn btn-secondary btn-xs" aria-label="{{ $row['verify_state'] === 'verified_changed' ? 'Re-verify' : 'Verify' }} the official grade for {{ $learnerName($st) }}">
                                    <i class="bi bi-patch-check" aria-hidden="true"></i> {{ $row['verify_state'] === 'verified_changed' ? 'Re-verify' : 'Verify' }}
                                </button>
                            </form>
                            @if($row['verify_reason'])<span class="block text-[10px] text-amber-800 mt-0.5">{{ $row['verify_reason'] }}</span>@endif
                        @elseif($row['verify_state'] === 'verified')
                            {{-- nothing more to do --}}
                        @elseif(!$isTermOpen && in_array($row['verify_state'], ['eligible', 'verified_changed', 'provisional'], true))
                            <span class="block text-[10px] text-gray-500 mt-0.5" data-verify-readonly><i class="bi bi-lock" aria-hidden="true"></i> Read-only — not verifiable here</span>
                        @elseif($row['verify_reason'])
                            <span class="block text-[10px] text-gray-600 mt-0.5" data-verify-reason>{{ $row['verify_reason'] }}</span>
                        @endif
                    </td>
                    <td>@include('partials.in-term-status-badge', ['its' => $row['in_term_status'], 'transmutedGrade' => $row['transmuted_grade']])</td>
                    <td data-monitoring-cell><x-ui.monitoring-status :status="$monitoringByStudentId->get($st->id)" /></td>
                    <td>
                        @if($row['weakest_component'] && ($row['components'][$row['weakest_component']]['status'] ?? null) === 'Needs Attention')
                            <span class="badge bg-status-attention/10 text-status-attention">{{ $componentLabels[$row['weakest_component']] ?? $row['weakest_component'] }}</span>
                        @elseif($row['weakest_component'])
                            <span class="text-xs text-muted">On track</span>
                        @else
                            <span class="text-xs text-gray-400">No data yet</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @endif
    @endif

    {{-- 6. How to read this table --}}
    <details class="px-5 py-3 border-t border-line text-xs text-gray-600">
        <summary class="cursor-pointer select-none font-medium text-gray-700">How to read this table</summary>
        <ul class="mt-2 space-y-1.5 list-disc pl-4 max-w-4xl">
            <li><strong>Written Work / Performance Task / Examination</strong> — each component's percentage from the Class Record, and its gap from 75.
                The 75 is a reference point set in this system, not a DepEd grading rule; no monitoring rule reads it.</li>
            <li><strong>Computed</strong> — the initial grade: the components weighted by this subject's grading profile.
                <strong>Transmuted</strong> — the computed grade after the DepEd transmutation table. Both are assessment results and are
                <strong>not official</strong> until verified.</li>
            <li><strong>Official grade</strong> — “Official · verified” was set here from the evidence; “Typed · not official” was typed or imported on
                Encode Grades. Only an official (verified, non-provisional) grade at 74 or below is shown as Failing, and only it can open a
                Subject Performance Concern.</li>
            <li><strong>Verify</strong> appears when the evidence is complete and a transmuted grade exists. A learner whose evidence is incomplete
                shows which component has no computable result yet. <strong>Re-verify</strong> appears only when the evidence changed after verification.</li>
            <li><strong>Verify all eligible</strong> covers every eligible learner of the section in this subject and term, whatever the Status filter shows.
                It never changes a grade that is already official, and leaves out provisional or incomplete results. It stops without saving anything if the
                eligible learners changed after you opened the confirmation.</li>
            <li><strong>In-Term Status is based on the Computed grade, not the report card grade</strong> — transmutation can lift a
                raw result below the passing anchor into a passing reported grade, so a learner can be Below Target while the transmuted result
                is 75 or more (“passing on paper”).</li>
            <li><strong>Academic Performance</strong> describes this subject's in-term evidence only; it is not a prediction and opens no concern.
                <strong>Monitoring</strong> counts the learner's open Academic Concerns this term, across all subjects.</li>
        </ul>
    </details>
</section>

{{-- VERIFY ALL ELIGIBLE confirmation — filled from the read-only preview
     (resources/js/verify-all-remaining.js). --}}
<div id="verifyAllRemainingModal" role="dialog" aria-modal="true" aria-labelledby="varTitle" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200 max-h-[85vh] flex flex-col">
        <div class="flex justify-between items-center mb-3">
            <h3 class="text-lg font-semibold text-ink" id="varTitle">Verify all eligible</h3>
            <button type="button" onclick="window.hideModal('verifyAllRemainingModal')" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <div class="overflow-y-auto flex-1 min-h-0 space-y-3 text-sm">
            <p class="text-gray-700" id="varContext"></p>
            <p class="text-xs text-gray-600">Scope: every eligible learner of the section in this subject and term — the Status filter does not limit it.
                Each learner's computed grade becomes the official (transmuted) grade. Learners who already have an official grade are not changed.</p>
            <div id="varChanged" class="hidden alert alert-warning text-xs" role="alert"></div>
            <div>
                <p class="text-xs font-medium text-gray-700">Included:</p>
                <ul class="list-disc list-inside text-xs text-gray-600" id="varIncluded"></ul>
            </div>
            <div id="varExcludedWrap" class="hidden space-y-2">
                <p class="text-xs font-medium text-gray-700">Not included:</p>
                <div id="varExcludedAlreadyEncoded" class="hidden text-xs text-gray-600">
                    <p class="font-medium">Already official (unchanged — re-verify individually if the evidence changed):</p>
                    <ul class="list-disc list-inside" data-name-list></ul>
                </div>
                <div id="varExcludedIncomplete" class="hidden text-xs text-gray-600">
                    <p class="font-medium">Incomplete evidence:</p>
                    <ul class="list-disc list-inside" data-name-list></ul>
                </div>
                <div id="varExcludedNoTransmutation" class="hidden text-xs text-gray-600">
                    <p class="font-medium">No (non-provisional) transmuted grade available:</p>
                    <ul class="list-disc list-inside" data-name-list></ul>
                </div>
            </div>
        </div>
        <div class="flex justify-end gap-3 pt-4">
            <button type="button" onclick="window.hideModal('verifyAllRemainingModal')" class="btn btn-outline">Cancel</button>
            <button type="button" id="varConfirmBtn" class="btn btn-primary">Verify</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Filters refresh the whole page, so the heading, counts, bulk scope
        // and rows always describe the same class. Changing the year,
        // section or term drops choices that belonged to the old context.
        const form = document.getElementById('verifyFilterForm');
        form?.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () {
                (select.dataset.vfReset || '').split(',').filter(Boolean).forEach(function (name) {
                    const dep = form.querySelector('[name="' + name + '"]');
                    if (dep) dep.disabled = true;
                });
                form.querySelectorAll('select').forEach(function (s) { s.setAttribute('aria-busy', 'true'); });
                form.submit();
            });
        });
        window.bindModalOverlayClose('verifyAllRemainingModal');
        window.initVerifyAllRemaining('#verifyAllRemainingBtn');
    });
</script>
@endpush
@endif
@endsection
