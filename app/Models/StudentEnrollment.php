<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * StudentEnrollment
 * -----------------
 * One learner, in one section, for one school year. This is the
 * HISTORY of where a learner belonged; `students.section_id` is only
 * ever the CURRENT pointer (see the student_enrollments migration for
 * why both exist).
 *
 * A learner moving from Grade 11 to Grade 12 gets a SECOND row here,
 * never a second Student — the LRN/identity stays on the one Student.
 * The old row is never rewritten by a promotion; see
 * StudentEnrollmentService::enroll().
 */
class StudentEnrollment extends Model
{
    public const STATUSES = ['enrolled', 'completed', 'transferred', 'dropped'];

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'school_year',
        'section_id',
        'grade_level',
        'curriculum',
        'status',
        'enrolled_at',
    ];

    protected $casts = [
        'grade_level' => 'integer',
        'enrolled_at' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function scopeForSchoolYear($query, string $schoolYear)
    {
        return $query->where('school_year', $schoolYear);
    }
}
