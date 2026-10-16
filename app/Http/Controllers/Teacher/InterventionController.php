<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Intervention;
use App\Services\InterventionScheduleService;
use App\Services\InterventionTransitionException;
use Illuminate\Http\Request;

/**
 * ADVISERS only (2026-10-07; subject teachers have no intervention access —
 * routes are role:adviser): an intervention's page for the adviser of its
 * section or its responsible adviser. Only the RESPONSIBLE adviser (the
 * section's current active adviser, assigned automatically) sets,
 * reschedules, extends or reopens the schedule directly — no approval step —
 * records completion while ongoing, and asks the Principal to review an
 * on-time completion recorded after the deadline. Nothing here changes
 * scores, grades, concerns or any other learner record.
 */
class InterventionController extends Controller
{
    public function __construct(private InterventionScheduleService $service = new InterventionScheduleService())
    {
    }

    private const DETAIL_ROUTE = 'adviser.interventions.show';

    /** An old /teacher/interventions bookmark (advisers only) lands on the adviser's Interventions, filter kept. */
    public function index(Request $request)
    {
        $filter = $request->input('filter');
        $schedule = $filter === 'mine' || array_key_exists((string) $filter, Intervention::LIFECYCLE_LABELS) ? $filter : null;

        return redirect()->route('adviser.interventions', array_filter(['schedule' => $schedule]));
    }

    public function show(Request $request, Intervention $intervention)
    {
        abort_unless(Intervention::schedulingAvailable(), 404);
        abort_unless($this->service->inScope($request->user(), $intervention), 403);
        if ($request->route()->getName() !== self::DETAIL_ROUTE) {
            return redirect()->route(self::DETAIL_ROUTE, $intervention); // old /teacher bookmark
        }
        $intervention->load(['student', 'subject', 'section', 'academicConcern', 'responsibleUser', 'decidedBy', 'acknowledgedBy', 'deliveredBy',
            'cancelledBy', 'events.actor', 'scheduleRequests.requestedBy', 'scheduleRequests.decidedBy']);

        return view('teacher.intervention-show', [
            'intervention'  => $intervention,
            'state'         => $intervention->lifecycle(),
            'isResponsible' => (int) $intervention->responsible_user_id === (int) $request->user()->id && $this->service->isEligible($request->user(), $intervention),
            'availability'  => $this->service->availability($intervention, $request->user()),
            'termNotices'   => $this->service->termNotices($intervention),
        ]);
    }

    public function complete(Request $request, Intervention $intervention)
    {
        $this->guard($request, $intervention);
        $data = $request->validateWithBag('complete', ['completed_at' => 'required|string', 'notes' => 'required|string|min:5|max:2000']);
        $completedAt = InterventionScheduleService::parseLocal($data['completed_at']);
        if (!$completedAt) {
            return back()->withErrors(['completed_at' => 'Enter a valid date and time (Manila time).'], 'complete')->withInput();
        }

        return $this->attempt($intervention, fn() => $this->service->recordCompletion($intervention, $request->user(), $completedAt, trim($data['notes'])), 'Completion recorded.');
    }

    /** Responsible staff: set the first schedule, or reschedule (reason required) before it starts. */
    public function schedule(Request $request, Intervention $intervention)
    {
        $this->guard($request, $intervention);
        $data = $request->validateWithBag('schedule', [
            'title'               => 'nullable|string|max:255',
            'starts_at'           => 'required|string',
            'deadline_at'         => 'required|string',
            'instructions'        => 'nullable|string|max:5000',
            'completion_criteria' => 'nullable|string|max:5000',
            'reason'              => 'nullable|string|max:2000',
            'schedule_version'    => 'required|integer',
        ]);
        [$start, $deadline] = $this->times($request, ['starts_at', 'deadline_at'], 'schedule');
        $first = $intervention->deadline_at === null;

        return $this->attempt($intervention, fn() => $this->service->schedule($intervention, [
            'title' => trim((string) ($data['title'] ?? '')) ?: null, 'instructions' => $data['instructions'] ?? null,
            'completion_criteria' => $data['completion_criteria'] ?? null, 'starts_at' => $start, 'deadline_at' => $deadline,
            'reason' => $data['reason'] ?? null,
        ], $request->user(), (int) $data['schedule_version']), $first ? 'Schedule set.' : 'Schedule changed.', $deadline);
    }

