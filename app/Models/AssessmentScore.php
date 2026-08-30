<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
