<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Student Model
 *
 * Represents a Senior High School student at Naggasican NHS.
 * Each student belongs to one section and has grades per subject per term.
 */
class Student extends Model
{
    use \App\Models\Concerns\ProtectsAcademicHistory;

    use HasFactory;

    /**
     * Keeps student_enrollments in step with the current-section pointer
     * on EVERY save path (Admin form, imports, factories, promotion):
     * whenever section_id is set or changed, the enrollment row for that
     * section's school year is created or corrected. A row for a
     * DIFFERENT (earlier) school year is never touched here — that is
     * the whole point of keeping history in a separate table.
     */
    protected static function booted(): void
    {
        static::saved(function (Student $student) {
            if ($student->section_id && ($student->wasRecentlyCreated || $student->wasChanged('section_id'))) {
                app(\App\Services\StudentEnrollmentService::class)->syncCurrentEnrollment($student);
            }
        });

        // Runs AFTER ProtectsAcademicHistory's own deleting guard (trait
        // boot listeners register first), so this only ever fires for a
        // learner with NO grades, scores, risk results, or interventions.
        // Enrollment rows are the placement mirror of students.section_id,
        // not academic history in themselves; a learner who was merely
        // added to a roster by mistake can still be removed, roster row
        // included, exactly as before this table existed.
        static::deleting(function (Student $student) {
            $student->enrollments()->delete();
        });
    }

    // Fields that can be mass-assigned
    protected $fillable = [
        'lrn',          // Learner Reference Number — 12 digits, unique
        'last_name',
        'first_name',
        'middle_name',
        'section_id',   // Which section the student belongs to
        'gender',       // 'male' or 'female'
        // birthdate was removed from the learner record in the "Student
        // identity and term-specific subject offerings" pass — the required
        // learner format is lrn / last_name / first_name / middle_name /
        // gender only. The column itself is dropped by
        // 2026_09_18_000001_drop_birthdate_from_students_table.
    ];

    // =============================================
    // ACCESSORS
    // =============================================

    /**
     * Returns formatted full name: Last Name, First Name Middle Name
     * Accessible as $student->full_name
     */
    public function getFullNameAttribute()
    {
        return $this->last_name . ', ' . $this->first_name . ' ' . $this->middle_name;
    }

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /**
     * The student's CURRENT section — the active school year's placement.
     * Never use this to label a historical record: a Grade 11 report from
     * 2026-2027 must show the Grade 11 section even after the learner is
     * promoted into a Grade 12 section for 2027-2028. Historical readers
     * use enrollmentFor()/sectionFor() below, or the section_id stored on
     * the record itself (grades, assessments, report_submissions,
     * risk_results, interventions all carry one).
     */
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * "Multi-school-year academic history" work order, PART 5 — one row
     * per school year the learner was enrolled in. Identity lives on this
     * model (one LRN, one row, forever); placement lives here.
     */
    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class)->orderByDesc('school_year');
    }

    /** The enrollment row for one school year, if the learner was enrolled that year. */
    public function enrollmentFor(string $schoolYear): ?StudentEnrollment
    {
        if ($this->relationLoaded('enrollments')) {
            return $this->enrollments->firstWhere('school_year', $schoolYear);
        }

        return $this->enrollments()->where('school_year', $schoolYear)->first();
    }

    /**
     * The section the learner was in during ONE school year — the
     * historical answer to "which section," falling back to the current
     * section only when it genuinely belongs to that year (a database
     * predating student_enrollments has its rows backfilled, so this
     * fallback is for a row created outside the sync path).
     */
    public function sectionFor(string $schoolYear): ?Section
    {
        $enrollment = $this->enrollmentFor($schoolYear);

        if ($enrollment) {
            return $enrollment->section;
        }

        return ($this->section && $this->section->school_year === $schoolYear) ? $this->section : null;
    }

    /**
     * Roster scope: learners ENROLLED in a section (student_enrollments),
     * which is the roster as it stood in that section's school year. For
     * the active year this is identical to students.section_id (the two
     * are kept in sync); for a historical section it still lists the
     * learners who have since been promoted elsewhere. Every "students of
     * this section" lookup reads this, so no screen loses a historical
     * roster on promotion.
     */
    public function scopeEnrolledIn($query, Section $section)
    {
        return $query->whereIn('id', StudentEnrollment::where('section_id', $section->id)->select('student_id'));
    }

    /** A student has many grade records (one per subject per term) */
    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    /** A student has many individual assessment scores (evidence, not the official grade) */
    public function assessmentScores()
    {
        return $this->hasMany(AssessmentScore::class);
    }

    /** A student has many risk classification results */
    public function riskResults()
    {
        return $this->hasMany(RiskResult::class);
    }

    /**
     * Returns the most recent risk result
     * Uses latestOfMany() — more efficient than orderBy()->first()
     */
    public function latestRisk()
    {
        return $this->hasOne(RiskResult::class)->latestOfMany();
    }
}