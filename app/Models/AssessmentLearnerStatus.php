<?php

namespace App\Models;

use App\Exceptions\AssessmentStatusConflictException;
use Illuminate\Database\Eloquent\Model;

/**
 * An explicit, adviser-recorded status for one learner on one assessment
 * item — today only 'missing'. Completed is never stored here (a score
 * row already proves it) and Not Yet Given is the absence of both.
 *
 * Read and written through App\Services\AssessmentStatusService; never
 * read by GradingEngine, so a marker can never become a zero.
 */
class AssessmentLearnerStatus extends Model
{
    public const STATUS_MISSING = 'missing';

    protected $fillable = [
        'assessment_id',
        'student_id',
        'status',
        'recorded_by',
    ];

    /**
     * Last line of defence for the one invariant: a learner never has a
     * score AND a Missing marker on the same item. The service already
     * refuses this; this catches any other write path.
     */
    protected static function booted(): void
    {
        static::saving(function (AssessmentLearnerStatus $status) {
            $hasScore = AssessmentScore::where('assessment_id', $status->assessment_id)
                ->where('student_id', $status->student_id)
                ->exists();

            if ($hasScore) {
                throw new AssessmentStatusConflictException(
                    'This learner already has a recorded score for this assessment item. Clear the score before marking the learner Missing.'
                );
            }
        });

        // Evidence history (App\Services\EvidenceAudit). A marker can only
        // exist without a score, so its old/new "other" state is Not Yet Given.
        static::created(function (AssessmentLearnerStatus $status) {
            \App\Services\EvidenceAudit::record((int) $status->assessment_id, (int) $status->student_id,
                null, null, \App\Services\AssessmentStatusService::NOT_YET_GIVEN, \App\Services\AssessmentStatusService::MISSING);
        });
        static::deleted(function (AssessmentLearnerStatus $status) {
            \App\Services\EvidenceAudit::record((int) $status->assessment_id, (int) $status->student_id,
                null, null, \App\Services\AssessmentStatusService::MISSING, \App\Services\AssessmentStatusService::NOT_YET_GIVEN);
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

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
