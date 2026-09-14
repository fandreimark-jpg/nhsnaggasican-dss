<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Section Model
 *
 * Represents a class section at Naggasican NHS.
 * Each section belongs to a Track and Specialization,
 * and is assigned one Adviser.
 *
 * Grade levels: 11 or 12 (Senior High School only)
 */
class Section extends Model
{
    use \App\Models\Concerns\ProtectsAcademicHistory;

    use HasFactory;

    // Fields that can be mass-assigned
    protected $fillable = [
        'name',               // Section name e.g. 'Narraa', 'Alber'
        'grade_level',        // 11 or 12
        'curriculum',         // 'sshs' or 'k12_2013' — what TransmutationService::schemeFor() actually reads now; null falls back to grade-level inference
        'track_id',           // Academic or TechPro track
        'specialization_id',  // e.g. HUMSS, STEM, ICT
        'adviser_id',         // Assigned adviser (nullable — can be unassigned)
        'school_year',        // e.g. '2026-2027'
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /** Section is assigned to one adviser (User with role='adviser') */
    public function adviser()
    {
        return $this->belongsTo(User::class, 'adviser_id');
    }

    /**
     * Students whose CURRENT section is this one (students.section_id).
     * For the active school year this is the roster. For a historical
     * section it empties out as learners are promoted — use
     * enrolledStudents() to read the roster as it was that year.
     */
    public function students()
    {
        return $this->hasMany(Student::class);
    }

    /**
     * "Multi-school-year academic history" work order, PART 5/6 — the
     * enrollment rows that place a learner in this section for its
     * school year. This is the roster that survives promotion.
     */
    public function enrollments()
    {
        // No school_year constraint needed: an enrollment row always carries
        // the school_year of the section it points at (see
        // StudentEnrollmentService::writeEnrollment()), and a plain relation
        // stays eager-loadable.
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * The learners enrolled in this section for ITS school year, read
     * from student_enrollments rather than students.section_id, so a
     * Grade 11 section from 2026-2027 still lists its 2026-2027 roster
     * after those learners move to Grade 12 sections in 2027-2028.
     */
    public function enrolledStudents()
    {
        return $this->belongsToMany(Student::class, 'student_enrollments')
            ->withPivot(['school_year', 'grade_level', 'curriculum', 'status'])
            ->withTimestamps();
    }

    /** Section belongs to a track (Academic or TechPro) */
    public function track()
    {
        return $this->belongsTo(Track::class);
    }

    /** Section belongs to a specialization (HUMSS, STEM, etc.) */
    public function specialization()
    {
        return $this->belongsTo(Specialization::class);
    }

    /** Section has many report submissions (one per term) */
    public function reportSubmissions()
    {
        return $this->hasMany(ReportSubmission::class);
    }

    /** Section has many assessment items (Quiz 1, Performance Task 1, etc.) */
    public function assessments()
    {
        return $this->hasMany(Assessment::class);
    }

    /** Risk results generated while learners were in THIS section (risk_results.section_id) — PART 11. */
    public function riskResults()
    {
        return $this->hasMany(RiskResult::class);
    }

    /** Interventions recorded while learners were in THIS section (interventions.section_id) — PART 12. */
    public function interventions()
    {
        return $this->hasMany(Intervention::class);
    }

    /** Grades encoded under this section (grades.section_id). */
    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    /** The AcademicYear this section belongs to, via its school_year string. */
    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'school_year', 'school_year');
    }

    /**
     * True when this section belongs to the active school year — the
     * only year Advisers may write into. A section from any other year
     * is a historical record.
     */
    public function isInActiveSchoolYear(): bool
    {
        return $this->school_year === static::activeSchoolYear();
    }

    /**
     * The section an adviser currently works in. Before the multi-year
     * work order every Adviser controller did `where('adviser_id', ...)
     * ->first()`, which returns the OLDEST row by id — so the moment an
     * adviser was assigned a 2027-2028 section, every Adviser screen
     * kept showing their 2026-2027 one. This prefers the active school
     * year's section and falls back to the most recent historical one
     * (shown read-only) when the adviser has no section this year.
     */
    public static function forAdviser(int $userId): ?self
    {
        $query = static::where('adviser_id', $userId);

        return (clone $query)->where('school_year', static::activeSchoolYear())->orderByDesc('id')->first()
            ?? $query->orderByDesc('id')->first();
    }

    /**
     * The school year currently in use system-wide. Reads
     * AcademicYear::active() first — the explicit, admin-configurable
     * flag (SYSTEM_FIXES_AND_ML_AUDIT.md, "Remove Hardcoded Academic
     * Year") — so an admin can activate a NEW school year before any
     * section exists in it. Falls back to the most recently created
     * section's school_year (this method's original behavior, kept for
     * every database that predates the academic_years table and for a
     * fresh install where nobody has explicitly activated a year yet),
     * and only as a last resort to a computed (never hardcoded) default.
     *
     * Shared by every consumer that needs "the current school year" —
     * assessments, grades, reports, risk classification, interventions,
     * imports, and every dashboard — so all of them agree on which year
     * is active instead of each independently guessing.
     */
    public static function activeSchoolYear(): string
    {
        return \App\Models\AcademicYear::active()?->school_year
            ?? static::orderByDesc('id')->value('school_year')
            ?? date('Y') . '-' . (date('Y') + 1);
    }
}