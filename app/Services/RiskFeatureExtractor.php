<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * Computes the CANONICAL ML feature set for one learner-period and hands
 * it to the Python classifier. `analytics/schema.py` is the source of
 * truth for the names, the order and the definitions; this class is the
 * Laravel half of that one contract, and the two must not drift.
 *
 * The nine canonical features are ww_mean, pt_mean, exam_mean,
 * current_average, prev_period_average, trend_delta,
 * failing_subject_count, weak_component_count and
 * missing_assessment_count. `failing_subject_count` is supplied by
 * ReportController, which already holds the per-subject failing grades;
 * everything else is computed here.
 *
 * THE MODEL CURRENTLY DEPLOYED READS ONLY ONE OF THEM. It is a legacy
 * synthetic prototype trained on `average_grade` alone (see
 * `analytics/README.md`). The full set is computed and sent anyway —
 * classify.py builds its feature vector from the loaded model's own
 * declared feature order and ignores the rest — so that the day an
 * authorized historical dataset arrives, Laravel is already producing
 * exactly what `analytics/train_model.py` expects, and switching models
 * is a promotion rather than a rebuild.
 *
 * BLANK IS NOT ZERO. Every feature here is null when there is not enough
 * evidence to compute it (no assessment items yet, no previous period on
 * record, a grading profile with no Examination component at all) rather
 * than a fabricated default. A null crosses to Python as JSON null and
 * becomes NaN, which scikit-learn's tree splitter handles natively; it is
 * never imputed to 0. The distinction is load-bearing: a learner on a
 * Work Immersion profile has no examination, which is not the same
 * academic fact as having scored zero in one.
 */
class RiskFeatureExtractor
{
    private const TARGET = 75.0;

    /**
     * Per (section_id, grading_period, school_year) cache of every
     * student's earned/max sums per component, built once via a single
     * grouped query — see componentSumsFor(). ReportController::
     * buildPythonPayload() reuses ONE RiskFeatureExtractor instance
     * across every student in a section (array_map over $gradesData),
     * so this survives for the whole Submit Report request but never
     * crosses requests (a fresh instance is constructed per request).
     *
     * @var array<string, array<string, array<int, array{earned: float, max: float}>>>
     */
    private array $componentSumsCache = [];

    /**
     * Per (section_id, grading_period) cache of whether an Examination
     * component exists at all — see examComponentApplicable(). Resolving
     * it walks every subject's weight profile, which does not vary across
     * the students of one section, so it is answered once per batch for
     * the same reason $componentSumsCache exists.
     *
     * @var array<string, bool|null>
     */
    private array $examApplicableCache = [];

    /**
     * "Performance audit" pass — the remaining per-student lookups
     * (missing-assessment counts, the previous period's stored average,
     * the previous and current periods' per-subject grades) are answered
     * from these per-batch maps, each filled by ONE query the first time a
     * (period, school year) scope is seen, for the same reason
     * $componentSumsCache exists: the scope does not vary across the
     * students of one Submit Report. Every map is keyed exactly the way
     * the per-student query it replaced was filtered, and a student
     * absent from a map gets exactly what an empty query result gave.
     *
     * @var array<string, int> (section|period|year) => COUNT(*) of assessment items in scope
     */
    private array $expectedAssessmentCountCache = [];
    /** @var array<string, array<int, int>> (section|period|year) => student_id => COUNT(*) of that student's score rows against items in scope */
    private array $recordedScoreCountCache = [];
    /** @var array<string, array<int, float|null>> (year|period) => student_id => risk_results.average_grade */
    private array $riskAverageCache = [];
    /** @var array<string, array<int, array<int, string>>> (year|period) => student_id => [subject_id => grade] */
    private array $gradesByStudentCache = [];

