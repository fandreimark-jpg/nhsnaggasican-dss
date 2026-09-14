<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Helpers\LogActivity;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Subject;
use App\Services\InTermStatusService;
use App\Services\ProgressMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * InterventionController (Principal)
 *
 * The only WRITE surface the Principal role has (see RoleAuthorizationTest
 * — every other principal.* route is read-only). Recording an intervention
 * (store()) happens from the Students page now, not here — see
 * Principal\StudentController's Record Intervention modal and the
 * "separate intervention discovery from tracking" prompt. This controller
 * is the TRACKING half: index() lists interventions already recorded,
 * regardless of whether a risk result backs them, and update() moves one
 * through its status lifecycle. A recommendation is never auto-approved —
 * every Intervention starts at 'recommended' and only advances when the
 * Principal explicitly updates it.
 */
class InterventionController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private ProgressMonitoringService $progress = new ProgressMonitoringService(),
        private InTermStatusService $inTermStatus = new InTermStatusService()
    ) {
    }

    /**
     * Lists RECORDED interventions — deliberately no whereHas('riskResults')
     * or any other risk-result gate anywhere in this method. An
     * intervention created from assessment evidence alone (no submitted
     * term report yet) must appear here exactly like one that has a
     * linked risk result; the whole point of this task is that this page
     * no longer decides which students are worth looking at — the
     * Students page already does that job. This page only tracks
     * decisions that were actually made.
     */
    public function index(Request $request)
    {
        // "Correctness and interface pass" TASK 2c — the dashboard's
        // "Awaiting Your Decision" count must link straight to exactly
        // the rows it counted (Intervention::scopeUndecided() — see
        // DashboardAnalyticsService::getPrincipalSummary() and CLAUDE.md
        // Design Decision #3), not to the unfiltered list.
        $awaitingDecisionOnly = $request->boolean('awaiting_decision');
        // "Decision flow, report scoping, and dashboard pass" TASK 1e —
        // a standing count of interventions still sitting at their
        // as-created state (the deferred "record as a recommendation
        // only" path from store()/storeBulk() — see Intervention::
        // awaitingDecision()), surfaced regardless of the current filter
        // so the Principal always knows whether anything is blocking an
        // adviser, not only when they happen to be viewing that filter.
        $undecidedCount = Intervention::undecided()->count();
        $readyOnly   = $request->boolean('ready_for_review');
        $subjectId   = $request->input('subject_id');
        $gradingPeriod = $this->resolveGradingPeriod($request);
        // "Multi-school-year academic history" work order, PART 12/13 —
        // one school year at a time, defaulting to the active one; a
        // historical year is selectable but never mixed into the current.
        $schoolYear = $this->resolveSchoolYear($request);

        $baseQuery = $this->buildFilteredQuery($request, includeSubjectFilter: false)
            ->with([
                'student',
                'section.track',
                'section.specialization',
                'subject',
                'riskResult',
                'createdBy',
                'decidedBy',
                'acknowledgedBy',
                'deliveredBy',
            ])
            ->when($awaitingDecisionOnly, fn($q) => $q->undecided());

        // TASK 3 of "terminology, transmutation, and interface cleanup" —
        // Subject options are whatever actually appears in the CURRENT
        // result set (every other active filter applied, but not the
        // Subject filter itself — so picking one doesn't collapse the
        // dropdown to just itself) rather than every Subject in the
        // system; listing subjects with zero interventions here would be
        // noise. Computed before the subject filter is added to $baseQuery.
        $availableSubjects = Subject::whereIn('id',
                (clone $baseQuery)->whereNotNull('subject_id')->distinct()->pluck('subject_id')
            )
            ->orderBy('name')
            ->get();

        $baseQuery = $baseQuery->when($subjectId, fn($q) => $q->where('subject_id', $subjectId));

        if ($readyOnly) {
            // "Ready for review" is a computed signal (In-Term Status is
            // never stored — see InTermStatusService::isReadyForReview()),
            // so unlike the other filters it can't be pushed into SQL.
            // Narrow to the small, already-selective candidate set first
            // (open status + actually delivered), check evidence in PHP,
            // then paginate the filtered collection by hand — same
            // "filter the whole set, then page what's left" pattern as
            // Principal\StudentController::buildAtRiskForBulk().
            $candidates = (clone $baseQuery)
                ->whereIn('status', ['approved', 'in_progress'])
                ->whereNotNull('delivered_at')
                ->latest()
                ->get()
                ->filter(fn(Intervention $iv) => $this->inTermStatus->isReadyForReview($iv))
                ->values();

            $page = max(1, (int) $request->input('page', 1));
            $pageItems = $candidates->forPage($page, self::PER_PAGE)->values();

            $interventions = new LengthAwarePaginator(
                $pageItems,
                $candidates->count(),
                self::PER_PAGE,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $interventions = $baseQuery->latest()->paginate(self::PER_PAGE)->withQueryString();
        }

        // "Clarity, progress, and visual design pass" TASK 3d — group
        // sizes for the current page's rows in ONE query, not one COUNT
        // per row (same pattern as Adviser\InterventionController::index()).
        $groupSizes = Intervention::groupDeliverySizes($interventions->items());

        // Progress comparison runs PerformanceAnalysisService::analyzeStudent()
        // twice per intervention — deferred to the current page only, same
        // pattern as everywhere else this app paginates at school scale.
        $interventions->through(function (Intervention $intervention) use ($groupSizes) {
            $intervention->deliveryGroupSize = $intervention->delivery_group_id
                ? ($groupSizes[$intervention->delivery_group_id] ?? null)
                : null;
            $intervention->progress = $this->progress->compare($intervention);
            // TASK 3 of "close the delivery loop" — same page-only-cost
            // pattern as compare() above, just a second (also cheap)
            // comparison per row.
            $intervention->withinTermProgress = $this->progress->compareWithinTerm($intervention);
            // TASK 2 of "bulk dialog and intervention closure" — a signal
            // only; nothing changes until the Principal clicks one of the
            // close buttons rendered for this flag on the view.
            $intervention->readyForReview = $this->inTermStatus->isReadyForReview($intervention);
            // TASK 2c of "clarity, progress, and visual design" — a signal
            // distinct from readyForReview: the targeted component
            // genuinely rose since delivery, but the student has not
            // reached On Track yet. These are the "continue support"
            // cases, currently indistinguishable from "nothing happened."
            $intervention->improvedButBelowTarget = $this->isImprovedButBelowTarget($intervention);
            return $intervention;
        });

        $gradeLevels = Section::where('school_year', $schoolYear)->select('grade_level')->distinct()->orderBy('grade_level')->pluck('grade_level');
        $sections = Section::where('school_year', $schoolYear)
            ->select('id', 'name', 'grade_level', 'track_id', 'specialization_id')
            ->with(['track:id,name', 'specialization:id,name'])
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get();

        return view('principal.interventions', [
            'interventions' => $interventions,
            'statuses'      => Intervention::STATUSES,
            'gradeLevels'   => $gradeLevels,
            'sections'      => $sections,
            'schoolYear'    => $schoolYear,
            'schoolYears'   => AcademicYear::selectableSchoolYears(),
            'isHistoricalYear' => $schoolYear !== Section::activeSchoolYear(),
            'subjects'      => $availableSubjects,
            'gradingPeriod' => $gradingPeriod,
            'readyForReviewCount' => $this->inTermStatus->readyForReviewCount(),
            'awaitingDecisionOnly' => $awaitingDecisionOnly,
            'undecidedCount' => $undecidedCount,
        ]);
    }

    /**
     * TASK 3 of "terminology, transmutation, and interface cleanup" —
     * defaults to the currently open term (same convention as the
     * Adviser Interventions page) rather than "all terms," so a fresh
     * page load matches what's actively being decided; explicitly
     * clearing it (an empty grading_period param) shows every term.
     * has() (not filled()) — an explicitly-submitted EMPTY value (the
     * "All terms" option) must mean "show every term," distinct from the
     * key being absent entirely (a fresh page load, which defaults to
     * the open term).
     */
    private function resolveGradingPeriod(Request $request): ?int
    {
        return $request->has('grading_period')
            ? ($request->filled('grading_period') ? (int) $request->input('grading_period') : null)
            : AcademicTerm::currentOpenTerm($this->resolveSchoolYear($request));
    }

    /**
     * The one school year this page shows — the requested one if it is
     * a real year, else the active one (AcademicYear::resolveSelected()).
     */
    private function resolveSchoolYear(Request $request): string
    {
        return AcademicYear::resolveSelected($request->input('school_year'));
    }

    /**
     * "Master pass" PART 1.4c — the SAME filter chain index() applies
     * (minus eager-loading and the awaiting-decision-only narrowing,
     * which are index()-specific display concerns), extracted so
     * approvePending() can intersect against it directly. This is what
     * makes "never approves outside the current filters" true by
     * construction rather than by convention: approvePending() never
     * trusts a submitted id on its own, only ids that ALSO satisfy this
     * same query.
     */
    private function buildFilteredQuery(Request $request, bool $includeSubjectFilter = true)
    {
        $gradeLevel  = $request->input('grade_level');
        $sectionName = $request->input('section_search');
        $status      = $request->input('status');
        $subjectId   = $request->input('subject_id');
        $studentSearch = $request->input('student_search');
        $riskLevel   = $request->input('risk_level');
        $gradingPeriod = $this->resolveGradingPeriod($request);

        $schoolYear = $this->resolveSchoolYear($request);

        return Intervention::query()
            // PART 12 — scoped to ONE school year, and Grade/Section read
            // the intervention's OWN stored section (the learner's section
            // when it was recorded), never students.section_id.
            ->where('school_year', $schoolYear)
            ->when($gradeLevel, fn($q) => $q->whereHas('section', fn($s) => $s->where('grade_level', $gradeLevel)))
            ->when($sectionName, fn($q) => $q->whereHas('section', fn($s) => $s->where('name', $sectionName)))
            ->when($status, fn($q) => $q->where('status', $status))
            // $includeSubjectFilter is false only for index()'s "which
            // subjects should the dropdown list" computation, which must
            // apply every OTHER active filter but deliberately not this
            // one — see its call site.
            ->when($includeSubjectFilter && $subjectId, fn($q) => $q->where('subject_id', $subjectId))
            // SYSTEM_FIXES_AND_ML_AUDIT.md, "Interventions" — Student
            // filter, by name (same text-search convention as
            // section_search above) rather than a select of every student
            // in the school, which would be unusably long.
            ->when($studentSearch, fn($q) => $q->whereHas('student', function ($s) use ($studentSearch) {
                $s->where(function ($s2) use ($studentSearch) {
                    $s2->where('last_name', 'like', "%{$studentSearch}%")
                       ->orWhere('first_name', 'like', "%{$studentSearch}%");
                });
            }))
            // Risk Level filter — reads the LINKED risk_result's risk_level,
            // never a copy of it on interventions itself (there is none).
            // An intervention with no risk result at all (recorded from
            // in-term evidence, no submitted term report yet — a real,
            // documented case, see CLAUDE.md's "Term-over-Term Progress
            // depends on the order a Principal acted in") has no risk
            // level to match, so it is correctly excluded when this filter
            // is active, not guessed into a bucket.
            ->when($riskLevel, fn($q) => $q->whereHas('riskResult', fn($r) => $r->where('risk_level', $riskLevel)))
            // An intervention with no recorded term (nullable on older
            // rows) isn't "about" any particular term, so the Term
            // filter never hides it — only narrows among rows that DO
            // have one — same rule as Adviser\InterventionController.
            ->when($gradingPeriod, fn($q) => $q->where(fn($q2) => $q2->where('grading_period', $gradingPeriod)->orWhereNull('grading_period')));
    }

    /**
     * Records an intervention — reachable from the Students page's Record
     * Intervention modal (Principal\StudentController), never from this
     * controller's own index() anymore.
     *
     * risk_result_id is null whenever no risk result exists yet for this
     * student/term — that is the entire point of this task: recording
     * must work identically either way, since assessment evidence alone
     * (no submitted term report) is already enough reason to act.
     */
    public function store(Request $request)
    {
        $request->validate([
            'student_id'             => 'required|exists:students,id',
            'subject_id'             => 'required|exists:subjects,id',
            'grading_period'         => 'required|integer|in:1,2,3',
            'recommended_type'       => ['required', Rule::in(Intervention::TYPES)],
            'recommendation_reason'  => 'nullable|string',
            'principal_notes'        => 'nullable|string',
            // "Decision flow, report scoping, and dashboard pass" TASK 1b
            // — deliberately unticked by default (see the view): the
            // common case is that the Principal just reviewed this
            // student and IS deciding right now.
            'recommendation_only'    => 'nullable|boolean',
        ]);

        $student = Student::findOrFail($request->student_id);

        // "Correctness and interface pass" TASK 1b — BUG FIX: an
        // intervention belongs to one subject AND one term, so this guard
        // must scope to (student, subject, term), not (student, subject)
        // alone. The prior subject-only scope meant an open Term 1
        // intervention silently blocked Term 2 and Term 3 forever for the
        // same student/subject.
        $existingOpen = Intervention::where('student_id', $student->id)
            ->where('subject_id', $request->subject_id)
            ->where('grading_period', (int) $request->grading_period)
            ->whereIn('status', Intervention::OPEN_STATUSES)
            ->latest()
            ->first();

        if ($existingOpen) {
            return back()->with('error', "An open intervention already exists for this student and subject in Term {$request->grading_period} — see the Interventions page.");
        }

        // "Multi-school-year academic history" work order, PART 12 — an
        // intervention is recorded against the ACTIVE school year and the
        // learner's section in that year, captured now so the record never
        // has to be re-derived from students.section_id (which changes on
        // promotion). A learner with no enrollment this year cannot have
        // an intervention recorded for it.
        $schoolYear = Section::activeSchoolYear();
        $sectionNow = $student->sectionFor($schoolYear);

        if (!$sectionNow) {
            return back()->with('error', "{$student->last_name}, {$student->first_name} is not enrolled in School Year {$schoolYear}, so no intervention can be recorded for this year.");
        }

        $riskResult = RiskResult::where('student_id', $student->id)
            ->where('grading_period', (int) $request->grading_period)
            ->where('school_year', $schoolYear)
            ->first();

        // "Decision flow, report scoping, and dashboard pass" TASK 1a/1b —
        // recording an intervention here IS the Principal's decision by
        // default: every intervention in this system is created by the
        // Principal, reviewing this exact student, right now. The
        // deferred path (status stays 'recommended', decided_by/at stay
        // null) only exists when explicitly requested via the checkbox —
        // see Intervention::awaitingDecision() for what that then blocks.
        $recommendationOnly = $request->boolean('recommendation_only');

        $intervention = Intervention::create([
            'student_id'             => $student->id,
            'subject_id'             => $request->subject_id,
            // Stored now (see the 2026_09_05_000002 migration) so TASK 3's
            // within-term comparison has a term to scope its evidence
            // query to — this was the gap the docblock above used to note.
            'grading_period'         => (int) $request->grading_period,
            'school_year'            => $schoolYear,
            'section_id'             => $sectionNow->id,
            'risk_result_id'         => $riskResult?->id,
            'recommended_type'       => $request->recommended_type,
            'recommendation_reason'  => $request->recommendation_reason,
            // TASK 2 of "status clarity and progress consistency" — the
            // component this reason actually names, captured once at
            // creation (strict, no recommended_type fallback — see
            // Intervention::extractNamedComponent()) so
            // ProgressMonitoringService::compareWithinTerm() always has a
            // stable anchor instead of recomputing "whichever is weakest
            // now," which drifts as soon as new evidence lands elsewhere.
            'focus_component'        => Intervention::extractNamedComponent($request->recommendation_reason),
            'status'                 => $recommendationOnly ? Intervention::STATUS_RECOMMENDED : 'approved',
            'principal_notes'        => $request->principal_notes,
            'created_by'             => auth()->id(),
            // "Master pass" PART 1 — every intervention this route creates
            // IS created by a Principal, through this interface. Never
            // 'system' — see Intervention::ORIGINS' docblock.
            'origin'                 => 'principal',
            'decided_by'             => $recommendationOnly ? null : auth()->id(),
            'decided_at'             => $recommendationOnly ? null : now(),
        ]);

        LogActivity::log(
            'create_intervention',
            'Recorded intervention (' . $request->recommended_type . ') for ' . $student->last_name . ', ' . $student->first_name .
                ($riskResult ? '' : ' (no submitted term report for this term yet — recorded from assessment evidence)') .
                ($recommendationOnly ? ' — recorded as a recommendation only, awaiting decision' : ' — approved on creation'),
            'interventions',
            $intervention->id
        );

        return redirect()->back()->with('success', $recommendationOnly
            ? 'Intervention recorded as a recommendation, awaiting your decision.'
            : 'Intervention recorded and approved — the adviser can now act on it.');
    }

    /**
     * TASK 4 of "close the intervention loop" / TASK 2 of "subject
     * scoping and bulk threshold": bulk-record interventions for
     * At Risk students, or — when the Principal has widened the
     * dialog's threshold — At Risk AND Needs Attention students.
     * Recording ten interventions used to mean opening ten modals. The
     * Principal still reviews and confirms every row (the dialog is
     * built server-side from Principal\StudentController::buildBulkInterventionCandidates(),
     * pre-checked and pre-typed, but nothing is created until this
     * request is actually sent, and the threshold choice itself never
     * changes which rows the Principal can review — only which are
     * shown/checked by default) — see the ground rule: "Never
     * auto-create an intervention." Cancelling the dialog never reaches
     * this method at all.
     *
     * Re-checks the open-intervention guard server-side per student
     * (the dialog's flags could be stale) and SKIPS rather than fails
     * the whole batch — the response reports how many were skipped.
     */
    public function storeBulk(Request $request)
    {
        $request->validate([
            'subject_id'     => 'required|exists:subjects,id',
            'grading_period' => 'required|integer|in:1,2,3',
            'included'       => 'required|array|min:1',
            'included.*'     => 'required|exists:students,id',
            'types'          => 'required|array',
            'types.*'        => ['required', Rule::in(Intervention::TYPES)],
            // TASK 2 of "subject scoping and bulk threshold" — which
            // threshold each row was recorded under, so the reason text
            // below can say so accurately instead of hardcoding one
            // value for every row. "The Failing layer" TASK 3d adds
            // 'Failing' (official grade <= 74) as a third, genuinely
            // distinct threshold — never a combination of the other two.
            // Optional/nullable — a missing entry is a data problem, not
            // silently defaulted (see below).
            'statuses'       => 'nullable|array',
            'statuses.*'     => ['nullable', Rule::in(['At Risk', 'Needs Attention', 'Failing'])],
            'notes'          => 'nullable|string',
            // "Decision flow, report scoping, and dashboard pass" TASK 1b
            // — one checkbox for the whole batch, same deferred-decision
            // meaning as store()'s.
            'recommendation_only' => 'nullable|boolean',
        ]);

        $subjectId = (int) $request->input('subject_id');
        $gradingPeriod = (int) $request->input('grading_period');
        $types = $request->input('types');
        $statuses = $request->input('statuses', []);
        $recommendationOnly = $request->boolean('recommendation_only');

        $componentLabels = [
            'written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'examination' => 'Examination',
        ];
        $focusToComponent = array_flip([
            'written_work' => 'additional_learning_activity',
            'performance_task' => 'additional_performance_task',
            'examination' => 'remediation',
        ]);

        $created = 0;
        $skipped = 0;

        foreach ($request->input('included') as $studentId) {
            $studentId = (int) $studentId;
            $type = $types[$studentId] ?? null;

            if (!$type || !in_array($type, Intervention::TYPES, true)) {
                $skipped++;
                continue;
            }

            // Same guard as store() — re-checked here since the dialog's
            // "already has one" flags were computed when the page loaded
            // and could be stale by the time this request arrives.
            // "Correctness and interface pass" TASK 1b — same term-scoping
            // fix as store()'s guard and buildBulkInterventionCandidates().
            $existingOpen = Intervention::where('student_id', $studentId)
                ->where('subject_id', $subjectId)
                ->where('grading_period', $gradingPeriod)
                ->whereIn('status', Intervention::OPEN_STATUSES)
                ->exists();

            if ($existingOpen) {
                $skipped++;
                continue;
            }

            $student = Student::find($studentId);
            if (!$student) {
                $skipped++;
                continue;
            }

            // PART 12 — same active-year/section capture as store().
            $schoolYear = Section::activeSchoolYear();
            $sectionNow = $student->sectionFor($schoolYear);
            if (!$sectionNow) {
                $skipped++;
                continue;
            }

            $riskResult = RiskResult::where('student_id', $studentId)
                ->where('grading_period', $gradingPeriod)
                ->where('school_year', $schoolYear)
                ->first();

            $componentKey = $focusToComponent[$type] ?? null;
            // "The Failing layer" TASK 3d — no silent 'At Risk' fallback:
            // that would misrecord a Failing-only or Needs-Attention-only
            // student under the wrong trigger reason. Every row from
            // buildBulkInterventionCandidates() always carries its own
            // accurate statuses[] entry; a genuinely missing one (a
            // tampered or malformed request) is surfaced honestly rather
            // than guessed.
            $studentStatus = $statuses[$studentId] ?? 'Unspecified';
            $triggerLabel = $studentStatus === 'Failing' ? 'Official Grade: Failing' : "In-Term Status: {$studentStatus}";
            $reason = $componentKey
                ? "Recorded in bulk — Focus Area: " . ($componentLabels[$componentKey] ?? $componentKey) . " ({$triggerLabel})."
                : "Recorded in bulk — {$triggerLabel}.";

            $intervention = Intervention::create([
                'student_id'            => $studentId,
                'subject_id'            => $subjectId,
                'grading_period'        => $gradingPeriod,
                'school_year'           => $schoolYear,
                'section_id'            => $sectionNow->id,
                'risk_result_id'        => $riskResult?->id,
                'recommended_type'      => $type,
                'recommendation_reason' => $reason,
                // TASK 2 of "status clarity and progress consistency" —
                // $componentKey is already known precisely here (it's what
                // $reason itself was built from), so it's persisted
                // directly rather than re-derived from the reason text.
                'focus_component'       => $componentKey,
                'status'                => $recommendationOnly ? Intervention::STATUS_RECOMMENDED : 'approved',
                'principal_notes'       => $request->input('notes'),
                'created_by'            => auth()->id(),
                // "Master pass" PART 1 — same as store(): this route is
                // reached only from the Principal's bulk dialog.
                'origin'                => 'principal',
                'decided_by'            => $recommendationOnly ? null : auth()->id(),
                'decided_at'            => $recommendationOnly ? null : now(),
            ]);

            $created++;

            LogActivity::log(
                'create_intervention',
                'Recorded intervention (' . $type . ') for ' . $student->last_name . ', ' . $student->first_name . ' — bulk ' . $studentStatus . ' action' .
                    ($recommendationOnly ? ' (recommendation only, awaiting decision)' : ' (approved on creation)'),
                'interventions',
                $intervention->id
            );
        }

        $message = $recommendationOnly
            ? "Recorded {$created} intervention(s) as recommendations, awaiting your decision."
            : "Recorded and approved {$created} intervention(s) — the adviser can now act on them.";
        if ($skipped > 0) {
            $message .= " {$skipped} student(s) already had an open intervention or were invalid and were skipped.";
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * TASK 2c of "clarity, progress, and visual design" — true only when
     * there IS a within-term comparison, the focus component actually
     * rose since delivery (change > 0), and the student's CURRENT
     * In-Term Status for that subject/term is not already On Track
     * (isReadyForReview() already covers that case with its own "close
     * this?" prompt — this signal never overlaps it). Read-only, exactly
     * like isReadyForReview(): nothing changes because of this flag, it
     * only tells the Principal which "nothing changed yet" rows actually
     * have real, measured improvement behind them.
     */
    private function isImprovedButBelowTarget(Intervention $intervention): bool
    {
        if ($this->inTermStatus->isReadyForReview($intervention)) {
            return false;
        }

        $progress = $intervention->withinTermProgress ?? null;

        if (!$progress || ($progress['not_recorded'] ?? false)) {
            return false;
        }

        return ($progress['change'] ?? null) !== null && $progress['change'] > 0;
    }

    public function update(Request $request, Intervention $intervention)
    {
        $request->validate([
            'status'           => ['required', Rule::in(Intervention::STATUSES)],
            'principal_notes'  => 'nullable|string',
        ]);

        $intervention->update([
            'status'          => $request->status,
            'principal_notes' => $request->principal_notes ?? $intervention->principal_notes,
            'decided_by'      => auth()->id(),
            'decided_at'      => now(),
        ]);

        LogActivity::log(
            'update_intervention',
            'Updated intervention #' . $intervention->id . ' status to ' . $request->status,
            'interventions',
            $intervention->id
        );

        return redirect()->route('principal.interventions')
            ->with('success', 'Intervention updated.');
    }

    /**
     * "Decision flow, report scoping, and dashboard pass" TASK 1f, hardened
     * by "master pass" PART 1.4c — a genuine Principal decision (unlike
     * delivery, it carries no per-learner claim about work performed),
     * applied to every id submitted that is STILL awaiting a decision
     * AND still matches the SAME filters (grade level, section, status,
     * subject, term) the Principal was actually viewing — via
     * buildFilteredQuery(), the identical chain index() applies. A
     * submitted id from outside those filters (stale page, or a
     * tampered request) is silently excluded rather than trusted; this
     * never approves anything the Principal wasn't looking at. One
     * activity log entry per intervention, all inside one transaction
     * so a mid-batch failure can never leave some approved and others
     * not.
     */
    public function approvePending(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'exists:interventions,id',
        ]);

        $interventions = $this->buildFilteredQuery($request)
            ->with('student')
            ->whereIn('id', $request->input('ids'))
            ->undecided()
            ->get();

        \DB::transaction(function () use ($interventions) {
            foreach ($interventions as $intervention) {
                $intervention->update([
                    'status'     => 'approved',
                    'decided_by' => auth()->id(),
                    'decided_at' => now(),
                ]);

                LogActivity::log(
                    'update_intervention',
                    'Approved intervention #' . $intervention->id . ' for ' . ($intervention->student->last_name ?? '') . ', ' . ($intervention->student->first_name ?? '') . ' (bulk approve pending)',
                    'interventions',
                    $intervention->id
                );
            }
        });

        return redirect()->route('principal.interventions')
            ->with('success', $interventions->count() . ' intervention(s) approved.');
    }
}
