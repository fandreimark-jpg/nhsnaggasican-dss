<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * School-wide, per-subject assessment-component analysis — the missing
 * "Subject Analysis" / "Assessment Component Analysis" areas from
 * CLAUDE.md's Principal section (consolidated into one page: components
 * are shown as columns rather than a separate third page, since that's
 * exactly the same underlying data at a different grouping).
 *
 * Computed via a single grouped aggregate query (AVG of each item's
 * score/max_score, grouped by subject+component) rather than looping
 * GradingEngine per student per subject — a school-wide summary doesn't
 * need per-student precision, and this stays cheap regardless of how
 * many students/subjects/sections exist. (Contrast with
 * PerformanceAnalysisService/GradingEngine, which DO need per-student
 * precision and are used for individual student pages instead.)
 */
class SubjectAnalysisService
{
    private const TARGET = 75.0;

    /**
     * @param ?int $sectionId Restrict to one section — decision support
     *        for a specific class, not just the whole school.
     * @param ?int $term Grading period (1-3) — a subject's Term 1 numbers
     *        can look very different from Term 3's; null means every term
     *        this school year combined.
     * @return array<int, array{subject: Subject, components: array, weakest_component: ?string, student_count: int, failure_rate: ?float, failing_count: int, graded_count: int, at_risk_count: int}>
     */
    public function getSubjectSummaries(?string $schoolYear = null, ?int $sectionId = null, ?int $term = null): array
    {
        $schoolYear ??= Section::activeSchoolYear();

        $rows = DB::table('assessment_scores')
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.school_year', $schoolYear)
            ->when($sectionId, fn($q) => $q->where('assessments.section_id', $sectionId))
            ->when($term, fn($q) => $q->where('assessments.grading_period', $term))
            ->select(
                'assessments.subject_id',
                'assessments.component',
                // The literal 100.0 forces floating-point division — on
                // SQLite (used by the test suite), dividing two
                // integer-affinity values (e.g. decimal columns holding
                // whole numbers like 90.00) truncates via INTEGER
                // division, silently producing 0 for every percentage.
                DB::raw('AVG(assessment_scores.score * 100.0 / assessments.max_score) as avg_percentage'),
                DB::raw('COUNT(DISTINCT assessment_scores.student_id) as student_count')
            )
            ->groupBy('assessments.subject_id', 'assessments.component')
            ->get();

        // failure_rate/at_risk_count are computed from Grade/RiskResult, not
        // assessment_scores — a subject can have failure/at-risk data even
        // in a term with no component evidence rows above, so this must not
        // bail out just because $rows is empty. Only the component-average
        // half of the page is empty in that case.
        $subjectIdsWithData = $rows->pluck('subject_id')
            ->merge($this->subjectIdsWithGrades($schoolYear, $sectionId, $term))
            ->merge($this->subjectIdsAtRisk($schoolYear, $sectionId, $term))
            ->unique();

        if ($subjectIdsWithData->isEmpty()) {
            return [];
        }

        $bySubject = $rows->groupBy('subject_id');
        $belowTargetCounts = $this->getBelowTargetCounts($schoolYear, $sectionId, $term);
        $failureStats = $this->getFailureStats($schoolYear, $sectionId, $term);
        $atRiskCounts = $this->getAtRiskCountsBySubject($schoolYear, $sectionId, $term);

        return Subject::whereIn('id', $subjectIdsWithData)
            ->orderBy('name')
            ->get()
            ->map(function ($subject) use ($bySubject, $belowTargetCounts, $failureStats, $atRiskCounts) {
                $componentRows = $bySubject->get($subject->id, collect())->keyBy('component');

                $components = [];
                foreach (['written_work', 'performance_task', 'examination'] as $key) {
                    $components[$key] = $componentRows->has($key) ? [
                        'avg_percentage' => round((float) $componentRows[$key]->avg_percentage, 2),
                        'student_count'  => (int) $componentRows[$key]->student_count,
                        'status'         => $componentRows[$key]->avg_percentage >= self::TARGET ? 'On Track' : 'Needs Attention',
                    ] : null;
                }

                $withData = collect($components)->filter();
                $weakest = $withData->sortBy('avg_percentage')->keys()->first();

                $stats = $failureStats[$subject->id] ?? ['graded_count' => 0, 'failing_count' => 0];

                return [
                    'subject'            => $subject,
                    'components'         => $components,
                    'weakest_component'  => $weakest,
                    'student_count'      => $withData->max('student_count') ?? 0,
                    // How many individual students sit below target in the
                    // weakest component — the average above (48.3%, say)
                    // hides whether that's everyone clustered near 48, or a
                    // handful pulling it down; this is the number a
                    // Principal can actually act on. Null when there's no
                    // weakest component to speak of.
                    'below_target_count' => $weakest ? ($belowTargetCounts[$subject->id][$weakest] ?? 0) : null,
                    // Failure rate reads Grade::scopeFailing() — the SAME
                    // official grade <= InTermStatusService::FAILING_THRESHOLD
                    // rule the dashboard's own Failing tile uses, not a
                    // second copy of "74" written here.
                    'graded_count'       => $stats['graded_count'],
                    'failing_count'      => $stats['failing_count'],
                    'failure_rate'       => $stats['graded_count'] > 0
                        ? round(($stats['failing_count'] / $stats['graded_count']) * 100, 1)
                        : null,
                    // How many students are at Moderate/High risk with THIS
                    // subject as their weakest — same "latest risk result
                    // per student this school year" query
                    // DashboardAnalyticsService::getSummaryData() uses, so
                    // this can't silently disagree with the Principal
                    // dashboard's own risk distribution.
                    'at_risk_count'      => $atRiskCounts[$subject->id] ?? 0,
                ];
            })
            ->values()
            ->all();
    }

