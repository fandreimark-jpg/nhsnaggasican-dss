<?php

namespace App\Monitoring;

/**
 * What one evaluation looks at: one school year, one term, and the
 * sections of that year in scope (every section of the year, or one).
 */
final class EvaluationScope
{
    /** @param list<int> $sectionIds */
    public function __construct(
        public readonly string $schoolYear,
        public readonly int $gradingPeriod,
        public readonly array $sectionIds,
    ) {
    }
}
