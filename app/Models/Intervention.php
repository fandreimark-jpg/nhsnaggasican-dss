<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Intervention
 * ------------
 * A Principal's decision on a DSS recommendation for one student. The
 * DSS recommends (recommended_type/recommendation_reason); the Principal
 * decides (status/principal_notes/decided_by/decided_at) — see
 * App\Services\InterventionRecommender for how a recommendation is
 * generated, and the migration's note for why status starts at
 * 'recommended' rather than any approved state.
 */
class Intervention extends Model
{
    use HasFactory;

    public const STATUSES = ['recommended', 'in_review', 'approved', 'in_progress', 'completed', 'monitoring'];

    public const TYPES = [
        'remediation',
        'additional_learning_activity',
        'additional_performance_task',
        'teacher_monitoring',
        'attendance_monitoring',
        'parent_conference',
        'other',
    ];

    protected $fillable = [
        'student_id',
        'subject_id',
        'risk_result_id',
        'recommended_type',
        'recommendation_reason',
        'status',
        'principal_notes',
        'created_by',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function riskResult()
    {
        return $this->belongsTo(RiskResult::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
