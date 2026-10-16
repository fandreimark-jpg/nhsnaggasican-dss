@extends('layouts.app')

@section('title', 'Class Record')
@section('subtitle', $section && $subject
    ? $subject->name . ' — Grade ' . $section->grade_level . ' ' . $section->name . ' — Term ' . $term . ' — ' . $section->school_year
    : 'Select a class')

@section('wide_content', '1')

@section('content')
@include('partials.validation-errors')

{{-- Flash notices — compact and dismissible; they never move the grid. --}}
@foreach(['error' => 'alert-danger', 'warning' => 'alert-warning', 'success' => 'alert-success', 'monitoring_warning' => 'alert-warning'] as $key => $class)
    @if(session($key))
        <div class="alert {{ $class }} alert-row mb-3 py-2 text-sm cr-flash" role="{{ $key === 'error' ? 'alert' : 'status' }}" @if($key === 'success') data-flash-success @endif @if($key === 'monitoring_warning') data-monitoring-warning @endif>
            <span class="flex-1">{{ session($key) }}</span>
            <button type="button" class="cr-dismiss" data-dismiss aria-label="Dismiss this message"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    @endif
@endforeach

{{-- B. Compact filters — only what this user may read (TeachingAccess). --}}
<form method="GET" action="{{ route('teacher.class-record') }}" class="card mb-3" id="classRecordSelectors" data-no-loading aria-label="Choose a class">
    <div class="px-4 py-3 grid grid-cols-2 lg:grid-cols-4 gap-x-3 gap-y-2">
        <div>
            <label class="cr-filter-label" for="crYear">School Year</label>
            <select id="crYear" name="school_year" class="form-select-sm w-full" data-cr-reset="section_id,subject_id">
                @forelse($schoolYears as $y)
                    <option value="{{ $y }}" @selected($y === $schoolYear)>{{ $y }}{{ $y === \App\Models\Section::activeSchoolYear() ? ' (active)' : '' }}</option>
                @empty
                    <option value="">No classes</option>
                @endforelse
            </select>
        </div>
        <div>
            <label class="cr-filter-label" for="crSection">Section</label>
            <select id="crSection" name="section_id" class="form-select-sm w-full" data-cr-reset="subject_id">
                @foreach($sections as $s)
                    <option value="{{ $s->id }}" @selected($section && $s->id === $section->id)>Grade {{ $s->grade_level }} — {{ $s->name }}{{ (int) $s->adviser_id === (int) auth()->id() ? ' (advisory)' : '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="cr-filter-label" for="crTerm">Term</label>
            <select id="crTerm" name="period" class="form-select-sm w-full" data-cr-reset="subject_id">
                @foreach($terms as $t)
                    <option value="{{ $t }}" @selected($t === $term)>Term {{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="cr-filter-label" for="crSubject">Subject</label>
            <select id="crSubject" name="subject_id" class="form-select-sm w-full" title="{{ $subject?->name }}">
                @forelse($subjects as $sub)
                    <option value="{{ $sub->id }}" @selected($subject && $sub->id === $subject->id)>{{ $sub->name }}</option>
                @empty
                    <option value="">No subject for this term</option>
                @endforelse
            </select>
        </div>
    </div>
    <noscript><div class="px-4 pb-3"><button type="submit" class="btn btn-outline btn-sm">Show</button></div></noscript>
</form>

@if(!$section)
    <div class="card"><x-empty-state icon="bi-journal-x" message="You have no class to open."
        hint="You see a Class Record for sections you advise and for subjects you are assigned to teach. The Admin records teaching assignments." /></div>
@elseif(!$subject || !$record)
    <div class="card"><x-empty-state icon="bi-journal-x" message="No subject to show for {{ $section->name }}, Term {{ $term }}."
        hint="Only subjects that apply to this section in this term, and that you advise or are assigned to teach, are listed." /></div>
@else
@php
    $weights = ['written_work' => $record['profile']['ww_weight'], 'performance_task' => $record['profile']['pt_weight'], 'examination' => $record['profile']['ex_weight']];
    $bandColors = ['written_work' => 'cr-band-ww', 'performance_task' => 'cr-band-pt', 'examination' => 'cr-band-ex'];
    $short = ['written_work' => 'WW', 'performance_task' => 'PT', 'examination' => 'EX'];
    $itemCount = $record['items']->count();
    $learnerCount = $record['students']->count();
    $scope = ['section_id' => $section->id, 'subject_id' => $subject->id, 'period' => $term];
    $fmtW = fn($w) => $w === null ? 'none' : \App\Services\ClassRecordService::fmt($w) . '%';
    $profileLabel = $record['profile']['group_key']
        ? ($record['profile']['scheme'] === 'do8_2015' ? 'DO 8, s. 2015' : 'DO 015, s. 2026') . ' — ' . \App\Models\SubjectGroupWeight::labelFor($record['profile']['group_key'])
        : $record['profile']['source_label'];
    $stateBadge = [
        'editable' => ['badge-success', 'bi-pencil-square', 'Editable — you teach this class'],
        'advisory' => ['badge-gray', 'bi-eye', 'View only'],
        'closed'   => ['badge-warning', 'bi-lock-fill', 'Read-only — term closed'],
    ][$accessState];
@endphp

{{-- A/C. Status and class context — one compact band, full subject name. --}}
<div class="cr-context mb-3" data-cr-header>
    <span class="badge {{ $stateBadge[0] }}" data-access-state="{{ $accessState }}"><i class="bi {{ $stateBadge[1] }}" aria-hidden="true"></i> {{ $stateBadge[2] }}</span>
    <span class="cr-context-item"><span class="cr-context-label">Subject</span> <strong>{{ $subject->name }}</strong></span>
    <span class="cr-context-item"><span class="cr-context-label">Class</span> SY {{ $section->school_year }} · Term {{ $term }} · Grade {{ $section->grade_level }} — {{ $section->name }}</span>
    <span class="cr-context-item"><span class="cr-context-label">Assigned teacher</span> <strong data-assigned-teacher>{{ $assignedTeacher?->full_name ?? 'Not assigned' }}</strong></span>
    <span class="cr-context-item"><span class="cr-context-label">Grading profile</span> <strong>WW {{ $fmtW($weights['written_work']) }} · PT {{ $fmtW($weights['performance_task']) }} · EX {{ $fmtW($weights['examination']) }}</strong>
        <span class="text-muted">({{ $profileLabel }})</span></span>
</div>

@if($writeRefusal)
    <div class="alert alert-info alert-row mb-3 py-2 text-sm" data-cr-readonly>
        <i class="bi bi-lock-fill mt-0.5" aria-hidden="true"></i>
        <span>
            @if($accessState === 'advisory')
                @if($assignedTeacher)
                    View only. {{ $assignedTeacher->full_name }} is assigned to teach {{ $subject->name }} here and enters its scores.
                @else
                    View only. No teacher is assigned to {{ $subject->name }} in {{ $section->name }} for Term {{ $term }} yet — the Admin assigns one in Teaching Assignments.
                @endif
                You can still view, download and copy this record. <span class="sr-only">{{ $writeRefusal }}</span>
            @else
                {{ $writeRefusal }} This record is read-only; you can still view, download and copy it.
            @endif
        </span>
    </div>
@endif

{{-- Examination Type warnings (moved from the retired Assessments page). --}}
@php
    $examItems = $record['items']->where('component', 'examination');
    $unroledExamItemNames = $examItems->whereNotNull('exam_role')->isNotEmpty() ? $examItems->whereNull('exam_role')->pluck('name') : collect();
    // Regular items only: an additional-support item may share a type (it is added for the learners scored on it).
    $duplicateExamRoles = $examItems->whereNotNull('exam_role')->where('is_additional_support', false)->groupBy('exam_role')->filter(fn($g) => $g->count() > 1);
@endphp
@if($record['profile']['configuration_error'] ?? null)
    <div class="alert alert-danger mb-3 py-2 text-sm" data-profile-configuration-error>
        <p class="font-medium">Grades cannot be computed for this subject.</p>
        <p class="mt-1">{{ $record['profile']['configuration_error'] }}</p>
    </div>
@endif
@if($unroledExamItemNames->isNotEmpty())
    <div class="alert alert-warning mb-3 py-2 text-sm" data-unroled-exam-warning>
        <p class="font-medium">{{ $unroledExamItemNames->count() === 1 ? 'One Examination item has' : $unroledExamItemNames->count() . ' Examination items have' }} no Examination Type and {{ $unroledExamItemNames->count() === 1 ? 'is' : 'are' }} not counted: {{ $unroledExamItemNames->implode(', ') }}.</p>
        <p class="mt-1">Other Examination items here carry a type (ST1, ST2, Term Exam), so the Examination percentage uses typed items only. The assigned teacher can set the type with Edit.</p>
    </div>
@endif
@if($duplicateExamRoles->isNotEmpty())
    <div class="alert alert-warning mb-3 py-2 text-sm" data-duplicate-exam-role-warning>
        <p class="font-medium">No Examination figure can be computed: more than one regular item has the same Examination Type.</p>
        <ul class="list-disc list-inside">
            @foreach($duplicateExamRoles as $role => $group)
                <li>{{ \App\Services\ExaminationRoles::label($role) }}: {{ $group->pluck('name')->implode(', ') }}</li>
            @endforeach
        </ul>
        <p class="mt-1">The E-Class Record has one column per Examination Type, so the system does not guess which item counts. Edit the extra item to its correct Examination Type (Edit Assessment). Until then no Term Grade is computed and none can be verified for this subject.</p>
    </div>
@endif

<div id="crErrors" class="alert alert-danger mb-3 hidden text-sm" role="alert"></div>
<div id="crConflicts" class="alert alert-warning mb-3 hidden text-sm" role="alert"></div>
<div id="crDraftNotice" class="alert alert-info mb-3 hidden text-sm"></div>

<section class="card cr-workspace" aria-label="Class Record for {{ $subject->name }}">
    {{-- D. Grid toolbar --}}
    <div class="cr-toolbar">
        @if($canEdit)
            <a href="{{ route('teacher.assessments.create', $scope) }}" class="btn btn-secondary btn-sm" data-cr-leave data-cr-add>
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add Assessment
            </a>
        @endif
        <a href="{{ route('teacher.class-record.export', $scope) }}" class="btn btn-outline btn-sm" data-no-loading data-cr-export>
            <i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Download Excel Class Record
        </a>
        <div class="cr-copy" role="group" aria-labelledby="crCopy">
            <button type="button" class="btn btn-outline btn-sm" id="crCopy" data-copy-url="{{ route('teacher.class-record.copy', $scope) }}">
                <i class="bi bi-clipboard" aria-hidden="true"></i> Copy Scores
            </button>
            <label for="crCopyMode" class="cr-copy-label">as</label>
            <select id="crCopyMode" class="form-select-sm cr-copy-select" aria-describedby="crCopyHint">
                <option value="full">with LRN, names &amp; headers</option>
                <option value="scores">scores only</option>
            </select>
        </div>
        <button type="button" class="btn btn-ghost btn-sm ml-auto" id="crHelpToggle" aria-expanded="false" aria-controls="crHelp">
            <i class="bi bi-question-circle" aria-hidden="true"></i> Help
        </button>
    </div>
    <p id="crCopyHint" class="sr-only">With LRN, names and headers: paste into a blank sheet. Scores only: paste into a matching score range.</p>

    {{-- Detailed instructions, on demand. --}}
    <div id="crHelp" class="cr-help" hidden>
        <div class="grid md:grid-cols-2 gap-x-6 gap-y-2">
            @if($canEdit)
            <div>
                <h3 class="cr-help-title">Entering scores</h3>
                <ul>
                    <li>Type a score. <strong>0</strong> is a recorded zero (Completed).</li>
                    <li>The assessments you change are the ones being saved. When you press <strong>Save Scores</strong>, every <strong>blank</strong> learner cell in those assessments is saved as <strong>Missing</strong> — you do not mark Missing one by one. Other assessments, and numbered columns with no assessment, are not touched.</li>
                    <li>To save an assessment's blanks as Missing without changing any score (for example, nobody submitted), select any of its cells and press <strong>Save this assessment's blanks as Missing</strong>, then Save Scores.</li>
                    <li>Typing a valid score later replaces Missing; the change is kept in the score history.</li>
                    <li>Totals, PS, WS and the <strong>Running Initial Grade</strong> update as you type (an unsaved preview). They become saved figures only after Save Scores.</li>
                    <li>Arrow keys and Enter move between cells (left/right move when the cursor is at the edge of the text). Esc undoes the current cell.</li>
                    <li>Paste a block of scores from Excel into a cell to preview it before placing it.</li>
                    <li>Nothing is saved until you press <strong>Save Scores</strong> (or Ctrl+S). A save is all-or-nothing.</li>
                </ul>
            </div>
            @endif
            <div>
                <h3 class="cr-help-title">Reading the computed columns</h3>
                <ul>
                    <li>The grid follows the SSHS E-Class Record layout: numbered columns 1–10 per component (an empty numbered column has no assessment yet; more than ten assessments continue as 11, 12, …), then ST 1 / ST 2 / TE.</li>
                    <li><strong>Highest Possible Score</strong> row — each assessment's HPS; under TOTAL, the HPS of all items; under WS, the component weight; under WS ST1/ST2/TE, each Examination Type's share.</li>
                    <li><strong>TOTAL</strong> — points earned / the HPS of every assessment in the component, as in the E-Class Record. A blank or Missing entry earns 0 points while its HPS still counts (Missing stays recorded as Missing, never as a 0). A component with no numeric score yet shows no TOTAL.</li>
                    <li><strong>PS</strong> — Percentage Score = TOTAL ÷ HPS × 100. <strong>WS</strong> — Weighted Score = PS × the component weight. <strong>WS ST1/ST2/TE</strong> — score ÷ HPS × that type's share. <strong>Examination PS</strong> — (WS ST1 + WS ST2 + WS TE) ÷ all configured shares × 100: an exam not given or not scored adds nothing, and the others are not scaled up.</li>
                    <li><strong>INITIAL GRADE</strong> — the WS of every component, added. While a component has no score yet it reads <em>running · partial</em>: only the components with scores are added, and the weights are not scaled up.</li>
                    <li><strong>TERM GRADE</strong> — the transmuted grade, shown once every required component — and every Examination Type given — has a score. Both are provisional. <strong>Official</strong> is a verified grade.</li>
                    <li><strong>—</strong> means there is not enough evidence yet; hover or focus the cell for the reason.</li>
                </ul>
            </div>
            <div>
                <h3 class="cr-help-title">Download and copy</h3>
                <ul>
                    <li>Both use the last <em>saved</em> scores.</li>
                    <li><strong>With LRN, names &amp; headers</strong>: paste into cell A1 of a blank sheet.</li>
                    <li><strong>Scores only</strong>: paste into the first cell of a matching score range (same learner order and assessment order).</li>
                    <li>Missing is copied as the text MISSING; an empty cell stays empty; 0 stays 0. The Excel file is a system export, not the official E-Class Record template.</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Always-visible legend. --}}
    <div class="cr-legend" id="crLegend">
        <span><span class="cr-chip">12</span> or <span class="cr-chip">0</span> Completed</span>
        <span><span class="cr-chip cr-chip-blank" aria-hidden="true">&nbsp;</span> Blank: No Evidence Yet</span>
        <span><span class="cr-chip cr-chip-missing">M</span> Missing</span>
        @if($canEdit)<span><span class="cr-chip cr-chip-dirty" aria-hidden="true">✎</span> Unsaved change</span>
        <span><span class="cr-chip cr-chip-pending" aria-hidden="true">M?</span> Blank — becomes Missing on save</span>@endif
        <span><span class="cr-chip cr-chip-slot" aria-hidden="true">&nbsp;</span> Numbered column with no assessment</span>
        <span class="text-muted">{{ $learnerCount }} learner(s) · {{ $itemCount }} assessment(s)</span>
    </div>

    {{-- Selected-cell actions (editable classes only). --}}
    @if($canEdit && $itemCount > 0 && $learnerCount > 0)
        <div class="cr-selection" id="crSelection" aria-label="Selected score cell">
            <div class="cr-selection-text">
                <span id="crSelTarget" class="cr-selection-target">No cell selected — click or tab into a score cell to clear it or undo it.</span>
                <span id="crSelNote" class="cr-selection-note"></span>
            </div>
            <div class="cr-selection-actions">
                <button type="button" class="btn btn-outline btn-xs" id="crClearEntry" disabled>
                    <i class="bi bi-eraser" aria-hidden="true"></i> Clear Entry
                </button>
                <button type="button" class="btn btn-ghost btn-xs" id="crUndoCell" disabled>
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Undo
                </button>
                {{-- Assessment-scoped: puts the selected cell's ASSESSMENT in the
                     save so its blanks become Missing, without typing a score. --}}
                <button type="button" class="btn btn-outline btn-xs" id="crScopeAssessment" disabled aria-pressed="false">
                    <i class="bi bi-list-check" aria-hidden="true"></i> <span>Save this assessment's blanks as Missing</span>
                </button>
            </div>
        </div>
    @endif

    @if($learnerCount === 0)
        <x-empty-state icon="bi-people" message="No learners are enrolled in {{ $section->name }} for {{ $section->school_year }}."
            hint="Learner records and section enrollment are managed by the Admin. Once learners are enrolled they appear here." />
    @elseif($itemCount === 0)
        <div class="cr-empty" data-cr-empty>
            @if($canEdit)
                <p class="font-medium text-ink">No assessments yet for {{ $subject->name }}, Term {{ $term }}.</p>
                <p class="text-sm text-muted mt-1">Add the first assessment (name, component, HPS). It appears here as a column with every learner's cell blank, ready for scores.</p>
                <a href="{{ route('teacher.assessments.create', $scope) }}" class="btn btn-primary btn-sm mt-3" data-cr-leave><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Assessment</a>
            @else
                <p class="font-medium text-ink">No assessment evidence is recorded for {{ $subject->name }}, Term {{ $term }}.</p>
                <p class="text-sm text-muted mt-1">Assessments and scores are added by the assigned teacher.</p>
            @endif
        </div>
    @endif

    {{-- E. Score grid. --}}
    <div class="{{ $itemCount === 0 || $learnerCount === 0 ? 'hidden' : '' }}" id="classRecord"
         data-save-url="{{ route('teacher.class-record.save') }}"
         data-preview-url="{{ route('teacher.class-record.preview') }}"
         data-section-id="{{ $section->id }}" data-subject-id="{{ $subject->id }}" data-period="{{ $term }}"
         data-school-year="{{ $section->school_year }}"
         data-user-id="{{ auth()->id() }}"
         data-can-edit="{{ $canEdit ? '1' : '0' }}"
         data-new-assessment="{{ $newAssessmentId }}"
         data-draft-key="cr-draft:{{ auth()->id() }}:{{ $section->school_year }}:{{ $section->id }}:{{ $subject->id }}:{{ $term }}">
        <div class="cr-scroll" tabindex="-1">
            @php
                $fmt = fn($v) => \App\Services\ClassRecordService::fmt($v);
                $cols = $record['columns'];
                $examOther = $cols['exam_other'];
                $examShares = $record['exam_shares'];
                $examRoleShort = \App\Services\ClassRecordService::EXAM_COLUMNS;
                $bandTitle = ['written_work' => 'Written / Oral Works (WWs)', 'performance_task' => 'Product / Performance Tasks (PTs)', 'examination' => 'Examinations (EXs)'];
                $totalCols = 1 + count($cols['written_work']) + 3 + count($cols['performance_task']) + 3 + 3 + count($examOther) + 5 + 4;
                $genderLabel = ['male' => 'MALE', 'female' => 'FEMALE'];
                $weightText = fn($c) => $weights[$c] === null ? 'not weighted' : $fmt($weights[$c]) . '%';
            @endphp
            <table class="cr-table cr-template">
                <thead>
                    <tr>
                        <th scope="col" rowspan="2" class="cr-sticky cr-name-col cr-name-head">Learners' Names</th>
                        @foreach(['written_work', 'performance_task'] as $component)
                            <th scope="colgroup" colspan="{{ count($cols[$component]) + 3 }}" class="{{ $bandColors[$component] }} cr-band cr-gs" data-band="{{ $component }}">
                                <span class="cr-band-label">{{ $bandTitle[$component] }} <span class="font-normal">· {{ $weightText($component) }}</span></span>
                            </th>
                        @endforeach
                        <th scope="colgroup" colspan="{{ 3 + count($examOther) + 5 }}" class="cr-band-ex cr-band cr-gs" data-band="examination">
                            <span class="cr-band-label">{{ $bandTitle['examination'] }} <span class="font-normal">· {{ $weightText('examination') }}</span></span>
                        </th>
                        <th scope="col" rowspan="2" class="cr-sum-head cr-grade-head cr-gs">Initial<br>Grade</th>
                        <th scope="col" rowspan="2" class="cr-sum-head cr-grade-head">Term<br>Grade</th>
                        <th scope="colgroup" colspan="2" class="cr-band cr-band-grade cr-gs"><span class="cr-band-label">DSS</span></th>
                    </tr>
                    <tr>
                        @foreach(['written_work', 'performance_task'] as $component)
                            @foreach($cols[$component] as $slot => $item)
                                @include('teacher.partials.class-record-item-head', ['item' => $item, 'label' => $slot + 1, 'first' => $loop->first])
                            @endforeach
                            <th scope="col" class="cr-sum-head" data-sum-head="{{ $component }}:total" title="Points earned / possible over scored items">TOTAL</th>
                            <th scope="col" class="cr-sum-head" data-sum-head="{{ $component }}:ps"><abbr title="Percentage Score">PS</abbr></th>
                            <th scope="col" class="cr-sum-head" data-sum-head="{{ $component }}:ws"><abbr title="Weighted Score">WS</abbr></th>
                        @endforeach
                        @foreach($cols['exam_roles'] as $role => $item)
                            @include('teacher.partials.class-record-item-head', ['item' => $item, 'label' => $examRoleShort[$role], 'first' => $loop->first])
                        @endforeach
                        @foreach($examOther as $item)
                            @include('teacher.partials.class-record-item-head', ['item' => $item, 'label' => 'Other', 'first' => false])
                        @endforeach
                        @foreach($examRoleShort as $role => $short)
                            <th scope="col" class="cr-sum-head" data-sum-head="examination:ws_{{ $role }}"><abbr title="Weighted score of {{ $short }}: its percentage × its share">WS {{ str_replace(' ', '', $short) }}</abbr></th>
                        @endforeach
                        <th scope="col" class="cr-sum-head" data-sum-head="examination:ps"><abbr title="Percentage Score">PS</abbr></th>
                        <th scope="col" class="cr-sum-head" data-sum-head="examination:ws"><abbr title="Weighted Score">WS</abbr></th>
                        <th scope="col" class="cr-sum-head cr-gs">Official</th>
                        <th scope="col" class="cr-sum-head">Evidence</th>
                    </tr>
                    <tr class="cr-hps-row" data-hps-row>
                        <th scope="row" class="cr-sticky cr-name-col text-left">Highest Possible Score</th>
                        @foreach(['written_work', 'performance_task'] as $component)
                            @foreach($cols[$component] as $item)
                                <td class="{{ $loop->first ? 'cr-gs' : '' }} {{ $item ? '' : 'cr-slot-empty' }}">{{ $item ? $fmt((float) $item->max_score) : '' }}</td>
                            @endforeach
                            @php $real = collect($cols[$component])->filter(); @endphp
                            <td title="Highest Possible Score of all {{ $real->count() }} item(s). A learner's TOTAL counts only the items they have a score for.">{{ $real->isEmpty() ? '' : $fmt((float) $real->sum('max_score')) }}</td>
                            <td>100</td>
                            <td title="Component weight">{{ $weights[$component] === null ? '—' : $fmt($weights[$component]) . '%' }}</td>
                        @endforeach
                        @foreach($cols['exam_roles'] as $item)
                            <td class="{{ $loop->first ? 'cr-gs' : '' }} {{ $item ? '' : 'cr-slot-empty' }}">{{ $item ? $fmt((float) $item->max_score) : '' }}</td>
                        @endforeach
                        @foreach($examOther as $item)
                            <td>{{ $fmt((float) $item->max_score) }}</td>
                        @endforeach
                        @foreach($examRoleShort as $role => $short)
                            <td title="Share of {{ $short }} in the Examination percentage">{{ isset($examShares[$role]) ? $fmt($examShares[$role]) : '—' }}</td>
                        @endforeach
                        <td>{{ $weights['examination'] === null ? '' : '100' }}</td>
                        <td title="Component weight">{{ $weights['examination'] === null ? '—' : $fmt($weights['examination']) . '%' }}</td>
                        <td colspan="4" class="cr-gs text-left text-muted">provisional until verified</td>
                    </tr>
                </thead>
                <tbody>
                    @php $currentGender = false; $groupNo = 0; @endphp
                    @foreach($record['students'] as $student)
                        @php
                            $out = $record['display'][$student->id];
                            $learnerName = $student->last_name . ', ' . $student->first_name;
                            $newGroup = $student->gender !== $currentGender;
                            if ($newGroup) { $currentGender = $student->gender; $groupNo = 0; }
                            $groupNo++;
                        @endphp
                        @if($newGroup)
                            <tr class="cr-group-row" data-gender-group="{{ $student->gender }}">
                                <th scope="rowgroup" class="cr-sticky cr-name-col">{{ $genderLabel[$student->gender] ?? 'NOT RECORDED' }}</th>
                                <td colspan="{{ $totalCols - 1 }}"></td>
                            </tr>
                        @endif
                        <tr data-student-row="{{ $student->id }}">
                            <th scope="row" class="cr-sticky cr-name-col">
                                <span class="block font-medium text-ink truncate" title="{{ $learnerName }}"><span class="cr-rowno">{{ $groupNo }}</span>{{ $learnerName }}</span>
                                <span class="block text-[11px] text-muted">{{ $student->lrn }}
                                    · <a href="{{ route('teacher.learners.report', ['student' => $student->id, 'school_year' => $section->school_year, 'period' => $term]) }}" class="text-brand-700 underline" data-cr-leave aria-label="Learner report for {{ $learnerName }}">Report</a></span>
                            </th>
                            @foreach(['written_work', 'performance_task'] as $component)
                                @foreach($cols[$component] as $item)
                                    @include('teacher.partials.class-record-cell', ['item' => $item, 'first' => $loop->first])
                                @endforeach
                                @foreach(['total', 'ps', 'ws'] as $k)
                                    <td class="cr-sum" data-cr-out="{{ $component }}:{{ $k }}" data-cr-why="{{ $component }}:{{ $k }}:why"
                                        title="{{ $out[$component . ':' . $k . ':why'] }}">{{ $out[$component . ':' . $k] }}</td>
                                @endforeach
                            @endforeach
                            @foreach($cols['exam_roles'] as $item)
                                @include('teacher.partials.class-record-cell', ['item' => $item, 'first' => $loop->first])
                            @endforeach
                            @foreach($examOther as $item)
                                @include('teacher.partials.class-record-cell', ['item' => $item, 'first' => false])
                            @endforeach
                            @foreach(array_keys($examRoleShort) as $role)
                                <td class="cr-sum" data-cr-out="examination:ws_{{ $role }}">{{ $out['examination:ws_' . $role] }}</td>
                            @endforeach
                            @foreach(['ps', 'ws'] as $k)
                                <td class="cr-sum" data-cr-out="examination:{{ $k }}" data-cr-why="examination:{{ $k }}:why"
                                    title="{{ $out['examination:' . $k . ':why'] }}">{{ $out['examination:' . $k] }}</td>
                            @endforeach
                            @foreach(['initial', 'transmuted', 'official', 'evidence'] as $key)
                                <td class="cr-sum {{ $loop->first || $key === 'official' ? 'cr-gs' : '' }} {{ in_array($key, ['initial', 'transmuted'], true) ? 'cr-grade' : '' }} {{ $key === 'official' && str_contains($out['grade:official:note'], 'Failed') ? 'text-status-failing font-semibold' : '' }}"
                                    @isset($out['grade:' . $key . ':why']) data-cr-why="grade:{{ $key }}:why" title="{{ $out['grade:' . $key . ':why'] }}" @endisset>
                                    <span data-cr-out="grade:{{ $key }}">{{ $out['grade:' . $key] }}</span>
                                    <span class="block text-[10px] text-muted font-normal" data-cr-out="grade:{{ $key }}:note">{{ $out['grade:' . $key . ':note'] }}</span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- F. Persistent save bar (editable classes). Sticky to the bottom of
         the page's scroll area while the workspace is on screen; it sits in
         the normal flow, so it never covers the last row or the grid's
         horizontal scrollbar when scrolled to the end. --}}
    @if($canEdit)
        <div class="cr-savebar" id="crSaveBar">
            <span class="cr-savebar-count" id="crUnsaved">No unsaved changes</span>
            <span class="cr-savebar-status" id="crStatus" role="status" aria-live="polite" data-state="idle"></span>
            <span class="cr-savebar-preview" id="crPreviewStatus" data-state="idle"></span>
            <span class="cr-savebar-notice" id="crMissingNotice">
                <i class="bi bi-info-circle" aria-hidden="true"></i> Blank entries in the assessments being saved will be marked Missing.
                <span id="crScope" class="cr-savebar-scope"></span>
            </span>
            <button type="button" class="btn btn-ghost btn-sm" id="crDiscard" disabled>Discard changes</button>
            <button type="button" class="btn btn-primary btn-sm" id="crSave" disabled data-no-loading>
                <i class="bi bi-save" aria-hidden="true"></i> Save Scores
            </button>
        </div>
    @endif
