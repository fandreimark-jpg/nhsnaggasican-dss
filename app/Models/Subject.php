<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Subject Model
 *
 * Represents a SHS subject at Naggasican NHS.
 *
 * Two types:
 * - 'core'     → applies to ALL sections of the same grade level
 *                (e.g. Effective Communication, General Mathematics)
 * - 'elective' → specific to a track and optionally a specialization
 *                (e.g. Programming — ICT only)
 *
 * Grade levels: 11 or 12 only (Senior High School).
 */
class Subject extends Model
{
    use HasFactory;

    // Fields that can be mass-assigned
    protected $fillable = [
        'name',               // e.g. 'General Mathematics'
        'type',               // 'core' or 'elective'
        'grade_level',        // 11 or 12
        'subject_group',      // which subject_group_weights row applies — see that table's migration
        'track_id',           // null for core, required for elective
        'specialization_id',  // null if applies to whole track, specific if specialization-only
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /**
     * Subject belongs to a track (only for elective subjects)
     * Core subjects have track_id = null
     */
    public function track()
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * Subject belongs to a specialization (optional, even for electives)
     * If specialization_id is null — applies to all specializations in the track
     */
    public function specialization()
    {
        return $this->belongsTo(Specialization::class);
    }

    /** Subject has many grade records (one per student per term) */
    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    /** Subject has many individual assessment items (evidence, not the official grade) */
    public function assessments()
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * Get the subjects applicable to a given section — core subjects for
     * that grade level, plus elective subjects matching the section's
     * track/specialization. Shared logic used by both grade encoding
     * AND the term-completion check, so both always count the same set.
     */
    public static function forSection(Section $section)
    {
        return static::where('grade_level', $section->grade_level)
            ->where(function ($query) use ($section) {
                $query->where('type', 'core')
                    ->orWhere(function ($q) use ($section) {
                        $q->where('type', 'elective')
                          ->where('track_id', $section->track_id)
                          ->where(function ($q2) use ($section) {
                              $q2->whereNull('specialization_id')
                                 ->orWhere('specialization_id', $section->specialization_id);
                          });
                    });
            });
    }
}