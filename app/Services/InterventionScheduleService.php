<?php

namespace App\Services;

use App\Helpers\LogActivity;
use App\Models\Intervention;
use App\Models\InterventionEvent;
use App\Models\InterventionRequest;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE one place an intervention's schedule, completion, expiry and
 * cancellation change (2026-10-06). Every transition:
 *  - runs in a transaction that first re-reads the intervention under a row
 *    lock, and re-checks the state and (where relevant) the schedule version
 *    against the CURRENT row — so completion, expiry, extension and
 *    cancellation racing each other end in exactly one valid state;
 *  - uses the server clock (now()), never the browser's;
 *  - writes one InterventionEvent with a dedupe key (no duplicate history on
 *    a retry or a rerun).
 *
 * Academic evidence is never touched here: no score, marker, grade or
 * concern changes because an intervention is scheduled, completed, expired
 * or cancelled (concerns follow evidence only — AcademicMonitoringService).
 */
class InterventionScheduleService
{
    /** Rows the expiry job handles per transaction batch. */
    public const EXPIRY_BATCH = 100;

    // ---- Who may do what -----------------------------------------------------------------

    /**
     * THE responsibility rule (2026-10-07): an intervention is managed by the
     * ADVISER of the section the learner was enrolled in for the
     * intervention's school year (interventions.section_id, captured from the
     * enrollment when it was recorded). Subject teachers have no intervention
     * role. Returns [adviser, null] or [null, why no one can manage it] — never
     * a fallback account. $lock: a locking read of the section (write paths),
     * so an Admin adviser change and this decision serialize.
     *
     * @return array{0: ?User, 1: ?string}
     */
    public function resolveAdviser(?Section $section, ?string $schoolYear, bool $lock = false): array
    {
        $fix = ' Correct the section\'s adviser in Admin › Sections.';
        if ($section && $lock) {
            $section = Section::whereKey($section->id)->lockForUpdate()->first();
        }
        if (!$section) {
            return [null, 'No section is recorded for this learner in this school year, so no adviser can be assigned.'];
        }
        if ($schoolYear !== null && $section->school_year !== $schoolYear) {
            return [null, "Section {$section->name} belongs to {$section->school_year}, not {$schoolYear}: the intervention's context does not match."];
        }
        if (!$section->adviser_id) {
            return [null, "Section {$section->name} has no adviser assigned, so no one can manage this intervention." . $fix];
        }
        $adviser = User::find($section->adviser_id);
        if (!$adviser || $adviser->role !== 'adviser' || !$adviser->isActive()) {
            return [null, "The adviser recorded for section {$section->name}" . ($adviser ? " ({$adviser->name})" : '') . ' is not an active Adviser account.' . $fix];
        }
        if (Section::where('adviser_id', $adviser->id)->where('school_year', $section->school_year)->count() > 1) {
            return [null, "{$adviser->name} is recorded as adviser of more than one section in {$section->school_year}, so the assignment is ambiguous." . $fix];
        }

        return [$adviser, null];
    }

    /** @return array{0: ?User, 1: ?string} the section adviser for this intervention, or the problem */
    public function adviserFor(Intervention $iv, bool $lock = false): array
    {
        return $this->resolveAdviser($iv->section_id ? Section::find($iv->section_id) : null, $iv->school_year, $lock);
    }

    /** Is $user the intervention's section adviser right now (active, unambiguous)? */
    public function isEligible(?User $user, Intervention $iv): bool
    {
        [$adviser] = $this->adviserFor($iv);

        return $user !== null && $adviser !== null && $adviser->id === $user->id;
    }

    /**
     * May this staff member SEE the intervention? Active advisers only: the
     * adviser of its section, or the currently responsible adviser.
     * Subject teachers never (their work is the Class Record).
     */
    public function inScope(User $user, Intervention $iv): bool
    {
        if (!$user->isActive() || $user->role !== 'adviser') {
            return false;
        }

        return (int) $iv->responsible_user_id === (int) $user->id
            || ($iv->section_id && Section::whereKey($iv->section_id)->where('adviser_id', $user->id)->exists());
    }

    /** Interventions an adviser may see: advisory section(s) or responsibility. Query only. */
    public function scopeFor(User $user)
    {
        if ($user->role !== 'adviser') {
            return Intervention::query()->whereRaw('1 = 0');
        }

        return Intervention::query()->where(fn($q) => $q->where('responsible_user_id', $user->id)
            ->orWhereIn('section_id', Section::where('adviser_id', $user->id)->select('id')));
    }

    // ---- Responsibility transfer ----------------------------------------------------------
    //
    // AUTHORITY (2026-10-07): the Principal decides the intervention; its
    // section ADVISER is the responsible staff automatically and sets,
    // reschedules, extends and reopens its schedule directly — no approval step.