    /**
     * @return array{
     *     ww_mean: float|null,
     *     pt_mean: float|null,
     *     exam_mean: float|null,
     *     current_average: float,
     *     prev_period_average: float|null,
     *     trend_delta: float|null,
     *     weak_component_count: int|null,
     *     missing_assessment_count: int|null,
     *     prev_term_average: float|null,
     *     exam_component_applicable: bool|null,
     *     same_subject_trend_delta: float|null,
     *     subject_composition_changed: bool|null,
     * }
     */
    public function extract(Student $student, Section $section, int $gradingPeriod, string $schoolYear, float $averageGrade): array
    {
        $componentMeans = $this->componentMeans($student, $section, $gradingPeriod, $schoolYear);
        $prevPeriodAverage = $this->previousPeriodAverage($student, $gradingPeriod, $schoolYear);
        $sameSubject = $this->sameSubjectTrend($student, $gradingPeriod, $schoolYear);

        return [
            // --- the nine canonical features (analytics/schema.py) -------
            'ww_mean'              => $componentMeans['written_work'],
            'pt_mean'              => $componentMeans['performance_task'],
            'exam_mean'            => $componentMeans['examination'],
            // The learner's overall average for this period. Passed in by
            // ReportController, which computed it from the term's grades.
            'current_average'      => $averageGrade,
            'prev_period_average'  => $prevPeriodAverage,
            // OVERALL period trend: this period's average vs the previous
            // one's, regardless of whether the same subjects were graded.
            // Null — never 0 — when there is no previous period at all.
            'trend_delta'          => $prevPeriodAverage === null ? null : round($averageGrade - $prevPeriodAverage, 2),
            'weak_component_count' => $this->weakComponentCount($componentMeans),
            'missing_assessment_count' => $this->missingAssessmentCount($student, $section, $gradingPeriod, $schoolYear),
            // failing_subject_count is the ninth; ReportController supplies
            // it, since it already holds the per-subject failing grades.

            // --- context, deliberately NOT features ---------------------
            // Whether an Examination component EXISTS for this learner's
            // subjects at all, which a null exam_mean on its own cannot
            // say: null means either "no examination component on this
            // grading profile" or "the component exists but nothing is
            // recorded yet". Both are correctly blank rather than zero,
            // but they are different facts, and a future feature-
            // engineering decision may want to tell them apart. Sent as
            // context so the distinction is preserved at the point it is
            // known, rather than being unrecoverable later.
            'exam_component_applicable'   => $this->examComponentApplicable($section, $gradingPeriod),
            // SAME-SUBJECT trend ("Student identity and term-specific
            // subject offerings" pass, STEP K): mean change across only the
            // subjects graded in BOTH periods; null when none were. Lets a
            // future retrain tell "the average moved" from "the same
            // subjects moved".
            'same_subject_trend_delta'    => $sameSubject['delta'],
            'subject_composition_changed' => $sameSubject['composition_changed'],

            // --- legacy alias, retained on purpose ----------------------
            // `prev_period_average` is the canonical name. This key is what
            // the payload carried before the canonical schema existed, and
            // is kept so a stored payload or an older reader is still
            // readable. classify.py resolves either spelling
            // (FEATURE_ALIASES); nothing new should consume this one.
            'prev_term_average'    => $prevPeriodAverage,
        ];
    }

    /**
     * How many of this learner's expected assessment records are genuinely
     * ABSENT for the period: an assessment item exists for their section,
     * period and school year, and they have no score row against it.
     *
     * A RECORDED SCORE OF 0 IS NOT COUNTED. `assessment_scores.score` is
     * NOT NULL and the table is unique per (assessment, student), so "the
     * adviser entered a zero" is a row that exists and "nothing was
     * recorded" is a row that does not. That is exactly the distinction
     * this feature carries, and it is why this counts missing ROWS rather
     * than summing or thresholding scores.
     *
     * Null — not 0 — when the section has no assessment items for the
     * period at all: with nothing expected, "how many are missing" has no
     * answer, and reporting 0 would claim complete evidence where there is
     * none. That is the same "missing means incomplete, not zero" rule
     * design decision 1 in CLAUDE.md applies one level up.
     */
    private function missingAssessmentCount(Student $student, Section $section, int $gradingPeriod, string $schoolYear): ?int
    {
        $scopeKey = $section->id . '|' . $gradingPeriod . '|' . $schoolYear;

        // COUNT(*) of items in scope — the same for every student in the
        // batch, so asked once.
        $this->expectedAssessmentCountCache[$scopeKey] ??= Assessment::where('section_id', $section->id)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $schoolYear)
            ->count();
        $expected = $this->expectedAssessmentCountCache[$scopeKey];

        if ($expected === 0) {
            return null;
        }

