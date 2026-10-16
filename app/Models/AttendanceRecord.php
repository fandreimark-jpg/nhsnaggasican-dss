<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * LEGACY / ARCHIVE ONLY. The Term Attendance Summary was RETIRED on
 * 2026-09-27: no route, page, rule, count or job writes or reads this table
 * any more (the AttendanceConcernRule it fed was removed, and
 * AcademicConcern::scopeActiveRules() keeps any old attendance concern out
 * of monitoring). The model remains only so history protection
 * (ProtectsAcademicHistory) can see existing rows. A future subject-term
 * attendance summary is NOT implemented; its threshold, units,
 * excused-absence handling and resolution rules are not approved.
 * Attendance was never part of a grade.
 */
class AttendanceRecord extends Model
{
    public const MAX_DAYS = 366;

    protected $fillable = [
        'student_id',
        'section_id',
        'school_year',
        'grading_period',
        'school_days',
        'days_present',
        'days_absent',
        'days_tardy',
        'concern_flagged',
        'concern_note',
        'recorded_by',
    ];

    protected $casts = [
        'grading_period'  => 'integer',
        'school_days'     => 'integer',
        'days_present'    => 'integer',
        'days_absent'     => 'integer',
        'days_tardy'      => 'integer',
        'concern_flagged' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** True when any day count or the concern flag holds something. */
    public function hasEvidence(): bool
    {
        return $this->school_days !== null || $this->days_present !== null
            || $this->days_absent !== null || $this->days_tardy !== null
            || $this->concern_flagged;
    }
}
