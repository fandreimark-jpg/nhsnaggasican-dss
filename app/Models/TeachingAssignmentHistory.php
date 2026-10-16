<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only history of teaching responsibility (assign, replace teacher,
 * scope correction, remove). Written only by Admin\TeachingAssignmentController.
 */
class TeachingAssignmentHistory extends Model
{
    protected $table = 'teaching_assignment_history';

    public $timestamps = false;

    public const ASSIGNED = 'assigned';
    public const REASSIGNED = 'reassigned';
    public const SCOPE_CORRECTED = 'scope_corrected';
    public const REVOKED = 'revoked';

    public const LABELS = [
        self::ASSIGNED        => 'Assigned',
        self::REASSIGNED      => 'Teacher replaced',
        self::SCOPE_CORRECTED => 'Scope corrected',
        self::REVOKED         => 'Removed',
    ];

    protected $fillable = [
        'teaching_assignment_id', 'action', 'section_id', 'subject_id', 'school_year', 'grading_period',
        'old_user_id', 'new_user_id', 'previous_scope', 'reason', 'changed_by', 'changed_at',
    ];

    protected $casts = [
        'previous_scope' => 'array',
        'changed_at'     => 'datetime',
    ];

    public function oldTeacher()
    {
        return $this->belongsTo(User::class, 'old_user_id');
    }

    public function newTeacher()
    {
        return $this->belongsTo(User::class, 'new_user_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /** Records one history row for an assignment as it stands now. */
    public static function record(TeachingAssignment $a, string $action, ?int $oldUserId, ?int $newUserId, ?string $reason, ?array $previousScope = null): self
    {
        return static::create([
            'teaching_assignment_id' => $a->id,
            'action'         => $action,
            'section_id'     => $a->section_id,
            'subject_id'     => $a->subject_id,
            'school_year'    => $a->school_year,
            'grading_period' => $a->grading_period,
            'old_user_id'    => $oldUserId,
            'new_user_id'    => $newUserId,
            'previous_scope' => $previousScope,
            'reason'         => $reason,
            'changed_by'     => auth()->id(),
            'changed_at'     => now(),
        ]);
    }
}