    /** Subject ids with at least one Grade row this scope — so a subject with grades but zero assessment_scores still appears. */
    private function subjectIdsWithGrades(string $schoolYear, ?int $sectionId, ?int $term): \Illuminate\Support\Collection
    {
        return Grade::where('school_year', $schoolYear)
            ->when($sectionId, fn($q) => $q->where('section_id', $sectionId))
            ->when($term, fn($q) => $q->where('grading_period', $term))
            ->distinct()
            ->pluck('subject_id');
    }

    /** Subject ids that are some student's weakest-subject this scope. */
    private function subjectIdsAtRisk(string $schoolYear, ?int $sectionId, ?int $term): \Illuminate\Support\Collection
    {
        return $this->latestRiskResultsQuery($schoolYear, $sectionId, $term)
            ->whereNotNull('weakest_subject_id')
            ->pluck('weakest_subject_id');
    }

    /**
     * @return array<int, array{graded_count: int, failing_count: int}>
     */
    private function getFailureStats(string $schoolYear, ?int $sectionId, ?int $term): array
    {
        $graded = Grade::where('school_year', $schoolYear)
            ->when($sectionId, fn($q) => $q->where('section_id', $sectionId))
            ->when($term, fn($q) => $q->where('grading_period', $term))
            ->where('is_verified', true)
            ->where('is_provisional', false)
            ->whereNotNull('grade')
            ->select('subject_id', DB::raw('COUNT(*) as graded_count'))
            ->groupBy('subject_id')
            ->pluck('graded_count', 'subject_id');

        $failing = Grade::where('school_year', $schoolYear)
            ->when($sectionId, fn($q) => $q->where('section_id', $sectionId))
            ->when($term, fn($q) => $q->where('grading_period', $term))
            ->failing()
            ->select('subject_id', DB::raw('COUNT(*) as failing_count'))
            ->groupBy('subject_id')
            ->pluck('failing_count', 'subject_id');

        $stats = [];
        foreach ($graded as $subjectId => $count) {
            $stats[$subjectId] = ['graded_count' => (int) $count, 'failing_count' => (int) ($failing[$subjectId] ?? 0)];
        }

        return $stats;
    }

    /** @return array<int, int> [subject_id => count of students at moderate/high risk with this subject weakest] */
    private function getAtRiskCountsBySubject(string $schoolYear, ?int $sectionId, ?int $term): array
    {
        return $this->latestRiskResultsQuery($schoolYear, $sectionId, $term)
            ->whereNotNull('weakest_subject_id')
            ->whereIn('risk_level', ['moderate', 'high'])
            ->select('weakest_subject_id', DB::raw('COUNT(*) as at_risk_count'))
            ->groupBy('weakest_subject_id')
            ->pluck('at_risk_count', 'weakest_subject_id')
            ->map(fn($count) => (int) $count)
            ->all();
    }

    /**
     * Latest risk result per student this school year — same pattern as
     * DashboardAnalyticsService::getSummaryData(), so the Principal
     * dashboard's own risk distribution and this page's at-risk-by-subject
     * counts read from the same underlying rows, not two independent
     * queries that could silently disagree. $term filters by
     * grading_period directly (a RiskResult IS scoped to one term); a
     * section filter joins through students, since risk_results itself
     * has no section_id.
     */
    private function latestRiskResultsQuery(string $schoolYear, ?int $sectionId, ?int $term)
    {
        $latestIds = RiskResult::where('school_year', $schoolYear)
            ->when($term, fn($q) => $q->where('grading_period', $term))
            ->selectRaw('MAX(id) as id')
            ->groupBy('student_id')
            ->pluck('id');

        return RiskResult::whereIn('id', $latestIds)
            ->when($sectionId, function ($q) use ($sectionId) {
                $q->whereIn('student_id', Student::where('section_id', $sectionId)->pluck('id'));
            });
    }

    /**
     * Per-student, per-component totals (SUM earned / SUM max — the same
     * aggregation GradingEngine uses, unlike getSubjectSummaries()'s own
     * AVG-of-item-percentages above, which is a subject-wide summary
     * only) — grouped down to one query so counting "how many students
     * are below target" never turns into a per-student loop.
     *
     * @return array<int, array<string, int>> [subject_id => [component => count below target]]
     */
    private function getBelowTargetCounts(string $schoolYear, ?int $sectionId = null, ?int $term = null): array
    {
        $perStudentTotals = DB::table('assessment_scores')
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.school_year', $schoolYear)
            ->when($sectionId, fn($q) => $q->where('assessments.section_id', $sectionId))
            ->when($term, fn($q) => $q->where('assessments.grading_period', $term))
            ->select(
                'assessments.subject_id',
                'assessments.component',
                'assessment_scores.student_id',
                DB::raw('SUM(assessment_scores.score) as earned'),
                DB::raw('SUM(assessments.max_score) as max_total')
            )
            ->groupBy('assessments.subject_id', 'assessments.component', 'assessment_scores.student_id')
            ->get();

        $counts = [];
        foreach ($perStudentTotals as $row) {
            if ($row->max_total <= 0) {
                continue;
            }
            // Plain PHP division — no SQLite integer-division pitfall
            // here since this happens outside the query, on floats.
            $percentage = ($row->earned / $row->max_total) * 100;
            if ($percentage < self::TARGET) {
                $counts[$row->subject_id][$row->component] = ($counts[$row->subject_id][$row->component] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
