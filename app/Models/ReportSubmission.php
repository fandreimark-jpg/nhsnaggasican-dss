<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ReportSubmission Model
 *
 * Records when an adviser submits a term report for their section.
 * One record = one section + one grading period.
 * Used to track which terms have been submitted and when.
 */
class ReportSubmission extends Model
{
    // Fields that can be mass-assigned
    protected $fillable = [
        'section_id',       // Which section submitted the report
        'submitted_by',     // User ID of the adviser who submitted
        'grading_period',   // 1, 2, or 3
        'status',           // 'submitted' (currently only one status)
        'school_year',      // e.g. '2026-2027'
        'submitted_at',     // Timestamp of submission
        'approved_at',      // Timestamp of approval (future use)
    ];

    // Auto-cast these fields to Carbon datetime objects
    // Allows: $submission->submitted_at->format('M d, Y')
    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /**
     * Submission belongs to a section. The section row is itself
     * school-year-specific (sections.school_year), so grade level, track,
     * specialization, and adviser resolved through it are the ones in
     * force when the report was submitted — never the learner's current
     * placement.
     */
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    /** The school year this report belongs to, via its school_year string. */
    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'school_year', 'school_year');
    }

    public function scopeForSchoolYear($query, string $schoolYear)
    {
        return $query->where('school_year', $schoolYear);
    }

    /**
     * Submission was made by an adviser (User)
     * Custom foreign key 'submitted_by' instead of default 'user_id'
     */
    public function adviser()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}