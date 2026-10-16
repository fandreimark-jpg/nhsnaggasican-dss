<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <title>@hasSection('title')@yield('title') — @endif Naggasican NHS DSS</title>
    <x-app-favicon />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface font-sans">

@php
    /*
     * "UI modernization pass" — role-specific navigation is data, grouped
     * the way the roles actually work (OVERVIEW / MANAGEMENT / ACADEMIC /
     * SYSTEM for Admin, and so on). Route names and active-state patterns
     * are unchanged from the previous flat list; only the presentation is.
     * Context pills (school year / open term) read the same resolvers every
     * page already uses — Section::activeSchoolYear() and
     * AcademicTerm::currentOpenTerm() — never a literal.
     */
    $role = auth()->user()->role;
    $navGroups = match ($role) {
        'admin' => [
            'Overview' => [
                ['admin.dashboard', 'admin.dashboard', 'bi-speedometer2', 'Dashboard'],
            ],
            'Management' => [
                ['admin.users', 'admin.users*', 'bi-people', 'Users'],
                ['admin.tracks', 'admin.tracks*', 'bi-diagram-3', 'Tracks'],
                ['admin.specializations', 'admin.specializations*', 'bi-collection', 'Specializations'],
                ['admin.subjects', 'admin.subjects*', 'bi-book', 'Subjects'],
                ['admin.sections', 'admin.sections*', 'bi-grid', 'Sections'],
                ['admin.students', 'admin.students*', 'bi-mortarboard', 'Students'],
                ['admin.teaching-assignments', 'admin.teaching-assignments*', 'bi-person-workspace', 'Teaching Assignments'],
            ],
            'Academic' => [
                ['admin.academic-terms', 'admin.academic-terms*', 'bi-calendar-check', 'Academic Terms'],
                ['admin.reports', 'admin.reports*', 'bi-file-earmark-text', 'Reports'],
            ],
            'System' => [
                ['admin.activity.logs', 'admin.activity*', 'bi-clock-history', 'Activity Logs'],
            ],
        ],
        'principal' => [
            'Overview' => [
                ['principal.dashboard', 'principal.dashboard', 'bi-speedometer2', 'Dashboard'],
            ],
            'Monitoring' => [
                ['principal.academic-monitoring', 'principal.academic-monitoring*', 'bi-flag', 'Academic Monitoring'],
                ['principal.students', 'principal.students*', 'bi-mortarboard', 'Students'],
                ['principal.reports', 'principal.reports*', 'bi-file-earmark-text', 'Reports'],
                ['principal.subject-analysis', 'principal.subject-analysis*', 'bi-bar-chart', 'Subject Analysis'],
            ],
            'Decision Support' => [
                ['principal.interventions', 'principal.interventions*', 'bi-clipboard2-pulse', 'Interventions'],
            ],
        ],
        'subject_teacher' => [
            'Teaching' => [
                ['teacher.classes', 'teacher.classes', 'bi-journal-bookmark', 'My Classes'],
                ['teacher.class-record', 'teacher.class-record*', 'bi-table', 'Class Record'],
            ],
        ],
        default => [
            'Overview' => [
                ['adviser.dashboard', 'adviser.dashboard', 'bi-speedometer2', 'Dashboard'],
            ],
            'Learners' => [
                ['adviser.students', 'adviser.students*', 'bi-mortarboard', 'My Students'],
                ['adviser.advisory-overview', 'adviser.advisory-overview*', 'bi-people', 'Advisory Overview'],
            ],
            'Academic' => [
                ['teacher.classes', 'teacher.classes', 'bi-journal-bookmark', 'My Classes'],
                ['teacher.class-record', 'teacher.class-record*', 'bi-table', 'Class Record'],
                // Two pages, two exact patterns: never both active at once.
                ['adviser.grades', 'adviser.grades', 'bi-pencil-square', 'Encode Grades'],
                ['adviser.verify-grades', 'adviser.verify-grades', 'bi-patch-check', 'Verify Grades'],
                ['adviser.submit.report', 'adviser.submit*', 'bi-file-earmark-arrow-up', 'Submit Report'],
            ],
            'Support' => [
                ['adviser.interventions', 'adviser.interventions*', 'bi-clipboard2-pulse', 'Interventions'],
            ],
        ],
    };

    $headerSchoolYear = \App\Models\Section::activeSchoolYear();
    $headerOpenTerm   = \App\Models\AcademicTerm::currentOpenTerm($headerSchoolYear);
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $firstName = auth()->user()->first_name ?: \Illuminate\Support\Str::of(auth()->user()->name)->before(' ');
    $roleLabel = ['admin' => 'Administrator', 'adviser' => 'Adviser', 'subject_teacher' => 'Subject Teacher', 'principal' => 'Principal'][$role] ?? ucfirst($role);
