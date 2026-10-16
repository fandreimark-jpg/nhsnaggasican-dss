<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One meaningful change in an intervention's schedule/completion history.
 * Written only by App\Services\InterventionScheduleService. effective_at is
 * when it took effect (for an expiry: the deadline that lapsed); recorded_at
 * is when the system wrote it. (intervention_id, event, dedupe_key) is
 * unique, so a retried request or a rerun of the expiry job never writes
 * the same transition twice. actor_id null = the system.
 */
class InterventionEvent extends Model
{
    public $timestamps = false;

    public const LABELS = [
        'scheduled'             => 'Schedule set',
        'schedule_changed'      => 'Schedule changed',
        'reassigned'            => 'Responsible adviser assigned',
        'extended'              => 'Deadline extended',
        'request_submitted'     => 'Request submitted',
        'request_approved'      => 'Request approved',
        'request_rejected'      => 'Request rejected',
        'request_superseded'    => 'Request superseded',
        'completed'             => 'Completion recorded',
        'expired'               => 'Expired — not completed',
        'reopened'              => 'Reopened with a new deadline',
        'cancelled'             => 'Cancelled',
    ];

    protected $fillable = ['intervention_id', 'event', 'actor_id', 'old_values', 'new_values', 'reason', 'effective_at', 'recorded_at', 'dedupe_key'];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'effective_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function label(): string
    {
        return self::LABELS[$this->event] ?? $this->event;
    }
}
