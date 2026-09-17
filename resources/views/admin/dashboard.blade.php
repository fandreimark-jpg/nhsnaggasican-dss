@extends('layouts.app')

@section('title', 'Admin Dashboard')
@section('subtitle', 'System & Master Data Overview')

@section('content')

{{-- WORK ORDER Part 5 — the Admin dashboard answers one question: is the
     master data in a state that lets the academic work proceed? It
     carries no risk level, no at-risk count, no DSS analytic — CLAUDE.md
     reserves those for the Principal. --}}

{{-- Row 1 — eight master-data count cards. A number the Admin cannot
     click through to is a dead end, and this is this role's whole job.
     accent is the cool count-* family, never a status.* token — these
     are card IDENTITY (which master-data type this is), not a DSS
     status. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
    <x-stat-card label="Total Users" :value="$totalUsers" accent="count-1" icon="people" :href="route('admin.users')" />
    <x-stat-card label="Total Students" :value="$totalStudents" accent="count-2" icon="mortarboard" :href="route('admin.students')" />
    <x-stat-card label="Total Advisers" :value="$totalAdvisers" accent="count-3" icon="person-badge" :href="route('admin.users')" />
    <x-stat-card label="Total Principals" :value="$totalPrincipals" accent="count-4" icon="person-workspace" :href="route('admin.users')" />
    <x-stat-card label="Total Sections" :value="$totalSections" accent="count-5" icon="grid" :href="route('admin.sections')" />
    <x-stat-card label="Total Subjects" :value="$totalSubjects" accent="count-6" icon="book" :href="route('admin.subjects')" />
    <x-stat-card label="Total Tracks" :value="$totalTracks" accent="count-7" icon="diagram-3" :href="route('admin.tracks')" />
    <x-stat-card label="Total Specializations" :value="$totalSpecializations" accent="count-8" icon="collection" :href="route('admin.specializations')" />
</div>

{{-- Row 2 — context: what school year/term the counts above and the
     rest of the system are currently operating in. With no term open,
     the value reads "No term open", never a blank. --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
    <a href="{{ route('admin.academic-terms') }}" class="card card-hover p-5 flex items-center gap-4 border-l-4 border-l-brand-700">
        <div class="icon-box icon-box-brand" aria-hidden="true"><i class="bi bi-calendar3"></i></div>
        <div class="min-w-0 flex-1">
            <p class="stat-label">Active School Year</p>
            <p class="stat-value">{{ $activeSchoolYear }}</p>
            <p class="stat-note">Manage academic terms →</p>
        </div>
        <span class="badge badge-success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Active</span>
    </a>
    <a href="{{ route('admin.academic-terms') }}" class="card card-hover p-5 flex items-center gap-4 border-l-4 {{ $activeTerm ? 'border-l-success' : 'border-l-gray-300' }}">
        <div class="icon-box {{ $activeTerm ? 'icon-box-success' : 'icon-box-slate' }}" aria-hidden="true"><i class="bi {{ $activeTerm ? 'bi-unlock' : 'bi-lock' }}"></i></div>
        <div class="min-w-0 flex-1">
            <p class="stat-label">Active Academic Term</p>
            <p class="stat-value">{{ $activeTerm ? 'Term '.$activeTerm : 'No term open' }}</p>
            <p class="stat-note">Manage academic terms →</p>
        </div>
        @if($activeTerm)<span class="badge badge-success">Open</span>@else<span class="badge badge-gray">Closed</span>@endif
    </a>
</div>

{{-- Row 3 — Data Health, collapsed. Keep the checks, lose the wall of
     text. This summary line is the ONE place on this dashboard where a
     status colour is correct, because it genuinely is a status: whether
     master data is in a state that blocks the academic workflow. When
     something fails, the panel opens by default and failing checks sort
     to the top — a health check nobody expands is a health check nobody
     reads. --}}
@php
    $c1 = $dataHealth['sectionsWithoutAdviser']->count();
    $c2 = $dataHealth['learnersWithoutSection']->count();
    $c3 = $dataHealth['sectionsWithNoSubjects']->count();
    $c4 = $dataHealth['subjectsWithNoAssessments']->count();
    $c5 = $dataHealth['duplicateLrns']->count();
    $c6 = $dataHealth['usersNeverLoggedIn']->count();
    $c7 = $dataHealth['subjectsWithSuspectGroup']->count();
    $ok1 = $c1 === 0;
    $ok2 = $c2 === 0;
    $ok3 = $c3 === 0;
    $ok4 = !$dataHealth['openTermForAssessmentCheck'] || $c4 === 0;
    $ok5 = $c5 === 0;
    $ok6 = $c6 === 0;
    $ok7 = $c7 === 0;
    $passingCount = collect([$ok1, $ok2, $ok3, $ok4, $ok5, $ok6, $ok7])->filter()->count();
    $totalChecks = 7;
    $allPassing = $passingCount === $totalChecks;
    $healthPct = (int) round($passingCount / $totalChecks * 100);

    // "UI modernization pass" — the same seven checks, same wording, same
    // links, grouped by severity. Red (critical) blocks the academic
    // workflow; yellow (attention) is worth a look; green is healthy.
    // Each entry: [ok?, severity, main text (HTML-safe pieces), detail, link, link label]
    $critical = [];
    $attention = [];
    $healthy = [];

    if (!$ok1) $critical[] = ['count' => $c1, 'text' => 'section' . ($c1 === 1 ? '' : 's') . ' with no assigned adviser', 'detail' => 'No one can encode grades for ' . ($c1 === 1 ? 'it' : 'them') . '.', 'href' => route('admin.sections'), 'link' => 'Review sections', 'icon' => 'bi-person-x'];
    else $healthy[] = ['text' => 'All sections have an adviser', 'icon' => 'bi-person-check'];

    if (!$ok2) $critical[] = ['count' => $c2, 'text' => 'learner' . ($c2 === 1 ? '' : 's') . ' not assigned to any section', 'detail' => ($c2 === 1 ? 'This learner appears' : 'These learners appear') . ' in no roster.', 'href' => route('admin.students', ['section_id' => 'none']), 'link' => 'Review learners', 'icon' => 'bi-mortarboard'];
    else $healthy[] = ['text' => 'Every learner is assigned to a section', 'icon' => 'bi-mortarboard'];

    if (!$ok3) $critical[] = ['count' => $c3, 'text' => 'section' . ($c3 === 1 ? '' : 's') . ' with no subjects resolved', 'detail' => $dataHealth['sectionsWithNoSubjects']->pluck('name')->implode(', ') . ' — term reports can never complete for ' . ($c3 === 1 ? 'it' : 'them') . '.', 'href' => route('admin.sections'), 'link' => 'Review sections', 'icon' => 'bi-grid'];
    else $healthy[] = ['text' => 'Every section resolves at least one subject', 'icon' => 'bi-grid'];

    if (!$ok4) $attention[] = ['count' => $c4, 'text' => 'subject' . ($c4 === 1 ? '' : 's') . ' with no assessments in Term ' . $dataHealth['openTermForAssessmentCheck'], 'detail' => $dataHealth['subjectsWithNoAssessments']->pluck('name')->implode(', ') . ' — advisers have not started.', 'href' => null, 'link' => null, 'icon' => 'bi-clipboard-data'];
    elseif (!$dataHealth['openTermForAssessmentCheck']) $healthy[] = ['text' => 'No term is currently open — nothing to check yet.', 'icon' => 'bi-lock', 'neutral' => true];
    else $healthy[] = ['text' => 'Every subject in use has assessment evidence this term', 'icon' => 'bi-clipboard-data'];

    if (!$ok5) $critical[] = ['count' => $c5, 'text' => 'duplicate LRN' . ($c5 === 1 ? '' : 's'), 'detail' => $dataHealth['duplicateLrns']->implode(', ') . ' — import and grade records will collide.', 'href' => null, 'link' => null, 'icon' => 'bi-fingerprint'];
    else $healthy[] = ['text' => 'No duplicate LRNs found', 'icon' => 'bi-fingerprint'];

    if (!$ok6) $attention[] = ['count' => $c6, 'text' => 'account' . ($c6 === 1 ? '' : 's') . ' that ' . ($c6 === 1 ? 'has' : 'have') . ' never logged in', 'detail' => $dataHealth['usersNeverLoggedIn']->pluck('name')->implode(', ') . ' — onboarding is incomplete.', 'href' => route('admin.users'), 'link' => 'Review users', 'icon' => 'bi-person'];
    else $healthy[] = ['text' => 'Every account has logged in at least once', 'icon' => 'bi-person-check'];

    if (!$ok7) $attention[] = ['count' => $c7, 'text' => 'subject' . ($c7 === 1 ? '' : 's') . ' may have the wrong grading weight', 'detail' => $dataHealth['subjectsWithSuspectGroup']->pluck('name')->implode(', ') . ' — an elective still on the Core Academic group (no longer possible from the Admin form or an import, but may remain from data created before that rule), or linked to a DepEd catalog row implying different weights than stored.', 'href' => route('admin.subjects', ['subject_group_check' => 1]), 'link' => 'Review subjects', 'icon' => 'bi-percent'];
    else $healthy[] = ['text' => "Every subject's stored grading weight matches its group or catalog link", 'icon' => 'bi-percent'];
@endphp
<div class="card mb-4">
    <div class="card-header">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="card-title">Data Health</h3>
                <span class="badge {{ $allPassing ? 'badge-success' : (count($critical) ? 'badge-danger' : 'badge-warning') }}">
                    {{ $passingCount }} of {{ $totalChecks }} checks passing
                </span>
            </div>
            <p class="card-subtitle">Yellow is worth reviewing but not blocking. Red blocks the academic workflow.</p>
            <div class="mt-3 flex items-center gap-3">
                <x-ui.progress-bar :value="$healthPct" :tone="$allPassing ? 'success' : (count($critical) ? 'danger' : 'warning')" class="max-w-xs" label="Data health checks passing" />
                <span class="text-xs font-medium text-muted tabular-nums">{{ $healthPct }}%</span>
            </div>
        </div>
        <div class="flex gap-4 text-center shrink-0">
            <div><p class="text-lg font-bold text-danger-text tabular-nums">{{ count($critical) }}</p><p class="text-[11px] uppercase tracking-wide text-muted">Critical</p></div>
            <div><p class="text-lg font-bold text-warning-text tabular-nums">{{ count($attention) }}</p><p class="text-[11px] uppercase tracking-wide text-muted">Attention</p></div>
            <div><p class="text-lg font-bold text-success-text tabular-nums">{{ count($healthy) }}</p><p class="text-[11px] uppercase tracking-wide text-muted">Healthy</p></div>
        </div>
    </div>

    <details @if(!$allPassing) open @endif class="group">
        <summary class="cursor-pointer select-none px-5 py-3 text-xs font-medium text-brand-700 hover:bg-surface flex items-center gap-2">
            <i class="bi bi-chevron-right transition-transform group-open:rotate-90" aria-hidden="true"></i>
            {{ $allPassing ? 'Show checks' : 'Show checks — failing checks listed first' }}
        </summary>

        @foreach([['Critical', $critical, 'danger', 'bi-exclamation-octagon-fill'], ['Attention', $attention, 'warning', 'bi-exclamation-triangle-fill'], ['Healthy', $healthy, 'success', 'bi-check-circle-fill']] as [$groupLabel, $items, $tone, $groupIcon])
            @if(count($items))
            <div class="border-t border-line">
                <p class="px-5 pt-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-{{ $tone }}-text flex items-center gap-1.5">
                    <i class="bi {{ $groupIcon }}" aria-hidden="true"></i> {{ $groupLabel }} ({{ count($items) }})
                </p>
                <div class="divide-y divide-line">
                    @foreach($items as $item)
                    <div class="px-5 py-2.5 flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="icon-box icon-box-sm {{ ($item['neutral'] ?? false) ? 'icon-box-slate' : 'icon-box-' . $tone }}" aria-hidden="true"><i class="bi {{ $item['icon'] }}"></i></div>
                            <p class="text-sm {{ ($item['neutral'] ?? false) ? 'text-muted' : 'text-gray-700' }} leading-snug">
                                @isset($item['count'])<span class="font-semibold">{{ $item['count'] }}</span> @endisset{{ $item['text'] }}
                                @if(!empty($item['detail']))<span class="block text-xs text-muted mt-0.5">{{ $item['detail'] }}</span>@endif
                            </p>
                        </div>
                        @if(!empty($item['href']))
                            <a href="{{ $item['href'] }}" class="btn btn-outline btn-xs shrink-0">{{ $item['link'] }} <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        @endforeach
    </details>
</div>

{{-- Row 4 — Quick Actions + Recent Activity. Every link targets a route
     that already exists; the modals they open are the pages' own. --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
    <x-ui.section-card title="Quick Actions" subtitle="Common master-data tasks" icon="bi-lightning-charge">
        <div class="grid grid-cols-2 gap-2">
            <a href="{{ route('admin.users') }}" class="btn btn-outline justify-start"><i class="bi bi-person-plus text-brand-700" aria-hidden="true"></i> Add User</a>
            <a href="{{ route('admin.students') }}" class="btn btn-outline justify-start"><i class="bi bi-mortarboard text-brand-700" aria-hidden="true"></i> Add Student</a>
            <a href="{{ route('admin.subjects') }}" class="btn btn-outline justify-start"><i class="bi bi-file-earmark-arrow-up text-brand-700" aria-hidden="true"></i> Import Subjects</a>
            <a href="{{ route('admin.sections') }}" class="btn btn-outline justify-start"><i class="bi bi-grid text-brand-700" aria-hidden="true"></i> Manage Sections</a>
            <a href="{{ route('admin.academic-terms') }}" class="btn btn-outline justify-start col-span-2"><i class="bi bi-calendar-check text-brand-700" aria-hidden="true"></i> Manage Academic Terms</a>
        </div>
    </x-ui.section-card>

    <x-panel title="Recent Activity" subtitle="The last five recorded actions" class="lg:col-span-2" :padded="false">
        <x-slot:action>
            <a href="{{ route('admin.activity.logs') }}" class="btn-link text-xs">View all →</a>
        </x-slot:action>
        @if($recentActivity->isEmpty())
            <x-empty-state message="No activity recorded yet." hint="Every create, update, and delete across the system is recorded here as it happens." icon="bi bi-clock-history" class="py-6" />
        @else
            <div class="divide-y divide-line">
                @foreach($recentActivity as $log)
                <div class="px-5 py-2.5 flex items-start gap-3 text-xs">
                    <div class="icon-box icon-box-sm icon-box-slate" aria-hidden="true"><i class="bi bi-activity"></i></div>
                    <div class="min-w-0">
                        <p class="text-gray-700 text-sm">
                            <span class="font-medium text-ink">{{ $log->user->name ?? 'Unknown' }}</span>
                            <span class="text-muted">{{ str_replace('_', ' ', $log->action) }}</span>
                        </p>
                        <p class="text-muted mt-0.5">{{ $log->created_at->format('M d, Y h:i A') }} — {{ $log->description }}</p>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </x-panel>
</div>

@endsection
