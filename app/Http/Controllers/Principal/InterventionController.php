<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Student;
use App\Services\DashboardAnalyticsService;
use App\Services\InterventionRecommender;
use App\Services\ProgressMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * InterventionController (Principal)
 *
 * The only WRITE surface the Principal role has (see RoleAuthorizationTest
 * — every other principal.* route is read-only). Lists at-risk students
 * with a DSS-generated recommendation (InterventionRecommender) next to
 * any existing Intervention record, and lets the Principal create one or
 * move an existing one through its status lifecycle. A recommendation is
 * never auto-approved — every Intervention starts at 'recommended' and
 * only advances when the Principal explicitly updates it.
 */
class InterventionController extends Controller
{
    public function __construct(
        private DashboardAnalyticsService $analytics = new DashboardAnalyticsService(),
        private InterventionRecommender $recommender = new InterventionRecommender(),
        private ProgressMonitoringService $progress = new ProgressMonitoringService()
    ) {
    }

    public function index()
    {
        $atRiskStudents = $this->analytics->getAtRiskStudentsData()['atRiskStudents'];

        $existingByStudentId = Intervention::with(['decidedBy'])
            ->whereIn('status', ['recommended', 'in_review', 'approved', 'in_progress', 'monitoring'])
            ->latest()
            ->get()
            ->unique('student_id') // most recent open intervention per student
            ->keyBy('student_id');

        $rows = collect($atRiskStudents)->map(function ($row) use ($existingByStudentId) {
            $row['recommendation'] = $this->recommender->recommend($row);
            $row['existing_intervention'] = $existingByStudentId->get($row['student_id']);
            $row['progress'] = $row['existing_intervention']
                ? $this->progress->compare($row['existing_intervention'])
                : null;
            return $row;
        });

        return view('principal.interventions', [
            'rows'     => $rows,
            'statuses' => Intervention::STATUSES,
            'types'    => Intervention::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'student_id'        => 'required|exists:students,id',
            'recommended_type'  => ['required', Rule::in(Intervention::TYPES)],
            'recommendation_reason' => 'nullable|string',
            'principal_notes'   => 'nullable|string',
        ]);

        $latestRisk = RiskResult::where('student_id', $request->student_id)
            ->orderByDesc('grading_period')
            ->first();

        Intervention::create([
            'student_id'             => $request->student_id,
            'subject_id'             => $latestRisk?->weakest_subject_id,
            'risk_result_id'         => $latestRisk?->id,
            'recommended_type'       => $request->recommended_type,
            'recommendation_reason'  => $request->recommendation_reason,
            'status'                 => 'recommended',
            'principal_notes'        => $request->principal_notes,
            'created_by'             => auth()->id(),
        ]);

        return redirect()->route('principal.interventions')
            ->with('success', 'Intervention recorded.');
    }

    public function update(Request $request, Intervention $intervention)
    {
        $request->validate([
            'status'           => ['required', Rule::in(Intervention::STATUSES)],
            'principal_notes'  => 'nullable|string',
        ]);

        $intervention->update([
            'status'          => $request->status,
            'principal_notes' => $request->principal_notes ?? $intervention->principal_notes,
            'decided_by'      => auth()->id(),
            'decided_at'      => now(),
        ]);

        return redirect()->route('principal.interventions')
            ->with('success', 'Intervention updated.');
    }
}
