<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\AssessmentStatusService;
use Illuminate\Database\Eloquent\Model;

/**
 * AssessmentScore
 * ---------------
 * One student's earned score on one Assessment item. Kept separate from
 * the item definition (Assessment) so name/max_score/component aren't
 * repeated on every student's row — this table only ever stores what's
 * actually specific to the student: the score itself.
 */
class AssessmentScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_id',
        'student_id',
        'score',
    ];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    /**
     * A recorded score makes the learner Completed, so any Missing marker
     * on the same item goes (AssessmentStatusService's invariant). Here
     * rather than at each call site so every write path — the ECR import
     * included — keeps it. Only a NEWLY created row can have a marker: a
     * marker is refused while a score exists (AssessmentLearnerStatus).
     */
    protected static function booted(): void
    {
        static::saved(function (AssessmentScore $score) {
            if ($score->wasRecentlyCreated) {
                // Model deletes (not a query-builder delete) so the marker's
                // removal is written to the evidence history too.
                AssessmentLearnerStatus::where('assessment_id', $score->assessment_id)
                    ->where('student_id', $score->student_id)
                    ->get()->each->delete();
            }
        });

        // Evidence history (App\Services\EvidenceAudit) — every path that
        // records, changes or clears a score, the import included.
        static::created(function (AssessmentScore $score) {
            \App\Services\EvidenceAudit::record((int) $score->assessment_id, (int) $score->student_id,
                null, (float) $score->score, AssessmentStatusService::NOT_YET_GIVEN, AssessmentStatusService::COMPLETED);
        });
        static::updated(function (AssessmentScore $score) {
            if ($score->wasChanged('score')) {
                \App\Services\EvidenceAudit::record((int) $score->assessment_id, (int) $score->student_id,
                    (float) $score->getOriginal('score'), (float) $score->score, AssessmentStatusService::COMPLETED, AssessmentStatusService::COMPLETED);
            }
        });
        static::deleted(function (AssessmentScore $score) {
            \App\Services\EvidenceAudit::record((int) $score->assessment_id, (int) $score->student_id,
                (float) $score->score, null, AssessmentStatusService::COMPLETED, AssessmentStatusService::NOT_YET_GIVEN);
        });
    }

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** This score as a percentage of the item's maximum — e.g. 18/20 -> 90.00 */
    public function getPercentageAttribute(): ?float
    {
        $maxScore = (float) $this->assessment?->max_score;

        if ($maxScore <= 0) {
            return null;
        }

        return round(((float) $this->score / $maxScore) * 100, 2);
    }
}
