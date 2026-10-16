{{-- Individual learner report — a standalone printable page (no sidebar),
     for a parent/guardian consultation. LearnerReportController decides the
     scope; this view shows only what it was given. No internal notes. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Learner Report — {{ $student->last_name }}, {{ $student->first_name }} — Naggasican NHS DSS</title>
    <x-app-favicon />
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    @vite(['resources/css/app.css'])
    <style>
        @page { margin: 12mm; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            main { max-width: none !important; padding: 0 !important; }
            /* Long tables may run over pages: rows never split and the header
               repeats; only the small identification block stays whole. */
            .card { box-shadow: none !important; border: 1px solid #ddd; break-inside: auto; }
            header.card, .card-header { break-inside: avoid; break-after: avoid; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
            h2 { break-after: avoid; }
        }
    </style>
</head>
<body class="min-h-screen bg-surface font-sans text-ink antialiased">
<main class="max-w-4xl mx-auto px-4 py-6" role="main">
    <div class="no-print flex flex-wrap items-center gap-2 mb-4">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('teacher.classes') }}" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back</a>
        <form method="GET" class="flex flex-wrap items-center gap-2 ml-auto" data-no-loading>
            <label class="text-xs text-muted" for="lrYear">School Year</label>
            <select id="lrYear" name="school_year" class="form-select-sm" onchange="this.form.submit()">
                @foreach($years as $y)<option value="{{ $y }}" @selected($y === $schoolYear)>{{ $y }}</option>@endforeach
            </select>
            <label class="text-xs text-muted" for="lrTerm">Term</label>
            <select id="lrTerm" name="period" class="form-select-sm" onchange="this.form.submit()">
                @foreach($terms as $t)<option value="{{ $t }}" @selected($t === $term)>Term {{ $t }}</option>@endforeach
            </select>
        </form>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print</button>
    </div>

    <header class="card mb-4">
        <div class="card-body flex items-start gap-4">
            <img src="{{ asset('images/nagga-logo.png') }}" alt="" class="w-14 h-14 object-contain">
            <div class="flex-1">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-700">Naggasican National High School — Learner Academic Report</p>
                <h1 class="text-xl font-bold text-ink">{{ $student->last_name }}, {{ $student->first_name }} {{ $student->middle_name }}</h1>
                <p class="text-sm text-muted">LRN {{ $student->lrn }} · Grade {{ $section->grade_level }} — {{ $section->name }} · School Year {{ $schoolYear }} · Term {{ $term }}</p>
                <p class="text-xs text-muted mt-1">
                    {{ $consolidated ? 'All subjects of the advisory section.' : 'Limited to the subject(s) taught by ' . auth()->user()->full_name . '.' }}
                    Generated {{ now()->format('F j, Y g:i A') }} by {{ auth()->user()->full_name }}.
                </p>
            </div>
        </div>
    </header>

    @if($monitoring)
    <section class="card mb-4" aria-labelledby="lrMonitoring">
        <div class="card-body">
            <h2 id="lrMonitoring" class="section-title mb-1">Current monitoring</h2>
            <x-ui.monitoring-status :status="$monitoring" />
            <p class="text-xs text-muted mt-2">"No Current Concern" means no open concern at this time; it is not a statement that every subject is complete or passed.</p>
        </div>
    </section>
    @endif

    <section class="card mb-4" aria-labelledby="lrResults">
        <div class="card-header"><h2 id="lrResults" class="card-title">Subject results — Term {{ $term }}</h2>
            <p class="card-subtitle">Official = a verified grade. Provisional = computed from recorded scores, not yet verified. Each subject stands on its own.</p></div>
        <table class="tbl">
            <thead><tr><th>Subject</th><th class="tbl-num">Written Work</th><th class="tbl-num">Performance Task</th><th class="tbl-num">Examination</th><th class="tbl-num">Grade</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($subjects as $subject)
                @php $r = $results[$subject->id] ?? null; $c = $r['computed']['components'] ?? []; @endphp
                <tr data-report-subject="{{ $subject->id }}">
                    <td class="font-medium">{{ $subject->name }}</td>
                    @foreach(['written_work', 'performance_task', 'examination'] as $k)
                        <td class="tbl-num">{{ isset($c[$k]) && $c[$k] !== null ? number_format($c[$k], 2) . '%' : '—' }}</td>
                    @endforeach
                    <td class="tbl-num font-semibold">{{ $r && $r['grade'] !== null ? number_format($r['grade'], 2) : '—' }}</td>
                    <td class="{{ ($r['state'] ?? '') === 'official_failed' ? 'text-status-failing font-semibold' : '' }}">{{ $r['label'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="card mb-4" aria-labelledby="lrEvidence">
        <div class="card-header"><h2 id="lrEvidence" class="card-title">Assessment evidence</h2>
            <p class="card-subtitle">Completed = a score was recorded (0 is a recorded score). Missing = marked by the teacher. No Evidence Yet = no score and not marked.</p></div>
        <table class="tbl">
            <thead><tr><th>Subject</th><th>Assessment</th><th>Component</th><th class="tbl-num">Score / HPS</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($items as $item)
                @php $status = $statuses[$item->id][$student->id] ?? 'not_yet_given'; @endphp
                <tr>
                    <td>{{ $subjects->firstWhere('id', $item->subject_id)?->name }}</td>
                    <td>{{ $item->name }}@if($item->is_additional_support) <span class="text-[10px] text-amber-700">(additional support)</span>@endif</td>
                    <td class="text-xs">{{ \App\Services\ClassRecordService::COMPONENTS[$item->component] ?? $item->component }}</td>
                    <td class="tbl-num">{{ $scores->has($item->id) ? \App\Services\ClassRecordService::fmt((float) $scores[$item->id]) : '—' }} / {{ \App\Services\ClassRecordService::fmt((float) $item->max_score) }}</td>
                    <td class="text-xs {{ $status === 'missing' ? 'text-red-700 font-semibold' : '' }}">{{ \App\Services\AssessmentStatusService::label($status) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">No assessments have been recorded for these subjects this term.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <section class="card mb-4" aria-labelledby="lrConcerns">
        <div class="card-header"><h2 id="lrConcerns" class="card-title">Academic concerns</h2></div>
        <div class="card-body text-sm">
            @forelse($concerns as $c)
                <p class="mb-1"><span class="font-medium">{{ $c['label'] }}</span> — {{ $c['evidence'] }}
                    <span class="text-xs text-muted">({{ $c['open'] ? 'open since ' . $c['since']?->format('M j, Y') : 'resolved ' . $c['resolved']?->format('M j, Y') . ($c['reason'] ? ': ' . $c['reason'] : '') }})</span></p>
            @empty
                <p class="text-muted">No academic concern was recorded for these subjects this term.</p>
            @endforelse
        </div>
    </section>

    @if($interventions !== null)
    <section class="card mb-4" aria-labelledby="lrFollowUp">
        <div class="card-header"><h2 id="lrFollowUp" class="card-title">Support and follow-up</h2></div>
        <div class="card-body text-sm">
            @forelse($interventions as $i)
                <p class="mb-1">{{ \App\Models\Intervention::typeLabel($i->recommended_type) }} — {{ $i->subject?->name ?? 'General' }}:
                    <span class="text-muted">{{ \App\Services\LearnerResultsService::followUp($i) }}</span></p>
            @empty
                <p class="text-muted">No intervention was recorded for these subjects this term.</p>
            @endforelse
            <p class="text-xs text-muted mt-2">A delivered activity does not by itself close a concern; a concern closes only when new evidence meets its rule.</p>
        </div>
    </section>
    @endif

    <p class="text-[11px] text-muted">This report is generated from the school's Decision Support System records as of the date above. Provisional figures may change as scores are recorded and verified.</p>
</main>
</body>
</html>
