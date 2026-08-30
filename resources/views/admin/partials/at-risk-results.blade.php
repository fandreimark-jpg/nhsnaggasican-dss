{{-- This partial is shared by BOTH the Admin and Principal dashboards (each
     read-only over the same risk data — see DashboardAnalyticsService), and
     within each role it's rendered TWICE:
     1. Included directly inside {admin,principal}/dashboard.blade.php on a
        normal page load.
     2. Returned standalone by each role's DashboardController::index() when
        the request is AJAX (see the filter form's fetch() call in the
        dashboard scripts).
     Keeping it as one file means all these code paths can never drift apart
     from each other. $reportRoute is optional — the Admin dashboard passes
     route('admin.reports'); Principal has no reports route yet, so it's
     omitted there and the link simply doesn't render. --}}

<p class="text-xs text-gray-500 px-5 pt-3">
    {{ $atRiskStudentsTotal }} moderate/high risk student{{ $atRiskStudentsTotal === 1 ? '' : 's' }}, sorted by urgency
    @isset($reportRoute)
        — <a href="{{ $reportRoute }}" class="text-green-700 font-medium hover:underline">Full section report &rarr;</a>
    @endisset
</p>
<div class="max-h-[480px] overflow-y-auto mt-2">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-gray-500 sticky top-0 z-10">
        <tr>
            <th class="text-left px-5 py-2 text-xs">Student</th>
            <th class="text-left px-3 py-2 text-xs">Section</th>
            <th class="text-center px-3 py-2 text-xs">Average Grade</th>
            <th class="text-center px-3 py-2 text-xs">Risk Level</th>
            <th class="text-center px-3 py-2 text-xs">Focus Area</th>
            <th class="text-center px-3 py-2 text-xs">Trend</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
        @forelse($atRiskStudents as $student)
        <tr class="hover:bg-gray-50">
            <td class="px-5 py-2 font-medium text-gray-800 text-sm align-top">{{ $student['name'] }}</td>
            <td class="px-3 py-2 text-gray-600 text-sm align-top">{{ $student['section'] }}</td>
            <td class="px-3 py-2 text-center text-gray-700 text-sm align-top">
                {{ is_numeric($student['average']) ? number_format($student['average'], 2) : $student['average'] }}
            </td>
            <td class="px-3 py-2 text-center align-top">
                @if($student['risk_level'] === 'high')
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">High</span>
                @elseif($student['risk_level'] === 'moderate')
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">Moderate</span>
                @else
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">{{ ucfirst($student['risk_level']) }}</span>
                @endif
                @if($student['was_overridden'])
                    <div class="mt-1" title="Model originally said '{{ ucfirst($student['ml_risk_level']) }}' — bumped up due to a failing subject">
                        <span class="text-[10px] text-orange-600 font-medium">&#9888; Rule-adjusted</span>
                    </div>
                @endif
            </td>
            <td class="px-3 py-2 align-top">
                @if(!empty($student['failing_subjects']))
                    <div class="text-xs">
                        @foreach($student['failing_subjects'] as $fs)
                            <div class="text-red-600">
                                {{ $fs['name'] }}
                                <span class="text-gray-400">({{ number_format($fs['grade'], 2) }})</span>
                            </div>
                        @endforeach
                    </div>
                @elseif($student['weakest_subject'] && $student['weakest_subject_grade'] < 75)
                    {{ $student['weakest_subject'] }}
                    <span class="text-gray-400">({{ number_format($student['weakest_subject_grade'], 2) }})</span>
                @else
                    <span class="text-gray-400 text-xs italic">No failing subjects</span>
                @endif
                @if(!empty($student['subject_declines']))
                    <div class="text-xs text-orange-600 mt-1">
                        @foreach($student['subject_declines'] as $sd)
                            <div>
                                &#128315; {{ $sd['subject'] }}
                                <span class="text-gray-400">
                                    ({{ number_format($sd['from'], 2) }} &rarr; {{ number_format($sd['to'], 2) }})
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </td>
            <td class="px-3 py-2 text-center text-sm align-top">
                @if($student['trend'] === 'improving')
                    <span class="text-green-600 font-medium">&uarr; Improving</span>
                @elseif($student['trend'] === 'declining')
                    <span class="text-red-600 font-medium">&darr; Declining</span>
                @elseif($student['trend'] === 'stable')
                    <span class="text-gray-500 font-medium">&rarr; Stable</span>
                @else
                    <span class="text-gray-300">—</span>
                @endif
                @if($student['consecutive_decline'])
                    <div class="mt-1" title="Average has dropped for 2 grading periods in a row">
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-red-50 text-red-700 border border-red-200">
                            &#9888; Watch — 2 terms declining
                        </span>
                    </div>
                @endif
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="6" class="px-5 py-6 text-center text-gray-400 text-sm">
                No students match the current filter.
            </td>
        </tr>
        @endforelse
    </tbody>
</table>
</div>