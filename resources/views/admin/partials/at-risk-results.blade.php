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
{{-- Standing property of the output, not a notification — never dismissible.
     See the "honest model evaluation" prompt: a 99% confidence reads as
     near-certainty about the student, when it actually only describes how
     much the model's own trees agreed with each other. --}}
<p class="text-xs text-gray-400 px-5 pt-1">
    Risk levels are derived from grade thresholds; confidence reflects how much the model's decision trees agreed with each other, not predictive certainty about any individual student.
</p>
<div class="tbl-scroll mt-2">
<table class="tbl tbl-sticky">
    <thead>
        <tr>
            <th scope="col">Student</th>
            <th scope="col">Section</th>
            <th scope="col" class="text-center">Average Grade</th>
            <th scope="col" class="text-center">Risk Level</th>
            <th scope="col" class="text-center">Focus Area</th>
            <th scope="col" class="text-center">Trend</th>
        </tr>
    </thead>
    <tbody>
        @forelse($atRiskStudents as $student)
        <tr>
            <td class="font-medium text-gray-800 align-top">
                @if(auth()->user()->role === 'principal' && isset($student['student_id']))
                    <a href="{{ route('principal.students.show', $student['student_id']) }}" class="hover:underline hover:text-brand-700">
                        {{ $student['name'] }}
                    </a>
                @else
                    {{ $student['name'] }}
                @endif
            </td>
            <td class="align-top">{{ $student['section'] }}</td>
            <td class="tbl-num text-center align-top">
                {{ is_numeric($student['average']) ? number_format($student['average'], 2) : $student['average'] }}
            </td>
            <td class="text-center align-top">
                @if($student['risk_level'] === 'high')
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-status-risk/10 text-status-risk">High</span>
                @elseif($student['risk_level'] === 'moderate')
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-status-attention/10 text-status-attention">Moderate</span>
                @else
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">{{ ucfirst($student['risk_level']) }}</span>
                @endif
                @if($student['was_overridden'])
                    <div class="mt-1" title="Model originally said '{{ ucfirst($student['ml_risk_level']) }}' — bumped up due to a failing subject">
                        <span class="text-[10px] text-orange-600 font-medium">&#9888; Rule-adjusted</span>
                    </div>
                @endif
            </td>
            <td class="align-top">
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
                @if(!empty($student['weakest_subject_component']))
                    @php
                        $wc = $student['weakest_subject_component'];
                        $wcLabels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
                    @endphp
                    <div class="text-xs text-gray-500 mt-1" title="From assessment evidence, not just the overall grade">
                        <i class="bi bi-clipboard-data"></i>
                        {{ $wcLabels[$wc['key']] ?? $wc['key'] }}:
                        {{ number_format($wc['percentage'], 1) }}%
                        <span class="{{ $wc['status'] === 'Needs Attention' ? 'text-red-500' : 'text-green-600' }}">
                            ({{ $wc['gap'] >= 0 ? '+' : '' }}{{ number_format($wc['gap'], 1) }})
                        </span>
                    </div>
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
            <td class="text-center align-top">
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
                    <div class="mt-1" title="Average has dropped for 2 terms in a row">
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-red-50 text-red-700 border border-red-200">
                            &#9888; Watch — 2 terms declining
                        </span>
                    </div>
                @endif
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="6">
                <x-empty-state message="No students match the current filter." hint="Try widening the filters above." class="py-6 text-sm" />
            </td>
        </tr>
        @endforelse
    </tbody>
</table>
</div>

@if($atRiskStudents->hasPages())
<div class="px-5 py-3 border-t flex flex-col items-center gap-2 text-sm text-gray-500">
    <div class="flex items-center gap-1">
        @if($atRiskStudents->onFirstPage())
            <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">← Prev</span>
        @else
            <a href="{{ $atRiskStudents->previousPageUrl() }}" class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">← Prev</a>
        @endif
        <span class="px-3 py-1 rounded border bg-brand-700 text-white font-medium">{{ $atRiskStudents->currentPage() }}</span>
        @if($atRiskStudents->hasMorePages())
            <a href="{{ $atRiskStudents->nextPageUrl() }}" class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">Next →</a>
        @else
            <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">Next →</span>
        @endif
    </div>
    <span class="text-xs">Showing {{ $atRiskStudents->firstItem() }}–{{ $atRiskStudents->lastItem() }} of <x-count-label :count="$atRiskStudents->total()" noun="student" /></span>
</div>
@endif