    /**
     * Makes the section's CURRENT adviser the responsible staff — an explicit,
     * audited write (Principal "Transfer to section adviser", Admin changing a
     * section's adviser, `dss:reconcile-intervention-staff --apply`). Never on
     * a page view. Completed/cancelled records keep their historical
     * responsibility. The version moves, so a form the previous person still
     * has open is refused. Returns true when the assignee changed.
     */
    public function transferToSectionAdviser(Intervention $iv, ?User $actor, string $reason, ?int $expectedVersion = null): bool
    {
        return DB::transaction(function () use ($iv, $actor, $reason, $expectedVersion) {
            $row = $this->lock($iv);
            if ($expectedVersion !== null) {
                $this->assertVersion($row, $expectedVersion);
            }
            $state = $row->lifecycle();
            if (in_array($state, ['completed', 'cancelled'], true)) {
                throw new InterventionTransitionException('This intervention is ' . strtolower(Intervention::LIFECYCLE_LABELS[$state]) . '; its responsibility stays as recorded.');
            }
            [$adviser, $problem] = $this->adviserFor($row, lock: true);
            if (!$adviser) {
                throw new InterventionTransitionException($problem);
            }
            if ((int) $row->responsible_user_id === $adviser->id) {
                return false; // unchanged: no event, no version change
            }
            $old = $row->responsible_user_id;
            $row->fill(['responsible_user_id' => $adviser->id, 'schedule_version' => $row->schedule_version + 1])->save();
            $this->event($row, 'reassigned', $actor, ['responsible_user_id' => $old], ['responsible_user_id' => $adviser->id, 'schedule_version' => $row->schedule_version], $reason, 'v' . $row->schedule_version);
            if ($actor) { // activity_logs.user_id is required; a CLI run (no actor) is recorded by the event above
                LogActivity::log('assign_intervention_staff', "Intervention #{$row->id}: responsibility transferred to section adviser {$adviser->name} — {$reason}", 'interventions', $row->id, $actor->id);
            }

            return true;
        });
    }

    /**
     * Called inside the recording transaction, right after create(): sets the
     * section adviser (already resolved under the section lock) and writes the
     * history entry. A no-op on a database without the scheduling columns.
     */
    public function recordAutoAssignment(Intervention $iv, User $adviser, User $principal): void
    {
        if (!Intervention::schedulingAvailable()) {
            return;
        }
        $iv->forceFill(['responsible_user_id' => $adviser->id])->save();
        $this->event($iv, 'reassigned', $principal, null, ['responsible_user_id' => $adviser->id], 'Assigned automatically: the learner\'s section adviser.', 'created');
    }

    /**
     * Actionable (not completed/cancelled) interventions whose responsible
     * staff is not their section's current adviser — the reconciliation scope.
     *
     * @return Collection<int, Intervention>
     */
    public function needingTransfer(?int $sectionId = null): Collection
    {
        return Intervention::query()
            ->when($sectionId, fn($q) => $q->where('section_id', $sectionId))
            ->with(['student', 'subject', 'section', 'responsibleUser', 'scheduleRequests'])
            ->orderBy('id')->get()
            ->reject(fn(Intervention $iv) => in_array($iv->lifecycle(), ['completed', 'cancelled'], true))
            ->filter(function (Intervention $iv) {
                [$adviser] = $this->adviserFor($iv);

                return $adviser === null || (int) $iv->responsible_user_id !== $adviser->id;
            })
            ->values();
    }

    // ---- Responsible staff: schedule, extend, reopen -------------------------------------

    /**
     * What $user may do with the schedule right now, and — when nothing — the
     * concrete reason. For DISPLAY only: every action re-checks all of this
     * under a row lock. Actions: set, reschedule, extend, complete, reopen,
     * correction (on-time completion recorded late).
     *
     * @return array{actions: list<string>, blocked: ?string}
     */
    public function availability(Intervention $iv, User $user, ?Carbon $now = null): array
    {
        $state = $iv->lifecycle($now);
        $blocked = match (true) {
            $state === 'awaiting_decision' => 'Not approved yet: the Principal has not decided this intervention.',
            $state === 'completed' => 'Completed: the schedule can no longer change.',
            $state === 'cancelled' => 'Cancelled: the schedule can no longer change.',
            $iv->responsible_user_id === null => 'No responsible adviser is assigned yet. ' . ($this->adviserFor($iv)[1] ?? 'The Principal transfers it to the section adviser from the intervention\'s page.'),
            (int) $iv->responsible_user_id !== (int) $user->id => 'You are not the responsible adviser' . ($iv->responsibleUser ? ' (' . $iv->responsibleUser->name . ' is)' : '') . '. You can view this intervention only.',
            !$this->isEligible($user, $iv) => 'Your responsibility is no longer valid: you are no longer this section\'s active adviser. ' . ($this->adviserFor($iv)[1] ?? 'The Principal transfers it to the current section adviser.'),
            default => null,
        };
        if ($blocked !== null) {
            return ['actions' => [], 'blocked' => $blocked];
        }
        $actions = match ($state) {
            'unscheduled' => ['set'],
            'scheduled' => ['reschedule', 'extend'],
            'ongoing' => ['extend', 'complete'],
            'expired' => ['reopen', 'correction'],
            default => [],
        };
        if ($state === 'expired') {
            $other = Intervention::where('id', '!=', $iv->id)->where('student_id', $iv->student_id)->where('subject_id', $iv->subject_id)
                ->where('grading_period', $iv->grading_period)->whereIn('status', Intervention::OPEN_STATUSES)->exists();
            if ($other) {
                return ['actions' => array_values(array_diff($actions, ['reopen', 'correction'])),
                    'blocked' => 'Another open intervention exists for this learner, subject and term, so this one cannot be reopened or restored.'];
            }
        }

        return ['actions' => $actions, 'blocked' => null];
    }

