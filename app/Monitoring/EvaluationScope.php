<?php

namespace App\Monitoring;

/**
 * What one evaluation looks at: one school year, one term, the sections of
 * that year in scope (every section of the year, or one) and — for an
 * automatic re-evaluation after evidence changed (Phase 2C) — optionally
 * only the assessment items that changed.
 *
 * A null $assessmentIds means "every item in the sections/term". A rule
 * must apply the same narrowing to its findings AND to the open concerns
 * it reconciles against (MonitoringRule::constrainOpenConcerns()), so a
 * narrow evaluation can never resolve a concern outside its own scope.
 */
final class EvaluationScope
{
    /**
     * @param list<int> $sectionIds
     * @param list<int>|null $assessmentIds
     */
    public function __construct(
        public readonly string $schoolYear,
        public readonly int $gradingPeriod,
        public readonly array $sectionIds,
        public readonly ?array $assessmentIds = null,
    ) {
    }
}
