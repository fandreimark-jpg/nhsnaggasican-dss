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

    /** The status every intervention starts at — see awaitingDecision(). */
    public const STATUS_RECOMMENDED = 'recommended';

    /** Statuses that mean the Principal has actually reviewed and decided this — everything except the starting state. */
    public const DECIDED_STATUSES = ['in_review', 'approved', 'in_progress', 'completed', 'monitoring'];

    /** Every status except 'completed' — an intervention still being tracked, not yet closed out. */
    public const OPEN_STATUSES = ['recommended', 'in_review', 'approved', 'in_progress', 'monitoring'];

    /**
     * "Master pass" PART 1 — WHO created this record, not what informed
     * it. Every intervention today is created by a Principal through the
     * interface, so 'principal' is the only value any code path sets.
     * 'system' is reserved for a future automatic generator that does
     * not exist — see isSystemGenerated() and the hard constraint against
     * ever setting it. Never confuse this with recommended_type/
     * recommendation_reason, which the DSS genuinely does produce.
     */
    public const ORIGINS = ['principal', 'system'];

    public const TYPES = [
        'remediation',
        'additional_learning_activity',
        'additional_performance_task',
        'teacher_monitoring',
        'attendance_monitoring',
        'parent_conference',
        'other',
    ];

    /** The three recorded-reason labels that name a component, in match priority order — see extractNamedComponent(). */
    private const COMPONENT_LABELS = ['Written Work' => 'written_work', 'Performance Task' => 'performance_task', 'Examination' => 'examination'];

    protected $fillable = [
        'student_id',
        'subject_id',
        'grading_period',
        'risk_result_id',
        'recommended_type',
        'recommendation_reason',
        'focus_component',
        'status',
        'principal_notes',
        'created_by',
        'origin',
        'decided_by',
        'decided_at',
        'acknowledged_by',
        'acknowledged_at',
        'delivered_by',
        'delivered_at',
        'delivery_notes',
        'delivery_mode',
        'delivery_group_id',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'delivered_at' => 'datetime',
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

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * "Correctness and interface pass" TASK 2 — true until the Principal
     * has actually decided on this recommendation (moved it off its
     * starting 'recommended' status AND stamped decided_by/decided_at via
     * Principal\InterventionController::update()). CLAUDE.md: "the DSS
     * recommends, the Principal decides" — creating a record from the
     * Students page is the DSS/Principal's recommendation step, not the
     * decision step; an adviser acknowledging or delivering one before
     * that decision happens would let the Principal's own review be
     * skipped entirely. Checks BOTH status and decided_by (not just one)
     * since they are set together by update() — this is defense in depth,
     * not two independent signals.
     */
    public function awaitingDecision(): bool
    {
        return $this->status === self::STATUS_RECOMMENDED || is_null($this->decided_by);
    }

    /**
     * "Progress column honesty and the last duplicate rule" work order,
     * PART 2 — the query-level twin of awaitingDecision() above, so a
     * COUNT can be taken without loading every row. Before this, the
     * Principal dashboard's "Awaiting Your Decision" card counted
     * `status IN ('recommended', 'in_review')` while the Interventions
     * page's banner counted `status = 'recommended' OR decided_by IS
     * NULL` — two different rules that agreed only because no
     * 'in_review' row has ever existed. `in_review` is actually listed
     * in DECIDED_STATUSES (a status the Principal has already acted on),
     * so the dashboard's version was the wrong one, not a harmless
     * variant — see CLAUDE.md Design Decision #3. Both callers now use
     * this scope exclusively; do not add a third copy of this WHERE
     * clause anywhere.
     *
     * Named `undecided`, not `awaitingDecision` — Eloquent resolves
     * `Intervention::awaitingDecision()` to the real INSTANCE method of
     * that exact name above before it ever considers a
     * `scopeAwaitingDecision` magic method, so a same-named scope fails
     * at call time ("Non-static method... cannot be called statically")
     * rather than at definition time.
     */
    public function scopeUndecided(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(fn($q) => $q->where('status', self::STATUS_RECOMMENDED)->orWhereNull('decided_by'));
    }

    /**
     * "Master pass" PART 1 — true only for a value no code path in this
     * application sets today (see ORIGINS' docblock). Exists so the
     * interface can tell the truth if such a source is ever added,
     * without every caller needing to know the column didn't always
     * exist. A null origin (a row from before this column existed,
     * though the backfill migration leaves none) is never treated as
     * system-generated — every intervention in this codebase's history
     * was created by a Principal.
     */
    public function isSystemGenerated(): bool
    {
        return $this->origin === 'system';
    }

    /**
     * "Clarity, progress, and visual design pass" TASK 3d — how many
     * interventions were delivered in the SAME group act as each row in
     * $interventions, keyed by delivery_group_id, in one query rather
     * than one COUNT per row. Rows with no group (individual delivery,
     * or not yet delivered — delivery_group_id is null either way) are
     * simply absent from the returned map; callers treat a missing key
     * as "not a group delivery."
     *
     * @param \Illuminate\Support\Collection<int, Intervention> $interventions
     * @return array<string, int>
     */
    public static function groupDeliverySizes($interventions): array
    {
        $groupIds = collect($interventions)->pluck('delivery_group_id')->filter()->unique()->values();

        if ($groupIds->isEmpty()) {
            return [];
        }

        return static::whereIn('delivery_group_id', $groupIds)
            ->selectRaw('delivery_group_id, count(*) as cnt')
            ->groupBy('delivery_group_id')
            ->pluck('cnt', 'delivery_group_id')
            ->all();
    }

    /**
     * The component literally NAMED in a recommendation_reason string —
     * no guessing, no fallback. Shared by focusComponent() below (which
     * DOES fall back, for the "Add Assessment Item" pre-fill's low-stakes
     * convenience) and by Principal\InterventionController::store()/
     * storeBulk() (which persist the result into focus_component at
     * creation — see TASK 2 of "status clarity and progress consistency").
     * The migration that added focus_component duplicates this exact
     * 3-label rule for its one-time backfill, deliberately not calling
     * this method, so that migration keeps working even if this method's
     * shape changes later.
     */
    public static function extractNamedComponent(?string $reason): ?string
    {
        $reason = (string) $reason;

        foreach (self::COMPONENT_LABELS as $label => $key) {
            if (str_contains($reason, $label)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * TASK 2 of "add an assessment item by hand" — best-effort "Focus
     * Area" component, used only to PRE-FILL (never enforce) the adviser's
     * Add Assessment Item form when reached from this intervention. Never
     * used to anchor the Within-Term Progress comparison — see
     * ProgressMonitoringService::compareWithinTerm(), which reads the raw
     * focus_component column directly and shows "not recorded" rather
     * than fall back to a guess, per TASK 2 of "status clarity and
     * progress consistency."
     *
     * Checks the persisted focus_component column first (the authoritative
     * value, set at creation — see extractNamedComponent()'s callers), then
     * falls back to re-deriving it from recommendation_reason text (for
     * any row created before that column existed and never backfilled),
     * then finally to recommended_type — a looser signal, since e.g.
     * 'remediation' is also used by InterventionRecommender for a
     * "Currently failing" reason that names no specific component, so
     * this last fallback can guess wrong. Either way the adviser still
     * explicitly picks the Component on the Add Assessment Item form —
     * this only saves them a click when the guess is right.
     */
    public function focusComponent(): ?string
    {
        if ($this->focus_component) {
            return $this->focus_component;
        }

        $named = self::extractNamedComponent($this->recommendation_reason);
        if ($named) {
            return $named;
        }

        return match ($this->recommended_type) {
            'additional_learning_activity' => 'written_work',
            'additional_performance_task'  => 'performance_task',
            'remediation'                  => 'examination',
            default => null,
        };
    }
}
