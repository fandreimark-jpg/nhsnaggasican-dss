@extends('layouts.app')

@section('title', 'Admin Dashboard')
@section('subtitle', 'System & Master Data Overview')

@section('content')

{{-- System/master-data statistics ONLY — no risk levels, no at-risk
     students, no DSS analytics. Those belong exclusively to the Principal
     dashboard (see Principal\DashboardController) so the two never look
     alike or duplicate each other. --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-brand-500">
        <p class="text-xs text-gray-500">Total Users</p>
        <p class="text-2xl font-bold text-brand-700 mt-1">{{ $totalUsers }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-blue-500">
        <p class="text-xs text-gray-500">Total Students</p>
        <p class="text-2xl font-bold text-blue-700 mt-1">{{ $totalStudents }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-teal-500">
        <p class="text-xs text-gray-500">Total Advisers</p>
        <p class="text-2xl font-bold text-teal-700 mt-1">{{ $totalAdvisers }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-purple-500">
        <p class="text-xs text-gray-500">Total Principals</p>
        <p class="text-2xl font-bold text-purple-700 mt-1">{{ $totalPrincipals }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-amber-500">
        <p class="text-xs text-gray-500">Total Sections</p>
        <p class="text-2xl font-bold text-amber-700 mt-1">{{ $totalSections }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-rose-500">
        <p class="text-xs text-gray-500">Total Subjects</p>
        <p class="text-2xl font-bold text-rose-700 mt-1">{{ $totalSubjects }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-indigo-500">
        <p class="text-xs text-gray-500">Total Tracks</p>
        <p class="text-2xl font-bold text-indigo-700 mt-1">{{ $totalTracks }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-cyan-500">
        <p class="text-xs text-gray-500">Total Specializations</p>
        <p class="text-2xl font-bold text-cyan-700 mt-1">{{ $totalSpecializations }}</p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
    <div class="bg-white rounded-lg p-4 shadow-sm">
        <p class="text-xs text-gray-500">Active School Year</p>
        <p class="text-2xl font-bold text-gray-800 mt-1">{{ $activeSchoolYear }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm">
        <p class="text-xs text-gray-500">Active Academic Term</p>
        @if($activeTerm)
            <p class="text-2xl font-bold text-gray-800 mt-1">Term {{ $activeTerm }}</p>
        @else
            <p class="text-lg font-medium text-gray-300 mt-1">No term currently open</p>
        @endif
        <a href="{{ route('admin.academic-terms') }}" class="text-xs text-brand-700 hover:underline">Manage academic terms &rarr;</a>
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
            <i class="bi bi-mortarboard-fill text-lg text-blue-700"></i>
            <p class="text-xs font-medium text-gray-700 mt-1">Students</p>
        </a>
        <a href="{{ route('admin.sections') }}" class="p-3 rounded-lg border hover:bg-gray-50 text-center">
            <i class="bi bi-diagram-3-fill text-lg text-amber-700"></i>
            <p class="text-xs font-medium text-gray-700 mt-1">Sections</p>
        </a>
        <a href="{{ route('admin.subjects') }}" class="p-3 rounded-lg border hover:bg-gray-50 text-center">
            <i class="bi bi-journal-bookmark-fill text-lg text-rose-700"></i>
            <p class="text-xs font-medium text-gray-700 mt-1">Subjects</p>
        </a>
    </div>
</div>

@endsection
