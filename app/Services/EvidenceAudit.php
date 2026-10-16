<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentEvidenceAudit;

/**
 * Writes the append-only evidence history (assessment_evidence_audits)
 * from AssessmentScore / AssessmentLearnerStatus model events, so every
 * write path is covered without remembering to log. Each row: actor,
 * time, learner, item, school year, term, old/new score and old/new
 * status (completed / missing / not_yet_given), the source (the route
 * that made the change unless a caller names one) and an optional reason.
 *
 * No correction policy in this system requires a reason today; the
 * column exists so a reason given by a caller is kept, never invented.
 */
class EvidenceAudit
{
    private static ?string $source = null;
    private static ?string $reason = null;

    /** @var array<int, Assessment> */
    private static array $assessments = [];

    /** Runs $callback with an explicit source/reason stamped on every audit row it causes. */
    public static function withContext(string $source, ?string $reason, callable $callback): mixed
    {
        [$prevSource, $prevReason] = [self::$source, self::$reason];
        self::$source = $source;
        self::$reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;

        try {
            return $callback();
        } finally {
            [self::$source, self::$reason] = [$prevSource, $prevReason];
        }
    }

    public static function record(int $assessmentId, int $studentId, ?float $oldScore, ?float $newScore, string $oldStatus, string $newStatus): void
    {
        if ($oldStatus === $newStatus && $oldScore === $newScore) {
            return;
        }

        $assessment = self::$assessments[$assessmentId] ??= Assessment::find($assessmentId);

        AssessmentEvidenceAudit::create([
            'assessment_id'   => $assessmentId,
            'assessment_name' => $assessment?->name,
            'student_id'      => $studentId,
            'section_id'      => $assessment?->section_id,
            'subject_id'      => $assessment?->subject_id,
            'school_year'     => $assessment?->school_year,
            'grading_period'  => $assessment?->grading_period,
            'old_score'       => $oldScore,
            'new_score'       => $newScore,
            'old_status'      => $oldStatus,
            'new_status'      => $newStatus,
            'source'          => self::$source ?? (app()->runningInConsole() && !app()->runningUnitTests() ? 'console' : request()?->route()?->getName()),
            'reason'          => self::$reason,
            'changed_by'      => auth()->id(),
            'changed_at'      => now(),
        ]);
    }

    /** Forget cached item snapshots (an item's name can change between requests in one process). */
    public static function flush(): void
    {
        self::$assessments = [];
    }
}
