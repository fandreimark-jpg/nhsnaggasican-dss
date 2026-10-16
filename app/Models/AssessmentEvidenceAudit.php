<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only history of one learner's evidence on one assessment item.
 * Written only by App\Services\EvidenceAudit from model events; never
 * updated or deleted by application code.
 */
class AssessmentEvidenceAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'assessment_id', 'assessment_name', 'student_id', 'section_id', 'subject_id',
        'school_year', 'grading_period', 'old_score', 'new_score', 'old_status',
        'new_status', 'source', 'reason', 'changed_by', 'changed_at',
    ];

    protected $casts = [
        'old_score'  => 'decimal:2',
        'new_score'  => 'decimal:2',
        'changed_at' => 'datetime',
    ];

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
