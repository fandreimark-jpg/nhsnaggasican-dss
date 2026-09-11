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
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
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
    <x-stat-card label="Active School Year" :value="$activeSchoolYear" accent="count-8" icon="calendar3"
        :href="route('admin.academic-terms')" note="Manage academic terms →" />
    <x-stat-card label="Active Academic Term" :value="$activeTerm ? 'Term '.$activeTerm : 'No term open'" accent="count-8" icon="calendar-check"
        :href="route('admin.academic-terms')" note="Manage academic terms →" />
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
@endphp
<x-panel class="mb-4"
    subtitle="Yellow is worth reviewing but not blocking. Red blocks the academic workflow.">
    <x-slot:title>
        Data Health
        <span class="ml-1 font-normal {{ $allPassing ? 'text-status-ontrack' : 'text-status-risk' }}">— {{ $passingCount }} of {{ $totalChecks }} checks passing</span>
    </x-slot:title>
    <details @if(!$allPassing) open @endif>
        <summary class="cursor-pointer text-xs text-brand-700 select-none">{{ $allPassing ? 'Show checks' : 'Show checks — failing checks listed first' }}</summary>
        <div class="divide-y divide-gray-50 mt-2 -mx-4">
            {{-- Failing checks first --}}
            @if(!$ok1)
            <div class="px-4 py-2 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $c1 }}</span> section{{ $c1 === 1 ? '' : 's' }} with no assigned adviser
                        <span class="block text-xs text-gray-400">No one can encode grades for {{ $c1 === 1 ? 'it' : 'them' }}.</span>
                    </p>
                </div>
                <a href="{{ route('admin.sections') }}" class="text-xs text-brand-700 hover:underline shrink-0">Review sections →</a>
            </div>
            @endif
            @if(!$ok2)
            <div class="px-4 py-2 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $c2 }}</span> learner{{ $c2 === 1 ? '' : 's' }} not assigned to any section
                        <span class="block text-xs text-gray-400">{{ $c2 === 1 ? 'This learner appears' : 'These learners appear' }} in no roster.</span>
                    </p>
                </div>
                <a href="{{ route('admin.students', ['section_id' => 'none']) }}" class="text-xs text-brand-700 hover:underline shrink-0">Review learners →</a>
            </div>
            @endif
            @if(!$ok3)
            <div class="px-4 py-2 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $c3 }}</span> section{{ $c3 === 1 ? '' : 's' }} with no subjects resolved
                        <span class="block text-xs text-gray-400">
                            {{ $dataHealth['sectionsWithNoSubjects']->pluck('name')->implode(', ') }} — term reports can never complete for {{ $c3 === 1 ? 'it' : 'them' }}.
                        </span>
                    </p>
                </div>
                <a href="{{ route('admin.sections') }}" class="text-xs text-brand-700 hover:underline shrink-0">Review sections →</a>
            </div>
            @endif
            @if(!$ok4)
            <div class="px-4 py-2 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-yellow-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $c4 }}</span> subject{{ $c4 === 1 ? '' : 's' }} with no assessments in Term {{ $dataHealth['openTermForAssessmentCheck'] }}
                        <span class="block text-xs text-gray-400">
                            {{ $dataHealth['subjectsWithNoAssessments']->pluck('name')->implode(', ') }} — advisers have not started.
                        </span>
                    </p>
                </div>
            </div>
            @endif
            @if(!$ok5)
            <div class="px-4 py-2 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $c5 }}</span> duplicate LRN{{ $c5 === 1 ? '' : 's' }}
                        <span class="block text-xs text-gray-400">
                            {{ $dataHealth['duplicateLrns']->implode(', ') }} — import and grade records will collide.
                        </span>
                    </p>
                </div>
            </div>
            @endif
            @if(!$ok6)
            <div class="px-4 py-2 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-yellow-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $c6 }}</span> account{{ $c6 === 1 ? '' : 's' }} that {{ $c6 === 1 ? 'has' : 'have' }} never logged in
                        <span class="block text-xs text-gray-400">
                            {{ $dataHealth['usersNeverLoggedIn']->pluck('name')->implode(', ') }} — onboarding is incomplete.
                        </span>
                    </p>
                </div>
                <a href="{{ route('admin.users') }}" class="text-xs text-brand-700 hover:underline shrink-0">Review users →</a>
            </div>
            @endif
            @if(!$ok7)
            <div class="px-4 py-2 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-yellow-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $c7 }}</span> subject{{ $c7 === 1 ? '' : 's' }} may have the wrong grading weight
                        <span class="block text-xs text-gray-400">
                            {{ $dataHealth['subjectsWithSuspectGroup']->pluck('name')->implode(', ') }} — an elective still on the Core Academic default, or linked to a DepEd catalog row implying different weights than stored. Across the full 141-subject catalog, 101 of 139 subjects get the wrong weights under this silent default.
                        </span>
                    </p>
                </div>
                <a href="{{ route('admin.subjects', ['subject_group_check' => 1]) }}" class="text-xs text-brand-700 hover:underline shrink-0">Review subjects →</a>
            </div>
            @endif

            {{-- Passing checks after --}}
            @if($ok1)
            <div class="px-4 py-2 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                <p class="text-sm text-gray-700">All sections have an adviser</p>
            </div>
            @endif
            @if($ok2)
            <div class="px-4 py-2 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                <p class="text-sm text-gray-700">Every learner is assigned to a section</p>
            </div>
            @endif
            @if($ok3)
            <div class="px-4 py-2 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                <p class="text-sm text-gray-700">Every section resolves at least one subject</p>
            </div>
            @endif
            @if($ok4)
            <div class="px-4 py-2 flex items-center gap-2">
                @if(!$dataHealth['openTermForAssessmentCheck'])
                    <span class="w-2 h-2 rounded-full bg-gray-300 shrink-0"></span>
                    <p class="text-sm text-gray-500">No term is currently open — nothing to check yet.</p>
                @else
                    <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">Every subject in use has assessment evidence this term</p>
                @endif
            </div>
            @endif
            @if($ok5)
            <div class="px-4 py-2 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                <p class="text-sm text-gray-700">No duplicate LRNs found</p>
            </div>
            @endif
            @if($ok6)
            <div class="px-4 py-2 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                <p class="text-sm text-gray-700">Every account has logged in at least once</p>
            </div>
            @endif
            @if($ok7)
            <div class="px-4 py-2 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                <p class="text-sm text-gray-700">Every subject's stored grading weight matches its group or catalog link</p>
            </div>
            @endif
        </div>
    </details>