    /**
     * Sets the first schedule, or (with a reason) reschedules one that has
     * not started yet. Once Ongoing the start is history: more time is an
     * extension. Activity title, instructions and completion criteria are the
     * staff's scheduling details; the Principal's decision (type, reason,
     * notes, status) is never touched. The start cannot be in the past.
     *
     * @param array{title: ?string, instructions: ?string, completion_criteria: ?string, starts_at: Carbon, deadline_at: Carbon, reason: ?string} $data
     */
    public function schedule(Intervention $iv, array $data, User $staff, int $expectedVersion): Intervention
    {
        return DB::transaction(function () use ($iv, $data, $staff, $expectedVersion) {
            $row = $this->lock($iv);
            $this->assertResponsible($row, $staff, 'manage this intervention\'s schedule');
            $this->assertVersion($row, $expectedVersion);
            $state = $row->lifecycle();
            match ($state) {
                'unscheduled', 'scheduled' => null,
                'awaiting_decision' => throw new InterventionTransitionException('This intervention is not approved yet, so it cannot be scheduled.'),
                'ongoing' => throw new InterventionTransitionException('This intervention has already started; its start stays as it was. Use "Extend deadline" for more time.'),
                'expired' => throw new InterventionTransitionException('This intervention has expired. Use "Reopen and set schedule" with a reason.'),
                default => throw new InterventionTransitionException('This intervention is ' . strtolower(Intervention::LIFECYCLE_LABELS[$state]) . '; its schedule can no longer be changed.'),
            };
            $first = $state === 'unscheduled';
            $reason = trim((string) ($data['reason'] ?? ''));
            if (!$first && mb_strlen($reason) < 5) {
                throw new InterventionTransitionException('Give a reason for rescheduling (at least 5 characters).');
            }
            $this->assertNotBackdated($data['starts_at']);
            $this->assertWindow($data['starts_at'], $data['deadline_at']);

            $old = $this->scheduleValues($row);
            $row->fill([
                'title' => $data['title'] ?: $row->title, 'instructions' => $data['instructions'] ?? $row->instructions,
                'completion_criteria' => $data['completion_criteria'] ?? $row->completion_criteria,
                'starts_at' => $data['starts_at'], 'deadline_at' => $data['deadline_at'], 'schedule_version' => $row->schedule_version + 1,
            ])->save();
            $this->supersedeLegacyScheduleRequests($row, $staff);
            $this->event($row, $first ? 'scheduled' : 'schedule_changed', $staff, $old, $this->scheduleValues($row) + ['schedule_version' => $row->schedule_version], $first ? null : $reason, 'v' . $row->schedule_version);
            LogActivity::log($first ? 'schedule_intervention' : 'reschedule_intervention', ($first ? 'Scheduled' : 'Rescheduled')
                . " intervention #{$row->id}: {$row->starts_at->format('Y-m-d H:i')} to {$row->deadline_at->format('Y-m-d H:i')}" . ($first ? '' : " — {$reason}"), 'interventions', $row->id, $staff->id);

            return $row;
        });
    }

    /** Responsible staff: a later deadline, with a reason, for a Scheduled or Ongoing intervention. The start is kept. */
    public function extend(Intervention $iv, User $staff, Carbon $deadline, string $reason, int $expectedVersion): Intervention
    {
        return DB::transaction(function () use ($iv, $staff, $deadline, $reason, $expectedVersion) {
            $row = $this->lock($iv);
            $this->assertResponsible($row, $staff, 'extend this intervention\'s deadline');
            $this->assertVersion($row, $expectedVersion);
            $state = $row->lifecycle();
            if (!in_array($state, ['scheduled', 'ongoing'], true)) {
                throw new InterventionTransitionException(match ($state) {
                    'expired' => 'The deadline has already passed. Use "Reopen and set schedule" with a reason.',
                    'unscheduled' => 'Set the schedule first.',
                    'awaiting_decision' => 'This intervention is not approved yet.',
                    default => 'This intervention is ' . strtolower(Intervention::LIFECYCLE_LABELS[$state]) . '; its deadline can no longer change.',
                });
            }
            if (mb_strlen(trim($reason)) < 5) {
                throw new InterventionTransitionException('Give a reason for the extension (at least 5 characters).');
            }
            if (!$deadline->greaterThan($row->deadline_at)) {
                throw new InterventionTransitionException('The new deadline must be later than the current one (' . $this->fmt($row->deadline_at) . ').');
            }
            $this->assertWindow($row->starts_at, $deadline);

            $oldDeadline = $row->deadline_at->format('Y-m-d H:i:s');
            $row->fill(['deadline_at' => $deadline, 'schedule_version' => $row->schedule_version + 1])->save();
            $this->supersedeLegacyScheduleRequests($row, $staff);
            $this->event($row, 'extended', $staff, ['deadline_at' => $oldDeadline], ['deadline_at' => $deadline->format('Y-m-d H:i:s'), 'schedule_version' => $row->schedule_version], trim($reason), 'v' . $row->schedule_version);
            LogActivity::log('extend_intervention', "Extended intervention #{$row->id} from {$oldDeadline} to {$deadline->format('Y-m-d H:i')}: " . trim($reason), 'interventions', $row->id, $staff->id);

            return $row;
        });
    }

