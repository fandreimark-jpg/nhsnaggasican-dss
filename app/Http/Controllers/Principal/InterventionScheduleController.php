<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\Intervention;
use App\Models\InterventionRequest;
use App\Services\InterventionScheduleService;
use App\Services\InterventionTransitionException;
use Illuminate\Http\Request;

/**
 * Principal: an intervention's detail — learner, linked concern, schedule
 * (set by the responsible staff), requests, completion evidence and history —
 * and the Principal-only transitions: transfer responsibility to the section's current adviser,
 * review a completion correction (or dismiss a request from the previous
 * approval workflow), cancel with a reason. The Principal does NOT set or
 * extend schedules (2026-10-07). Every transition goes through
 * InterventionScheduleService.
 */
class InterventionScheduleController extends Controller
{
    public function __construct(private InterventionScheduleService $service = new InterventionScheduleService())
    {
    }

    public function show(Intervention $intervention)
    {
        if (!Intervention::schedulingAvailable()) {
            return redirect()->route('principal.interventions')->with('error', 'Intervention scheduling is not available on this database yet. Ask the administrator to apply the pending update.');
        }
        $intervention->load(['student', 'subject', 'section', 'academicConcern', 'responsibleUser', 'cancelledBy', 'deliveredBy', 'decidedBy',
            'events.actor', 'scheduleRequests.requestedBy', 'scheduleRequests.decidedBy']);

        return view('principal.intervention-show', [
            'intervention'  => $intervention,
            'state'         => $intervention->lifecycle(),
            'sectionAdviser' => $this->service->adviserFor($intervention),
            'responsibleEligible' => $intervention->responsible_user_id ? $this->service->isEligible($intervention->responsibleUser, $intervention) : null,
            'termNotices'   => $this->service->termNotices($intervention),
        ]);
    }

    /**
     * Transfers responsibility to the section's CURRENT adviser (2026-10-07).
     * The assignee is resolved on the server; any submitted staff id is ignored.
     */
    public function responsible(Request $request, Intervention $intervention)
    {
        $data = $request->validateWithBag('responsible', ['schedule_version' => 'required|integer']);

        return $this->attempt($intervention, fn() => $this->service->transferToSectionAdviser($intervention, $request->user(),
            'Transferred by the Principal to the current section adviser.', (int) $data['schedule_version']),
            'Responsibility is now with the section adviser. Only they can set and change the schedule.');
    }

    /**
     * RETIRED 2026-10-07: the Principal no longer sets schedules or reopens
     * expired interventions — the responsible staff do it directly. The
     * routes stay so an old form or bookmarked POST gets a clear refusal
     * (nothing is written), not a 404/405.
     */
    public function retired(Request $request, Intervention $intervention)
    {
        $message = 'The schedule is now set, extended and reopened by the responsible adviser (the section adviser) on the intervention\'s page. As Principal you decide the intervention, cancel, and review completion corrections. Nothing was changed.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 410);
        }

        return redirect()->route('principal.interventions.show', $intervention)->with('error', $message);
    }

    public function cancel(Request $request, Intervention $intervention)
    {
        $data = $request->validateWithBag('cancel', ['reason' => 'required|string|min:5|max:2000']);

        return $this->attempt($intervention, fn() => $this->service->cancel($intervention, $request->user(), trim($data['reason'])), 'Intervention cancelled. Its history and any linked academic concern are kept.');
    }

    public function decide(Request $request, InterventionRequest $interventionRequest)
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'decision_notes' => 'nullable|string|max:2000',
        ]);

        return $this->attempt($interventionRequest->intervention, fn() => $this->service->decideRequest($interventionRequest, $request->user(), $data['decision'] === 'approve', $data['decision_notes'] ?? null),
            $data['decision'] === 'approve' ? 'Request approved.' : 'Request rejected.');
    }

    private function attempt(Intervention $intervention, callable $action, string $success, ?\Illuminate\Support\Carbon $deadline = null)
    {
        if (!Intervention::schedulingAvailable()) {
            return back()->with('error', 'Intervention scheduling is not available on this database yet.');
        }
        try {
            $action();
        } catch (InterventionTransitionException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
        $notices = $deadline ? $this->service->termNotices($intervention->fresh(), $deadline) : [];

        return redirect()->route('principal.interventions.show', $intervention)->with('success', $success)->with('warning', $notices ? implode(' ', $notices) : null);
    }
}
