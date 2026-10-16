<?php

namespace App\Http\Controllers\Teacher;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentEvidenceAudit;
use App\Models\AssessmentScore;
use App\Models\AssessmentUpload;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\AcademicMonitoringService;
use App\Services\ClassRecordService;
use App\Services\ExaminationRoles;
use App\Services\GradingEngine;
use App\Services\TeachingAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Add / Edit Assessment — DEFINITION ONLY ("Class Record refinements",
 * 2026-09-28). These pages and actions create or correct an assessment
 * item (title, component, Examination Type, HPS, date, additional-support
 * flag). They never write a score or a Missing marker: a request carrying
 * `scores` or `missing` is refused. Scores are entered only in the Class
 * Record grid (ClassRecordController::save()).
 *
 * Both require the teaching assignment (TeachingAccess::canEdit) and a term
 * that accepts writes (AcademicTerm::acceptsWrites), re-checked on POST/PUT.
 */
class AssessmentFormController extends Controller
{
    public const COMPONENT_LABELS = ClassRecordService::COMPONENTS;

    /** Keys a definition request may never carry — scores belong to the grid. */
    private const EVIDENCE_KEYS = ['scores', 'missing', 'score', 'statuses'];

    public function __construct(
        private TeachingAccess $access = new TeachingAccess(),
        private ExaminationRoles $examinationRoles = new ExaminationRoles(),
    ) {
    }

    public function create(Request $request)
    {
        $request->validate([
            'section_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'period'     => ['required', 'integer', Rule::in(AcademicTerm::termNumbers())],
            'component'  => ['nullable', Rule::in(array_keys(self::COMPONENT_LABELS))],
        ]);

        $section = Section::findOrFail((int) $request->input('section_id'));
        $term = (int) $request->input('period');
        $subject = Subject::forSection($section, $term)->whereKey((int) $request->input('subject_id'))->first();
        abort_if(!$subject || !$this->access->canEdit(auth()->user(), $section, $subject, $term), 403);

        if (!AcademicTerm::acceptsWrites($section->school_year, $term)) {
            return $this->toRecord($section, $subject->id, $term)->with('error', AcademicTerm::writeRefusalReason($section->school_year, $term));
        }

        return view('teacher.assessment-form', $this->formData($section, $subject, $term) + [
            'assessment'       => null,
            'prefillComponent' => $request->input('component'),
            'submissionToken'  => (string) Str::uuid(),
            'fingerprint'      => null,
            'scoreCount'       => 0,
            'highestScore'     => null,
        ]);
    }

