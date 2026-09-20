<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per academic term a subject is TAUGHT in — the "Terms Taught"
 * configuration set on Admin > Subjects ("Subject applicability"
 * refactor, 2026-09-20). `term` is the same integer every academic table
 * keys as grading_period (1..3); AcademicTerm::termNumbers() is the set
 * it may be chosen from.
 *
 * This answers "is this subject taught in Term N?" and nothing else. It
 * is NOT whether Term N is open for encoding (AcademicTerm::isOpen()), and
 * NOT which sections take the subject (SubjectApplicabilityService).
 */
class SubjectTerm extends Model
{
    protected $fillable = ['subject_id', 'term'];

    protected $casts = ['term' => 'integer'];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
