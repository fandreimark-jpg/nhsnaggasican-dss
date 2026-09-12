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

    /** Section has many students */
    public function students()
    {
        return $this->hasMany(Student::class);
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