    /**
     * Responsible staff: reopen an EXPIRED intervention with a new start,
     * deadline and reason. If the scheduler has not yet recorded the expiry,
     * it is recorded first, in the same transaction (same dedupe key as the
     * job, so never twice). The lapsed schedule stays in the history.
     */
    public function reopen(Intervention $iv, User $staff, Carbon $start, Carbon $deadline, string $reason, int $expectedVersion): Intervention
    {
        return DB::transaction(function () use ($iv, $staff, $start, $deadline, $reason, $expectedVersion) {
            $row = $this->lock($iv);
            $this->assertResponsible($row, $staff, 'reopen this intervention');
            $this->assertVersion($row, $expectedVersion);
            if ($row->lifecycle() !== 'expired') {
                throw new InterventionTransitionException('Only an expired intervention can be reopened.');
            }
            if (mb_strlen(trim($reason)) < 5) {
                throw new InterventionTransitionException('Give a reason for reopening (at least 5 characters).');
            }
            if (!$deadline->greaterThan($row->deadline_at)) {
                throw new InterventionTransitionException('The new deadline must be later than the deadline that passed (' . $this->fmt($row->deadline_at) . ').');
            }
            $this->assertNotBackdated($start);
            if ($row->status !== Intervention::STATUS_EXPIRED) {
                $this->persistExpiry($row, now()); // effective expiry, not yet recorded by the scheduler
            }
            $this->applyReopen($row, $staff, $deadline, $start, trim($reason), null);

            return $row;
        });
    }

    public function cancel(Intervention $iv, User $principal, string $reason): Intervention
    {
        return DB::transaction(function () use ($iv, $principal, $reason) {
            $row = $this->lock($iv);
            $state = $row->lifecycle();
            if (in_array($state, ['completed', 'cancelled'], true)) {
                throw new InterventionTransitionException('This intervention is already ' . strtolower(Intervention::LIFECYCLE_LABELS[$state]) . '.');
            }
            $old = ['status' => $row->status];
            $row->fill(['status' => Intervention::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $principal->id, 'cancellation_reason' => $reason])->save();
            $this->supersedePending($row, $principal, 'The intervention was cancelled.');
            $this->event($row, 'cancelled', $principal, $old, ['status' => Intervention::STATUS_CANCELLED], $reason, 'once');
            LogActivity::log('cancel_intervention', "Cancelled intervention #{$row->id}: {$reason}", 'interventions', $row->id, $principal->id);

            return $row;
        });
    }

    // ---- Responsible staff: completion ---------------------------------------------------

    /**
     * Normal completion by the responsible staff, while the intervention is
     * ongoing (server time between start and deadline, deadline inclusive).
     * After the deadline normal completion is refused: an on-time completion
     * recorded late goes through a late_completion request instead.
     */
    public function recordCompletion(Intervention $iv, User $staff, Carbon $completedAt, string $notes): Intervention
    {
        return DB::transaction(function () use ($iv, $staff, $completedAt, $notes) {
            $row = $this->lock($iv);
            $now = now();
            $this->assertResponsible($row, $staff);
            $state = $row->lifecycle($now);
            if ($state === 'completed') {
                throw new InterventionTransitionException('Completion was already recorded for this intervention. The existing record is kept.');
            }
            if ($state === 'expired') {
                throw new InterventionTransitionException('The deadline (' . $this->fmt($row->deadline_at) . ') has passed, so completion cannot be recorded normally. '
                    . 'If the activity was completed on time, submit an on-time-completion correction for the Principal to review; otherwise reopen it with a new schedule.');
            }
            if ($state !== 'ongoing') {
                throw new InterventionTransitionException($state === 'scheduled'
                    ? 'This intervention starts on ' . $this->fmt($row->starts_at) . ' — completion can be recorded once it has started.'
                    : 'Completion can only be recorded for an ongoing scheduled intervention.');
            }
            if ($completedAt->greaterThan($now)) {
                throw new InterventionTransitionException('The completion time cannot be in the future.');
            }
            if ($row->starts_at && $completedAt->lessThan($row->starts_at)) {
                throw new InterventionTransitionException('The completion time cannot be before the start (' . $this->fmt($row->starts_at) . ').');
            }

            $row->fill(['completed_at' => $completedAt, 'delivered_at' => $now, 'delivered_by' => $staff->id, 'delivery_notes' => $notes])->save();
            $this->supersedePending($row, $staff, 'Completion was recorded.');
            $this->event($row, 'completed', $staff, null, ['completed_at' => $completedAt->format('Y-m-d H:i:s')], $notes, 'once', $completedAt);
            LogActivity::log('complete_intervention', "Recorded completion of intervention #{$row->id} (completed {$completedAt->format('Y-m-d H:i')})", 'interventions', $row->id, $staff->id);

            return $row;
        });
    }

