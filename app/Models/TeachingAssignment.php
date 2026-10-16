<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One teacher's responsibility for one subject in one section, for one
 * term of the section's school year. This — not sections.adviser_id — is
 * what grants the right to encode or edit assessment evidence
 * (App\Services\TeachingAccess). Advising a section grants reading only.
 *
 * Recorded by the Admin (Admin > Teaching Assignments). Never inferred
 * from who uploaded a score.
 */
class TeachingAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'section_id',
        'subject_id',
        'school_year',
        'grading_period',
        'assigned_by',
    ];

    protected $casts = [
        'grading_period' => 'integer',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
