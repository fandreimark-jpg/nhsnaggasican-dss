<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Assessment
 * ----------
 * An assessment ITEM/definition — e.g. "Quiz 1" worth 20 points, for a
 * given subject/section/term. One row per item, NOT per student; the
 * per-student earned score lives in AssessmentScore. This is EVIDENCE
 * feeding a later-computed grade (see a future GradingEngine), never the
 * official final grade itself — grades.grade stays adviser-controlled and
 * nothing here writes to it.
 *
 * `component` (written_work/performance_task/examination) is the
 * VERIFIED classification — see AssessmentComponent for where each
 * component's weight toward the final grade is configured.
 */
class Assessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'section_id',
        'grading_period',
        'school_year',
        'name',
        'assessment_type',
        'component',
        'max_score',
        'import_batch_id',
        'uploaded_by',
    ];

    protected $casts = [
        'max_score' => 'decimal:2',
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Per-student earned scores for this item */
    public function scores()
    {
        return $this->hasMany(AssessmentScore::class);
    }

    // =============================================
    // HELPERS
    // =============================================

    /** This item's configured weight toward the final grade (looked up by its component key) */
    public function componentWeight(): ?float
    {
        $component = AssessmentComponent::where('key', $this->component)->first();

        return $component ? (float) $component->weight : null;
    }
}
