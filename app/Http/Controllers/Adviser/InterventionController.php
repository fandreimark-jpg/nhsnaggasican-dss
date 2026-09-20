<?php

namespace App\Http\Controllers\Adviser;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Subject;
use App\Services\ProgressMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * InterventionController (Adviser) — closes the loop TASK 1 of "close
 * the intervention loop" describes: the Principal records a decision on
 * the Students page, and before this controller existed, nothing told
 * the adviser who would actually deliver it that it existed at all.
 *
 * Read and acknowledge ONLY. The adviser cannot create, approve, or
 * delete an intervention, and cannot change status, recommended_type, or
 * principal_notes — see RoleAuthorizationTest and
 * Principal\InterventionController's docblock ("the only WRITE surface
 * the Principal role has"). Acknowledging is a receipt (acknowledged_at/
 * acknowledged_by), not a decision.
 */
class InterventionController extends Controller
{
    public function __construct(
        private ProgressMonitoringService $progress = new ProgressMonitoringService()
    ) {
    }

    /**
     * Every intervention for a student in THIS adviser's section — same
     * scoping as Adviser\StudentController. No status filter: an adviser
     * needs to see the whole history, not just what's still open.
     *
     * TASK 3 of "terminology, transmutation, and interface cleanup" —
     * Subject (scoped to what this section actually takes, via
     * Subject::forSection() — the same lookup the Assessments screen
     * already trusts) and Term filters, alongside the page's existing
     * content. Term defaults to the school's currently open term rather
     * than "all terms" so a fresh page load matches what the adviser is
     * actively working on; either can be cleared/changed via the filter
     * form, same auto-submit pattern as every other filter in this app.
     */
    public function index(Request $request)
    {
        $section = Section::forAdviser(auth()->id());

        $subjectId = $request->input('subject_id');
        // has() (not filled()) — an explicitly-submitted EMPTY value (the
        // "All terms" option) must mean "show every term," distinct from
        // the key being absent entirely (a fresh page load, which
        // defaults to the open term).
        $gradingPeriod = $request->has('grading_period')
            ? ($request->filled('grading_period') ? (int) $request->input('grading_period') : null)
            : ($section ? AcademicTerm::currentOpenTerm($section->school_year) : null);

        // "Student identity and term-specific subject offerings" pass — the
        // Subject filter lists the selected term's offerings; "All terms"
        // lists the union across the year (Subject::forSection() with null).
        $subjects = $section
            ? Subject::forSection($section, $gradingPeriod)->orderBy('type')->orderBy('name')->get()
            : collect();

        $interventions = $section
            ? Intervention::where('section_id', $section->id)
                ->when($subjectId, fn($q) => $q->where('subject_id', $subjectId))
                // An intervention with no recorded term (nullable on older
                // rows — see the migration notes) isn't "about" any
                // particular term, so the Term filter never hides it —
                // only narrows among rows that DO have one.
                ->when($gradingPeriod, fn($q) => $q->where(fn($q2) => $q2->where('grading_period', $gradingPeriod)->orWhereNull('grading_period')))
                // 'section' — Intervention::contextSection() reads it per
                // row for the progress comparison below; without it every
                // row lazy-loaded the same section again.
                ->with(['student', 'section', 'subject', 'decidedBy', 'acknowledgedBy', 'deliveredBy'])
                ->latest()
                ->get()
            : collect();

        // TASK 3 of "close the delivery loop" — bounded to one section's
        // interventions (never the whole school), so computing this for
        // every row here is cheap, same shape as
        // Principal\InterventionController::index()'s equivalent loop.
        // "Clarity, progress, and visual design pass" TASK 3d — group
        // sizes for every row in ONE query, not one COUNT per row.
        $groupSizes = Intervention::groupDeliverySizes($interventions);

        $interventions->each(function (Intervention $intervention) use ($groupSizes) {
            $intervention->withinTermProgress = $this->progress->compareWithinTerm($intervention);
            $intervention->deliveryGroupSize = $intervention->delivery_group_id
                ? ($groupSizes[$intervention->delivery_group_id] ?? null)
                : null;
        });

        // TASK 4 of "UI cleanup and correctness pass" — undelivered items
        // need attention now; delivered ones are historical record. PHP
        // 8's sort is stable, so this preserves the ->latest() ordering
        // above within each of the two groups.
        $interventions = $interventions->sortBy(fn (Intervention $iv) => $iv->delivered_at !== null ? 1 : 0)->values();

        // "Correctness and interface pass" TASK 2b — split out
        // recommendations the Principal hasn't decided on yet into their
        // own group, shown but not actionable (see the view): the adviser
        // should see what's coming without being able to acknowledge or
        // deliver a decision that was never actually made.
        [$awaitingPrincipalDecision, $interventions] = $interventions->partition(
            fn (Intervention $iv) => $iv->awaitingDecision()
        );

        // "Master pass" PART 1.3a — the panel's wording must match who
        // actually created the record, not describe a workflow the
        // system doesn't have. Split further by origin so a genuine mix
        // (once a 'system' source exists) renders as two distinct
        // groups instead of one sentence trying to cover both.
        [$awaitingPrincipalOrigin, $awaitingSystemOrigin] = $awaitingPrincipalDecision->partition(
            fn (Intervention $iv) => !$iv->isSystemGenerated()
        );

        return view('adviser.interventions', compact(
            'section', 'interventions', 'awaitingPrincipalDecision',
            'awaitingPrincipalOrigin', 'awaitingSystemOrigin',
            'subjects', 'gradingPeriod'
        ));
    }

