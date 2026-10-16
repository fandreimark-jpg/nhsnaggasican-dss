<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A staff request on an intervention, reviewed by the Principal:
 *  - late_completion: the work was done ON TIME but recorded after the
 *                     deadline (actual_completed_at <= the lapsed deadline).
 *                     The only type still created.
 *  - schedule / extension: the PREVIOUS approval workflow (retired
 *                     2026-10-07 — the responsible staff now set, extend and
 *                     reopen schedules directly). Kept as history; a pending
 *                     one never blocks staff, is superseded by their next
 *                     schedule change, and can be rejected but never approved.
 * A pending request never changes the schedule. based_on_version
 * is the intervention's schedule_version when it was made: approving a
 * request built on an older schedule is refused (status 'superseded').
 */
class InterventionRequest extends Model
{
    public const TYPES = ['schedule', 'extension', 'late_completion'];
    public const TYPE_LABELS = [
        // Retired 2026-10-07 (previous approval workflow): kept for history only.
        'schedule' => 'Schedule proposal (previous approval workflow)',
        'extension' => 'Extension request (previous approval workflow)',
        'late_completion' => 'On-time completion recorded late',
    ];

    protected $fillable = [
        'intervention_id', 'type', 'status', 'requested_by', 'proposed_starts_at', 'proposed_deadline_at',
        'actual_completed_at', 'reason', 'evidence_notes', 'based_on_version', 'decided_by', 'decided_at', 'decision_notes',
    ];

    protected $casts = [
        'proposed_starts_at' => 'datetime',
        'proposed_deadline_at' => 'datetime',
        'actual_completed_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function intervention()
    {
        return $this->belongsTo(Intervention::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function label(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /** A schedule/extension request from the retired Principal-approval workflow: may be dismissed, never approved. */
    public function isLegacy(): bool
    {
        return $this->type !== 'late_completion';
    }
}
