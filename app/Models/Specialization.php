<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Specialization Model
 *
 * Represents a SHS specialization under a track.
 * Examples:
 * - Academic Track: HUMSS, STEM, ABM
 * - Technical-Professional Track: ICT, HE
 *
 * Each section belongs to one specialization.
 * Elective subjects can be tied to a specific specialization.
 */
class Specialization extends Model
{
    use HasFactory;

    // Fields that can be mass-assigned
    protected $fillable = [
        'track_id',    // Which track this specialization belongs to
        'name',        // e.g. 'Humanities and Social Sciences'
        'code',        // e.g. 'HUMSS' — always uppercase
        'curriculum',  // 'sshs' or 'k12_2013' — see the migration adding this column; (track_id, code) is no longer unique alone
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /** Specialization belongs to one track */
    public function track()
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * Specialization has many subjects
     * These are elective subjects specific to this specialization
     */
    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    /** Specialization has many sections */
    public function sections()
    {
        return $this->hasMany(Section::class);
    }
}