    /**
     * Records that the adviser has SEEN this decision — nothing more.
     * Scoped to the adviser's own section the same way
     * Adviser\StudentController::update() is: a 403 (via abort, not a
     * silent redirect) for an intervention belonging to another
     * section's student, since this is a security boundary, not a
     * missing-record 404.
     */
    public function acknowledge(Request $request, Intervention $intervention)
    {
        $section = Section::forAdviser(auth()->id());

        abort_if(!$section || (int) $intervention->section_id !== (int) $section->id, 403);

        // "Correctness and interface pass" TASK 2a — the Principal must
        // have actually decided before an adviser can acknowledge. See
        // Intervention::awaitingDecision().
        if ($intervention->awaitingDecision()) {
            return back()->with('error', 'This intervention is still awaiting the Principal\'s decision — it cannot be acknowledged yet.');
        }

        $intervention->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => auth()->id(),
        ]);

        LogActivity::log(
            'acknowledge_intervention',
            'Acknowledged intervention #' . $intervention->id . ' for ' . $intervention->student->last_name . ', ' . $intervention->student->first_name,
            'interventions',
            $intervention->id
        );

        return redirect()->route('adviser.interventions')->with('success', 'Marked as acknowledged.');
    }

    /**
     * TASK 4 of "terminology, transmutation, and interface cleanup" —
     * acknowledgement is a receipt, not a decision (see this class's
     * docblock), so doing it thirteen times one at a time carries no
     * individual judgement worth thirteen separate clicks. Acknowledges
     * every NOT-YET-acknowledged intervention for this adviser's own
     * section that matches whatever Subject/Term filters are currently
     * applied on the page — never the whole section's history
     * unconditionally, and never anything already acknowledged (no-op,
     * not an error, if everything in scope already is). Delivery is
     * deliberately NOT offered in bulk — see markDelivered()'s docblock:
     * a bulk delivery note would claim work that may not have happened.
     */
    public function acknowledgeAll(Request $request)
    {
        $section = Section::forAdviser(auth()->id());

        abort_if(!$section, 403);

        $subjectId = $request->input('subject_id');
        $gradingPeriod = $request->input('grading_period');

        // "Correctness and interface pass" TASK 2a — never bulk-acknowledge
        // a recommendation the Principal hasn't decided on yet (see
        // Intervention::awaitingDecision()) — same guard as the single-row
        // acknowledge() action, applied here so the bulk count/action can
        // never include what individual acknowledgement would reject.
        $interventions = Intervention::where('section_id', $section->id)
            ->whereNull('acknowledged_at')
            ->where('status', '!=', Intervention::STATUS_RECOMMENDED)
            ->whereNotNull('decided_by')
            ->when($subjectId, fn($q) => $q->where('subject_id', $subjectId))
            ->when($gradingPeriod, fn($q) => $q->where('grading_period', $gradingPeriod))
            ->get();

        foreach ($interventions as $intervention) {
            $intervention->update([
                'acknowledged_at' => now(),
                'acknowledged_by' => auth()->id(),
            ]);
        }

        LogActivity::log(
            'acknowledge_intervention_bulk',
            'Acknowledged ' . $interventions->count() . ' intervention(s) in bulk for section ' . $section->name,
            'interventions',
            null
        );

        return redirect()->route('adviser.interventions', $request->only(['subject_id', 'grading_period']))
            ->with('success', 'Acknowledged ' . $interventions->count() . ' intervention(s).');
    }

    /**
     * TASK 2 of "close the delivery loop": a record that the extra
     * performance task / remedial session / parent conference actually
     * HAPPENED — not just that the adviser read the decision. Requires
     * acknowledgement first (Acknowledged -> Delivered is the only order
     * this can happen in — see AdviserInterventionDeliveryTest's
     * "rejected before acknowledgement" case) and a short note describing
     * what was done. Still never touches status/recommended_type/
     * principal_notes — delivery is a record of action taken, not a
     * decision (see this class's docblock).
     */
    public function markDelivered(Request $request, Intervention $intervention)
    {
        $section = Section::forAdviser(auth()->id());

        abort_if(!$section || (int) $intervention->section_id !== (int) $section->id, 403);

        // "Correctness and interface pass" TASK 2a — checked even though
        // acknowledge() already guards this, since acknowledged_at could
        // in principle predate this guard's rollout on an old row; either
        // way delivery must never proceed on an undecided recommendation.
        if ($intervention->awaitingDecision()) {
            return $this->deliveryFailureResponse($request, 'This intervention is still awaiting the Principal\'s decision — it cannot be delivered yet.');
        }

        if (!$intervention->acknowledged_at) {
            return $this->deliveryFailureResponse($request, 'Acknowledge this intervention before marking it delivered.');
        }

        $request->validate([
            'delivery_notes' => 'required|string|max:2000',
        ]);

        $intervention->update([
            'delivered_at'    => now(),
            'delivered_by'    => auth()->id(),
            'delivery_notes'  => $request->delivery_notes,
        ]);

        LogActivity::log(
            'deliver_intervention',
            'Marked intervention #' . $intervention->id . ' as delivered for ' . $intervention->student->last_name . ', ' . $intervention->student->first_name,
            'interventions',
            $intervention->id
        );

        // TASK 4 of "UI cleanup and correctness pass" — a JS-driven caller
        // (the row's own fetch() submit, see resources/js/intervention-
        // delivery.js) asks for JSON via the Accept header and updates
        // just that row in place, instead of the full-page redirect a
        // non-JS submit still gets below. Never bulk — this always
        // records exactly ONE intervention's own note, same as the form
        // it replaces.
        if ($request->expectsJson()) {
            return response()->json([
                'id'                    => $intervention->id,
                'delivered_at'          => $intervention->delivered_at->format('M d, Y h:i A'),
                'delivery_notes'        => $intervention->delivery_notes,
                'add_assessment_item_url' => ($intervention->subject_id && $intervention->grading_period)
                    ? route('adviser.assessments', array_filter([
                        'period'            => $intervention->grading_period,
                        'subject_id'        => $intervention->subject_id,
                        'add_item'          => 1,
                        'component'         => $intervention->focusComponent(),
                        'from_intervention' => 1,
                    ]))
                    : null,
                'message' => 'Marked as delivered.',
            ]);
        }

        return redirect()->route('adviser.interventions')->with('success', 'Marked as delivered.');
    }

    /** JSON error for a fetch()-driven deliver submit; redirect-with-error for a normal form submit — see markDelivered(). */
    private function deliveryFailureResponse(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }

    /**
     * "Clarity, progress, and visual design pass" TASK 3 — the ONE
     * exception to "delivery is never bulk": several learners who
     * genuinely received the SAME activity in the same act (a single
     * re-teaching session for a group of At Risk learners) can be marked
     * delivered together, with ONE note that is honestly labelled as
     * covering all of them — never presented as if it were written for
     * one child alone (see Intervention::groupDeliverySizes() and the
     * "Delivered as a group activity (N learners)" badge on both
     * Interventions views).
     *
     * Still requires a REAL note: 'required' alone would let a
     * whitespace-only string through (and min:20 counts bytes, not
     * meaningful content, so "                    " would also pass it),
     * so the closure below checks the TRIMMED length explicitly — see
     * GroupDeliveryRequiresNoteTest. Every included id is re-checked
     * against this adviser's own section, already acknowledged, and not
     * yet delivered — the same eligibility markDelivered() enforces for
     * one row at a time — and anything that fails silently drops out of
     * the batch (reported back as skipped) rather than failing the whole
     * request, since a stale checkbox state (another tab already
     * delivered one of these) is a normal race, not an error worth
     * blocking the rest of the group over.
     */
    public function markDeliveredGroup(Request $request)
    {
        $section = Section::forAdviser(auth()->id());

        abort_if(!$section, 403);

        $request->validate([
            'intervention_ids'   => 'required|array|min:1',
            'intervention_ids.*' => 'required|integer|exists:interventions,id',
            'delivery_notes'     => ['required', 'string', 'max:2000', function ($attribute, $value, $fail) {
                if (mb_strlen(trim((string) $value)) < 20) {
                    $fail('Describe what was actually done — at least 20 characters, not counting leading/trailing spaces.');
                }
            }],
        ]);

        // "Correctness and interface pass" TASK 2a — same undecided guard
        // as the individual markDelivered()/acknowledge() actions; a
        // selected intervention that somehow still awaits the Principal's
        // decision is simply not eligible and silently drops out of the
        // batch (reported as skipped), same as any other ineligible row.
        $eligible = Intervention::whereIn('id', $request->input('intervention_ids'))
            ->where('section_id', $section->id)
            ->whereNotNull('acknowledged_at')
            ->whereNull('delivered_at')
            ->where('status', '!=', Intervention::STATUS_RECOMMENDED)
            ->whereNotNull('decided_by')
            ->get();

        if ($eligible->isEmpty()) {
            return back()->with('error', 'None of the selected interventions are eligible for group delivery — each must already be acknowledged and not yet delivered.');
        }

        $groupId = (string) Str::uuid();
        $notes = trim($request->input('delivery_notes'));

        DB::transaction(function () use ($eligible, $groupId, $notes) {
            foreach ($eligible as $intervention) {
                $intervention->update([
                    'delivered_at'       => now(),
                    'delivered_by'       => auth()->id(),
                    'delivery_notes'     => $notes,
                    'delivery_mode'      => 'group',
                    'delivery_group_id'  => $groupId,
                ]);

                LogActivity::log(
                    'deliver_intervention_group',
                    'Marked intervention #' . $intervention->id . ' as delivered — group activity covering '
                        . $eligible->count() . ' learner(s) — for ' . $intervention->student->last_name . ', ' . $intervention->student->first_name,
                    'interventions',
                    $intervention->id
                );
            }
        });

        $skipped = count($request->input('intervention_ids')) - $eligible->count();
        $message = 'Marked ' . $eligible->count() . ' intervention(s) as delivered together.';
        if ($skipped > 0) {
            $message .= " {$skipped} selected item(s) were skipped — not eligible (already delivered, not yet acknowledged, or not one of your students).";
        }

        return redirect()->route('adviser.interventions')->with('success', $message);
    }
}
