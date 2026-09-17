@extends('layouts.app')

@section('title', 'Academic Terms')
@section('subtitle', 'Academic years and term control — Active School Year ' . $activeSchoolYear)

@section('content')

@if($errors->any())
<div role="alert" class="alert alert-danger mb-4">
    @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
</div>
@endif

@if(session('error'))
<div class="alert alert-danger mb-4">
    {{ session('error') }}
</div>
@endif

@if(session('success'))
<div class="alert alert-success mb-4">
    {{ session('success') }}
</div>
@endif

<div class="card mb-4">
    <div class="card-header">
        <div>
        <h2 class="card-title">Academic Years</h2>
        <p class="card-subtitle max-w-3xl">
            Configure which school year is active — no code change or new section required. Everything on this page,
            and every other screen that reads "the active school year," follows whichever one is marked Active below.
            Activating a new year never renames, moves, or deletes a previous year's records — they stay on file as
            historical records (grades, reports, assessments, risk results, interventions, and enrollments), and any
            term still open in the previous year is closed. Academic years are never deleted.
        </p>
        </div>
    </div>

    <div class="p-5 grid grid-cols-1 lg:grid-cols-2 gap-3">
        @forelse($academicYears as $year)
        @php
            $yearTone = $year->is_active ? 'success' : ($year->lifecycle === 'Completed' ? 'gray' : 'info');
            $yearLabel = $year->is_active ? 'Active' : ($year->lifecycle === 'Completed' ? 'Completed — Historical Record' : 'Upcoming');
            $yearIcon = $year->is_active ? 'bi-check-circle-fill' : ($year->lifecycle === 'Completed' ? 'bi-archive' : 'bi-calendar-plus');
        @endphp
        <div class="card p-5 flex flex-col gap-4 {{ $year->is_active ? 'ring-2 ring-brand-500/30' : '' }} {{ $schoolYear === $year->school_year ? 'bg-brand-50/40' : '' }}">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="icon-box {{ $year->is_active ? 'icon-box-brand' : ($year->lifecycle === 'Completed' ? 'icon-box-slate' : 'icon-box-info') }}" aria-hidden="true"><i class="bi bi-calendar3"></i></div>
                    <div class="min-w-0">
                        <p class="text-xl font-bold text-ink leading-tight">{{ $year->school_year }}</p>
                        <p class="text-xs text-muted mt-0.5">
                            @if($year->start_date || $year->end_date)
                                {{ $year->start_date?->format('M j, Y') ?? '—' }} – {{ $year->end_date?->format('M j, Y') ?? '—' }}
                            @else
                                No dates recorded
                            @endif
                        </p>
                    </div>
                </div>
                <x-ui.status-badge :tone="$yearTone" :icon="$yearIcon" :label="$yearLabel" />
            </div>

            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center" title="Records on file for this school year">
                <div class="rounded-lg bg-surface px-2 py-2"><dt class="text-[11px] uppercase tracking-wide text-muted">Sections</dt><dd class="text-lg font-bold text-ink tabular-nums">{{ $year->record_counts['sections'] }}</dd></div>
                <div class="rounded-lg bg-surface px-2 py-2"><dt class="text-[11px] uppercase tracking-wide text-muted">Enrolled</dt><dd class="text-lg font-bold text-ink tabular-nums">{{ $year->record_counts['enrollments'] }}</dd></div>
                <div class="rounded-lg bg-surface px-2 py-2"><dt class="text-[11px] uppercase tracking-wide text-muted">Grades</dt><dd class="text-lg font-bold text-ink tabular-nums">{{ $year->record_counts['grades'] }}</dd></div>
                <div class="rounded-lg bg-surface px-2 py-2"><dt class="text-[11px] uppercase tracking-wide text-muted">Reports</dt><dd class="text-lg font-bold text-ink tabular-nums">{{ $year->record_counts['reports'] }}</dd></div>
            </dl>

            <div class="flex items-center gap-2 flex-wrap mt-auto">
                @unless($year->is_active)
                    <form method="POST" action="{{ route('admin.academic-years.activate', $year->id) }}"
                          data-action-confirm="Activate School Year {{ $year->school_year }}? It becomes the active school year for every screen. {{ $activeSchoolYear }} will be marked completed and its records kept as historical records; any term still open in it will be closed."
                          data-action-title="Confirm Activation" data-action-label="Yes, Activate" data-action-icon="bi-check-circle" data-action-loading="Activating...">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-circle" aria-hidden="true"></i> Activate
                        </button>
                    </form>
                @endunless
                <a href="{{ route('admin.academic-terms', ['school_year' => $year->school_year]) }}"
                   class="btn btn-outline btn-sm {{ $schoolYear === $year->school_year ? 'bg-surface font-semibold' : '' }}">
                    <i class="bi bi-calendar-check" aria-hidden="true"></i> View terms
                </a>
                <button type="button" data-academic-edit="Year" data-record="{{ json_encode($year->only(['school_year', 'start_date', 'end_date', 'rename_blocked'])) }}" data-url="{{ route('admin.academic-years.update', $year) }}"
                        class="btn btn-secondary btn-sm">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </button>
            </div>
        </div>
        @empty
        <div class="lg:col-span-2">
            <x-empty-state icon="bi bi-calendar3" message="No academic years configured yet."
                hint="The system falls back to the most recently created section's school year (currently {{ $schoolYear }}). Add one below to take explicit control." />
        </div>
        @endforelse
    </div>

    <div class="card-footer">
        <form method="POST" action="{{ route('admin.academic-years.store') }}" class="flex flex-wrap items-end gap-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-muted self-center mr-1">Add a school year</span>
            @csrf
            <div>
                <label class="form-label">School Year</label>
                <input type="text" name="school_year" required placeholder="e.g. 2027-2028"
                       class="form-select-sm">
            </div>
            <div>
                <label class="form-label">Start Date <span class="text-gray-400">(optional)</span></label>
                <input type="date" name="start_date" class="form-select-sm">
            </div>
            <div>
                <label class="form-label">End Date <span class="text-gray-400">(optional)</span></label>
                <input type="date" name="end_date" class="form-select-sm">
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add Academic Year
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="flex flex-wrap items-center justify-between gap-3 w-full">
            <div>
                <h2 class="card-title">
                    Term Control — School Year {{ $schoolYear }}
                    @if($isActiveYear)
                        <x-ui.status-badge tone="success" icon="bi-check-circle-fill" label="Active" class="ml-1" />
                    @else
                        <x-ui.status-badge tone="gray" icon="bi-archive" label="Historical Record — read-only" class="ml-1" />
                    @endif
                </h2>
                <p class="card-subtitle max-w-3xl">
                    Only one term can be open for encoding at a time. A term cannot be opened until the previous term is 100% encoded across every section.
                    @unless($isActiveYear)
                        Terms of a school year that is not active cannot be opened or closed from here, and Advisers cannot write into them; activate the year first if it genuinely needs to be reopened.
                    @endunless
                </p>
            </div>
            @if($schoolYears->count() > 1)
            <form method="GET" class="flex items-center gap-2">
                <label class="form-label mb-0" for="termsSchoolYear">School year</label>
                <select id="termsSchoolYear" name="school_year" onchange="this.form.submit()" class="form-select-sm">
                    @foreach($schoolYears as $sy)
                        <option value="{{ $sy }}" {{ $schoolYear === $sy ? 'selected' : '' }}>{{ $sy }}{{ $sy === $activeSchoolYear ? ' (active)' : '' }}</option>
                    @endforeach
                </select>
            </form>
            @endif
        </div>
    </div>

    @if($terms->isEmpty())
    <div class="px-6 py-6 text-sm text-gray-400">
        No terms exist for School Year {{ $schoolYear }} yet — Term 1, 2, and 3 are created when the year is activated.
    </div>
    @endif

    <div class="divide-y divide-line">
        @foreach($terms as $term)
        @php
            // Overall encoding progress for this term — the SAME encoded/expected
            // figures sectionCapacityBreakdown() supplies for the table below,
            // summed. Null when nothing is expected yet (never a fabricated 0%).
            $termExpectedTotal = collect($term->capacity)->sum('expected');
            $termEncodedTotal  = collect($term->capacity)->sum(fn($c) => min($c['encoded'], $c['expected']));
            $termPct = $termExpectedTotal > 0 ? (int) round($termEncodedTotal / $termExpectedTotal * 100) : null;
            $openTermNumber = $terms->firstWhere('is_open', true)?->term;
            $termStateLabel = $term->is_open ? 'Open' : (($isActiveYear && !$term->opened_at && $openTermNumber && $term->term > $openTermNumber) ? 'Locked' : 'Closed');
            $termStateTone  = $term->is_open ? 'success' : 'gray';
        @endphp
        <div class="flex flex-col md:flex-row md:items-start justify-between gap-4 px-5 py-5 {{ $term->is_open ? 'bg-brand-50/30' : '' }}">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-3">
                    <div class="icon-box {{ $term->is_open ? 'icon-box-success' : 'icon-box-slate' }}" aria-hidden="true"><i class="bi {{ $term->is_open ? 'bi-unlock' : 'bi-lock' }}"></i></div>
                    <div>
                        <h3 class="card-title">Term {{ $term->term }}</h3>
                        <div class="flex items-center gap-2 mt-0.5">
                            <x-ui.status-badge :tone="$termStateTone" :icon="$term->is_open ? 'bi-unlock' : 'bi-lock'" :label="$termStateLabel" />
                            @if($term->start_date || $term->end_date)
                                <span class="text-xs text-muted">{{ $term->start_date?->format('M j, Y') ?? '—' }} – {{ $term->end_date?->format('M j, Y') ?? '—' }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                @if($termPct !== null)
                <div class="mt-3 max-w-md">
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="text-muted">Overall encoding progress</span>
                        <span class="font-semibold text-ink tabular-nums">{{ $termEncodedTotal }} / {{ $termExpectedTotal }} · {{ $termPct }}%</span>
                    </div>
                    <x-ui.progress-bar :value="$termPct" :auto="true" :label="'Term ' . $term->term . ' encoding progress'" />
                </div>
                @endif
                <p class="text-xs text-muted mt-2">
                    @if(!$term->completion['has_anything_expected'])
                        No section has both students and subjects assigned yet — nothing to encode.
                    @elseif($term->completion['complete'])
                        All sections fully encoded for this term.
                    @else
                        Not fully encoded:
                        @foreach($term->completion['incomplete_sections'] as $s)
                            {{ $s['section'] }}
                            @if(isset($s['reason']))
                                ({{ $s['reason'] }})
                            @else
                                ({{ $s['encoded'] }}/{{ $s['expected'] }})
                            @endif
                            @if(!$loop->last), @endif
                        @endforeach
                    @endif
                </p>

                {{-- TASK 4 of "dashboard structure and upload safeguards"
                     — the subjects x students arithmetic behind the line
                     above, per section, visible before anyone attempts to
                     open the NEXT term and gets refused. Open by default
                     only when this term isn't complete, so the gap that
                     matters surfaces without a click. --}}
                <details class="mt-2" {{ $term->completion['complete'] ? '' : 'open' }}>
                    <summary class="text-xs font-medium text-brand-700 cursor-pointer hover:underline">
                        Section breakdown ({{ count($term->capacity) }} section{{ count($term->capacity) === 1 ? '' : 's' }})
                    </summary>
                    <div class="mt-2 tbl-wrap tbl-scroll">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th scope="col">Section</th>
                                    <th scope="col" class="tbl-num">Subjects</th>
                                    <th scope="col" class="tbl-num">Students</th>
                                    <th scope="col" class="tbl-num">Expected</th>
                                    <th scope="col" class="tbl-num">Encoded</th>
                                    <th scope="col" class="min-w-[9rem]">Completion</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($term->capacity as $c)
                                @php $cPct = $c['expected'] > 0 ? (int) round(min($c['encoded'], $c['expected']) / $c['expected'] * 100) : null; @endphp
                                <tr>
                                    <td class="whitespace-nowrap font-medium text-ink">{{ $c['section']->name }} <span class="text-muted font-normal">(Grade {{ $c['section']->grade_level }})</span></td>
                                    <td class="tbl-num">{{ $c['subject_count'] }}</td>
                                    <td class="tbl-num">{{ $c['student_count'] }}</td>
                                    <td class="tbl-num">{{ $c['expected'] }}</td>
                                    <td class="tbl-num">{{ $c['encoded'] }}</td>
                                    <td>
                                        @if($cPct === null)
                                            <span class="text-gray-300">—</span>
                                        @else
                                            <div class="flex items-center gap-2">
                                                <x-ui.progress-bar :value="$cPct" :auto="true" size="sm" class="flex-1" :label="$c['section']->name . ' completion'" />
                                                <span class="text-xs tabular-nums text-ink w-9 text-right">{{ $cPct }}%</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($c['expected'] === 0)
                                            <x-ui.status-badge tone="outline" label="No students/subjects yet" />
                                        @elseif($c['encoded'] >= $c['expected'])
                                            <x-ui.status-badge tone="success" icon="bi-check-circle" label="Complete" />
                                        @else
                                            <x-ui.status-badge tone="warning" icon="bi-exclamation-circle" :label="($c['expected'] - $c['encoded']) . ' short'" />
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7">
                                        <x-empty-state message="No sections exist for this school year yet." icon="bi bi-grid"
                                            hint="Add a section from the Sections page — it will appear here once it does." class="py-4 text-xs" />
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>

            <div class="flex gap-2 flex-wrap items-start">
                <button type="button" data-academic-edit="Term" data-record="{{ json_encode($term->only(['term', 'start_date', 'end_date'])) }}" data-url="{{ route('admin.academic-terms.update', $term) }}"
                        class="btn btn-secondary btn-sm">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </button>

                @if($isActiveYear)
                    @if($term->is_open)
                        {{-- Closing is not destructive — nothing is removed; the
                             term becomes read-only. Neutral confirmation, never
                             the red removal dialog. --}}
                        <form method="POST" action="{{ route('admin.academic-terms.close', $term->term) }}"
                              data-action-confirm="Close Term {{ $term->term }} of School Year {{ $schoolYear }}? Advisers will no longer be able to encode grades or upload assessments for it. Nothing is deleted."
                              data-action-title="Confirm Close" data-action-label="Yes, Close" data-action-icon="bi-lock" data-action-loading="Closing...">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-sm">
                                <i class="bi bi-lock" aria-hidden="true"></i> Close Term {{ $term->term }}
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.academic-terms.open', $term->term) }}"
                              data-action-confirm="Open Term {{ $term->term }} of School Year {{ $schoolYear }} for encoding? Any other open term will be closed."
                              data-action-title="Confirm Open" data-action-label="Yes, Open" data-action-icon="bi-unlock" data-action-loading="Opening...">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-unlock" aria-hidden="true"></i> Open Term {{ $term->term }}
                            </button>
                        </form>
                    @endif
                @else
                    <span class="text-xs text-muted self-center">
                        {{ $term->closed_at ? 'Closed ' . $term->closed_at->format('M j, Y') : ($term->opened_at ? 'Opened ' . $term->opened_at->format('M j, Y') : 'Never opened') }}
                    </span>
                @endif
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- EDIT ACADEMIC YEAR MODAL --}}
<div role="dialog" aria-modal="true" tabindex="-1" id="editAcademicYearModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200 opacity-0">
    <div class="modal-box bg-white rounded-xl shadow-modal w-full max-w-md p-6 transition-all duration-200 scale-95 opacity-0">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-ink">Edit Academic Year</h3>
            <button type="button" onclick="closeEditAcademicYearModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <form id="academicYearEditForm" method="POST" class="space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label class="form-label">School Year</label>
                <input type="text" name="school_year" id="editAcademicYearSchoolYear" required maxlength="20"
                       placeholder="e.g. 2027-2028"
                       class="form-input">
                <p id="editAcademicYearLockedNote" class="hidden text-xs text-status-attention mt-1">
                    This school year already has academic records referencing it, so its label cannot be changed here — you may still edit its dates below.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Start Date <span class="text-gray-400 text-xs">(optional)</span></label>
                    <input type="date" name="start_date" id="editAcademicYearStartDate"
                           class="form-input">
                </div>
                <div>
                    <label class="form-label">End Date <span class="text-gray-400 text-xs">(optional)</span></label>
                    <input type="date" name="end_date" id="editAcademicYearEndDate"
                           class="form-input">
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeEditAcademicYearModal()"
                        class="btn btn-outline">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

