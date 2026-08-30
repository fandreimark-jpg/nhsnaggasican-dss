<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Track Model
 *
 * Represents a Senior High School track at Naggasican NHS.
 * Current tracks: Academic Track, Technical-Professional Track.
 *
 * Tracks are the top-level grouping for sections and specializations.
 * Each section belongs to one track.
 */
class Track extends Model
{
    use HasFactory;

    // Fields that can be mass-assigned
    protected $fillable = [
        'name',  // e.g. 'Academic Track'
        'code',  // e.g. 'ACAD' — short identifier, always uppercase
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /** A track has many specializations (HUMSS, STEM, ABM, etc.) */
    public function specializations()
    {
        return $this->hasMany(Specialization::class);
    }

    /** A track has many subjects (elective subjects are track-specific) */
    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    /** A track has many sections (each section belongs to one track) */
    public function sections()
    {
        return $this->hasMany(Section::class);
    }
}