@extends('layouts.app')

@section('title', 'Admin Dashboard')
@section('subtitle', 'System & Master Data Overview')

@section('content')

{{-- "Decision flow, report scoping, and dashboard pass" TASK 4 — the
     Admin dashboard used to be nothing but master-data counts, which
     carry no status and so read as thin/empty. The Admin's actual job
     is master-data INTEGRITY, and these are genuine problems that block
     the academic workflow — see the table in the "make the Admin
     dashboard useful" task. Zero states are shown positively (green,
     "All ... have/has ...") so a healthy system reads as healthy rather
     than as an absence of problems. --}}
<div class="bg-white rounded-lg shadow-sm mb-4">
    <div class="px-5 py-3 border-b">
        <h3 class="font-semibold text-gray-800 text-sm">Data Health</h3>
        <p class="text-xs text-gray-500 mt-0.5">Master-data problems that block the academic workflow — computed fresh on every load.</p>
    </div>
    <div class="divide-y divide-gray-50">
        {{-- Sections without an assigned adviser --}}
        @php $count = $dataHealth['sectionsWithoutAdviser']->count(); @endphp
        <div class="px-5 py-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 min-w-0">
                @if($count > 0)
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $count }}</span> section{{ $count === 1 ? '' : 's' }} with no assigned adviser
                        <span class="block text-xs text-gray-400">No one can encode grades for {{ $count === 1 ? 'it' : 'them' }}.</span>
                    </p>
                @else
                    <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">All sections have an adviser</p>
                @endif
            </div>
            @if($count > 0)
                <a href="{{ route('admin.sections') }}" class="text-xs text-brand-700 hover:underline shrink-0">Review sections →</a>
            @endif
        </div>

        {{-- Learners not assigned to any section --}}
        @php $count = $dataHealth['learnersWithoutSection']->count(); @endphp
        <div class="px-5 py-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 min-w-0">
                @if($count > 0)
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $count }}</span> learner{{ $count === 1 ? '' : 's' }} not assigned to any section
                        <span class="block text-xs text-gray-400">{{ $count === 1 ? 'This learner appears' : 'These learners appear' }} in no roster.</span>
                    </p>
                @else
                    <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">Every learner is assigned to a section</p>
                @endif
            </div>
            @if($count > 0)
                <a href="{{ route('admin.students', ['section_id' => 'none']) }}" class="text-xs text-brand-700 hover:underline shrink-0">Review learners →</a>
            @endif
        </div>

        {{-- Sections with no subjects resolved --}}
        @php $count = $dataHealth['sectionsWithNoSubjects']->count(); @endphp
        <div class="px-5 py-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 min-w-0">
                @if($count > 0)
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $count }}</span> section{{ $count === 1 ? '' : 's' }} with no subjects resolved
                        <span class="block text-xs text-gray-400">
                            {{ $dataHealth['sectionsWithNoSubjects']->pluck('name')->implode(', ') }} — term reports can never complete for {{ $count === 1 ? 'it' : 'them' }}.
                        </span>
                    </p>
                @else
                    <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">Every section resolves at least one subject</p>
                @endif
            </div>
            @if($count > 0)
                <a href="{{ route('admin.sections') }}" class="text-xs text-brand-700 hover:underline shrink-0">Review sections →</a>
            @endif
        </div>

        {{-- Subjects with no assessments in the open term --}}
        @php $count = $dataHealth['subjectsWithNoAssessments']->count(); @endphp
        <div class="px-5 py-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 min-w-0">
                @if(!$dataHealth['openTermForAssessmentCheck'])
                    <span class="w-2 h-2 rounded-full bg-gray-300 shrink-0"></span>
                    <p class="text-sm text-gray-500">No term is currently open — nothing to check yet.</p>
                @elseif($count > 0)
                    <span class="w-2 h-2 rounded-full bg-yellow-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $count }}</span> subject{{ $count === 1 ? '' : 's' }} with no assessments in Term {{ $dataHealth['openTermForAssessmentCheck'] }}
                        <span class="block text-xs text-gray-400">
                            {{ $dataHealth['subjectsWithNoAssessments']->pluck('name')->implode(', ') }} — advisers have not started.
                        </span>
                    </p>
                @else
                    <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">Every subject in use has assessment evidence this term</p>
                @endif
            </div>
        </div>

        {{-- Learners with duplicate LRNs --}}
        @php $count = $dataHealth['duplicateLrns']->count(); @endphp
        <div class="px-5 py-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 min-w-0">
                @if($count > 0)
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $count }}</span> duplicate LRN{{ $count === 1 ? '' : 's' }}
                        <span class="block text-xs text-gray-400">
                            {{ $dataHealth['duplicateLrns']->implode(', ') }} — import and grade records will collide.
                        </span>
                    </p>
                @else
                    <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">No duplicate LRNs found</p>
                @endif
            </div>
        </div>

        {{-- User accounts that have never logged in --}}
        @php $count = $dataHealth['usersNeverLoggedIn']->count(); @endphp
        <div class="px-5 py-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 min-w-0">
                @if($count > 0)
                    <span class="w-2 h-2 rounded-full bg-yellow-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">{{ $count }}</span> account{{ $count === 1 ? '' : 's' }} that {{ $count === 1 ? 'has' : 'have' }} never logged in
                        <span class="block text-xs text-gray-400">
                            {{ $dataHealth['usersNeverLoggedIn']->pluck('name')->implode(', ') }} — onboarding is incomplete.
                        </span>
                    </p>
                @else
                    <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                    <p class="text-sm text-gray-700">Every account has logged in at least once</p>
                @endif
            </div>
            @if($count > 0)
                <a href="{{ route('admin.users') }}" class="text-xs text-brand-700 hover:underline shrink-0">Review users →</a>
            @endif
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    {{-- TASK 4c — the Admin controls academic terms but had no view of
         their effect: active school year/term plus per-section
         encoding/submission progress for the currently open term. --}}
    <div class="bg-white rounded-lg shadow-sm">
        <div class="px-5 py-3 border-b flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-gray-800 text-sm">Open Term</h3>
                <p class="text-xs text-gray-500 mt-0.5">{{ $openTermPanel['schoolYear'] }}</p>
            </div>
            <a href="{{ route('admin.academic-terms') }}" class="text-xs text-brand-700 hover:underline">Manage terms →</a>
        </div>
        @if(!$openTermPanel['openTerm'])
            <x-empty-state message="No term is currently open." hint="Open a term from Academic Terms to allow grade encoding." class="py-6 text-xs" />
        @else
            <p class="px-5 pt-3 text-xs text-gray-500">Term {{ $openTermPanel['openTerm'] }} is open — per-section encoding progress:</p>
            <div class="max-h-64 overflow-auto mt-1">
                <table class="tbl tbl-sticky">
                    <thead>
                        <tr>
                            <th scope="col">Section</th>
                            <th scope="col" class="tbl-num">Encoded</th>
                            <th scope="col" class="text-center">Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($openTermPanel['sections'] as $row)
                        <tr>
                            <td>{{ $row['section']->name }} <span class="text-gray-400">(Grade {{ $row['section']->grade_level }})</span></td>
                            <td class="tbl-num {{ $row['expected'] > 0 && $row['encoded'] < $row['expected'] ? 'text-yellow-600' : 'text-gray-600' }}">
                                {{ $row['encoded'] }} of {{ $row['expected'] }}
                            </td>
                            <td class="text-center">
                                @if($row['submitted'])
                                    <span class="text-green-600"><i class="bi bi-check-circle-fill"></i></span>
                                @else
                                    <span class="text-gray-300"><i class="bi bi-dash-circle"></i></span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- TASK 4d — the activity_logs table exists and is populated;
         nothing surfaced it on the dashboard before this. --}}
    <div class="bg-white rounded-lg shadow-sm">
        <div class="px-5 py-3 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800 text-sm">Recent Activity</h3>
            <a href="{{ route('admin.activity.logs') }}" class="text-xs text-brand-700 hover:underline">View all →</a>
        </div>
        @if($recentActivity->isEmpty())
            <x-empty-state message="No activity recorded yet." class="py-6 text-xs" />
        @else
            <div class="max-h-64 overflow-auto divide-y divide-gray-50">
                @foreach($recentActivity as $log)
                <div class="px-5 py-2 text-xs">
                    <p class="text-gray-700">
                        <span class="font-medium">{{ $log->user->name ?? 'Unknown' }}</span>
                        {{ str_replace('_', ' ', $log->action) }}
                    </p>
                    <p class="text-gray-400">{{ $log->created_at->format('M d, Y h:i A') }} — {{ $log->description }}</p>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- TASK 4e — master-data counts kept, but secondary: one quiet row of
     labelled figures rather than eight large coloured cards. --}}
<div class="bg-white rounded-lg shadow-sm p-4 mb-4">
    <div class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-gray-500">
        <span>Users <span class="font-semibold text-gray-700">{{ $totalUsers }}</span></span>
        <span>Students <span class="font-semibold text-gray-700">{{ $totalStudents }}</span></span>
        <span>Advisers <span class="font-semibold text-gray-700">{{ $totalAdvisers }}</span></span>
        <span>Principals <span class="font-semibold text-gray-700">{{ $totalPrincipals }}</span></span>
        <span>Sections <span class="font-semibold text-gray-700">{{ $totalSections }}</span></span>
        <span>Subjects <span class="font-semibold text-gray-700">{{ $totalSubjects }}</span></span>
        <span>Tracks <span class="font-semibold text-gray-700">{{ $totalTracks }}</span></span>
        <span>Specializations <span class="font-semibold text-gray-700">{{ $totalSpecializations }}</span></span>
    </div>
</div>

{{-- Quick links to master-data management — the Admin dashboard's actual
     job, per CLAUDE.md: system/master-data management, not analysis. --}}
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