</section>

{{-- Paste preview --}}
<div id="crPasteModal" role="dialog" aria-modal="true" aria-labelledby="crPasteTitle" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-2xl p-6 transition-all duration-200">
        <h3 id="crPasteTitle" class="text-lg font-semibold text-ink mb-2">Paste from Excel — preview</h3>
        <p class="text-sm text-muted mb-3" id="crPasteSummary"></p>
        <div class="max-h-80 overflow-auto border rounded-lg" id="crPasteBody"></div>
        <div class="flex justify-end gap-3 pt-4">
            <button type="button" class="btn btn-outline" id="crPasteCancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="crPasteApply">Place these values</button>
        </div>
    </div>
</div>

{{-- Unsaved-changes guard: leaving, changing the class, adding an
     assessment, downloading or copying while edits are unsaved. --}}
<div id="crLeaveModal" role="dialog" aria-modal="true" aria-labelledby="crLeaveTitle" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-md p-6 transition-all duration-200">
        <h3 id="crLeaveTitle" class="text-lg font-semibold text-ink mb-2">You have unsaved scores</h3>
        <p class="text-sm text-muted mb-4" id="crLeaveMessage"></p>
        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" class="btn btn-outline" id="crLeaveCancel">Cancel</button>
            <button type="button" class="btn btn-danger-outline" id="crLeaveDiscard">Discard changes</button>
            <button type="button" class="btn btn-primary" id="crLeaveSave">Save and continue</button>
        </div>
    </div>
</div>

{{-- Explicit discard confirmation (save bar). --}}
<div id="crDiscardModal" role="dialog" aria-modal="true" aria-labelledby="crDiscardTitle" class="hidden opacity-0 fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div class="modal-box scale-95 opacity-0 bg-white rounded-xl shadow-modal w-full max-w-md p-6 transition-all duration-200">
        <h3 id="crDiscardTitle" class="text-lg font-semibold text-ink mb-2">Discard unsaved changes?</h3>
        <p class="text-sm text-muted mb-4" id="crDiscardMessage">Every cell returns to its last saved value. Nothing already saved is changed.</p>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn btn-outline" id="crDiscardCancel">Keep editing</button>
            <button type="button" class="btn btn-danger-outline" id="crDiscardConfirm">Discard changes</button>
        </div>
    </div>
</div>
@endif
@endsection