    // ---- Requests ---------------------------------------------------------------------------

    public function submitRequest(Intervention $iv, User $staff, string $type, array $data): InterventionRequest
    {
        return DB::transaction(function () use ($iv, $staff, $type, $data) {
            $row = $this->lock($iv);
            if (!$this->inScope($staff, $row)) {
                throw new InterventionTransitionException('You can only submit requests for interventions in your advisory section, your assigned classes, or your responsibility.', 403);
            }
            $state = $row->lifecycle();
            if (in_array($state, ['completed', 'cancelled', 'awaiting_decision'], true)) {
                throw new InterventionTransitionException('No request can be made: this intervention is ' . strtolower(Intervention::LIFECYCLE_LABELS[$state]) . '.');
            }

            if ($type !== 'late_completion') {
                // Retired 2026-10-07: the responsible staff now set and extend the
                // schedule directly; there is no Principal schedule approval.
                throw new InterventionTransitionException('Schedule and extension requests are no longer used: the responsible staff sets, extends or reopens the schedule directly on the intervention\'s page.', 410);
            }
            $fields = ['intervention_id' => $row->id, 'type' => $type, 'requested_by' => $staff->id, 'reason' => $data['reason'],
                'evidence_notes' => $data['evidence_notes'] ?? null, 'based_on_version' => $row->schedule_version];
            $this->assertResponsible($row, $staff);
            if ($state !== 'expired') {
                throw new InterventionTransitionException('An on-time-completion correction applies only after the deadline has passed. While it is ongoing, record completion normally.');
            }
            $actual = $data['actual_completed_at'];
            $this->assertOnTime($row, $actual);
            $fields['actual_completed_at'] = $actual;

            $request = InterventionRequest::create($fields);
            $this->event($row, 'request_submitted', $staff, null, ['request_id' => $request->id, 'type' => $type]
                + array_filter(['proposed_starts_at' => $request->proposed_starts_at?->format('Y-m-d H:i:s'), 'proposed_deadline_at' => $request->proposed_deadline_at?->format('Y-m-d H:i:s'),
                    'actual_completed_at' => $request->actual_completed_at?->format('Y-m-d H:i:s')]), $data['reason'], 'req' . $request->id);
            LogActivity::log('request_intervention_change', InterventionRequest::TYPE_LABELS[$type] . " #{$request->id} submitted for intervention #{$row->id}", 'interventions', $row->id, $staff->id);

            return $request;
        });
    }

    /** Principal approves or rejects a pending request; everything is re-validated against the CURRENT intervention. */
    public function decideRequest(InterventionRequest $request, User $principal, bool $approve, ?string $notes): InterventionRequest
    {
        $result = DB::transaction(fn() => $this->decideLocked($request, $principal, $approve, $notes));
        if ($result instanceof InterventionTransitionException) {
            throw $result; // after commit: the 'superseded' marking is kept
        }

        return $result;
    }

