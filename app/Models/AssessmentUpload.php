<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AssessmentUpload
 * ----------------
 * An audit record of one uploaded assessment form — who uploaded it, for
 * which section/subject/term, and the final verified column->component
 * mapping used to import it. The upload/verify/import workflow itself is
 * implemented in a later phase; this model exists so that workflow has
 * somewhere to record its result.
 */
class AssessmentUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_id',
        'subject_id',
        'uploaded_by',
        'grading_period',
        'school_year',
        'original_filename',
        'column_mapping',
        'ecr_profile_version', // set only when read through the DepEd ECR profile — see EcrProfileDetector
        'status',
        'imported_count',
        'error_count',
    ];

    protected $casts = [
        'column_mapping' => 'array',
    ];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * assessments.import_batch_id is a loose nullable string, not a real
     * foreign key to this table (that column already existed with this
     * shape before this table was added — see the note on
     * 2026_08_29_162447_create_assessments_table) — so there's no
     * hasMany() here. The upload workflow (a later phase) is expected to
     * set import_batch_id to (string) $upload->id; join manually via
     * Assessment::where('import_batch_id', (string) $this->id) if needed.
     */
}
