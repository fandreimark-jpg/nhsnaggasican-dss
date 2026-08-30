<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AssessmentComponent
 * -------------------
 * The 3 fixed grading components — Written Work, Performance Task,
 * Examination — and their weight toward the final computed grade.
 * Seeded once by its migration; rows are looked up by 'key', not id,
 * so code never hard-codes a numeric id for "Written Work".
 */
class AssessmentComponent extends Model
{
    protected $fillable = ['key', 'name', 'weight'];

    protected $casts = [
        'weight' => 'decimal:2',
    ];

    /**
     * Assessment items don't have a foreign key to this table — they store
     * the classification directly as the `component` enum string (see
     * Assessment::componentWeight()) — so there's no hasMany() here to
     * join by id; look assessments up by `where('component', $this->key)`
     * if ever needed.
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
