<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An observable academic concern found in current assessment evidence by a
 * school-approved MonitoringRule — For Review evidence for the Principal.
 *
 * It is NOT a prediction and NOT an intervention: nothing here creates,
 * approves or closes one. The Principal reviews the evidence and decides.
 *
 * Written only by App\Services\AcademicMonitoringService. Never deleted:
 * when the evidence no longer meets the rule the concern is RESOLVED and
 * kept, with its last evidence, first_detected_at, resolved_at and
 * resolution_reason.
 */
class AcademicConcern extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    /** Principal-facing wording — an open concern is For Review, not "at risk". */
    public const STATUS_LABELS = [
        self::STATUS_OPEN     => 'For Review',
        self::STATUS_RESOLVED => 'Resolved',
    ];

    protected $fillable = [
        'student_id',
        'subject_id',
        'section_id',
        'school_year',
        'grading_period',
        'rule_key',
        'rule_version',
        'context_key',
        'open_key',
        'evidence',
        'status',
        'first_detected_at',
        'last_evaluated_at',
        'resolved_at',
        'resolution_reason',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'evidence'          => 'array',
        'grading_period'    => 'integer',
        'rule_version'      => 'integer',
        'first_detected_at' => 'datetime',
        'last_evaluated_at' => 'datetime',
        'resolved_at'       => 'datetime',
        'reviewed_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        // History is retained: a concern that no longer applies is resolved,
        // never deleted.
        static::deleting(function () {
            throw new \LogicException('Academic concerns are history and are never deleted — resolve them instead.');
        });
    }

    /** The one duplicate-prevention key: at most one OPEN concern per rule, learner and context. */
    public static function openKeyFor(string $ruleKey, int $studentId, string $contextKey): string
    {
        return $ruleKey . '|' . $studentId . '|' . $contextKey;
    }

    /**
     * Only concerns of a rule monitoring currently runs. A retired rule's
     * rows (the Attendance Concern) stay in the table as history but never
     * make a learner For Review, and are never listed or counted.
     */
    public function scopeActiveRules(Builder $query): Builder
    {
        return $query->whereIn('rule_key', \App\Services\AcademicMonitoringService::enabledRuleKeys());
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    // --- Relationships ---------------------------------------------------

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /** The section the learner was in when this was found — stored, never re-derived. */
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Interventions the Principal recorded from this concern (Phase 2E). Optional: most interventions have none. */
    public function interventions()
    {
        return $this->hasMany(Intervention::class);
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    // --- Phase 2E schema availability ------------------------------------
    // The working tree is served live by XAMPP; until the Phase 2E
    // migrations are applied these report false and the review note /
    // intervention link are simply not read or written, instead of a
    // "column not found" error. Memoised per process.

    private static array $columnMemo = [];

    public static function reviewNoteAvailable(): bool
    {
        return self::$columnMemo['review_note'] ??= \Illuminate\Support\Facades\Schema::hasColumn('academic_concerns', 'review_note');
    }

    public static function interventionLinkAvailable(): bool
    {
        return self::$columnMemo['academic_concern_id'] ??= \Illuminate\Support\Facades\Schema::hasColumn('interventions', 'academic_concern_id');
    }

    // --- Scopes ----------------------------------------------------------

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    public function scopeForSchoolYear(Builder $query, string $schoolYear): Builder
    {
        return $query->where('school_year', $schoolYear);
    }

    public function scopeForTerm(Builder $query, int $gradingPeriod): Builder
    {
        return $query->where('grading_period', $gradingPeriod);
    }

    public function scopeForSection(Builder $query, int $sectionId): Builder
    {
        return $query->where('section_id', $sectionId);
    }

    public function scopeForRule(Builder $query, string $ruleKey): Builder
    {
        return $query->where('rule_key', $ruleKey);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }
}
