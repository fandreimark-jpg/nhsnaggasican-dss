<?php

namespace App\Http\Controllers\Principal;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\AcademicConcern;
use Illuminate\Support\Facades\DB;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Services\AcademicMonitoringOverviewService;
use Illuminate\Http\Request;

/**
 * Principal "Academic Monitoring" (Phase 2D) — read-only.
 *
 * Shows the observable academic concerns the approved monitoring rules found
 * in current assessment evidence (academic_concerns), For Review by default,
 * with the evidence behind each one and the resolved history.
 *
 * A concern is not a prediction and not an intervention: this page never
 * creates or approves one. Its only write is review() (Phase 2E): the
 * Principal marks an open concern as reviewed — nothing else changes.
 * Recording an intervention from a concern goes through the existing
 * Principal\InterventionController::store().
 */
class AcademicMonitoringController extends Controller
{
    public function __construct(private AcademicMonitoringOverviewService $overview = new AcademicMonitoringOverviewService())
    {
    }

    public function index(Request $request)
    {
        $filters = $this->overview->filtersFrom($request);
        $schoolYear = $filters['school_year'];

        $sections = $this->overview->sectionOptions($schoolYear, $filters['grade_level']);

        return view('principal.academic-monitoring', [
            'filters'          => $filters,
            'groups'           => $this->overview->groups($filters),
            'counts'           => $this->overview->counts($filters),
            'schoolYears'      => AcademicYear::selectableSchoolYears(),
            'gradeLevels'      => $this->overview->gradeLevelOptions($schoolYear),
            'sections'         => $sections,
            'selectedSection'  => $filters['section_id'] ? $sections->firstWhere('id', $filters['section_id']) : null,
            'subjects'         => $this->overview->subjectOptions($schoolYear),
            'concernTypes'     => $this->overview->concernTypeOptions(),
            'terms'            => AcademicTerm::termNumbers(),
            'openTerm'         => $schoolYear === Section::activeSchoolYear() ? AcademicTerm::currentOpenTerm($schoolYear) : null,
            'isHistoricalYear' => $schoolYear !== Section::activeSchoolYear(),
            'interventionLinkAvailable' => AcademicConcern::interventionLinkAvailable(),
            'reviewNoteAvailable' => AcademicConcern::reviewNoteAvailable(),
        ]);
    }

    /**
     * Phase 2E — "Mark as Reviewed": the Principal has inspected this
     * concern's evidence. That is ALL it records.
     *
     * It never changes the concern's status, evidence or context, never
     * resolves it (only the monitoring rules do that, when the evidence
     * changes), and never creates an intervention (a separate decision).
     *
     * Only an OPEN concern can be reviewed; a resolved one is history and
     * is refused, not rewritten. A concern that is already reviewed is
     * refused too, so the first review (who, when, note) is never
     * overwritten.
     */
    public function review(Request $request, AcademicConcern $concern)
    {
        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000', 'not_regex:/<[^>]*>/'],
        ], [
            'review_note.not_regex' => 'The review note must be plain text.',
        ]);

        $note = trim((string) ($validated['review_note'] ?? ''));
        $note = $note === '' ? null : $note;

        if ($note !== null && !AcademicConcern::reviewNoteAvailable()) {
            return back()->with('error', 'Review notes are not available yet on this database. Mark the concern as reviewed without a note, or ask the administrator to apply the pending update.');
        }

        $outcome = DB::transaction(function () use ($concern, $note) {
            $fresh = AcademicConcern::whereKey($concern->id)->lockForUpdate()->firstOrFail();

            if (!$fresh->isOpen()) {
                return 'resolved';
            }
            if ($fresh->isReviewed()) {
                return 'already';
            }

            $values = ['reviewed_by' => auth()->id(), 'reviewed_at' => now()];
            if (AcademicConcern::reviewNoteAvailable()) {
                $values['review_note'] = $note;
            }
            $fresh->forceFill($values)->save();

            return 'reviewed';
        });

        if ($outcome === 'resolved') {
            return back()->with('error', 'This concern is already resolved, so it is kept as history and cannot be marked as reviewed.');
        }
        if ($outcome === 'already') {
            return back()->with('error', 'This concern has already been marked as reviewed. The existing review is kept.');
        }

        $e = $concern->evidence;
        $about = \App\Services\MonitoringClassificationService::evidenceLine((array) $e) . ', ' . ($e['learner']['name'] ?? 'learner');
        LogActivity::log(
            'review_academic_concern',
            'Marked academic concern #' . $concern->id . ' as reviewed ('
                . ($e['concern'] ?? 'Academic concern') . ' — ' . $about . ', Term ' . $concern->grading_period . ')',
            'academic_concerns',
            $concern->id
        );

        return back()->with('success', 'Marked as reviewed. The concern stays open until its evidence changes; no intervention was created.');
    }
}
