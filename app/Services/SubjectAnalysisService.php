<?php

namespace App\Services;

use App\Models\Section;
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

    /** @return array<int, array{subject: Subject, components: array, weakest_component: ?string, student_count: int}> */
    public function getSubjectSummaries(?string $schoolYear = null): array
    {
        $schoolYear ??= Section::activeSchoolYear();

        $rows = DB::table('assessment_scores')
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.school_year', $schoolYear)
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

        if ($rows->isEmpty()) {
            return [];
        }

        $bySubject = $rows->groupBy('subject_id');
        $belowTargetCounts = $this->getBelowTargetCounts($schoolYear);

        return Subject::whereIn('id', $bySubject->keys())
            ->orderBy('name')
            ->get()
            ->map(function ($subject) use ($bySubject, $belowTargetCounts) {
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
                ];
            })
            ->values()
            ->all();
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
    private function getBelowTargetCounts(string $schoolYear): array
    {
        $perStudentTotals = DB::table('assessment_scores')
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.school_year', $schoolYear)
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
