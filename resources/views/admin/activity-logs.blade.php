@extends('layouts.app')
@section('title', 'Activity Logs')
@section('subtitle', 'System activity history')

@section('content')

<div class="card">
    <div class="px-5 py-3 border-b">
        <h3 class="card-title">Recent Activity</h3>
        <p class="text-xs text-gray-500">All system actions recorded</p>
    </div>

    <div class="tbl-scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th scope="col">Date & Time</th>
                <th scope="col">User</th>
                <th scope="col">Role</th>
                <th scope="col">Action</th>
                <th scope="col">Description</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
            @php
                $actionColors = [
                    'login'         => 'bg-brand-100 text-brand-700',
                    'logout'        => 'bg-gray-100 text-gray-600',
                    'encode_grades' => 'bg-green-100 text-green-700',
                    'submit_report' => 'bg-purple-100 text-purple-700',
                    'create_section'=> 'bg-yellow-100 text-yellow-700',
                    'add_student'   => 'bg-teal-100 text-teal-700',
                    'delete_student'=> 'bg-red-100 text-red-700',
                    'create_user'   => 'bg-indigo-100 text-indigo-700',
                ];
                $actionColor = $actionColors[$log->action] ?? 'bg-gray-100 text-gray-600';
            @endphp
            <tr>
                <td class="text-xs text-gray-500">
                    {{ $log->created_at->format('M d, Y h:i A') }}
                </td>
                <td class="font-medium text-ink text-xs">
                    {{ $log->user->name ?? 'Unknown' }}
                </td>
                <td class="text-xs text-gray-500 capitalize">
                    {{ $log->user->role ?? '—' }}
                </td>
                <td>
                    <span class="badge {{ $actionColor }}">
                        {{ str_replace('_', ' ', ucfirst($log->action)) }}
                    </span>
                </td>
                <td class="text-xs text-gray-600">
                    {{ $log->description }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6">
                    <x-empty-state message="No activity logs yet." icon="bi-clock-history"
                        hint="Every create, update, and delete across the system is recorded here as it happens." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    {{-- Pagination --}}
    @if($logs->hasPages())
    <div class="px-5 py-4 border-t border-line flex flex-col items-center gap-2 text-sm text-muted">
        <div class="flex items-center gap-1">
            @if($logs->onFirstPage())
                <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">← Prev</span>
            @else
                <a href="{{ $logs->previousPageUrl() }}"
                   class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">← Prev</a>
            @endif
            <span class="px-3 py-1 rounded-md border border-brand-800 bg-brand-800 text-white font-medium">
                {{ $logs->currentPage() }}
            </span>
            @if($logs->hasMorePages())
                <a href="{{ $logs->nextPageUrl() }}"
                   class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">Next →</a>
            @else
                <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">Next →</span>
            @endif
        </div>
        <span class="text-xs">Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }} of <x-count-label :count="$logs->total()" noun="log" /></span>
    </div>
    @endif
</div>

@endsection