    private function decideLocked(InterventionRequest $request, User $principal, bool $approve, ?string $notes): InterventionRequest|InterventionTransitionException
    {
        return (function () use ($request, $principal, $approve, $notes) {
            $row = $this->lock($request->intervention);
            $req = InterventionRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($req->status !== 'pending') {
                throw new InterventionTransitionException('This request was already ' . $req->status . '.');
            }
            $decide = fn(string $status) => $req->fill(['status' => $status, 'decided_by' => $principal->id, 'decided_at' => now(), 'decision_notes' => $notes])->save();

            if (!$approve) {
                $decide('rejected');
                $this->event($row, 'request_rejected', $principal, null, ['request_id' => $req->id], $notes, 'req' . $req->id);
                LogActivity::log('decide_intervention_request', "Rejected request #{$req->id} on intervention #{$row->id}", 'interventions', $row->id, $principal->id);

                return $req;
            }

            if ($req->type !== 'late_completion') {
                // A request from the PREVIOUS approval workflow is never executed
                // now: the responsible staff manage the schedule directly.
                throw new InterventionTransitionException('This schedule/extension request belongs to the previous approval workflow and cannot be approved. The responsible staff now set or extend the schedule directly; you can dismiss it (Reject).', 410);
            }
            $state = $row->lifecycle();
            if (in_array($state, ['completed', 'cancelled'], true)) {
                throw new InterventionTransitionException('The intervention is now ' . strtolower(Intervention::LIFECYCLE_LABELS[$state]) . '; the request can only be rejected.');
            }
            if ($req->based_on_version !== $row->schedule_version) {
                $decide('superseded');
                $this->event($row, 'request_superseded', $principal, null, ['request_id' => $req->id], 'The schedule changed after this request was made.', 'req' . $req->id);

                return new InterventionTransitionException('The schedule changed after this request was made, so approving it could overwrite the newer schedule. It was marked superseded; ask for a new request if still needed.', 409, committed: true);
            }

            if ($req->type === 'late_completion') {
                if ($state !== 'expired') {
                    throw new InterventionTransitionException('A late-recorded completion can only be approved for an expired intervention.');
                }
                $this->assertOnTime($row, $req->actual_completed_at);
                $this->assertNoOtherOpen($row);
                $requester = User::find($req->requested_by);
                $row->fill(['status' => $row->status_before_expiry ?: 'approved', 'completed_at' => $req->actual_completed_at, 'delivered_at' => now(),
                    'delivered_by' => $requester?->id, 'delivery_notes' => trim($req->reason . "\n" . (string) $req->evidence_notes)])->save();
                $decide('approved');
                $this->event($row, 'request_approved', $principal, null, ['request_id' => $req->id, 'type' => 'late_completion'], $notes, 'req' . $req->id);
                $this->event($row, 'completed', $requester, ['status' => Intervention::STATUS_EXPIRED],
                    ['completed_at' => $req->actual_completed_at->format('Y-m-d H:i:s'), 'recorded_late' => true, 'approved_by' => $principal->id, 'judged_against_deadline' => ($row->expired_at ?? $row->deadline_at)->format('Y-m-d H:i:s'), 'schedule_version' => $row->schedule_version], $req->reason, 'once', $req->actual_completed_at);
            }
            LogActivity::log('decide_intervention_request', "Approved request #{$req->id} on intervention #{$row->id}", 'interventions', $row->id, $principal->id);

            return $req;
        })();
    }

    // ---- Expiry (scheduled command) ------------------------------------------------------

    /**
     * Persists expiry for every overdue intervention: approved (decided),
     * scheduled, no completion, not cancelled, deadline < $now. Bounded
     * batches; each row re-read under a lock and re-checked (a completion,
     * extension or cancellation that won the race is left alone). Safe to
     * rerun: an expired row no longer matches, and the 'expired' event is
     * unique per deadline. Catches up any backlog after downtime.
     *
     * @return int rows expired
     */
    public function expireOverdue(?Carbon $now = null, int $batch = self::EXPIRY_BATCH): int
    {
        $now ??= now();
        $total = 0;
        $lastId = 0;
        while (true) {
            $ids = Intervention::whereIn('status', array_diff(Intervention::OPEN_STATUSES, [Intervention::STATUS_RECOMMENDED]))
                ->whereNotNull('decided_by')
                ->whereNotNull('deadline_at')->where('deadline_at', '<', $now)
                ->whereNull('delivered_at')->whereNull('completed_at')
                ->where('id', '>', $lastId)->orderBy('id')->limit($batch)->pluck('id');
            if ($ids->isEmpty()) {
                return $total;
            }
            $lastId = $ids->last();
            foreach ($ids as $id) {
                $total += DB::transaction(function () use ($id, $now) {
                    $row = Intervention::whereKey($id)->lockForUpdate()->first();
                    if (!$row || $row->lifecycle($now) !== 'expired' || $row->status === Intervention::STATUS_EXPIRED) {
                        return 0;
                    }
                    $this->persistExpiry($row, $now);

                    return 1;
                }, 3);
            }
        }
    }

    // ---- Internals -------------------------------------------------------------------------

    private function applyReopen(Intervention $row, User $actor, Carbon $deadline, ?Carbon $start, string $reason, ?int $requestId): void
    {
        if ($row->lifecycle() !== 'expired') {
            throw new InterventionTransitionException('Only an expired intervention can be reopened. Change the schedule of an ongoing one instead.');
        }
        if ($start === null) {
            throw new InterventionTransitionException('A start date and time is required.');
        }
        $this->assertWindow($start, $deadline);
        $this->assertNoOtherOpen($row);
        $responsible = $row->responsible_user_id ? User::find($row->responsible_user_id) : null;
        if (!$this->isEligible($responsible, $row)) {
            throw new InterventionTransitionException('The responsible adviser is no longer this section\'s active adviser. Transfer it to the current section adviser first.');
        }
        $old = $this->scheduleValues($row) + ['status' => $row->status];
        $row->fill(['status' => $row->status_before_expiry ?: 'approved', 'starts_at' => $start, 'deadline_at' => $deadline,
            'schedule_version' => $row->schedule_version + 1])->save();
        $this->event($row, 'reopened', $actor, $old, $this->scheduleValues($row) + ['status' => $row->status, 'request_id' => $requestId, 'schedule_version' => $row->schedule_version], $reason, 'v' . $row->schedule_version);
        // Requests made against the lapsed schedule (incl. a late-completion
        // claim about that expiry) no longer apply: say so, never leave them pending.
        $this->supersedePending($row, $actor, 'The intervention was reopened with a new deadline.');
        LogActivity::log('reopen_intervention', "Reopened expired intervention #{$row->id} with deadline {$deadline->format('Y-m-d H:i')}: {$reason}", 'interventions', $row->id, $actor->id);
    }