        // The per-student COUNT(*) this used to run, grouped by student in
        // one query; a student with no score rows is simply absent -> 0.
        if (!isset($this->recordedScoreCountCache[$scopeKey])) {
            $this->recordedScoreCountCache[$scopeKey] = AssessmentScore::query()
                ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
                ->where('assessments.section_id', $section->id)
                ->where('assessments.grading_period', $gradingPeriod)
                ->where('assessments.school_year', $schoolYear)
                ->groupBy('assessment_scores.student_id')
                ->selectRaw('assessment_scores.student_id as student_id, COUNT(*) as recorded')
                ->get()
                ->pluck('recorded', 'student_id')
                ->map(fn($n) => (int) $n)
                ->all();
        }
        $recorded = $this->recordedScoreCountCache[$scopeKey][$student->id] ?? 0;

        return max(0, $expected - $recorded);
    }

    /**
     * True when at least one subject offered to this section for this
     * period carries an Examination component, false when none does, null
     * when the section has no resolvable subjects.
     *
     * Reads the SAME resolution GradingEngine uses (`resolveWeightProfile`,
     * catalog row first, then subject-group weights), so this cannot
     * disagree with how the grade itself was computed. A null `ex_weight`
     * there means the component does not exist for that subject — see
     * CLAUDE.md, "The Examination role split is per subject, not
     * universal".
     */
    private function examComponentApplicable(Section $section, int $gradingPeriod): ?bool
    {
        $cacheKey = $section->id . '|' . $gradingPeriod;

        if (array_key_exists($cacheKey, $this->examApplicableCache)) {
            return $this->examApplicableCache[$cacheKey];
        }

        // forSection() returns a QUERY, not a collection — resolve it once
        // here rather than leaving a lazily-evaluated builder in a cache.
        $subjects = Subject::forSection($section, $gradingPeriod)->get();

        if ($subjects->isEmpty()) {
            return $this->examApplicableCache[$cacheKey] = null;
        }

        $engine = new GradingEngine();
        $applicable = false;
        foreach ($subjects as $subject) {
            if ($engine->resolveWeightProfile($section, $subject)['ex_weight'] !== null) {
                $applicable = true;
                break;
            }
        }

        return $this->examApplicableCache[$cacheKey] = $applicable;
    }

    /**
     * @return array{delta: float|null, composition_changed: bool|null}
     */
    private function sameSubjectTrend(Student $student, int $gradingPeriod, string $schoolYear): array
    {
        if ($gradingPeriod <= 1) {
            return ['delta' => null, 'composition_changed' => null];
        }

        $previous = collect($this->gradesByStudentFor($schoolYear, $gradingPeriod - 1)[$student->id] ?? []);

        if ($previous->isEmpty()) {
            return ['delta' => null, 'composition_changed' => null];
        }

        $current = collect($this->gradesByStudentFor($schoolYear, $gradingPeriod)[$student->id] ?? []);

        $sharedIds = $current->keys()->intersect($previous->keys());
        $compositionChanged = $current->keys()->diff($previous->keys())->isNotEmpty()
            || $previous->keys()->diff($current->keys())->isNotEmpty();

        if ($sharedIds->isEmpty()) {
            return ['delta' => null, 'composition_changed' => $compositionChanged];
        }

        $deltas = $sharedIds->map(fn($id) => (float) $current[$id] - (float) $previous[$id]);

        return [
            'delta'               => round($deltas->avg(), 2),
            'composition_changed' => $compositionChanged,
        ];
    }

    /**
     * Each component's percentage aggregated across EVERY subject the
     * student has assessment evidence for this term (not just one
     * subject) — a term-wide signal, distinct from
     * PerformanceAnalysisService's per-subject breakdown.
     */
    private function componentMeans(Student $student, Section $section, int $gradingPeriod, string $schoolYear): array
    {
        $sums = $this->componentSumsFor($section, $gradingPeriod, $schoolYear);

        $means = [];
        foreach (['written_work', 'performance_task', 'examination'] as $key) {
            $studentSum = $sums[$key][$student->id] ?? null;
            $means[$key] = ($studentSum !== null && $studentSum['max'] > 0)
                ? round(($studentSum['earned'] / $studentSum['max']) * 100, 2)
                : null;
        }

        return $means;
    }

    /**
     * Used to run 3 identical Assessment::where('section_id', ...)
     * ->pluck('id') queries PER STUDENT — section_id/grading_period/
     * school_year don't vary across students in the same section, so
     * every student re-ran the exact same lookup. This runs ONCE per
     * (section, period, school year) via a single grouped join query
     * covering all 3 components at once, cached on $this so every
     * subsequent student in the same batch reads from memory instead of
     * re-querying (see the class docblock).
     *
     * A student with no rows for a component (either no assessment
     * items exist for it at all, or items exist but this student has
     * no scores yet) simply doesn't appear in that component's array —
     * componentMeans() above treats that as null, matching the original
     * per-student implementation's "not enough evidence" behavior.
     *
     * @return array<string, array<int, array{earned: float, max: float}>>
     */
    private function componentSumsFor(Section $section, int $gradingPeriod, string $schoolYear): array
    {
        $cacheKey = $section->id . '|' . $gradingPeriod . '|' . $schoolYear;

        if (isset($this->componentSumsCache[$cacheKey])) {
            return $this->componentSumsCache[$cacheKey];
        }

        $rows = AssessmentScore::query()
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.section_id', $section->id)
            ->where('assessments.grading_period', $gradingPeriod)
            ->where('assessments.school_year', $schoolYear)
            ->select(
                'assessments.component',
                'assessment_scores.student_id',
                DB::raw('SUM(assessment_scores.score) as earned'),
                DB::raw('SUM(assessments.max_score) as max_total')
            )
            ->groupBy('assessments.component', 'assessment_scores.student_id')
            ->get();

        $sums = ['written_work' => [], 'performance_task' => [], 'examination' => []];
        foreach ($rows as $row) {
            $sums[$row->component][$row->student_id] = [
                'earned' => (float) $row->earned,
                'max'    => (float) $row->max_total,
            ];
        }

        return $this->componentSumsCache[$cacheKey] = $sums;
    }

    private function weakComponentCount(array $componentMeans): ?int
    {
        $available = array_filter($componentMeans, fn($v) => $v !== null);

        if (empty($available)) {
            return null;
        }

        return count(array_filter($available, fn($v) => $v < self::TARGET));
    }

    private function previousPeriodAverage(Student $student, int $gradingPeriod, string $schoolYear): ?float
    {
        if ($gradingPeriod <= 1) {
            return null;
        }

        $scopeKey = $schoolYear . '|' . ($gradingPeriod - 1);

        // risk_results is unique per (student, period, year), so one row
        // per student at most — the map holds exactly what value() returned.
        if (!isset($this->riskAverageCache[$scopeKey])) {
            $this->riskAverageCache[$scopeKey] = RiskResult::where('school_year', $schoolYear)
                ->where('grading_period', $gradingPeriod - 1)
                ->orderBy('id')
                ->get(['student_id', 'average_grade'])
                ->reduce(function (array $map, RiskResult $row) {
                    $map[$row->student_id] ??= $row->average_grade;
                    return $map;
                }, []);
        }

        $value = $this->riskAverageCache[$scopeKey][$student->id] ?? null;

        return $value !== null ? (float) $value : null;
    }

    /**
     * Every student's per-subject grades for one (school year, period) —
     * the two pluck('grade', 'subject_id') queries sameSubjectTrend() used
     * to run per student, loaded once per scope. grades is unique per
     * (student, subject, period, year), so the inner map is exactly what
     * the pluck produced. Not scoped to the section on purpose: the
     * original query was not either (a learner's previous-period grades
     * count wherever they were earned that year).
     *
     * @return array<int, array<int, string>> student_id => [subject_id => grade]
     */
    private function gradesByStudentFor(string $schoolYear, int $gradingPeriod): array
    {
        $scopeKey = $schoolYear . '|' . $gradingPeriod;

        if (!isset($this->gradesByStudentCache[$scopeKey])) {
            $map = [];
            $rows = Grade::where('school_year', $schoolYear)
                ->where('grading_period', $gradingPeriod)
                ->orderBy('id')
                ->get(['student_id', 'subject_id', 'grade']);
            foreach ($rows as $row) {
                $map[$row->student_id][$row->subject_id] = $row->grade;
            }
            $this->gradesByStudentCache[$scopeKey] = $map;
        }

        return $this->gradesByStudentCache[$scopeKey];
    }
}
