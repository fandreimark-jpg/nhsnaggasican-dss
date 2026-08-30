@extends('layouts.app')

@section('title', 'Student Detail')
@section('subtitle', $student->last_name . ', ' . $student->first_name . ' — ' . ($section?->name ?? 'No section') . ' (read-only)')

@section('content')

@php
    $componentLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
    $riskColors = ['low' => 'bg-green-100 text-green-700', 'moderate' => 'bg-yellow-100 text-yellow-700', 'high' => 'bg-red-100 text-red-700'];
@endphp

<div class="mb-4">
    <a href="{{ url()->previous() }}" class="text-sm text-gray-500 hover:underline"><i class="bi bi-arrow-left"></i> Back</a>
</div>

{{-- Risk overview + term selector --}}
<div class="bg-white rounded-xl shadow-sm p-5 mb-4 flex flex-wrap justify-between items-center gap-3">
    <div>
        <p class="text-xs text-gray-500">Latest Risk Level</p>
        @if($latestRisk)
            <span class="inline-block mt-1 px-3 py-1 rounded-full text-sm font-medium {{ $riskColors[$latestRisk->risk_level] ?? 'bg-gray-100 text-gray-600' }}">
                {{ ucfirst($latestRisk->risk_level) }} — {{ number_format($latestRisk->average_grade, 2) }} avg
            </span>
        @else
            <p class="text-sm text-gray-400 mt-1">No risk classification yet.</p>
        @endif
    </div>
    <form method="GET" class="flex items-center gap-2">
        <label class="text-xs text-gray-500">Term:</label>
        @foreach([1, 2, 3] as $t)
            <a href="{{ route('principal.students.show', ['student' => $student->id, 'period' => $t]) }}"
               class="px-3 py-1 rounded-full text-xs font-medium border {{ $period == $t ? 'bg-brand-700 text-white border-brand-700' : 'bg-white text-gray-600 border-gray-300' }}">
                Term {{ $t }}
            </a>
        @endforeach
    </form>
</div>

{{-- Subject -> Component -> Evidence drill-down --}}
<div class="space-y-4 mb-4">
    @forelse($subjectAnalysis as $row)
    <div class="bg-white rounded-xl shadow-sm overflow-hidden {{ $row['weakest_component'] && ($row['components'][$row['weakest_component']]['status'] ?? null) === 'Needs Attention' ? 'ring-1 ring-red-200' : '' }}">
        <div class="px-5 py-3 border-b flex justify-between items-center">
            <h3 class="font-semibold text-gray-800">{{ $row['subject']->name }}</h3>
            <span class="font-semibold {{ $row['complete'] ? 'text-gray-800' : 'text-gray-300' }}">
                {{ $row['complete'] ? number_format($row['computed_grade'], 2) : 'Incomplete' }}
            </span>
        </div>

        {{-- Component breakdown --}}
        <div class="grid grid-cols-3 divide-x">
            @foreach(['written_work', 'performance_task', 'examination'] as $key)
                @php $c = $row['components'][$key]; @endphp
                <div class="p-3 text-center {{ $row['weakest_component'] === $key && $c['status'] === 'Needs Attention' ? 'bg-red-50' : '' }}">
                    <p class="text-xs text-gray-500">{{ $componentLabels[$key] }}</p>
                    @if($c['percentage'] === null)
                        <p class="text-sm text-gray-300 mt-1">No data</p>
                    @else
                        <p class="text-lg font-bold {{ $c['status'] === 'On Track' ? 'text-green-700' : 'text-red-600' }} mt-1">
                            {{ number_format($c['percentage'], 1) }}%
                        </p>
                        <p class="text-xs text-gray-400">
                            gap {{ $c['gap'] >= 0 ? '+' : '' }}{{ number_format($c['gap'], 1) }} — {{ $c['status'] }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Evidence: individual assessment items --}}
        @if($row['evidence']->isNotEmpty())
        <div class="border-t px-5 py-3">
            <p class="text-xs font-medium text-gray-500 mb-2">Evidence</p>
            <table class="w-full text-xs">
                <tbody class="divide-y divide-gray-50">
                    @foreach($row['evidence'] as $item)
                    <tr>
                        <td class="py-1.5 text-gray-700">{{ $item['name'] }}</td>
                        <td class="py-1.5 text-gray-400">{{ $componentLabels[$item['component']] ?? $item['component'] }}</td>
                        <td class="py-1.5 text-right font-medium">
                            @if($item['score'] !== null)
                                {{ number_format($item['score'], 2) }} / {{ number_format($item['max_score'], 2) }}
                            @else
                                <span class="text-gray-300">Not yet scored</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="border-t px-5 py-3 text-xs text-gray-400">No assessment evidence uploaded yet for this subject/term.</div>
        @endif
    </div>
    @empty
    <div class="bg-white rounded-xl shadow-sm p-8 text-center text-gray-400">No subjects found for this student's section.</div>
    @endforelse
</div>

{{-- Intervention history --}}
<div class="bg-white rounded-xl shadow-sm p-5">
    <h3 class="font-semibold text-gray-800 text-sm mb-3">Intervention History</h3>
    @forelse($interventions as $iv)
        <div class="text-xs text-gray-600 py-1.5 border-b last:border-0">
            <span class="font-medium">{{ ucfirst(str_replace('_', ' ', $iv->recommended_type)) }}</span>
            — {{ ucfirst(str_replace('_', ' ', $iv->status)) }}
            <span class="text-gray-400">({{ $iv->created_at->format('M d, Y') }})</span>
        </div>
    @empty
        <p class="text-xs text-gray-400">No interventions recorded for this student.
            <a href="{{ route('principal.interventions') }}" class="text-brand-700 underline">Go to Interventions</a>
        </p>
    @endforelse
</div>

@endsection
