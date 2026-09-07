<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AssessmentComponent
 * -------------------
 * The 3 fixed grading components — Written Work, Performance Task,
 * Examination — and their keys. Seeded once by its migration; rows are
 * looked up by 'key', not id, so code never hard-codes a numeric id for
 * "Written Work".
 *
 * `weight` here is a historical, now-UNUSED flat 25/50/25 default kept
 * only for backward compatibility with existing rows/tests — the actual
 * weight a grade is computed with varies by scheme and by the subject's
 * subject_group (see SubjectGroupWeight::resolve(), used by
 * GradingEngine and DashboardAnalyticsService). Nothing reads this
 * column for grading purposes anymore.
 */
class AssessmentComponent extends Model
{
    protected $fillable = ['key', 'name', 'weight'];

    protected $casts = [
        'weight' => 'decimal:2',
    ];

    /**
     * Assessment items don't have a foreign key to this table — they store
     * the classification directly as the `component` enum string — so
     * there's no hasMany() here to join by id; look assessments up by
     * `where('component', $this->key)` if ever needed.
     */

    public static function writtenWork(): self
    {
        return static::where('key', 'written_work')->firstOrFail();
    }

    public static function performanceTask(): self
    {
        return static::where('key', 'performance_task')->firstOrFail();
    }

    public static function examination(): self
    {
        return static::where('key', 'examination')->firstOrFail();
    }
}
