@extends('layouts.app')

@section('title', 'Intervention Decisions')
@section('subtitle', 'DSS recommends. You decide.')

@section('content')

@if(session('success'))
<div class="bg-green-100 text-green-700 text-sm p-4 rounded-lg mb-4">{{ session('success') }}</div>
@endif

@php
    $typeLabels = [
        'remediation' => 'Remediation',
        'additional_learning_activity' => 'Additional Learning Activity',
        'additional_performance_task' => 'Additional Performance Task',
        'teacher_monitoring' => 'Teacher Monitoring',
        'attendance_monitoring' => 'Attendance Monitoring',
        'parent_conference' => 'Parent/Guardian Conference',
        'other' => 'Other',
    ];
    $statusLabels = [
        'recommended' => 'Recommended', 'in_review' => 'In Review', 'approved' => 'Approved',
        'in_progress' => 'In Progress', 'completed' => 'Completed', 'monitoring' => 'Monitoring',
    ];
    $statusColors = [
        'recommended' => 'bg-gray-100 text-gray-600', 'in_review' => 'bg-yellow-100 text-yellow-700',
        'approved' => 'bg-blue-100 text-blue-700', 'in_progress' => 'bg-purple-100 text-purple-700',
        'completed' => 'bg-green-100 text-green-700', 'monitoring' => 'bg-orange-100 text-orange-700',
    ];
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
@endphp

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <div class="px-6 py-4 border-b">
        <h3 class="font-semibold text-gray-800 text-sm">At-Risk Students — Recommendations & Decisions</h3>
        <p class="text-xs text-gray-500 mt-1">
            Every recommendation is generated from evidence (risk level, weakest subject/component, trend) — you
            decide whether to act on it, and how.
        </p>
    </div>

    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-6 py-3">Student</th>
                <th class="text-center px-3 py-3">Risk</th>
                <th class="text-left px-4 py-3">DSS Recommendation</th>
                <th class="text-left px-4 py-3">Status / Decision</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($rows as $row)
            <tr class="hover:bg-gray-50 align-top">
                <td class="px-6 py-3 font-medium text-gray-800 whitespace-nowrap">
                    <a href="{{ route('principal.students.show', $row['student_id']) }}" class="hover:underline hover:text-brand-700">
                        {{ $row['name'] }}
                    </a>
                    <span class="block text-xs text-gray-400 font-normal">{{ $row['section'] }}</span>
                </td>
                <td class="px-3 py-3 text-center">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $row['risk_level'] === 'high' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700' }}">
                        {{ ucfirst($row['risk_level']) }}
                    </span>
                </td>
                <td class="px-4 py-3 max-w-xs">
                    <span class="font-medium text-gray-700">{{ $typeLabels[$row['recommendation']['type']] ?? $row['recommendation']['type'] }}</span>
                    <p class="text-xs text-gray-500 mt-1">{{ $row['recommendation']['reason'] }}</p>
                </td>
                <td class="px-4 py-3 min-w-[260px]">
                    @if($row['existing_intervention'])
                        @php $iv = $row['existing_intervention']; @endphp
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $statusColors[$iv->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ $statusLabels[$iv->status] ?? $iv->status }}
                        </span>
                        <span class="block text-xs text-gray-500 mt-1">{{ $typeLabels[$iv->recommended_type] ?? $iv->recommended_type }}</span>

                        @if($row['progress'])
                            @php $p = $row['progress']; @endphp
                            <div class="text-xs text-gray-500 mt-2 bg-gray-50 rounded-md px-2 py-1.5">
                                <span class="font-medium text-gray-600">{{ $componentLabels[$p['component']] ?? $p['component'] }}:</span>
                                Term {{ $p['before_period'] }} was {{ number_format($p['before_percentage'], 1) }}%
                                @if($p['after_percentage'] !== null)
                                    , Term {{ $p['after_period'] }} is {{ number_format($p['after_percentage'], 1) }}%.
                                    <span class="{{ $p['change'] > 0 ? 'text-green-600' : ($p['change'] < 0 ? 'text-red-500' : 'text-gray-500') }} font-medium">
                                        Change: {{ $p['change'] >= 0 ? '+' : '' }}{{ number_format($p['change'], 1) }} points.
                                    </span>
                                @else
                                    — no Term {{ $p['before_period'] + 1 }} evidence yet to compare against.
                                @endif
                            </div>
                        @endif

                        <form method="POST" action="{{ route('principal.interventions.update', $iv->id) }}" class="flex items-end gap-2 mt-2">
                            @csrf
                            @method('PUT')
                            <select name="status" class="border rounded-md text-xs px-2 py-1">
                                @foreach($statuses as $s)
                                    <option value="{{ $s }}" {{ $iv->status === $s ? 'selected' : '' }}>{{ $statusLabels[$s] ?? $s }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="bg-brand-700 text-white text-xs px-3 py-1 rounded-md">Update</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('principal.interventions.store') }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="student_id" value="{{ $row['student_id'] }}">
                            <input type="hidden" name="recommendation_reason" value="{{ $row['recommendation']['reason'] }}">
                            <select name="recommended_type" class="w-full border rounded-md text-xs px-2 py-1">
                                @foreach($types as $t)
                                    <option value="{{ $t }}" {{ $row['recommendation']['type'] === $t ? 'selected' : '' }}>{{ $typeLabels[$t] ?? $t }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="bg-brand-700 text-white text-xs px-3 py-1.5 rounded-md w-full">
                                Record Intervention
                            </button>
                        </form>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="px-6 py-8 text-center text-gray-400">
                    <i class="bi bi-check-circle text-2xl block mb-2"></i>
                    No students currently need attention.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
