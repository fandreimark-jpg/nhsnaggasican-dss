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
 * VERIFIED classification — see AssessmentComponent for the fixed list
 * of components, and SubjectGroupWeight for where each component's
 * weight toward the final grade is actually configured (it varies by
 * scheme and by the subject's subject_group, not a single flat default).
 *
 * `exam_role` (st1/st2/term_exam, nullable) applies only when `component`
 * is 'examination' — see ExamRoleShare and GradingEngine's
 * examinationPercentage() for how it's weighted within the component.
 *
 * `is_additional_support` (default false) — "Workflow completion pass"
 * TASK 3: whether this item is within-term additional support (a
 * re-teach quiz, an extra activity) rather than a regular planned item.
 * Set explicitly by the Adviser on the Verify screen or the Add
 * Assessment Item form — never inferred from the item's name. Display
 * only: it still contributes its full points to the term total exactly
 * like any other item — see CLAUDE.md's "Known limitations" open policy
 * question this column exists to make visible, not resolve.
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
        'exam_role',
        'is_additional_support',
        'max_score',
        'import_batch_id',
        'uploaded_by',
    ];

    protected $casts = [
        'max_score' => 'decimal:2',
        'is_additional_support' => 'boolean',
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
}
