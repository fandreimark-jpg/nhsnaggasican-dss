<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AcademicYear
 * ------------
 * The explicit, admin-configurable "which school year is active"
 * record — SYSTEM_FIXES_AND_ML_AUDIT.md, "Remove Hardcoded Academic
 * Year." Only one row is ever active at a time (enforced in
 * Admin\AcademicYearController::activate(), same "close the others
 * first" pattern AcademicTermController already uses for is_open).
 *
 * Section::activeSchoolYear() reads this FIRST, falling back to its own
 * insertion-order inference only when no row here is marked active —
 * every existing caller of that method (assessments, grades, reports,
 * risk, interventions, imports, dashboards — see its own docblock)
 * therefore becomes admin-configurable automatically, with no changes
 * needed at any of those call sites.
 */
class AcademicYear extends Model
{
    protected $fillable = ['school_year', 'start_date', 'end_date', 'is_active'];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }
}
