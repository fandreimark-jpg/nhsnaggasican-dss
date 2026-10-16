<?php

namespace App\Services;

use App\Models\Grade;
use Illuminate\Support\Collection;

/**
 * SUBJECT FIRST: a learner's official results evaluated subject by subject,
 * so a strong overall average can never hide a failing subject.
 *
 * Works on grade rows the caller already has (its only queries: staleOfficial()), and
 * uses the app's existing official rules only:
 *   - official  = verified and not provisional (Grade::scopeFailing()'s own
 *                 definition of an official grade);
 *   - Failed    = official and InTermStatusService::isFailing() (at or below
 *                 FAILING_THRESHOLD, DepEd's failing mark);
 *   - Passed    = official and not Failing;
 *   - a grade that is not yet official is neither Passed nor Failed — it is
 *     counted as "not yet official" and never labelled Failed.
 * The overall average is summary information over the official grades; it
 * never decides anything.
 */
final class SubjectResultSummary
{
    /**
     * @param iterable<Grade> $grades one learner's grade rows (subject relation loaded for names)
     * @return array{
     *     overall_average: ?float, subjects: int, passed: int, failed: int, not_official: int,
     *     failed_subjects: list<array{subject: string, term: int, grade: float}>, stale: int, stale_subjects: list<string>
     * }
     */
    public static function fromGrades(iterable $grades, ?int $term = null): array
    {
        $rows = collect($grades)->when($term !== null, fn(Collection $c) => $c->where('grading_period', $term));

        $official = $rows->filter(fn(Grade $g) => $g->is_verified && !$g->is_provisional && $g->grade !== null);
        $failed = $official->filter(fn(Grade $g) => InTermStatusService::isFailing($g));
        $stale = self::staleOfficial($official);

        return [
            'overall_average' => $official->isNotEmpty() ? round((float) $official->avg(fn(Grade $g) => (float) $g->grade), 2) : null,
            'subjects'        => $rows->count(),
            'passed'          => $official->count() - $failed->count(),
            'failed'          => $failed->count(),
            'not_official'    => $rows->count() - $official->count(),
            'failed_subjects' => $failed->map(fn(Grade $g) => [
                'subject' => $g->subject?->name ?? 'Subject #' . $g->subject_id,
                'term'    => (int) $g->grading_period,
                'grade'   => (float) $g->grade,
            ])->sortBy('subject')->values()->all(),
            // Official grades whose evidence changed since verification
            // (ClassRecordService::evidenceChanged()): still the official
            // record, shown with "evidence changed — re-verify".
            'stale'           => $stale->count(),
            'stale_subjects'  => $stale->map(fn(Grade $g) => $g->subject?->name ?? 'Subject #' . $g->subject_id)->sort()->values()->all(),
        ];
    }

    /**
     * The official grades among $official whose evidence changed. The one
     * query-bearing step here: models are batch-loaded and GradingEngine
     * reads each class's evidence once.
     *
     * @param Collection<int, Grade> $official
     * @return Collection<int, Grade>
     */
    public static function staleOfficial(Collection $official): Collection
    {
        if ($official->isEmpty()) {
            return $official;
        }
        (new \Illuminate\Database\Eloquent\Collection($official->all()))->loadMissing(['student', 'subject', 'section']);
        $engine = new GradingEngine();

        return $official->filter(function (Grade $g) use ($engine) {
            if (!$g->student || !$g->subject || !$g->section) {
                return false;
            }
            try {
                $computed = $engine->computeGrade($g->student, $g->subject, $g->section, (int) $g->grading_period, $g->school_year);
            } catch (\RuntimeException) {
                return false; // unclassified subject: nothing can be computed, so no comparison (the report still loads)
            }

            return ClassRecordService::evidenceChanged($g, $computed);
        })->values();
    }
}
