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
     * The school year currently in use system-wide — the most recently
     * created section's school_year, or a fresh default if none exist yet.
     * Shared by AcademicTermController and the Admin dashboard so both
     * agree on which year is "active" instead of each guessing separately.
     */
    public static function activeSchoolYear(): string
    {
        return static::orderByDesc('id')->value('school_year')
            ?? date('Y') . '-' . (date('Y') + 1);
    }
}