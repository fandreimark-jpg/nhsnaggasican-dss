<?php

namespace App\Services;

use App\Exceptions\AssessmentStatusConflictException;
use App\Models\Assessment;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE one place a learner's status on one assessment item is decided.
 *
 *   a score row exists             -> Completed
 *   else an explicit Missing marker -> Missing
 *   else                           -> Not Yet Given / No Evidence Yet
 *
 * Completed is never stored (the score row proves it); Not Yet Given is
 * the absence of both. Missing is recorded only when an adviser says so —
 * never inferred from a blank ECR cell or a blank form field, because a
 * blank does not say WHY there is no score.
 *
 * None of this reaches grading. GradingEngine reads assessment_scores only,
 * so Missing and Not Yet Given both stay "no evidence" there — never a 0.
 *
 * Invariant: a learner never has a score AND a Missing marker on the same
 * item. Marking Missing refuses while a score exists; recording a score
 * removes any Missing marker (here, and in AssessmentScore's saved hook,
 * which covers the ECR import path too).
 */
class AssessmentStatusService
{
    public const COMPLETED = 'completed';
    public const MISSING = 'missing';
    public const NOT_YET_GIVEN = 'not_yet_given';

    public const LABELS = [
        self::COMPLETED     => 'Completed',
        self::MISSING       => 'Missing',
        self::NOT_YET_GIVEN => 'Not Yet Given / No Evidence Yet',
    ];

    /** The precedence rule itself — a score always wins. */
    public static function resolve(bool $hasScore, bool $markedMissing): string
    {
        return match (true) {
            $hasScore      => self::COMPLETED,
            $markedMissing => self::MISSING,
            default        => self::NOT_YET_GIVEN,
        };
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public function statusFor(Assessment $assessment, int $studentId): string
    {
        $hasScore = AssessmentScore::where('assessment_id', $assessment->id)
            ->where('student_id', $studentId)
            ->exists();

        $markedMissing = !$hasScore && AssessmentLearnerStatus::where('assessment_id', $assessment->id)
            ->where('student_id', $studentId)
            ->where('status', AssessmentLearnerStatus::STATUS_MISSING)
            ->exists();

        return self::resolve($hasScore, $markedMissing);
    }

    /**
     * Every learner's status on each item, for display — two queries for
     * the whole set.
     *
     * @param Collection<int, Assessment> $assessments
     * @param iterable<int> $studentIds
     * @return array<int, array<int, string>> [assessment_id][student_id] => status
     */
    public function statusesFor(Collection $assessments, iterable $studentIds): array
    {
        $ids = $assessments->pluck('id');
        $scored = $this->pairs(AssessmentScore::whereIn('assessment_id', $ids));
        $missing = $this->pairs(AssessmentLearnerStatus::whereIn('assessment_id', $ids)
            ->where('status', AssessmentLearnerStatus::STATUS_MISSING));

        $result = [];
        foreach ($ids as $assessmentId) {
            foreach ($studentIds as $studentId) {
                $result[$assessmentId][$studentId] = self::resolve(
                    isset($scored[$assessmentId][$studentId]),
                    isset($missing[$assessmentId][$studentId])
                );
            }
        }

        return $result;
    }

    /**
     * Student ids explicitly marked Missing, per item.
     *
     * @return Collection<int, array<int, int>> assessment_id => [student_id, ...]
     */
    public function missingStudentIdsByAssessment(Collection $assessmentIds): Collection
    {
        if ($assessmentIds->isEmpty()) {
            return collect();
        }

        return AssessmentLearnerStatus::whereIn('assessment_id', $assessmentIds)
            ->where('status', AssessmentLearnerStatus::STATUS_MISSING)
            ->get(['assessment_id', 'student_id'])
            ->groupBy('assessment_id')
            ->map(fn($rows) => $rows->pluck('student_id')->map(fn($id) => (int) $id)->values()->all());
    }

    /**
     * Records an explicit Missing marker. Refuses while a score exists —
     * the caller must clear the score first, deliberately; a Missing mark
     * never silently discards a recorded score.
     *
     * @throws AssessmentStatusConflictException
     */
    public function markMissing(Assessment $assessment, int $studentId, int $recordedBy): AssessmentLearnerStatus
    {
        return DB::transaction(function () use ($assessment, $studentId, $recordedBy) {
            $existing = AssessmentLearnerStatus::where('assessment_id', $assessment->id)
                ->where('student_id', $studentId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Already Missing — nothing to change, and the original
                // recorder is kept.
                return $existing;
            }

            // The model's saving hook refuses if a score exists.
            return AssessmentLearnerStatus::create([
                'assessment_id' => $assessment->id,
                'student_id'    => $studentId,
                'status'        => AssessmentLearnerStatus::STATUS_MISSING,
                'recorded_by'   => $recordedBy,
            ]);
        });
    }

    /** Removes a Missing marker, if any. Returns how many rows were removed (0 or 1). */
    public function clearMissing(int $assessmentId, int $studentId): int
    {
        // Model deletes so the removal reaches the evidence history.
        $markers = AssessmentLearnerStatus::where('assessment_id', $assessmentId)
            ->where('student_id', $studentId)
            ->get();
        $markers->each->delete();

        return $markers->count();
    }

    /**
     * Saves a score and removes any Missing marker, together. The score is
     * exactly what the caller validated — never a substitute value.
     */
    public function recordScore(Assessment $assessment, int $studentId, float $score): AssessmentScore
    {
        return DB::transaction(function () use ($assessment, $studentId, $score) {
            $this->clearMissing($assessment->id, $studentId);

            return AssessmentScore::updateOrCreate(
                ['assessment_id' => $assessment->id, 'student_id' => $studentId],
                ['score' => $score]
            );
        });
    }

    /** @return array<int, array<int, true>> [assessment_id][student_id] => true */
    private function pairs($query): array
    {
        $pairs = [];
        foreach ($query->get(['assessment_id', 'student_id']) as $row) {
            $pairs[(int) $row->assessment_id][(int) $row->student_id] = true;
        }

        return $pairs;
    }
}