    /** Records an expiry (the job, or a direct reopen of an overdue row). Event unique per deadline: never twice. */
    private function persistExpiry(Intervention $row, Carbon $now): void
    {
        $deadline = $row->deadline_at->copy();
        $row->fill(['status_before_expiry' => $row->status, 'status' => Intervention::STATUS_EXPIRED,
            'expired_at' => $deadline, 'expiry_processed_at' => $now])->save();
        $this->event($row, 'expired', null, ['status' => $row->status_before_expiry], ['status' => Intervention::STATUS_EXPIRED],
            'The deadline passed with no completion recorded.', 'deadline:' . $deadline->format('Y-m-d H:i:s'), $deadline, $now);
    }

    /** No backdating: a start set by staff is never before the current minute (server clock). */
    private function assertNotBackdated(?Carbon $start): void
    {
        if ($start !== null && $start->lessThan(now()->startOfMinute())) {
            throw new InterventionTransitionException('The start cannot be in the past (' . $this->fmt($start) . '). Use the current time or later.');
        }
    }

    /**
     * Pending schedule/extension requests come from the PREVIOUS approval
     * workflow. A direct schedule change by the responsible staff replaces
     * them: they are marked superseded (kept, never executed).
     */
    private function supersedeLegacyScheduleRequests(Intervention $row, User $actor): void
    {
        foreach (InterventionRequest::where('intervention_id', $row->id)->where('status', 'pending')->whereIn('type', ['schedule', 'extension'])->lockForUpdate()->get() as $req) {
            $req->fill(['status' => 'superseded', 'decided_at' => now(), 'decision_notes' => 'Replaced by a direct schedule change by the responsible staff (previous approval workflow).'])->save();
            $this->event($row, 'request_superseded', $actor, null, ['request_id' => $req->id], 'Replaced by a direct schedule change.', 'req' . $req->id);
        }
    }

    private function lock(Intervention $iv): Intervention
    {
        return Intervention::whereKey($iv->id)->lockForUpdate()->firstOrFail();
    }

    private function assertVersion(Intervention $row, int $expected): void
    {
        if ($row->schedule_version !== $expected) {
            throw new InterventionTransitionException('This intervention\'s schedule was changed by someone else after you opened it. Nothing was saved — reload to see the current schedule.', 409);
        }
    }

    private function assertWindow(?Carbon $start, ?Carbon $deadline): void
    {
        if ($start === null || $deadline === null) {
            throw new InterventionTransitionException('Both a start and a deadline (date and time) are required.');
        }
        if (!$deadline->greaterThan($start)) {
            throw new InterventionTransitionException('The deadline must be later than the start.');
        }
        if (!$deadline->greaterThan(now())) {
            throw new InterventionTransitionException('The deadline must be in the future.');
        }
    }

    private function assertResponsible(Intervention $row, User $staff, string $action = 'record completion for this intervention'): void
    {
        if ($row->responsible_user_id === null) {
            throw new InterventionTransitionException('No responsible adviser is assigned yet. The Principal transfers it to the section adviser on the intervention\'s page.', 403);
        }
        if ((int) $row->responsible_user_id !== (int) $staff->id) {
            throw new InterventionTransitionException("Only the responsible adviser can {$action}.", 403);
        }
        if (!$this->isEligible($staff, $row)) {
            throw new InterventionTransitionException('You are no longer this section\'s active adviser, so you cannot manage this intervention. Its responsibility moves to the current section adviser.', 403);
        }
    }

    /** A late-recorded completion must have happened within the window that lapsed: start <= actual <= original deadline, and not in the future. */
    private function assertOnTime(Intervention $row, ?Carbon $actual): void
    {
        $deadline = $row->expired_at ?? $row->deadline_at;
        if ($actual === null || $actual->greaterThan(now())) {
            throw new InterventionTransitionException('The actual completion time is required and cannot be in the future.');
        }
        if ($actual->greaterThan($deadline)) {
            throw new InterventionTransitionException('The actual completion (' . $this->fmt($actual) . ') is after the deadline (' . $this->fmt($deadline) . '): that is late completion, not on-time completion recorded late. Reopen it with a new schedule instead.');
        }
        if ($row->starts_at && $actual->lessThan($row->starts_at)) {
            throw new InterventionTransitionException('The actual completion cannot be before the start (' . $this->fmt($row->starts_at) . ').');
        }
    }

