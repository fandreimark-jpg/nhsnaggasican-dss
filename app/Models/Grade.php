<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Grade Model
 *
 * Stores the grade of a student for a specific subject in a specific term.
 * One record = one student + one subject + one grading period.
 *
 * DepEd SHS Grading Periods:
 * - grading_period 1 = First Term
 * - grading_period 2 = Second Term
 * - grading_period 3 = Third Term
 */
class Grade extends Model
{
    use HasFactory;

    // Fields that can be mass-assigned
    protected $fillable = [
        'student_id',       // Which student
        'subject_id',       // Which subject
        'section_id',       // Which section (for faster querying)
        'encoded_by',       // User ID of the adviser who encoded this grade
        'grading_period',   // 1, 2, or 3
        'grade',            // Numeric grade (60.00 - 100.00) — the OFFICIAL grade
        'computed_grade',   // Derived from assessment evidence by GradingEngine — evidence, not official
        'is_verified',      // Whether an adviser has reviewed computed_grade
        'verified_at',      // When it was verified
        'is_provisional',   // Whether `grade` came from a fallback scheme, not the subject's real one — see TransmutationService::resolve()
        'provisional_scheme', // Which scheme's bands actually produced `grade`, when is_provisional is true
        'school_year',      // e.g. '2026-2027'
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'is_provisional' => 'boolean',
    ];

    // =============================================
    // MONITORING HOOK
    // =============================================

    /**
     * Subject-first monitoring: every official-grade write re-evaluates the
     * Subject Performance Concern for its section and term after commit
     * (App\Monitoring\OfficialGradeSync). Grading itself is untouched.
     */
    protected static function booted(): void
    {
        static::saved(fn(Grade $grade) => \App\Monitoring\OfficialGradeSync::queue($grade));
        static::deleted(fn(Grade $grade) => \App\Monitoring\OfficialGradeSync::queue($grade));
    }

    /**
     * Audit for a typed/imported change to an ALREADY-OFFICIAL grade
     * (Encode Grades store/import). Existing policy is unchanged — the row
     * stays official — but the change is now recorded with both values,
     * where before only "Encoded N grade(s)" was logged. Call with the row
     * as it was BEFORE the write; does nothing for a new or unverified row
     * or an unchanged value.
     */
    public static function logOfficialCorrection(?self $before, $newValue, string $via): void
    {
        if (!$before || !$before->is_verified || $newValue === null || $newValue === '') {
            return;
        }
        if (round((float) $before->grade, 2) === round((float) $newValue, 2)) {
            return;
        }
        $before->loadMissing(['student', 'subject']);
        \App\Helpers\LogActivity::log(
            'correct_official_grade',
            'Changed an official (verified) grade via ' . $via . ' from ' . number_format((float) $before->grade, 2)
                . ' to ' . number_format((float) $newValue, 2) . ' for ' . ($before->student?->last_name . ', ' . $before->student?->first_name)
                . ' — ' . ($before->subject?->name ?? 'subject #' . $before->subject_id) . ', Term ' . $before->grading_period . ' ' . $before->school_year
                . '. It remains marked official (existing behaviour); computed evidence grade ' . ($before->computed_grade !== null ? number_format((float) $before->computed_grade, 2) : '—') . '.',
            'grades',
            $before->id
        );
    }

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /** Grade belongs to a student */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** Grade belongs to a subject */
    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /** The section the grade was encoded under — historical context, stored on the row. */
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    /** The school year this grade belongs to, via its school_year string. */
    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'school_year', 'school_year');
    }

    public function scopeForSchoolYear($query, string $schoolYear)
    {
        return $query->where('school_year', $schoolYear);
    }

    /**
     * The formal Failing determination (official grade <= 74), for SQL
     * contexts (aggregate dashboard counts, etc.) where fetching every
     * Grade model just to call InTermStatusService::isFailing() in PHP
     * would be wasteful. Must stay in lockstep with that method — both
     * read the same InTermStatusService::FAILING_THRESHOLD constant, so
     * there is exactly one place the "74" rule is written.
     */
    public function scopeFailing($query)
    {
        return $query->where('is_verified', true)
                     ->where('is_provisional', false)
                     ->whereNotNull('grade')
                     ->where('grade', '<=', \App\Services\InTermStatusService::FAILING_THRESHOLD);
    }
}