    public function store(Request $request)
    {
        if ($refusal = $this->evidencePayloadRefusal($request, 'addItem')) {
            return $refusal;
        }

        $validator = Validator::make($request->all(), [
            'section_id'       => ['required', 'integer', 'exists:sections,id'],
            'subject_id'       => ['required', 'integer', 'exists:subjects,id'],
            'grading_period'   => ['required', 'integer', Rule::in(AcademicTerm::termNumbers())],
            'item_name'        => ['required', 'string', 'max:255'],
            'component'        => ['required', Rule::in(array_keys(self::COMPONENT_LABELS))],
            'exam_role'        => ['nullable', 'in:st1,st2,term_exam'],
            'is_additional_support' => ['nullable', 'boolean'],
            'max_score'        => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'assessment_date'  => ['nullable', 'date'],
            'submission_token' => ['nullable', 'uuid'],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator, 'addItem')->withInput();
        }

        $section = Section::find((int) $request->input('section_id'));
        abort_if(!$section || !$this->access->canReadSection(auth()->user(), $section), 403);
        $term = (int) $request->input('grading_period');
        $subject = Subject::forSection($section, $term)->whereKey((int) $request->input('subject_id'))->first();
        if (!$subject) {
            return back()->withErrors(['item_name' => (new \App\Services\SubjectOfferingService())
                ->notOfferedMessage(Subject::find($request->input('subject_id'))?->name ?? 'That subject', $section, $term)], 'addItem')->withInput();
        }
        if (!$this->access->canEdit(auth()->user(), $section, $subject, $term)) {
            return back()->withErrors(['item_name' => TeachingAccess::notAssignedMessage($subject->name, $section, $term)], 'addItem')->withInput();
        }
        if (!AcademicTerm::acceptsWrites($section->school_year, $term)) {
            return back()->withErrors(['item_name' => AcademicTerm::writeRefusalReason($section->school_year, $term)], 'addItem')->withInput();
        }

        // The same form sent twice (double click, resend) lands on the item it
        // already created — never a duplicate, never a misleading error.
        if ($already = $this->alreadySaved($request)) {
            return $this->addedRedirect($section, $subject->id, $term, $already);
        }

        $itemName = trim($request->input('item_name'));
        $component = $request->input('component');
        $isSupport = $request->boolean('is_additional_support');
        if ($problem = $this->examRoleProblem($section, $subject, $term, $component, $request->input('exam_role'), $isSupport)) {
            return back()->withErrors($problem, 'addItem')->withInput();
        }

        $existing = Assessment::where('subject_id', $subject->id)->where('section_id', $section->id)
            ->where('grading_period', $term)->where('school_year', $section->school_year)->where('name', $itemName)->first();
        if ($existing) {
            return back()->withErrors(['item_name' => "An assessment named \"{$itemName}\" already exists for {$subject->name}, Term {$term} ("
                . (self::COMPONENT_LABELS[$existing->component] ?? $existing->component) . ', HPS ' . ClassRecordService::fmt((float) $existing->max_score)
                . '). Choose a different name, or edit that assessment.'], 'addItem')->withInput();
        }

        try {
            $assessment = DB::transaction(function () use ($request, $section, $subject, $term, $itemName, $component, $isSupport) {
                $upload = AssessmentUpload::create([
                    'section_id'        => $section->id,
                    'subject_id'        => $subject->id,
                    'uploaded_by'       => auth()->id(),
                    'grading_period'    => $term,
                    'school_year'       => $section->school_year,
                    'original_filename' => '(Class Record — ' . $itemName . ')',
                    'column_mapping'    => [$itemName => $component],
                    'status'            => 'manual_entry',
                    'imported_count'    => 0,
                    'error_count'       => 0,
                ]);

                return Assessment::create([
                    'subject_id'      => $subject->id,
                    'section_id'      => $section->id,
                    'grading_period'  => $term,
                    'school_year'     => $section->school_year,
                    'name'            => $itemName,
                    'assessment_type' => $itemName,
                    'component'       => $component,
                    'exam_role'       => $component === 'examination' ? ($request->input('exam_role') ?: null) : null,
                    'is_additional_support' => $isSupport,
                    'max_score'       => round((float) $request->input('max_score'), 2),
                    'assessment_date' => $request->input('assessment_date') ?: null,
                    'import_batch_id' => (string) $upload->id,
                    'uploaded_by'     => auth()->id(),
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            if ($already = $this->alreadySaved($request)) {
                return $this->addedRedirect($section, $subject->id, $term, $already);
            }
            return back()->withErrors(['item_name' => "An assessment named \"{$itemName}\" was just saved by another submission. Edit that one instead."], 'addItem')->withInput();
        }

        if ($request->filled('submission_token')) {
            Cache::put($this->submissionKey($request), $assessment->id, now()->addHours(12));
        }

        LogActivity::log('add_assessment_item', 'Added assessment "' . $itemName . '" (' . self::COMPONENT_LABELS[$component] . ', HPS '
            . ClassRecordService::fmt((float) $assessment->max_score) . ') for ' . $subject->name . ' — Term ' . $term . ' — Section '
            . $section->name . ' (' . $section->school_year . '). No scores were recorded.', 'assessments', $assessment->id);

        return $this->addedRedirect($section, $subject->id, $term, $assessment->id);
    }

    public function edit(Assessment $assessment)
    {
        $section = $assessment->section;
        $term = (int) $assessment->grading_period;
        abort_if(!$section || !$this->access->canEdit(auth()->user(), $section, (int) $assessment->subject_id, $term), 403);

        if (!AcademicTerm::acceptsWrites($section->school_year, $term)) {
            return $this->toRecord($section, $assessment->subject_id, $term)->with('error', AcademicTerm::writeRefusalReason($section->school_year, $term));
        }

        $scores = AssessmentScore::where('assessment_id', $assessment->id);

        return view('teacher.assessment-form', $this->formData($section, $assessment->subject, $term) + [
            'assessment'       => $assessment,
            'prefillComponent' => null,
            'submissionToken'  => null,
            'fingerprint'      => ClassRecordService::definitionFingerprint($assessment),
            'scoreCount'       => (clone $scores)->count(),
            'highestScore'     => (clone $scores)->max('score'),
        ]);
    }

    /**
     * Metadata only: HPS, component, Examination Type, date. The item's
     * subject, section, school year, term and title never change here, and
     * no score or Missing marker is touched. Locked + version-checked.
     */
    public function update(Request $request, Assessment $assessment)
    {
        if ($refusal = $this->evidencePayloadRefusal($request, 'editItem')) {
            return $refusal;
        }

        $section = $assessment->section;
        $term = (int) $assessment->grading_period;
        abort_if(!$section || !$this->access->canEdit(auth()->user(), $section, (int) $assessment->subject_id, $term), 403);
        if (!AcademicTerm::acceptsWrites($section->school_year, $term)) {
            return $this->toRecord($section, $assessment->subject_id, $term)->with('error', AcademicTerm::writeRefusalReason($section->school_year, $term));
        }

        // Scope fields are not editable. If a request names a different one,
        // it is refused rather than silently ignored.
        foreach (['section_id' => $assessment->section_id, 'subject_id' => $assessment->subject_id, 'grading_period' => $assessment->grading_period, 'school_year' => $assessment->school_year] as $field => $current) {
            if ($request->has($field) && (string) $request->input($field) !== (string) $current) {
                return back()->withErrors(['component' => 'An assessment cannot be moved to another subject, section, school year or term — its recorded scores belong to this class.'], 'editItem')->withInput();
            }
        }

        $validator = Validator::make($request->all(), [
            'max_score'          => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'component'          => ['required', Rule::in(array_keys(self::COMPONENT_LABELS))],
            'exam_role'          => ['nullable', 'in:st1,st2,term_exam'],
            'assessment_date'    => ['nullable', 'date'],
            'loaded_fingerprint' => ['required', 'string'],
        ], ['loaded_fingerprint.required' => 'Open the Edit page again — this request did not say which version of the assessment it was editing.']);
        if ($validator->fails()) {
            return back()->withErrors($validator, 'editItem')->withInput();
        }

        $newMax = round((float) $request->input('max_score'), 2);
        $newComponent = $request->input('component');
        $newRole = $newComponent === 'examination' ? ($request->input('exam_role') ?: null) : null;
        $subject = $assessment->subject;

        $result = DB::transaction(function () use ($request, $assessment, $section, $subject, $term, $newMax, $newComponent, $newRole) {
            $locked = Assessment::whereKey($assessment->id)->lockForUpdate()->first();
            if (!hash_equals(ClassRecordService::definitionFingerprint($locked), (string) $request->input('loaded_fingerprint'))) {
                return ['errors' => ['component' => 'This assessment was changed by someone else (or in another tab) after you opened it. Nothing was saved — reopen Edit to see the current details.']];
            }

            if ($newComponent !== $locked->component || $newRole !== $locked->exam_role) {
                $rawRole = $newComponent === 'examination' ? $newRole : $request->input('exam_role');
                if ($problem = $this->examRoleProblem($section, $subject, $term, $newComponent, $rawRole, (bool) $locked->is_additional_support, $locked->id)) {
                    return ['errors' => $problem];
                }
            }

            $highest = AssessmentScore::where('assessment_id', $locked->id)->max('score');
            if ($highest !== null && (float) $highest > $newMax + 0.000001) {
                return ['errors' => ['max_score' => 'The Highest Possible Score cannot be lower than a score already recorded (' . ClassRecordService::fmt((float) $highest)
                    . '). Correct that score in the Class Record first.']];
            }

            $before = ['max_score' => ClassRecordService::fmt((float) $locked->max_score), 'component' => $locked->component, 'exam_role' => $locked->exam_role, 'date' => $locked->assessment_date?->format('Y-m-d')];
            $locked->update([
                'max_score'       => $newMax,
                'component'       => $newComponent,
                'exam_role'       => $newRole,
                'assessment_date' => $request->input('assessment_date') ?: null,
            ]);

            return ['before' => $before, 'assessment' => $locked];
        });

        if (isset($result['errors'])) {
            return back()->withErrors($result['errors'], 'editItem')->withInput();
        }

        $after = $result['assessment'];
        $changes = [];
        foreach (['max_score' => ClassRecordService::fmt((float) $after->max_score), 'component' => $after->component, 'exam_role' => $after->exam_role, 'date' => $after->assessment_date?->format('Y-m-d')] as $k => $v) {
            if ($result['before'][$k] !== $v) {
                $changes[] = "{$k}: " . ($result['before'][$k] ?? '—') . ' → ' . ($v ?? '—');
            }
        }
        LogActivity::log('edit_assessment_item', 'Edited assessment "' . $after->name . '" for ' . $subject->name . ' — Term ' . $term . ' — Section '
            . $section->name . ' (' . $section->school_year . '): ' . ($changes ? implode('; ', $changes) : 'no change') . '. Scores unchanged.', 'assessments', $after->id);

        // A component/HPS change re-weights recorded evidence; bring the
        // concerns in line (monitoring never undoes the saved edit).
        try {
            (new AcademicMonitoringService())->synchronizeAssessments([$after->id]);
        } catch (\Throwable $e) {
            report($e);
            session()->flash('monitoring_warning', 'The assessment was saved, but Academic Monitoring could not update its concerns. Please tell the administrator.');
        }

        return $this->toRecord($section, $after->subject_id, $term)->with('success', 'Assessment "' . $after->name . '" updated. Scores were not changed.');
    }

    /** The evidence history of one item — readable by anyone who can read the item. */
    public function history(Assessment $assessment)
    {
        $section = $assessment->section;
        abort_if(!$section || !$this->access->canRead(auth()->user(), $section, (int) $assessment->subject_id, (int) $assessment->grading_period), 403);

        $audits = AssessmentEvidenceAudit::with('changedBy')
            ->where('assessment_id', $assessment->id)
            ->orderByDesc('changed_at')->orderByDesc('id')
            ->limit(500)->get();
        $students = Student::whereIn('id', $audits->pluck('student_id')->unique())->get()->keyBy('id');

        return view('teacher.assessment-history', compact('assessment', 'section', 'audits', 'students'));
    }

    // ---------------------------------------------------------------------

    private function evidencePayloadRefusal(Request $request, string $bag)
    {
        foreach (self::EVIDENCE_KEYS as $key) {
            if ($request->has($key)) {
                $message = 'Scores and Missing marks are not saved here. Save the assessment details, then enter scores in the Class Record.';
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 422);
                }

                return back()->withErrors(['scores' => $message], $bag)->withInput($request->except(self::EVIDENCE_KEYS));
            }
        }

        return null;
    }

    private function addedRedirect(Section $section, int $subjectId, int $term, int $assessmentId)
    {
        return $this->toRecord($section, $subjectId, $term)
            ->with('new_assessment_id', $assessmentId)
            ->with('success', 'Assessment added. Enter scores in the Class Record.');
    }

    private function toRecord(Section $section, int $subjectId, int $term)
    {
        return redirect()->route('teacher.class-record', [
            'school_year' => $section->school_year, 'section_id' => $section->id, 'subject_id' => $subjectId, 'period' => $term,
        ]);
    }

    private function submissionKey(Request $request): string
    {
        return 'assessment-definition-submission:' . auth()->id() . ':' . $request->input('submission_token');
    }

    private function alreadySaved(Request $request): ?int
    {
        if (!$request->filled('submission_token')) {
            return null;
        }
        $id = Cache::get($this->submissionKey($request));

        return $id && Assessment::whereKey($id)->exists() ? (int) $id : null;
    }

    private function formData(Section $section, Subject $subject, int $term): array
    {
        return [
            'section'        => $section,
            'subject'        => $subject,
            'term'           => $term,
            'teacher'        => $this->access->teacherFor($section, $subject->id, $term),
            'examProfile'    => $this->examinationRoles->forContext($section, $subject),
            'takenExamRoles' => ExaminationRoles::regularHolders($section->id, $subject->id, $term, $section->school_year)
                ->map(fn($items) => $items->first()->name)->all(),
            'profile'        => (new GradingEngine())->resolveWeightProfile($section, $subject),
        ];
    }

    /**
     * The Examination Type rules (App\Services\ExaminationRoles), unchanged
     * from the former item endpoints: only an Examination item carries a
     * type; a profile with no Examination takes none; where ST1/ST2/Term Exam
     * apply a type is required and must be one the profile uses; DO 8's flat
     * Examination takes none; one REGULAR item per type per class and term.
     *
     * @return array<string, string>|null field => message
     */
    private function examRoleProblem(Section $section, Subject $subject, int $term, string $component, mixed $rawRole, bool $isAdditionalSupport, ?int $exceptId = null): ?array
    {
        $role = is_string($rawRole) && trim($rawRole) !== '' ? trim($rawRole) : null;

        if ($component !== 'examination') {
            return $role === null ? null
                : ['exam_role' => 'Examination Type applies only to Examination items — choose Examination as the component, or leave the type empty.'];
        }

        $profile = $this->examinationRoles->forContext($section, $subject);
        if (!$profile['has_examination']) {
            return ['component' => "{$subject->name} has no Examination component in its grading profile, so an Examination item would never count. Choose Written Work or Performance Task."];
        }

        $labels = array_map(fn($r) => ExaminationRoles::LABELS[$r], $profile['roles']);
        if ($profile['roles'] === []) {
            return $role === null ? null
                : ['exam_role' => "{$subject->name} is graded with one Examination score (no ST1 / ST2 / Term Exam split). Leave Examination Type empty."];
        }
        if ($role === null) {
            return ['exam_role' => 'Choose the Examination Type (' . implode(', ', $labels) . ').'];
        }
        if (!in_array($role, $profile['roles'], true)) {
            return ['exam_role' => ExaminationRoles::label($role) . " is not part of {$subject->name}'s grading profile, which uses: " . implode(', ', $labels) . '.'];
        }

        if (!$isAdditionalSupport) {
            $holder = ExaminationRoles::regularHolders($section->id, $subject->id, $term, $section->school_year, $exceptId)->get($role)?->first();
            if ($holder) {
                return ['exam_role' => "{$subject->name} already has a " . ExaminationRoles::label($role) . " for Term {$term}: \"{$holder->name}\". "
                    . 'There is one per term — edit that assessment instead of adding a second one.'];
            }
        }

        return null;
    }
}
