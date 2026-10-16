<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\AcademicConcern;
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

    /**
     * @param ?int $sectionId Restrict to one section — decision support
     *        for a specific class, not just the whole school.
     * @param ?int $term Grading period (1-3) — a subject's Term 1 numbers
     *        can look very different from Term 3's; null means every term
     *        this school year combined.
     * @return array<int, array{subject: Subject, components: array, weakest_component: ?string, student_count: int, failure_rate: ?float, failing_count: int, graded_count: int, open_concern_count: int, open_concern_learners: int}>
     */
    public function getSubjectSummaries(?string $schoolYear = null, ?int $sectionId = null, ?int $term = null, ?int $gradeLevel = null): array
    {
        $schoolYear ??= Section::activeSchoolYear();

        // "Student identity and term-specific subject offerings" pass, STEP
        // J — the scope is School Year + Academic Term + Grade Level +
        // Section. A grade-level filter is a set of sections; both it and
        // a single section become one whereIn on section_id so every
        // query below scopes identically.
        $sectionIds = $this->scopedSectionIds($schoolYear, $sectionId, $gradeLevel);

        $rows = DB::table('assessment_scores')
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.school_year', $schoolYear)
            ->when($sectionIds !== null, fn($q) => $q->whereIn('assessments.section_id', $sectionIds))
            ->when($term, fn($q) => $q->where('assessments.grading_period', $term))
            // A legacy item with no positive HPS has no percentage (x / 0 is
            // NULL in SQL, which a cast would turn into a fabricated 0%).
            ->where('assessments.max_score', '>', 0)
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

        // failure_rate and the open-concern counts are computed from Grade /
        // academic_concerns, not assessment_scores — a subject can have
        // either even in a term with no component evidence rows above, so
        // this must not bail out just because $rows is empty. Only the
        // component-average half of the page is empty in that case.
        $subjectIdsWithData = $rows->pluck('subject_id')
            ->merge($this->subjectIdsWithGrades($schoolYear, $sectionIds, $term))
            ->merge($this->subjectIdsWithOpenConcerns($schoolYear, $sectionIds, $term))
            ->unique();

        if ($subjectIdsWithData->isEmpty()) {
            return [];
        }

        $bySubject = $rows->groupBy('subject_id');
        $belowTargetCounts = $this->getBelowTargetCounts($schoolYear, $sectionIds, $term);
        $failureStats = $this->getFailureStats($schoolYear, $sectionIds, $term);
        $openConcerns = $this->getOpenConcernCountsBySubject($schoolYear, $sectionIds, $term);

        return Subject::whereIn('id', $subjectIdsWithData)
            ->orderBy('name')
            ->get()
            ->map(function ($subject) use ($bySubject, $belowTargetCounts, $failureStats, $openConcerns) {
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
                    // Open Academic Concerns (approved monitoring rules —
                    // today the explicit Missing requirement) in this
                    // subject and scope, and how many learners they cover.
                    // Observable evidence, never a prediction.
                    'open_concern_count'    => $openConcerns[$subject->id]['concerns'] ?? 0,
                    'open_concern_learners' => $openConcerns[$subject->id]['learners'] ?? 0,
                    'open_subject_performance' => $openConcerns[$subject->id]['subject_performance'] ?? 0,
                    'open_missing'             => $openConcerns[$subject->id]['missing'] ?? 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Subjects OFFERED in the selected scope that have no evidence, grades,
     * or open concerns yet — listed so the Principal can tell "offered but
     * nothing recorded" apart from "not offered this term". Only
     * answerable for one section and one term (an offering is per section
     * per term); null otherwise.
     *
     * @return ?\Illuminate\Support\Collection<int, Subject>
     */
    public function offeredWithoutData(string $schoolYear, ?int $sectionId, ?int $term, array $summaries): ?\Illuminate\Support\Collection
    {
        if (!$sectionId || !$term) {
            return null;
        }

        $section = Section::where('id', $sectionId)->where('school_year', $schoolYear)->first();
        if (!$section) {
            return null;
        }

        $withData = collect($summaries)->map(fn($row) => $row['subject']->id);

        return Subject::forSection($section, $term)
            ->whereNotIn('id', $withData)
            ->orderBy('type')->orderBy('name')
            ->get();
    }

    /**
     * The section ids the scope covers: one section, every section of a
     * grade level in the year, or null for "every section". An explicit
     * section that is not in the year (or grade level) yields an empty
     * set, never a silent fall-back to the whole school.
     *
     * @return ?array<int, int>
     */
    private function scopedSectionIds(string $schoolYear, ?int $sectionId, ?int $gradeLevel): ?array
    {
        if (!$sectionId && !$gradeLevel) {
            return null;
        }

        return Section::where('school_year', $schoolYear)
            ->when($gradeLevel, fn($q) => $q->where('grade_level', $gradeLevel))
            ->when($sectionId, fn($q) => $q->where('id', $sectionId))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /** Subject ids with at least one Grade row this scope — so a subject with grades but zero assessment_scores still appears. */
    private function subjectIdsWithGrades(string $schoolYear, ?array $sectionIds, ?int $term): \Illuminate\Support\Collection
    {
        return Grade::where('school_year', $schoolYear)
            ->when($sectionIds !== null, fn($q) => $q->whereIn('section_id', $sectionIds))
            ->when($term, fn($q) => $q->where('grading_period', $term))
            ->distinct()
            ->pluck('subject_id');
    }

    /** Subject ids with at least one OPEN academic concern in this scope. */
    private function subjectIdsWithOpenConcerns(string $schoolYear, ?array $sectionIds, ?int $term): \Illuminate\Support\Collection
    {
        return $this->openConcernsQuery($schoolYear, $sectionIds, $term)->distinct()->pluck('subject_id');
    }

    /**
     * @return array<int, array{graded_count: int, failing_count: int}>
     */
    private function getFailureStats(string $schoolYear, ?array $sectionIds, ?int $term): array
    {
        $graded = Grade::where('school_year', $schoolYear)
            ->when($sectionIds !== null, fn($q) => $q->whereIn('section_id', $sectionIds))
            ->when($term, fn($q) => $q->where('grading_period', $term))
            ->where('is_verified', true)
            ->where('is_provisional', false)
            ->whereNotNull('grade')
            ->select('subject_id', DB::raw('COUNT(*) as graded_count'))
            ->groupBy('subject_id')
            ->pluck('graded_count', 'subject_id');

        $failing = Grade::where('school_year', $schoolYear)
            ->when($sectionIds !== null, fn($q) => $q->whereIn('section_id', $sectionIds))
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

    /**
     * @return array<int, array{concerns: int, learners: int, subject_performance: int, missing: int}>
     *         [subject_id => open concern and learner counts, split by concern type]
     */
    private function getOpenConcernCountsBySubject(string $schoolYear, ?array $sectionIds, ?int $term): array
    {
        return $this->openConcernsQuery($schoolYear, $sectionIds, $term)
            ->select('subject_id', DB::raw('COUNT(*) as concerns'), DB::raw('COUNT(DISTINCT student_id) as learners'))
            ->selectRaw('SUM(CASE WHEN rule_key = ? THEN 1 ELSE 0 END) as subject_performance', [\App\Monitoring\Rules\OfficialFailingSubjectRule::KEY])
            ->selectRaw('SUM(CASE WHEN rule_key = ? THEN 1 ELSE 0 END) as missing', [\App\Monitoring\Rules\MissingRequirementRule::KEY])
            ->groupBy('subject_id')
            ->get()
            ->mapWithKeys(fn($row) => [(int) $row->subject_id => [
                'concerns' => (int) $row->concerns, 'learners' => (int) $row->learners,
                'subject_performance' => (int) $row->subject_performance, 'missing' => (int) $row->missing,
            ]])
            ->all();
    }

    /**
     * OPEN academic concerns in the selected scope — the rule-based
     * monitoring source of truth. Each concern stores the section, term and
     * year it was raised in, so a historical year stays correctly scoped
     * after promotion.
     */
    private function openConcernsQuery(string $schoolYear, ?array $sectionIds, ?int $term)
    {
        // Active rules only (a retired rule's rows — the legacy Attendance
        // Concern — are history), and only concerns tied to a subject.
        return AcademicConcern::where('status', AcademicConcern::STATUS_OPEN)
            ->activeRules()
            ->whereNotNull('subject_id')
            ->where('school_year', $schoolYear)
            ->when($sectionIds !== null, fn($q) => $q->whereIn('section_id', $sectionIds))
            ->when($term, fn($q) => $q->where('grading_period', $term));
    }

    /**
     * Per-student, per-component totals (SUM earned / SUM of every item's
     * HPS — the same aggregation GradingEngine uses for WW/PT, unlike getSubjectSummaries()'s own
     * AVG-of-item-percentages above, which is a subject-wide summary
     * only) — grouped down to one query so counting "how many students
     * are below target" never turns into a per-student loop.
     *
     * @return array<int, array<string, int>> [subject_id => [component => count below target]]
     */
    private function getBelowTargetCounts(string $schoolYear, ?array $sectionIds = null, ?int $term = null): array
    {
        $perStudentTotals = DB::table('assessment_scores')
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.school_year', $schoolYear)
            ->when($sectionIds !== null, fn($q) => $q->whereIn('assessments.section_id', $sectionIds))
            ->when($term, fn($q) => $q->where('assessments.grading_period', $term))
            ->select(
                'assessments.subject_id',
                'assessments.component',
                'assessments.section_id',
                'assessments.grading_period',
                'assessment_scores.student_id',
                DB::raw('SUM(assessment_scores.score) as earned'),
                DB::raw('SUM(CASE WHEN assessments.is_additional_support THEN assessments.max_score ELSE 0 END) as support_hps')
            )
            ->groupBy('assessments.subject_id', 'assessments.component', 'assessments.section_id', 'assessments.grading_period', 'assessment_scores.student_id')
            ->get();

        // The workbook denominator (D1, 2026-10-04): the HPS of EVERY item in
        // the learner's class/term/component, scored or not — as GradingEngine.
        $hpsTotals = DB::table('assessments')->where('school_year', $schoolYear)->where('is_additional_support', false)
            ->when($sectionIds !== null, fn($q) => $q->whereIn('section_id', $sectionIds))
            ->when($term, fn($q) => $q->where('grading_period', $term))
            ->selectRaw('subject_id, section_id, grading_period, component, SUM(max_score) as hps')
            ->groupBy('subject_id', 'section_id', 'grading_period', 'component')->get()
            ->mapWithKeys(fn($r) => ["{$r->subject_id}|{$r->section_id}|{$r->grading_period}|{$r->component}" => (float) $r->hps]);

        $totals = [];
        foreach ($perStudentTotals as $row) {
            $key = "{$row->subject_id}|{$row->component}|{$row->student_id}";
            $totals[$key]['subject_id'] = $row->subject_id;
            $totals[$key]['component'] = $row->component;
            $totals[$key]['earned'] = ($totals[$key]['earned'] ?? 0.0) + (float) $row->earned;
            $totals[$key]['max_total'] = ($totals[$key]['max_total'] ?? 0.0)
                + ($hpsTotals["{$row->subject_id}|{$row->section_id}|{$row->grading_period}|{$row->component}"] ?? 0.0)
                + (float) $row->support_hps; // support items: only for the learners scored on them
        }

        $counts = [];
        foreach ($totals as $row) {
            $row = (object) $row;
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
