<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Intervention
 * ------------
 * A Principal's decision for one learner, subject and term. The Principal
 * chooses the type (recommended_type) from the evidence shown — nothing is
 * pre-selected — and decides (status/principal_notes/decided_by/
 * decided_at). An intervention recorded from an open Academic Concern
 * links to it (academic_concern_id); it never resolves the concern. See the
 * migration's note for why status can start at 'recommended' (a deferred
 * decision) rather than an approved state.
 */
class Intervention extends Model
{
    use HasFactory;

    public const STATUSES = ['recommended', 'in_review', 'approved', 'in_progress', 'completed', 'monitoring', 'expired', 'cancelled'];

    /**
     * Statuses the Principal's ordinary status edit may set. 'expired' and
     * 'cancelled' are reached only through InterventionScheduleService (the
     * expiry job, Cancel with a reason) and left only through its explicit
     * transitions (Principal-approved extension / late-completion approval).
     */
    public const EDITABLE_STATUSES = ['in_review', 'approved', 'in_progress', 'completed', 'monitoring'];

    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Schedule lifecycle (2026-10-06) — DERIVED, never stored as its own
     * column, from the decision status, the completion record, the persisted
     * expiry/cancellation and the server clock. See lifecycle().
     */
    public const LIFECYCLE_LABELS = [
        'awaiting_decision' => 'Awaiting decision',
        'unscheduled'       => 'Schedule not set',
        'scheduled'         => 'Scheduled',
        'ongoing'           => 'Ongoing',
        'completed'         => 'Completed',
        'expired'           => 'Expired — Not completed',
        'cancelled'         => 'Cancelled',
    ];

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

    /**
     * Display wording for each stored type — the ONE map every screen reads
     * (Intervention::typeLabel()). Stored values are unchanged:
     *  - 'remediation' is not DepEd's post-term remediation (CLAUDE.md,
     *    Known limitations), so it reads as within-term practice;
     *  - 'attendance_monitoring' is RETIRED with attendance (2026-09-27):
     *    kept only so an existing record still reads in words; it is not
     *    in SELECTABLE_TYPES, so no new intervention can use it.
     */
    public const TYPE_LABELS = [
        'remediation'                  => 'Additional Practice and Re-teaching',
        'additional_learning_activity' => 'Additional Learning Activity',
        'additional_performance_task'  => 'Additional Performance Task',
        'teacher_monitoring'           => 'Teacher Monitoring',
        'attendance_monitoring'        => 'Attendance Follow-up (retired)',
        'parent_conference'            => 'Parent/Guardian Conference',
        'other'                        => 'Other',
    ];

    /** Types a Principal can choose for a NEW intervention — every stored type but the retired one. */
    public const SELECTABLE_TYPES = [
        'remediation',
        'additional_learning_activity',
        'additional_performance_task',
        'teacher_monitoring',
        'parent_conference',
        'other',
    ];

    public static function typeLabel(?string $type): string
    {
        return self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', (string) $type));
    }

    /** The three recorded-reason labels that name a component, in match priority order — see extractNamedComponent(). */
    private const COMPONENT_LABELS = ['Written Work' => 'written_work', 'Performance Task' => 'performance_task', 'Examination' => 'examination'];

    protected $fillable = [
        'student_id',
        'subject_id',
        'grading_period',
        'school_year',
        'section_id',
        'risk_result_id',
        'academic_concern_id',
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
        // Scheduling (2026-10-06) — written only by InterventionScheduleService.
        'title',
        'instructions',
        'completion_criteria',
        'responsible_user_id',
        'starts_at',
        'deadline_at',
        'completed_at',
        'expired_at',
        'expiry_processed_at',
        'status_before_expiry',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'schedule_version',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'delivered_at' => 'datetime',
        'starts_at' => 'datetime',
        'deadline_at' => 'datetime',
        'completed_at' => 'datetime',
        'expired_at' => 'datetime',
        'expiry_processed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'schedule_version' => 'integer',
    ];

    /**
     * The schedule lifecycle at $now (server time), in this order:
     *  cancelled → completed (a completion is recorded, or the Principal
     *  closed it as completed) → awaiting_decision (an unapproved proposal is
     *  never scheduled or expired) → expired (persisted, OR deadline passed
     *  with no completion — shown even if the expiry job has not run yet) →
     *  unscheduled (no deadline: legacy / not yet scheduled) → scheduled
     *  (now < start) → ongoing (start <= now <= deadline).
     * Boundary: completion AT the deadline is on time; expiry is now > deadline.
     */
    public function lifecycle(?\DateTimeInterface $now = null): string
    {
        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();

        return match (true) {
            $this->status === self::STATUS_CANCELLED => 'cancelled',
            $this->delivered_at !== null || $this->completed_at !== null || $this->status === 'completed' => 'completed',
            $this->awaitingDecision() => 'awaiting_decision',
            $this->status === self::STATUS_EXPIRED => 'expired',
            $this->deadline_at === null => 'unscheduled',
            $now->greaterThan($this->deadline_at) => 'expired',
            $this->starts_at !== null && $now->lessThan($this->starts_at) => 'scheduled',
            default => 'ongoing',
        };
    }