@endphp

    {{-- Dark overlay behind the sidebar on mobile — tapping it closes
         the menu, same pattern as clicking outside any other modal. --}}
    <div id="sidebarOverlay" onclick="closeSidebar()"
         class="hidden md:hidden fixed inset-0 bg-black/50 z-30"></div>

    {{-- Fixed/sticky sidebar layout — mobile keeps its off-canvas drawer
         (`fixed inset-y-0 -translate-x-full`) below `md`; on desktop the
         sidebar is a flex sibling pinned to one viewport height with its
         own scrollbar, and <main> scrolls independently. --}}
    <div class="flex h-screen overflow-hidden">
        <aside id="sidebar" aria-label="Main navigation"
               class="w-64 bg-brand-900 text-white flex flex-col fixed md:static inset-y-0 left-0 z-40 -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out overflow-y-auto md:h-screen md:shrink-0">
            <div class="px-5 pt-5 pb-4 border-b border-white/10 flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    {{-- The seal sits directly on the sidebar green: no box,
                         border, ring or shadow behind it. Sized on the <img>
                         itself so the aspect ratio is the image's own. --}}
                    <img src="{{ asset('images/nagga-logo.png') }}" alt="" class="w-[52px] h-[52px] object-contain shrink-0" onerror="this.remove()">
                    <div class="min-w-0">
                        <h1 class="text-[15px] font-bold leading-tight tracking-tight truncate">Naggasican NHS</h1>
                        <p class="text-[11px] text-brand-200/80 mt-0.5 leading-tight">Decision Support System</p>
                    </div>
                </div>
                {{-- Close button — mobile only. --}}
                <button type="button" onclick="closeSidebar()" aria-label="Close menu" class="md:hidden text-brand-200 hover:text-white">
                    <i class="bi bi-x-lg text-lg" aria-hidden="true"></i>
                </button>
            </div>

            <nav class="flex-1 px-3 pb-4">
                @foreach($navGroups as $groupLabel => $links)
                    <p class="nav-group-label">{{ $groupLabel }}</p>
                    <div class="space-y-0.5">
                        @foreach($links as [$routeName, $activePattern, $icon, $label])
                            @php $isActive = request()->routeIs($activePattern); @endphp
                            <a href="{{ route($routeName) }}"
                               class="nav-link {{ $isActive ? 'nav-link-active' : '' }}"
                               @if($isActive) aria-current="page" @endif>
                                <i class="bi {{ $icon }}" aria-hidden="true"></i>
                                <span>{{ $label }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="px-4 py-4 border-t border-white/10 bg-black/10">
                {{-- Profile button opens the Profile modal (profile/_modal.blade.php). --}}
                <div class="flex items-center gap-3">
                    <button type="button" onclick="openProfileModal()"
                            title="My Profile" aria-label="My Profile"
                            class="w-9 h-9 rounded-full bg-white/15 text-white flex items-center justify-center text-sm font-semibold shrink-0 hover:bg-white/25 transition">
                        {{ strtoupper(substr(auth()->user()->first_name ?: auth()->user()->name, 0, 1)) }}
                    </button>
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate">{{ auth()->user()->name }}</p>
                        <p class="text-[11px] text-brand-200/80">{{ $roleLabel }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="w-full flex items-center gap-2 text-xs text-brand-200 hover:text-white rounded-md px-1 py-1 transition">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Logout
                    </button>
                </form>
            </div>
        </aside>

        {{-- Profile modal — included once here so it's available on
             every authenticated page, not just a dedicated route. --}}
        @include('profile._modal')

        {{-- Content column ("mobile header" fix, 2026-09-28). Below md a
             compact header in normal flow carries the menu trigger, and
             <main> scrolls underneath it; the old fixed floating button
             covered the top-left of scrolled content on every page. --}}
        <div id="appContent" class="flex-1 min-w-0 flex flex-col h-screen">
        <header class="md:hidden shrink-0 flex items-center gap-3 px-4 h-14 bg-brand-900 text-white shadow-sm z-20" data-mobile-header>
            <button type="button" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false"
                    class="inline-flex items-center justify-center w-11 h-11 -ml-2 rounded-lg hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white">
                <i class="bi bi-list text-2xl" aria-hidden="true"></i>
            </button>
            <span class="text-sm font-semibold truncate">Naggasican NHS</span>
            <span class="ml-auto text-[11px] text-brand-200/90 truncate">@yield('title')</span>
        </header>
        {{-- `relative`: <main> must be the containing block of absolutely
             positioned descendants (e.g. .sr-only link text). Without it they
             are placed against the viewport, escape main's overflow clip and
             make the DOCUMENT scroll too: a second scrollbar and blank space
             below the footer (2026-10-07). --}}
        <main id="main" class="relative flex-1 p-6 overflow-y-auto">
            {{-- A wide data grid (the Class Record) opts into the full width with @section('wide_content', '1'). --}}
            <div class="@hasSection('wide_content') max-w-[112rem] @else max-w-6xl @endif mx-auto">
                <div class="page-header">
                    <div class="min-w-0">
                        <p class="page-eyebrow">{{ $greeting }}, {{ $firstName }}</p>
                        <h2 class="page-title">@yield('title')</h2>
                        <p class="page-subtitle">@yield('subtitle')</p>
                    </div>
                    <div class="page-context">
                        <span class="pill"><i class="bi bi-calendar3 text-brand-700" aria-hidden="true"></i><span class="pill-label">School Year</span> {{ $headerSchoolYear }}</span>
                        @if($headerOpenTerm)
                            <span class="pill"><i class="bi bi-unlock text-success" aria-hidden="true"></i><span class="pill-label">Term {{ $headerOpenTerm }}</span> Open</span>
                        @else
                            <span class="pill"><i class="bi bi-lock text-muted" aria-hidden="true"></i> No term open</span>
                        @endif
                    </div>
                </div>
                @if($errors->has('deletion'))
                    <div role="alert" class="alert alert-danger alert-row mb-4"><i class="bi bi-shield-exclamation mt-0.5" aria-hidden="true"></i><span>{{ $errors->first('deletion') }}</span></div>
                @endif
                @yield('content')
                @include('partials.footer')
            </div>
        </main>
        </div>
    </div>

    {{-- ===================== TOAST NOTIFICATION ===================== --}}
    @php $toastError = session('error_inline') ? null : session('error'); @endphp
    @if(session('success') || $toastError || session('warning'))
    <div id="flashToast"
         role="status" aria-live="polite"
         class="fixed top-4 right-4 md:top-6 md:right-6 z-[9999] flex items-start gap-3 px-5 py-4 rounded-xl shadow-modal border w-80 max-w-[calc(100vw-2rem)]
                opacity-0 translate-y-3 transition-all duration-500
                {{ session('success') ? 'bg-white border-green-300' : '' }}
                {{ $toastError   ? 'bg-white border-red-300'   : '' }}
                {{ session('warning') ? 'bg-white border-yellow-300': '' }}">
        <div class="absolute left-0 top-0 bottom-0 w-1 rounded-l-xl
            {{ session('success') ? 'bg-green-500' : '' }}
            {{ $toastError   ? 'bg-red-500'   : '' }}
            {{ session('warning') ? 'bg-yellow-400': '' }}">
        </div>
        <div class="ml-2 mt-0.5 text-lg shrink-0
            {{ session('success') ? 'text-green-500' : '' }}
            {{ $toastError   ? 'text-red-500'   : '' }}
            {{ session('warning') ? 'text-yellow-500': '' }}">
            @if(session('success')) <i class="bi bi-check-circle-fill"></i> @endif
            @if($toastError)   <i class="bi bi-x-circle-fill"></i>     @endif
            @if(session('warning')) <i class="bi bi-exclamation-circle-fill"></i> @endif
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800">
                @if(session('success')) Success @endif
                @if($toastError)   Error   @endif
                @if(session('warning')) Warning @endif
            </p>
            <p class="text-xs text-gray-500 mt-0.5 leading-relaxed">
                {{ session('success') ?? $toastError ?? session('warning') }}
            </p>
        </div>
        <button onclick="dismissToast()" aria-label="Dismiss notification" class="shrink-0 text-gray-300 hover:text-gray-500 text-lg leading-none mt-0.5">
            <i class="bi bi-x"></i>
        </button>
        <div id="toastProgress"
             class="absolute bottom-0 left-0 right-0 h-0.5 rounded-b-xl
                {{ session('success') ? 'bg-green-400' : '' }}
                {{ $toastError   ? 'bg-red-400'   : '' }}
                {{ session('warning') ? 'bg-yellow-400': '' }}"
             style="animation: toastShrink 4s linear forwards;">
        </div>
    </div>
    <style>
        @keyframes toastShrink { from { width: 100%; } to { width: 0%; } }
    </style>
    {{-- Toast auto-dismiss logic now lives in resources/js/confirm.js --}}
    @endif
    {{-- ============================================================= --}}

    <div id="confirmImportModal" role="dialog" aria-modal="true" aria-labelledby="confirmImportTitle" aria-describedby="confirmImportMessage"
         class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9998] transition-opacity duration-200 opacity-0">
        <div class="modal-box bg-white rounded-xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform transition-all duration-200 scale-95 opacity-0">
            <div class="h-1.5 bg-brand-700 w-full"></div>
            <div class="p-6">
                <div class="flex items-center gap-3 mb-3">
                    <div class="icon-box icon-box-brand rounded-full">
                        <i class="bi bi-upload" aria-hidden="true"></i>
                    </div>
                    <h3 id="confirmImportTitle" class="text-base font-semibold text-ink">Confirm Import</h3>
                </div>
                <p id="confirmImportMessage" class="text-sm text-gray-600 mb-5"></p>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeConfirmImport()" class="btn btn-outline">Cancel</button>
                    <button type="button" id="confirmImportBtn" onclick="proceedImport()"
                            class="btn btn-primary bg-brand-700 hover:bg-brand-800 disabled:opacity-50">Yes, Import</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== CONFIRM ACTION MODAL ================== --}}
    {{-- Neutral confirmation for non-destructive actions (activate a
         school year, open/close a term, enroll a learner). Title, button
         label, and icon come from the submitting form's data-action-*
         attributes — see resources/js/confirm.js. Never red: nothing
         confirmed here deletes anything. --}}
    <div id="confirmActionModal" role="dialog" aria-modal="true" aria-labelledby="confirmActionTitle" aria-describedby="confirmActionMessage"
         class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9998] transition-opacity duration-200 opacity-0">
        <div class="modal-box bg-white rounded-xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform transition-all duration-200 scale-95 opacity-0" id="confirmActionBox">
            <div class="h-1.5 bg-brand-700 w-full"></div>
            <div class="p-6">
                <div class="flex items-center gap-3 mb-3">
                    <div class="icon-box icon-box-brand rounded-full">
                        <i id="confirmActionIcon" class="bi bi-check-circle text-brand-700 text-lg" aria-hidden="true"></i>
                    </div>
                    <h3 id="confirmActionTitle" class="text-base font-semibold text-ink">Confirm Action</h3>
                </div>
                <p id="confirmActionMessage" class="text-sm text-gray-600 mb-5"></p>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeConfirmAction()" class="btn btn-outline">
                        <i class="bi bi-x-lg" aria-hidden="true"></i> Cancel
                    </button>
                    <button type="button" id="confirmActionBtn" onclick="proceedAction()" class="btn btn-primary">
                        <i class="bi bi-check-circle mr-1"></i> Yes, Continue
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== CONFIRM DELETE MODAL ================== --}}
    <div id="confirmDeleteModal" role="dialog" aria-modal="true" aria-labelledby="confirmDeleteTitle" aria-describedby="confirmDeleteMessage" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9998] transition-opacity duration-200 opacity-0">
        <div class="modal-box bg-white rounded-xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform transition-all duration-200 scale-95 opacity-0" id="confirmDeleteBox">
            <div class="h-1.5 bg-danger w-full"></div>
            <div class="p-6">
                <div class="flex items-center gap-3 mb-3">
                    <div class="icon-box icon-box-danger rounded-full">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                    </div>
                    <h3 id="confirmDeleteTitle" class="text-base font-semibold text-ink">Confirm Delete</h3>
                </div>
                <p id="confirmDeleteMessage" class="text-sm text-gray-600 mb-1"></p>
                <p class="text-xs text-danger-text mb-5">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> This action cannot be undone.
                </p>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeConfirmDelete()" class="btn btn-outline">
                        <i class="bi bi-x-lg" aria-hidden="true"></i> Cancel
                    </button>
                    <button type="button" id="confirmDeleteBtn" onclick="proceedDelete()"
                            class="btn btn-danger bg-red-600 hover:bg-red-700">
                        <i class="bi bi-trash" aria-hidden="true"></i> Yes, Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
    {{-- ============================================================= --}}

    {{-- ===================== CONFIRM RESUBMIT MODAL ================ --}}
    <div id="confirmResubmitModal" role="dialog" aria-modal="true" aria-labelledby="confirmResubmitTitle" aria-describedby="confirmResubmitMessage" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9998] transition-opacity duration-200 opacity-0">
        <div class="modal-box bg-white rounded-xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform transition-all duration-200 scale-95 opacity-0" id="confirmResubmitBox">
            <div class="h-1.5 bg-warning w-full"></div>
            <div class="p-6">
                <div class="flex items-center gap-3 mb-3">
                    <div class="icon-box icon-box-warning rounded-full">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    </div>
                    <h3 id="confirmResubmitTitle" class="text-base font-semibold text-ink">Confirm Re-submit</h3>
                </div>
                <p id="confirmResubmitMessage" class="text-sm text-gray-600 mb-1"></p>
                <p class="text-xs text-warning-text mb-5">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> A new version is recorded with the current time; earlier versions are kept.
                </p>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeConfirmResubmit()" class="btn btn-outline">
                        <i class="bi bi-x-lg" aria-hidden="true"></i> Cancel
                    </button>
                    <button type="button" id="confirmResubmitBtn" onclick="proceedResubmit()" class="btn btn-warning">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Yes, Re-submit
                    </button>
                </div>
            </div>
        </div>
    </div>
    {{-- ============================================================= --}}

    {{-- Confirm Delete / Confirm Resubmit logic now lives in
         resources/js/confirm.js, reusing showModal()/hideModal()
         from modal.js instead of its own separate animation code. --}}

    @stack('scripts')
</body>
</html>
