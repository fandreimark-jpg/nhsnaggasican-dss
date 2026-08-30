@extends('layouts.app')

@section('title', 'Principal Dashboard')
@section('subtitle', 'Academic Performance Overview — Read Only')

@section('content')

{{-- Summary Cards --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-brand-500">
        <p class="text-xs text-gray-500">Total Students</p>
        <p class="text-2xl font-bold text-brand-700 mt-1">{{ $totalStudents }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-green-500">
        <p class="text-xs text-gray-500">Low Risk</p>
        <p class="text-2xl font-bold text-green-600 mt-1">{{ $lowRisk }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-yellow-400">
        <p class="text-xs text-gray-500">Moderate Risk</p>
        <p class="text-2xl font-bold text-yellow-500 mt-1">{{ $moderateRisk }}</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-red-500">
        <p class="text-xs text-gray-500">High Risk</p>
        <p class="text-2xl font-bold text-red-600 mt-1">{{ $highRisk }}</p>
    </div>
</div>

{{-- Principal-only summary: intervention status + assessment completion.
     Not shown on the Admin dashboard — this is what the Principal actually
     owns that Admin doesn't (see InterventionController). --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    <a href="{{ route('principal.interventions') }}" class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-purple-500 hover:shadow-md transition-shadow block">
        <p class="text-xs text-gray-500">Under Intervention</p>
        <p class="text-2xl font-bold text-purple-700 mt-1">{{ $under_intervention }}</p>
        <p class="text-xs text-gray-400 mt-1">Approved, in progress, or being monitored</p>
    </a>
    <a href="{{ route('principal.interventions') }}" class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-orange-400 hover:shadow-md transition-shadow block">
        <p class="text-xs text-gray-500">Awaiting Your Decision</p>
        <p class="text-2xl font-bold text-orange-600 mt-1">{{ $awaiting_decision }}</p>
        <p class="text-xs text-gray-400 mt-1">Recommended, not yet reviewed</p>
    </a>
    <div class="bg-white rounded-lg p-4 shadow-sm border-t-4 border-brand-500">
        <p class="text-xs text-gray-500">Assessment Completion</p>
        @if($assessment_completion['has_data'])
            <p class="text-2xl font-bold text-brand-700 mt-1">{{ $assessment_completion['percentage'] }}%</p>
            <p class="text-xs text-gray-400 mt-1">{{ $assessment_completion['actual'] }} of {{ $assessment_completion['expected'] }} expected scores entered</p>
        @else
            <p class="text-lg font-medium text-gray-300 mt-1">No data yet</p>
            <p class="text-xs text-gray-400 mt-1">No assessment forms uploaded this school year</p>
        @endif
    </div>
</div>

{{-- Charts Row --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    <div class="bg-white rounded-lg shadow-sm p-4">
        <h3 class="font-semibold text-gray-700 text-sm mb-1">Risk Distribution</h3>
        <p class="text-xs text-gray-400 mb-2">Overall student risk levels</p>
        <div style="height:180px;">
            <canvas id="riskDonutChart"></canvas>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow-sm p-4">
        <h3 class="font-semibold text-gray-700 text-sm mb-1">Performance Trend</h3>
        <p class="text-xs text-gray-400 mb-2">Average grade per term</p>
        <div style="height:180px;">
            <canvas id="termTrendChart"></canvas>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow-sm p-4">
        <h3 class="font-semibold text-gray-700 text-sm mb-1">At-Risk per Section</h3>
        <p class="text-xs text-gray-400 mb-2">Moderate + High risk per section</p>
        <div style="height:180px;">
            <canvas id="sectionRiskChart"></canvas>
        </div>
    </div>
</div>

{{-- Recommendations — the DSS provides these; the Principal makes the
     final decision (see Intervention, a later phase). --}}
<div class="bg-white rounded-lg shadow-sm p-4 mb-4">
    <h3 class="font-semibold text-gray-800 text-sm mb-3">Recommendations</h3>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

        <div class="flex gap-3 p-3 bg-green-50 rounded-lg border border-green-200">
            <span class="w-2 h-2 rounded-full bg-green-500 block mt-1 shrink-0"></span>
            <div>
                <p class="text-xs font-semibold text-green-700">
                    Low Risk — {{ $lowRisk }} {{ $lowRisk === 1 ? 'student' : 'students' }}
                </p>
                <p class="text-xs text-green-600 mt-0.5">
                    Students are performing well. Continue regular monitoring and maintain current academic support strategies.
                </p>
            </div>
        </div>

        <div class="flex gap-3 p-3 bg-yellow-50 rounded-lg border border-yellow-200">
            <span class="w-2 h-2 rounded-full bg-yellow-400 block mt-1 shrink-0"></span>
            <div>
                <p class="text-xs font-semibold text-yellow-700">
                    Moderate Risk — {{ $moderateRisk }} {{ $moderateRisk === 1 ? 'student' : 'students' }}
                </p>
                <p class="text-xs text-yellow-600 mt-0.5">Students need academic attention. Suggested actions:</p>
                <ul class="text-xs text-yellow-600 mt-1 list-disc list-inside">
                    <li>Conduct parent-teacher conference</li>
                    <li>Provide remedial or tutorial sessions</li>
                    <li>Monitor performance closely each term</li>
                </ul>
            </div>
        </div>

        <div class="flex gap-3 p-3 bg-red-50 rounded-lg border border-red-200">
            <span class="w-2 h-2 rounded-full bg-red-500 block mt-1 shrink-0"></span>
            <div>
                <p class="text-xs font-semibold text-red-700">
                    High Risk — {{ $highRisk }} {{ $highRisk === 1 ? 'student' : 'students' }}
                </p>
                <p class="text-xs text-red-600 mt-0.5">Immediate academic intervention suggested. Consider:</p>
                <ul class="text-xs text-red-600 mt-1 list-disc list-inside">
                    <li>Schedule immediate parent conference</li>
                    <li>Refer to guidance counselor</li>
                    <li>Enroll in intensive remedial program</li>
                    <li>Weekly academic progress monitoring</li>
                </ul>
            </div>
        </div>

    </div>
</div>

{{-- At-Risk Students Table — shared partial with the Admin dashboard --}}
@if($atRiskStudentsTotal > 0 || request('ar_grade_level') || request('ar_section_search'))
<div class="bg-white rounded-lg shadow-sm mb-4">
    <div class="px-5 py-3 border-b">
        <h3 class="font-semibold text-gray-800 text-sm">Students Needing Attention</h3>
        <div class="flex items-center gap-4 mt-2 text-xs text-gray-500">
            <span class="flex items-center gap-1">
                <span class="w-2 h-2 rounded-full bg-red-600 inline-block"></span>
                Currently failing (below 75 this term)
            </span>
            <span class="flex items-center gap-1">
                <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
                Declining 5+ points vs. last term (may still be passing)
            </span>
        </div>

        <div class="flex flex-wrap items-end gap-3 mt-3 pt-3 border-t">
            <form method="GET" id="atRiskFilterForm" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Grade Level</label>
                    <select name="ar_grade_level" id="atRiskGradeLevelSelect"
                            class="border rounded-md text-sm px-2 py-1.5 min-w-[130px]">
                        <option value="">All grade levels</option>
                        @foreach($atRiskGradeLevels as $gl)
                            <option value="{{ $gl }}" {{ request('ar_grade_level') == $gl ? 'selected' : '' }}>
                                Grade {{ $gl }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Section</label>
                    <select name="ar_section_search" id="atRiskSectionSelect"
                            class="border rounded-md text-sm px-2 py-1.5 min-w-[130px]">
                        <option value="">All sections</option>
                        @foreach($atRiskSections as $sec)
                            <option value="{{ $sec->name }}" data-grade-level="{{ $sec->grade_level }}"
                                    {{ request('ar_section_search') === $sec->name ? 'selected' : '' }}>
                                {{ $sec->name }} (Grade {{ $sec->grade_level }})
                            </option>
                        @endforeach
                    </select>
                </div>
                @if(request('ar_grade_level') || request('ar_section_search'))
                    <a href="{{ route('principal.dashboard') }}" class="text-sm text-gray-500 hover:underline pb-1.5">Clear</a>
                @endif
            </form>
        </div>
    </div>
    <div id="atRiskResultsContainer">
        @include('admin.partials.at-risk-results')
    </div>
</div>
@endif

{{-- Academic Honors --}}
<div class="bg-white rounded-lg shadow-sm mb-4">
    <div class="px-5 py-3 border-b">
        <h3 class="font-semibold text-gray-800 text-sm">Academic Honors</h3>
        <p class="text-xs text-gray-500">Students with outstanding academic performance</p>
    </div>

    <div class="divide-y divide-gray-100">

        <div class="px-5 py-3">
            <div class="flex items-center gap-2 mb-2">
                <i class="bi bi-trophy-fill" style="font-size:40px; color:#FFD700;"></i>
                <div>
                    <p class="text-xs font-semibold text-gray-800">With Highest Honors</p>
                    <p class="text-xs text-gray-400">Average of 98–100</p>
                </div>
                <span class="ml-auto text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-medium">
                    {{ $highestHonors->count() }} {{ $highestHonors->count() === 1 ? 'student' : 'students' }}
                </span>
            </div>
            @if($highestHonors->count() > 0)
                <div class="space-y-1">
                    @foreach($highestHonors as $student)
                    <div class="flex justify-between text-xs bg-purple-50 rounded px-3 py-1.5">
                        <span class="font-medium text-gray-800">{{ $student['name'] }}</span>
                        <span class="text-gray-500">{{ $student['section'] }}</span>
                        <span class="font-semibold text-purple-700">{{ number_format($student['average'], 2) }}</span>
                    </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-gray-400 italic">No students in this category yet.</p>
            @endif
        </div>

        <div class="px-5 py-3">
            <div class="flex items-center gap-2 mb-2">
                <i class="bi bi-bookmark-star-fill" style="color: #dc3545; font-size: 30px;"></i>
                <div>
                    <p class="text-xs font-semibold text-gray-800">With High Honors</p>
                    <p class="text-xs text-gray-400">Average of 95–97</p>
                </div>
                <span class="ml-auto text-xs bg-brand-100 text-brand-700 px-2 py-0.5 rounded-full font-medium">
                    {{ $highHonors->count() }} {{ $highHonors->count() === 1 ? 'student' : 'students' }}
                </span>
            </div>
            @if($highHonors->count() > 0)
                <div class="space-y-1">
                    @foreach($highHonors as $student)
                    <div class="flex justify-between text-xs bg-brand-50 rounded px-3 py-1.5">
                        <span class="font-medium text-gray-800">{{ $student['name'] }}</span>
                        <span class="text-gray-500">{{ $student['section'] }}</span>
                        <span class="font-semibold text-brand-700">{{ number_format($student['average'], 2) }}</span>
                    </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-gray-400 italic">No students in this category yet.</p>
            @endif
        </div>

        <div class="px-5 py-3">
            <div class="flex items-center gap-2 mb-2">
                <i class="bi bi-award-fill" style="color: gold; font-size: 30px;"></i>
                <div>
                    <p class="text-xs font-semibold text-gray-800">With Honors</p>
                    <p class="text-xs text-gray-400">Average of 90–94</p>
                </div>
                <span class="ml-auto text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">
                    {{ $withHonors->count() }} {{ $withHonors->count() === 1 ? 'student' : 'students' }}
                </span>
            </div>
            @if($withHonors->count() > 0)
                <div class="space-y-1">
                    @foreach($withHonors as $student)
                    <div class="flex justify-between text-xs bg-green-50 rounded px-3 py-1.5">
                        <span class="font-medium text-gray-800">{{ $student['name'] }}</span>
                        <span class="text-gray-500">{{ $student['section'] }}</span>
                        <span class="font-semibold text-green-700">{{ number_format($student['average'], 2) }}</span>
                    </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-gray-400 italic">No students in this category yet.</p>
            @endif
        </div>

    </div>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const RISK_DATA = {
        low:      {{ $lowRisk }},
        moderate: {{ $moderateRisk }},
        high:     {{ $highRisk }},
    };
    const TERM_TRENDS       = @json($termTrends);
    const SECTION_RISK_DATA = @json($sectionRiskData);
</script>
<script src="{{ asset('js/admin/dashboard.js') }}"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initCascadingSelect('atRiskGradeLevelSelect', 'atRiskSectionSelect');
        initAutoSubmitFilter('atRiskFilterForm', { ajaxTarget: 'atRiskResultsContainer' });
    });
</script>
@endpush
