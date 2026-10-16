<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Services\DashboardAnalyticsService;
use App\Services\TransmutationService;

/**
 * DashboardController (Principal)
 *
 * Read-only Decision Support dashboard for the Principal: open academic
 * concerns, interventions, In-Term Status and evidence-based performance
 * trends, computed by the shared DashboardAnalyticsService. No classifier
 * output (risk_results) is read here — rule-based monitoring
 * (academic_concerns) is the current source of truth. The Principal
 * role has no write routes anywhere in this controller by design: grades
 * and assessment records stay adviser/admin-controlled (see the
 * 'role:principal' middleware on principal.* routes and
 * RoleAuthorizationTest for the enforcement).
 */
class DashboardController extends Controller
{
    public function __construct(private DashboardAnalyticsService $analytics = new DashboardAnalyticsService())
    {
    }

    public function index()
    {
        $inTermStatusSummary = $this->analytics->getInTermStatusSummary();
        // "Correctness and interface pass" TASK 6a/6b — the term-over-term
        // trend (every term 1-3) doubles as the source for the "up/down
        // from Term N" comparison next to the current term's counts, so
        // the two can never silently disagree.
        $inTermStatusTrend = $this->analytics->getInTermStatusTrend();
        $previousTermCounts = collect($inTermStatusTrend)->firstWhere('term', $inTermStatusSummary['inTermTerm'] - 1);

        return view('principal.dashboard', array_merge(
            $this->analytics->getSummaryData(),
            $this->analytics->getPrincipalSummary(),
            $inTermStatusSummary,
            $this->analytics->getFailingSummary(),
            [
                // "UI modernization pass" — Component Performance chart data:
                // the mean of each subject's component average from the SAME
                // SubjectAnalysisService the Subject Analysis page uses. A
                // presentation summary of existing figures, not a new metric.
                'componentPerformance' => $this->componentPerformance(),
                // Phase 2D — open academic concerns for the same school year
                // the rest of this page shows (academic_concerns, not risk_results).
                // One learner with two concerns = 1 learner For Review, 2 open concerns.
                'academicMonitoring' => app(\App\Services\MonitoringClassificationService::class)
                    ->summary($this->analytics->selectedSchoolYear()),
                // Compact "pending interventions" list for Zone 2 — the five
                // most recent rows the same Intervention::scopeUndecided()
                // rule counts on the Awaiting Your Decision card (CLAUDE.md
                // Design Decision #3), for the selected school year only.
                'pendingInterventions' => \App\Models\Intervention::undecided()
                    ->where('school_year', $this->analytics->selectedSchoolYear())
                    ->with(['student:id,last_name,first_name', 'section:id,name,grade_level', 'subject:id,name'])
                    ->latest()->take(5)->get(),
                'transmutationBanner' => $this->buildTransmutationBanner(),
                'inTermStatusTrend' => $inTermStatusTrend,
                'previousTermCounts' => $previousTermCounts,
                'performance' => $this->performance($inTermStatusSummary['inTermTerm']),
            ]
        ));
    }

    /**
     * "Performance by Grade and Section" — filters (perf_term, perf_grade,
     * perf_subject) validated against the selected year; anything unknown
     * falls back to no filter. Figures: SectionPerformanceService.
     */
    private function performance(int $defaultTerm): array
    {
        $schoolYear = $this->analytics->selectedSchoolYear();
        $request = request();
        $terms = \App\Models\AcademicTerm::termNumbers();
        $term = in_array($request->integer('perf_term'), $terms, true) ? $request->integer('perf_term') : $defaultTerm;
        $gradeLevels = Section::where('school_year', $schoolYear)->distinct()->orderBy('grade_level')->pluck('grade_level')->map(fn($g) => (int) $g);
        $grade = $gradeLevels->contains($request->integer('perf_grade')) ? $request->integer('perf_grade') : null;
        $subjects = \App\Models\Subject::whereIn('grade_level', $grade ? [$grade] : $gradeLevels)->orderBy('name')->get(['id', 'name', 'grade_level']);
        $subject = $subjects->contains('id', $request->integer('perf_subject')) ? $request->integer('perf_subject') : null;

        return (new \App\Services\SectionPerformanceService())->build($schoolYear, $term, $grade, $subject) + [
            'term' => $term, 'terms' => $terms, 'grade' => $grade, 'gradeLevels' => $gradeLevels,
            'subject' => $subject, 'subjects' => $subjects,
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, average: float, subjects: int}>
     */
    private function componentPerformance(): array
    {
        $summaries = (new \App\Services\SubjectAnalysisService())->getSubjectSummaries($this->analytics->selectedSchoolYear());
        $labels = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination'];
        $out = [];

        foreach ($labels as $key => $label) {
            $values = collect($summaries)
                ->map(fn($row) => $row['components'][$key]['avg_percentage'] ?? null)
                ->filter(fn($v) => $v !== null);

            // A component with no recorded score anywhere stays in the list as
            // "No data" (average null) — never dropped silently, never a 0.
            $out[] = ['key' => $key, 'label' => $label, 'average' => $values->isEmpty() ? null : round($values->avg(), 2), 'subjects' => $values->count()];
        }

        return $out;
    }

    /**
     * TASK 1 of "unblock verification" — school-wide version of the same
     * banner the Adviser dashboard shows for its one section: which
     * SHS grade level(s) (11-12; this system covers no others) currently
     * have sections actually running on a fallback transmutation scheme.
     * null when no fallback is configured or none is actually in use.
     */
    private function buildTransmutationBanner(): ?array
    {
        $fallbackScheme = config('dss.transmutation_fallback_scheme');
        if (!$fallbackScheme) {
            return null;
        }

        $schoolYear = $this->analytics->selectedSchoolYear();
        $transmutation = new TransmutationService();

        $affectedGradeLevels = collect([11, 12])
            ->filter(fn($gradeLevel) => Section::where('grade_level', $gradeLevel)->where('school_year', $schoolYear)->exists()
                && $transmutation->fallbackActiveFor($gradeLevel, $schoolYear))
            ->values();

        if ($affectedGradeLevels->isEmpty()) {
            return null;
        }

        return [
            'fallback_scheme' => $fallbackScheme,
            'grade_levels'    => $affectedGradeLevels->all(),
        ];
    }
}
