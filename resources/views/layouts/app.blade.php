<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <title>Naggasican NHS DSS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 font-sans">

    {{-- Hamburger button — mobile only (hidden on desktop via md:hidden).
         Fixed at top-left so it's always reachable even while scrolling. --}}
    <button type="button" onclick="toggleSidebar()" aria-label="Open menu"
            class="md:hidden fixed top-4 left-4 z-50 bg-brand-900 text-white p-2.5 rounded-lg shadow-lg">
        <i class="bi bi-list text-xl"></i>
    </button>

    {{-- Dark overlay behind the sidebar on mobile — tapping it closes
         the menu, same pattern as clicking outside any other modal. --}}
    <div id="sidebarOverlay" onclick="closeSidebar()"
         class="hidden md:hidden fixed inset-0 bg-black/50 z-30"></div>

    <div class="flex min-h-screen">
        <aside id="sidebar"
               class="w-64 bg-brand-900 text-white flex flex-col fixed md:static inset-y-0 left-0 z-40 -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out">
            <div class="p-6 border-b border-brand-700 flex items-start justify-between">
                <div>
                    <h1 class="text-lg font-bold leading-tight">Naggasican NHS</h1>
                    <p class="text-xs text-brand-300 mt-1">Decision Support System</p>
                </div>
                {{-- Close button — mobile only, lets users dismiss the
                     drawer without hunting for the overlay or hamburger. --}}
                <button type="button" onclick="closeSidebar()" aria-label="Close menu" class="md:hidden text-brand-300 hover:text-white">
                    <i class="bi bi-x-lg text-lg"></i>
                </button>
            </div>

            <nav class="flex-1 p-4 space-y-1">
                @if(auth()->user()->role === 'admin')
                    <a href="{{ route('admin.dashboard') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.dashboard') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a href="{{ route('admin.users') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.users*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-people"></i> Users
                    </a>
                    <a href="{{ route('admin.tracks') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.tracks*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-diagram-3"></i> Tracks
                    </a>
                    <a href="{{ route('admin.specializations') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.specializations*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-collection"></i> Specializations
                    </a>
                    <a href="{{ route('admin.subjects') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.subjects*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-book"></i> Subjects
                    </a>
                    <a href="{{ route('admin.sections') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.sections*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-grid"></i> Sections
                    </a>
                    <a href="{{ route('admin.students') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.students*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-mortarboard"></i> Students
                    </a>
                    <a href="{{ route('admin.academic-terms') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.academic-terms*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-calendar-check"></i> Academic Terms
                    </a>
                    <a href="{{ route('admin.reports') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.reports*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-file-earmark-text"></i> Reports
                    </a>
                    <a href="{{ route('admin.activity.logs') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('admin.activity*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-clock-history"></i> Activity Logs
                    </a>
                @elseif(auth()->user()->role === 'principal')
                    <a href="{{ route('principal.dashboard') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('principal.dashboard') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a href="{{ route('principal.students') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('principal.students*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-mortarboard"></i> Students
                    </a>
                    <a href="{{ route('principal.interventions') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('principal.interventions*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-clipboard2-pulse"></i> Interventions
                    </a>
                    <a href="{{ route('principal.reports') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('principal.reports*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-file-earmark-text"></i> Reports
                    </a>
                    <a href="{{ route('principal.subject-analysis') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('principal.subject-analysis*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-bar-chart"></i> Subject Analysis
                    </a>
                @else
                    <a href="{{ route('adviser.dashboard') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('adviser.dashboard') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a href="{{ route('adviser.students') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('adviser.students*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-mortarboard"></i> My Students
                    </a>
                    <a href="{{ route('adviser.grades') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('adviser.grades*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-pencil-square"></i> Encode Grades
                    </a>
                    <a href="{{ route('adviser.assessments') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('adviser.assessments*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-clipboard-data"></i> Assessments
                    </a>
                    <a href="{{ route('adviser.interventions') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('adviser.interventions*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-clipboard2-pulse"></i> Interventions
                    </a>
                    <a href="{{ route('adviser.submit.report') }}"
                    class="flex items-center gap-3 px-4 py-2 rounded-lg border-l-4 hover:bg-brand-700 {{ request()->routeIs('adviser.submit*') ? 'bg-brand-700 border-gold-500' : 'border-transparent' }}">
                        <i class="bi bi-file-earmark-arrow-up"></i> Submit Report
                    </a>
                @endif
            </nav>

            <div class="p-4 border-t border-brand-700">
                {{-- Icon button next to the user's name — opens the Profile
                     modal (defined in resources/views/profile/_modal.blade.php)
                     instead of navigating to a separate page. Visible to BOTH
                     advisers and admins; each only ever edits their own account. --}}
                <div class="flex items-center gap-2">
                    <button type="button" onclick="openProfileModal()"
                            title="My Profile" aria-label="My Profile"
                            class="text-brand-200 hover:text-white text-lg leading-none">
                        <i class="bi bi-person-circle"></i>
                    </button>
                    <div>
                        <p class="text-sm font-medium">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-brand-300 capitalize">{{ auth()->user()->role }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="w-full text-left text-xs text-brand-300 hover:text-white">
                        Logout →
                    </button>
                </form>
            </div>
        </aside>

        {{-- Profile modal — included once here so it's available on
             every authenticated page, not just a dedicated route. --}}
        @include('profile._modal')

        <main class="flex-1 p-6 overflow-y-auto">
            <div class="max-w-6xl mx-auto">
                <div class="mb-4">
                    <h2 class="text-xl font-bold text-gray-800">@yield('title')</h2>
                    <p class="text-sm text-gray-500">@yield('subtitle')</p>
                </div>
                @yield('content')
            </div>
        </main>
    </div>

    {{-- ===================== TOAST NOTIFICATION ===================== --}}
    @if(session('success') || session('error') || session('warning'))
    <div id="flashToast"
         class="fixed top-6 right-6 z-[9999] flex items-start gap-3 px-5 py-4 rounded-xl shadow-2xl border w-80
                opacity-0 translate-y-3 transition-all duration-500
                {{ session('success') ? 'bg-white border-green-300' : '' }}
                {{ session('error')   ? 'bg-white border-red-300'   : '' }}
                {{ session('warning') ? 'bg-white border-yellow-300': '' }}">
        <div class="absolute left-0 top-0 bottom-0 w-1 rounded-l-xl
            {{ session('success') ? 'bg-green-500' : '' }}
            {{ session('error')   ? 'bg-red-500'   : '' }}
            {{ session('warning') ? 'bg-yellow-400': '' }}">
        </div>
        <div class="ml-2 mt-0.5 text-lg shrink-0
            {{ session('success') ? 'text-green-500' : '' }}
            {{ session('error')   ? 'text-red-500'   : '' }}
            {{ session('warning') ? 'text-yellow-500': '' }}">
            @if(session('success')) <i class="bi bi-check-circle-fill"></i> @endif
            @if(session('error'))   <i class="bi bi-x-circle-fill"></i>     @endif
            @if(session('warning')) <i class="bi bi-exclamation-circle-fill"></i> @endif
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800">
                @if(session('success')) Success @endif
                @if(session('error'))   Error   @endif
                @if(session('warning')) Warning @endif
            </p>
            <p class="text-xs text-gray-500 mt-0.5 leading-relaxed">
                {{ session('success') ?? session('error') ?? session('warning') }}
            </p>
        </div>
        <button onclick="dismissToast()" aria-label="Dismiss notification" class="shrink-0 text-gray-300 hover:text-gray-500 text-lg leading-none mt-0.5">
            <i class="bi bi-x"></i>
        </button>
        <div id="toastProgress"
             class="absolute bottom-0 left-0 right-0 h-0.5 rounded-b-xl
                {{ session('success') ? 'bg-green-400' : '' }}
                {{ session('error')   ? 'bg-red-400'   : '' }}
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

    {{-- ===================== CONFIRM DELETE MODAL ================== --}}
    <div id="confirmDeleteModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9998] transition-opacity duration-200 opacity-0">
        <div class="modal-box bg-white rounded-xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform transition-all duration-200 scale-95 opacity-0" id="confirmDeleteBox">
            <div class="h-1.5 bg-red-500 w-full"></div>
            <div class="p-6">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <i class="bi bi-trash text-red-600 text-lg"></i>
                    </div>
                    <h3 class="text-base font-semibold text-gray-800">Confirm Delete</h3>
                </div>
                <p id="confirmDeleteMessage" class="text-sm text-gray-600 mb-1"></p>
                <p class="text-xs text-red-400 mb-5">
                    <i class="bi bi-exclamation-triangle-fill"></i> This action cannot be undone.
                </p>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeConfirmDelete()"
                            class="px-4 py-2 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        <i class="bi bi-x-lg mr-1"></i> Cancel
                    </button>
                    <button type="button" id="confirmDeleteBtn" onclick="proceedDelete()"
                            class="px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors">
                        <i class="bi bi-trash mr-1"></i> Yes, Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
    {{-- ============================================================= --}}

    {{-- ===================== CONFIRM RESUBMIT MODAL ================ --}}
    <div id="confirmResubmitModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-[9998] transition-opacity duration-200 opacity-0">
        <div class="modal-box bg-white rounded-xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform transition-all duration-200 scale-95 opacity-0" id="confirmResubmitBox">
            <div class="h-1.5 bg-yellow-400 w-full"></div>
            <div class="p-6">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-full bg-yellow-100 flex items-center justify-center shrink-0">
                        <i class="bi bi-arrow-repeat text-yellow-600 text-lg"></i>
                    </div>
                    <h3 class="text-base font-semibold text-gray-800">Confirm Re-submit</h3>
                </div>
                <p id="confirmResubmitMessage" class="text-sm text-gray-600 mb-1"></p>
                <p class="text-xs text-yellow-600 mb-5">
                    <i class="bi bi-exclamation-triangle-fill"></i> Risk classification will be updated.
                </p>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeConfirmResubmit()"
                            class="px-4 py-2 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        <i class="bi bi-x-lg mr-1"></i> Cancel
                    </button>
                    <button type="button" id="confirmResubmitBtn" onclick="proceedResubmit()"
                            class="px-4 py-2 text-sm font-medium text-white bg-yellow-500 hover:bg-yellow-600 rounded-lg transition-colors">
                        <i class="bi bi-arrow-repeat mr-1"></i> Yes, Re-submit
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