    /**
     * The schedule status SHOWN on pages: lifecycle(), except that an
     * UNSCHEDULED (legacy) record keeps reading "Schedule not set" even when an
     * adviser delivery or a Principal closure exists — those are shown as what
     * they are (delivery / decision), never relabelled as schedule completion.
     */
    public function scheduleState(?\DateTimeInterface $now = null): string
    {
        $state = $this->lifecycle($now);

        return $state === 'completed' && !$this->isScheduled() ? 'unscheduled' : $state;
    }

    public function lifecycleLabel(?\DateTimeInterface $now = null): string
    {
        return self::LIFECYCLE_LABELS[$this->scheduleState($now)];
    }

    /** @var array<string, bool> */
    private static array $schemaMemo = [];

    /**
     * Whether the scheduling migration (2026_10_06_000001) has been applied.
     * New code may be served before the migration runs on a database; every
     * scheduling page, query and the expiry command check this first and say
     * "not available yet" instead of failing on a missing column/table.
     */
    public static function schedulingAvailable(): bool
    {
        return self::$schemaMemo['scheduling'] ??= \Illuminate\Support\Facades\Schema::hasTable('intervention_events')
            && \Illuminate\Support\Facades\Schema::hasColumn('interventions', 'deadline_at');
    }

    /** True once the activity has a schedule (a deadline): completion then goes through the responsible staff, not adviser delivery. */
    public function isScheduled(): bool
    {
        return $this->deadline_at !== null;
    }

    public function responsibleUser()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scheduleRequests()
    {
        return $this->hasMany(InterventionRequest::class)->latest('id');
    }

    public function events()
    {
        return $this->hasMany(InterventionEvent::class)->orderBy('id');
    }

    /**
     * "Multi-school-year academic history" work order, PART 12 — every
     * intervention carries the school year and section it was recorded
     * under. The Principal controllers set both explicitly; this fills
     * them for any other creation path (factories, older call sites)
     * from the best evidence available at creation time — the linked
     * risk result's year, else the learner's current section's year —
     * so no row is ever created without historical context. A section
     * is only stamped when it genuinely belongs to that year.
     */
    protected static function booted(): void
    {
        static::creating(function (Intervention $intervention) {
            if ($intervention->school_year === null || $intervention->section_id === null) {
                $risk = $intervention->risk_result_id ? RiskResult::find($intervention->risk_result_id) : null;
                $student = Student::with('section')->find($intervention->student_id);

                $schoolYear = $intervention->school_year
                    ?? $risk?->school_year
                    ?? $student?->section?->school_year;

                $intervention->school_year = $schoolYear;

                if ($intervention->section_id === null && $schoolYear) {
                    $intervention->section_id = $risk?->section_id
                        ?? $student?->sectionFor($schoolYear)?->id;
                }
            }
        });
    }

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

    /**
     * Phase 2E — the academic concern this intervention was recorded from,
     * if any. Nullable: interventions recorded outside Academic Monitoring
     * (and every intervention before Phase 2E) have none, and stay valid.
     */
    public function academicConcern()
    {
        return $this->belongsTo(AcademicConcern::class);
    }

    /**
     * "Multi-school-year academic history" work order, PART 12 — the
     * section the learner was in when this intervention was recorded,
     * captured at creation the same way focus_component and
     * risk_result_id are (see CLAUDE.md, "Term-over-Term Progress depends
     * on the order a Principal acted in"). Never re-derived from
     * students.section_id — that changes on promotion; this must not.
     */
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    /** The school year this intervention belongs to, via its school_year string. */
    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'school_year', 'school_year');
    }

    public function scopeForSchoolYear($query, string $schoolYear)
    {
        return $query->where('school_year', $schoolYear);
    }

    /**
     * The section this intervention's evidence lives under: the stored
     * section_id (PART 12), else the learner's enrollment for the stored
     * school year, else — for a legacy row with neither — the learner's
     * current section. Every evidence comparison (ProgressMonitoringService,
     * InTermStatusService::isReadyForReview()) reads THIS, never
     * $intervention->student->section directly.
     */
    public function contextSection(): ?Section
    {
        if ($this->section_id) {
            return $this->section;
        }

        $student = $this->student;

        if (!$student) {
            return null;
        }

        return ($this->school_year ? $student->sectionFor($this->school_year) : null) ?? $student->section;
    }

    /** The school year this intervention's evidence lives in — stored, else the context section's. */
    public function contextSchoolYear(): ?string
    {
        return $this->school_year ?? $this->contextSection()?->school_year;
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
     * 'remediation' can be chosen for a reason that names no specific
     * component, so this last fallback can guess wrong. Either way the adviser still
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
