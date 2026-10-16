<?php

namespace App\Monitoring;

/**
 * One learner's CURRENT OVERALL MONITORING CLASSIFICATION for a school year
 * (and optionally one term), derived — never stored — from their OPEN
 * academic_concerns (App\Services\MonitoringClassificationService).
 *
 *   0 open concerns   -> No Current Concern
 *   1+ open concerns  -> For Review, with the count and the concern types
 *
 * Deliberately NOT a severity: the number of concerns never becomes Low /
 * Moderate / High, and nothing here predicts anything. It is also NOT the
 * learner's academic performance (In-Term Status: On Track / Below Target /
 * No Evidence Yet), and never the overall average: one failing subject is
 * one Subject Performance Concern however high the other subjects are.
 */
final class MonitoringStatus
{
    public const NO_CURRENT_CONCERN = 'No Current Concern';
    public const FOR_REVIEW = 'For Review';

    /**
     * @param array<string, int> $reasons concern type label => how many open concerns of that type
     * @param list<int> $concernIds
     * @param list<string> $details one evidence line per open concern ("Subject Performance Concern: General Mathematics — official grade 72.00 (Failed)")
     */
    public function __construct(
        public readonly int $studentId,
        public readonly array $reasons = [],
        public readonly array $concernIds = [],
        public readonly array $details = [],
    ) {
    }

    public function openCount(): int
    {
        return count($this->concernIds);
    }

    public function isForReview(): bool
    {
        return $this->openCount() > 0;
    }

    public function classification(): string
    {
        return $this->isForReview() ? self::FOR_REVIEW : self::NO_CURRENT_CONCERN;
    }

    /** "No Current Concern" · "For Review — Subject Performance Concern" · "For Review — 2 Open Concerns" */
    public function headline(): string
    {
        return match (true) {
            !$this->isForReview()    => self::NO_CURRENT_CONCERN,
            $this->openCount() === 1 => self::FOR_REVIEW . ' — ' . array_key_first($this->reasons),
            default                  => self::FOR_REVIEW . ' — ' . $this->openCount() . ' Open Concerns',
        };
    }

    /** "Subject Performance Concern · Missing Requirement (2)" — empty when nothing is open. */
    public function reasonsLine(): string
    {
        return implode(' · ', array_map(
            fn(string $label, int $n) => $n > 1 ? "{$label} ({$n})" : $label,
            array_keys($this->reasons),
            $this->reasons,
        ));
    }
}
