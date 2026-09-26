<?php

namespace App\Monitoring;

/**
 * One condition a MonitoringRule observed — the evidence a concern is made
 * of. `contextKey` says what within the rule it is about (e.g.
 * "assessment:123"); together with the rule and the learner it identifies
 * the logical concern, so evaluating again finds the same one.
 */
final class ConcernFinding
{
    public function __construct(
        public readonly int $studentId,
        public readonly ?int $subjectId,
        public readonly int $sectionId,
        public readonly string $schoolYear,
        public readonly int $gradingPeriod,
        public readonly string $contextKey,
        /** @var array<string, mixed> */
        public readonly array $evidence,
        /** One line for the dry-run report and logs. */
        public readonly string $summary,
    ) {
    }
}
