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
    use HasFactory;

    // Fields that can be mass-assigned
    protected $fillable = [
        'lrn',          // Learner Reference Number — 12 digits, unique
        'last_name',
        'first_name',
        'middle_name',
        'section_id',   // Which section the student belongs to
        'gender',       // 'male' or 'female'
        'birthdate',
    ];

    // Tells Laravel to treat 'birthdate' as a real date object (Carbon)
    // instead of a plain string, so ->format() works on it.
    protected $casts = [
        'birthdate' => 'date',
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

    /**
     * Returns the birthdate as MM/DD/YYYY instead of the raw YYYY-MM-DD
     * that comes straight from the database.
     * Accessible as $student->formatted_birthdate
     */
    public function getFormattedBirthdateAttribute()
    {
        return $this->birthdate ? $this->birthdate->format('m/d/Y') : null;
    }

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /** A student belongs to one section */
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    /** A student has many grade records (one per subject per term) */
    public function grades()
    {
        return $this->hasMany(Grade::class);
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