    /** Responsible staff: a later deadline with a reason; the start is kept. */
    public function extend(Request $request, Intervention $intervention)
    {
        $this->guard($request, $intervention);
        $data = $request->validateWithBag('extend', [
            'deadline_at'      => 'required|string',
            'reason'           => 'required|string|min:5|max:2000',
            'schedule_version' => 'required|integer',
        ]);
        [$deadline] = $this->times($request, ['deadline_at'], 'extend');

        return $this->attempt($intervention, fn() => $this->service->extend($intervention, $request->user(), $deadline, trim($data['reason']), (int) $data['schedule_version']),
            'Deadline extended.', $deadline);
    }

    /** Responsible staff: reopen an expired intervention with a new start, deadline and reason. */
    public function reopen(Request $request, Intervention $intervention)
    {
        $this->guard($request, $intervention);
        $data = $request->validateWithBag('reopen', [
            'starts_at'        => 'required|string',
            'deadline_at'      => 'required|string',
            'reason'           => 'required|string|min:5|max:2000',
            'schedule_version' => 'required|integer',
        ]);
        [$start, $deadline] = $this->times($request, ['starts_at', 'deadline_at'], 'reopen');

        return $this->attempt($intervention, fn() => $this->service->reopen($intervention, $request->user(), $start, $deadline, trim($data['reason']), (int) $data['schedule_version']),
            'Intervention reopened with the new schedule.', $deadline);
    }

    /**
     * Only the on-time-completion correction remains a request (reviewed by
     * the Principal). Schedule and extension requests were retired 2026-10-07.
     */
    public function request(Request $request, Intervention $intervention)
    {
        $this->guard($request, $intervention);
        if ($request->input('type') !== 'late_completion') {
            return back()->with('error', 'Schedule and extension requests are no longer used: the responsible staff set, extend or reopen the schedule directly on this page. Nothing was sent.');
        }
        $data = $request->validateWithBag('correction', [
            'actual_completed_at' => 'required|string',
            'reason' => 'required|string|min:5|max:2000',
            'evidence_notes' => 'nullable|string|max:2000',
        ]);
        [$actual] = $this->times($request, ['actual_completed_at'], 'correction');

        return $this->attempt($intervention, fn() => $this->service->submitRequest($intervention, $request->user(), 'late_completion', [
            'actual_completed_at' => $actual, 'reason' => trim($data['reason']), 'evidence_notes' => $data['evidence_notes'] ?? null,
        ]), 'Correction sent to the Principal for review.');
    }

    private function guard(Request $request, Intervention $intervention): void
    {
        abort_unless(Intervention::schedulingAvailable(), 404);
        abort_unless($this->service->inScope($request->user(), $intervention), 403);
    }

    /** Parses Manila wall-clock inputs; one message per bad field. @return list<\Illuminate\Support\Carbon> */
    private function times(Request $request, array $keys, string $bag = 'default'): array
    {
        $out = [];
        $errors = [];
        foreach ($keys as $key) {
            $out[] = $value = InterventionScheduleService::parseLocal($request->input($key));
            if (!$value) {
                $errors[$key] = 'Enter a valid date and time (Manila time).';
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors)->errorBag($bag);
        }

        return $out;
    }

    private function attempt(Intervention $intervention, callable $action, string $success, ?\Illuminate\Support\Carbon $deadline = null)
    {
        try {
            $action();
        } catch (InterventionTransitionException $e) {
            abort_if($e->status === 403, 403, $e->getMessage());

            // From one of the page's forms: shown inside that form (once, and
            // it stays), not in the auto-dismissing toast.
            return back()->with('error', $e->getMessage())->with('error_inline', request()->filled('_form'))->withInput();
        }

        $notices = $deadline ? $this->service->termNotices($intervention->fresh(), $deadline) : [];

        return redirect()->route(self::DETAIL_ROUTE, $intervention)->with('success', $success)->with('warning', $notices ? implode(' ', $notices) : null);
    }
}