    /** Reopening/restoring may not create a second open intervention for the same learner, subject and term. */
    private function assertNoOtherOpen(Intervention $row): void
    {
        \App\Models\Student::whereKey($row->student_id)->lockForUpdate()->first();
        // A LOCKING read: under REPEATABLE READ a plain SELECT would use the
        // snapshot taken earlier in this transaction and miss an intervention
        // a concurrent store() committed while we waited for the learner lock
        // (reproduced on isolated MySQL 2026-10-06: two open interventions).
        $other = Intervention::where('id', '!=', $row->id)->where('student_id', $row->student_id)->where('subject_id', $row->subject_id)
            ->where('grading_period', $row->grading_period)->whereIn('status', Intervention::OPEN_STATUSES)->lockForUpdate()->get(['id'])->isNotEmpty();
        if ($other) {
            throw new InterventionTransitionException('Another open intervention now exists for this learner, subject and term. Close or cancel it first.');
        }
    }

    private function supersedePending(Intervention $row, User $actor, string $why): void
    {
        foreach (InterventionRequest::where('intervention_id', $row->id)->where('status', 'pending')->lockForUpdate()->get() as $req) {
            $req->fill(['status' => 'superseded', 'decided_at' => now(), 'decision_notes' => $why])->save();
            $this->event($row, 'request_superseded', $actor, null, ['request_id' => $req->id], $why, 'req' . $req->id);
        }
    }

    private function scheduleValues(Intervention $row): array
    {
        return [
            'title' => $row->title, 'responsible_user_id' => $row->responsible_user_id,
            'starts_at' => $row->starts_at?->format('Y-m-d H:i:s'), 'deadline_at' => $row->deadline_at?->format('Y-m-d H:i:s'),
            'instructions' => $row->instructions, 'completion_criteria' => $row->completion_criteria,
        ];
    }

    private function event(Intervention $row, string $event, ?User $actor, ?array $old, ?array $new, ?string $reason, string $dedupe, ?Carbon $effective = null, ?Carbon $recorded = null): void
    {
        $recorded ??= now();
        // insertOrIgnore on the unique (intervention, event, dedupe_key): a
        // retried request or rerun never writes a second copy.
        InterventionEvent::insertOrIgnore([[
            'intervention_id' => $row->id, 'event' => $event, 'actor_id' => $actor?->id,
            'old_values' => $old === null ? null : json_encode($old), 'new_values' => $new === null ? null : json_encode($new),
            'reason' => $reason, 'effective_at' => ($effective ?? $recorded)->format('Y-m-d H:i:s'), 'recorded_at' => $recorded->format('Y-m-d H:i:s'),
            'dedupe_key' => $dedupe,
        ]]);
    }

    /**
     * A datetime-local form value ("YYYY-MM-DDTHH:MM") read as Asia/Manila
     * (the application timezone). Null for an empty or malformed value — an
     * explicit date AND time is required; no cutoff hour is ever assumed.
     */
    public static function parseLocal(?string $value): ?Carbon
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return null;
        }
        $parsed = Carbon::createFromFormat('Y-m-d\TH:i', $value, config('app.timezone'));

        return $parsed && $parsed->format('Y-m-d\TH:i') === $value ? $parsed->second(0) : null; // rejects 2026-02-30T25:00
    }

    /**
     * Term facts that affect the activity — informational, never a block:
     * the term's academic recording is locked (closed term / past year), or
     * the deadline falls after the term's recorded end date. Terms are never
     * reopened here, and no "must end with the term" rule is applied.
     *
     * @return list<string>
     */
    public function termNotices(Intervention $iv, ?Carbon $deadline = null): array
    {
        if (!$iv->school_year || !$iv->grading_period) {
            return [];
        }
        $notices = [];
        if (!\App\Models\AcademicTerm::acceptsWrites($iv->school_year, (int) $iv->grading_period)) {
            $notices[] = "Term {$iv->grading_period} ({$iv->school_year}) is closed for academic recording: scores and grades for it cannot be entered or changed. "
                . 'If completing this activity needs a score recorded, that is currently locked — terms are not reopened automatically.';
        }
        $deadline ??= $iv->deadline_at;
        $end = \App\Models\AcademicTerm::where('school_year', $iv->school_year)->where('term', $iv->grading_period)->value('end_date');
        if ($deadline && $end && $deadline->greaterThan(Carbon::parse($end, config('app.timezone'))->endOfDay())) {
            $notices[] = "The deadline is after Term {$iv->grading_period} ends (" . Carbon::parse($end)->format('M j, Y') . '). Scores for this term can only be recorded while it is open.';
        }

        return $notices;
    }

    public static function fmt(?\DateTimeInterface $t): string
    {
        return $t ? Carbon::instance($t)->timezone(config('app.timezone'))->format('M j, Y g:i A') : '—';
    }
}