{{-- EDIT ACADEMIC TERM MODAL --}}
<div role="dialog" aria-modal="true" tabindex="-1" id="editAcademicTermModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200 opacity-0">
    <div class="modal-box bg-white rounded-xl shadow-modal w-full max-w-md p-6 transition-all duration-200 scale-95 opacity-0">
        <div class="flex justify-between items-center mb-4">
            <h3 id="editAcademicTermTitle" class="text-lg font-semibold text-ink">Edit Academic Term</h3>
            <button type="button" onclick="closeEditAcademicTermModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <form id="academicTermEditForm" method="POST" class="space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label class="form-label">Term Name</label>
                <input type="text" id="editAcademicTermName" disabled
                       class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500">
                <p class="text-xs text-muted mt-1">The term number cannot be changed — only its dates.</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Start Date <span class="text-gray-400 text-xs">(optional)</span></label>
                    <input type="date" name="start_date" id="editAcademicTermStartDate"
                           class="form-input">
                </div>
                <div>
                    <label class="form-label">End Date <span class="text-gray-400 text-xs">(optional)</span></label>
                    <input type="date" name="end_date" id="editAcademicTermEndDate"
                           class="form-input">
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeEditAcademicTermModal()"
                        class="btn btn-outline">Cancel</button>
                <button type="submit"
                        class="btn btn-primary">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
{{-- Modal logic lives in resources/js/modal.js. --}}

@endsection