</x-panel>

{{-- Row 4 — Recent Activity. A glance, not the log: five entries. --}}
<x-panel title="Recent Activity" class="mb-4">
    <x-slot:action>
        <a href="{{ route('admin.activity.logs') }}" class="text-brand-700 hover:underline">View all →</a>
    </x-slot:action>
    @if($recentActivity->isEmpty())
        <x-empty-state message="No activity recorded yet." hint="Every create, update, and delete across the system is recorded here as it happens." class="py-6 text-xs" />
    @else
        <div class="divide-y divide-gray-50 -mx-4 -mb-4">
            @foreach($recentActivity as $log)
            <div class="px-4 py-2 text-xs">
                <p class="text-gray-700">
                    <span class="font-medium">{{ $log->user->name ?? 'Unknown' }}</span>
                    {{ str_replace('_', ' ', $log->action) }}
                </p>
                <p class="text-gray-400">{{ $log->created_at->format('M d, Y h:i A') }} — {{ $log->description }}</p>
            </div>
            @endforeach
        </div>
    @endif
</x-panel>

{{-- Row 5 — System Management. Unchanged. --}}
<div class="bg-white rounded-lg shadow-sm p-4">
    <h3 class="font-semibold text-gray-800 text-sm mb-3">System Management</h3>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <a href="{{ route('admin.users') }}" class="p-3 rounded-lg border hover:bg-gray-50 text-center">
            <i class="bi bi-people-fill text-lg text-brand-700"></i>
            <p class="text-xs font-medium text-gray-700 mt-1">Users</p>
        </a>
        <a href="{{ route('admin.students') }}" class="p-3 rounded-lg border hover:bg-gray-50 text-center">
            <i class="bi bi-mortarboard-fill text-lg text-brand-700"></i>
            <p class="text-xs font-medium text-gray-700 mt-1">Students</p>
        </a>
        <a href="{{ route('admin.sections') }}" class="p-3 rounded-lg border hover:bg-gray-50 text-center">
            <i class="bi bi-diagram-3-fill text-lg text-brand-700"></i>
            <p class="text-xs font-medium text-gray-700 mt-1">Sections</p>
        </a>
        <a href="{{ route('admin.subjects') }}" class="p-3 rounded-lg border hover:bg-gray-50 text-center">
            <i class="bi bi-journal-bookmark-fill text-lg text-brand-700"></i>
            <p class="text-xs font-medium text-gray-700 mt-1">Subjects</p>
        </a>
    </div>
</div>

@endsection
