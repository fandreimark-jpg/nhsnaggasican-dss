<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One explicit submission of a term report, with the contents exactly as
 * submitted (snapshot). Immutable: written once by
 * Adviser\ReportController::submit(), never updated.
 */
class ReportSubmissionVersion extends Model
{
    protected $fillable = ['report_submission_id', 'version', 'submitted_by', 'submitted_at', 'snapshot'];

    protected $casts = [
        'submitted_at' => 'datetime',
        'snapshot'     => 'array',
        'version'      => 'integer',
    ];

    public function submission()
    {
        return $this->belongsTo(ReportSubmission::class, 'report_submission_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
