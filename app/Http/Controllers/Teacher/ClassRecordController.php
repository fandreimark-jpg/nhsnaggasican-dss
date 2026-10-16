<?php

namespace App\Http\Controllers\Teacher;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Subject;
use App\Services\AcademicMonitoringService;
use App\Services\ClassRecordExport;
use App\Services\ClassRecordService;
use App\Services\ExaminationRoles;
use App\Services\TeachingAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The Class Record — the score-entry workspace for Advisers and Subject
 * Teachers ("Subject Teacher and Class Record" pass, 2026-09-28).
 *
 * Selectors: School Year, Section, Subject, Term — each offered only from
 * what TeachingAccess lets this user read. A subject the user is not
 * ASSIGNED to teach in that section and term (an advisory-only view), a
 * closed term, and a non-active school year are all read-only, and the
 * batch save refuses them server-side regardless of what the page shows.
 */
class ClassRecordController extends Controller
{
    public function __construct(
        private TeachingAccess $access = new TeachingAccess(),
        private ClassRecordService $records = new ClassRecordService(),
    ) {
    }

    public function show(Request $request)
    {
        $user = auth()->user();
        $ctx = $this->context($request);

        $record = null;
        $canEdit = false;
        $writeRefusal = null;
        $accessState = null; // editable | advisory | closed — display only; the save endpoint re-checks
        if ($ctx['section'] && $ctx['subject']) {
            $record = $this->records->build($ctx['section'], $ctx['subject'], $ctx['term']);
            $assigned = $this->access->canEdit($user, $ctx['section'], $ctx['subject'], $ctx['term']);
            $termOpen = AcademicTerm::acceptsWrites($ctx['section']->school_year, $ctx['term']);
            $canEdit = $assigned && $termOpen;
            $accessState = $canEdit ? 'editable' : ($assigned ? 'closed' : 'advisory');
            $writeRefusal = !$assigned
                ? 'View only — you are not assigned to teach ' . $ctx['subject']->name . ' in ' . $ctx['section']->name . ' for Term ' . $ctx['term'] . '.'
                : (!$termOpen ? AcademicTerm::writeRefusalReason($ctx['section']->school_year, $ctx['term']) : null);
        }

        return view('teacher.class-record', array_merge($ctx, [
            'record'       => $record,
            'canEdit'      => $canEdit,
            'writeRefusal' => $writeRefusal,
            'accessState'  => $accessState,
            'termOpen'     => $ctx['section'] ? AcademicTerm::acceptsWrites($ctx['section']->school_year, $ctx['term']) : false,
            'examRoleLabels' => ExaminationRoles::LABELS,
            'newAssessmentId' => session('new_assessment_id'),
            'assignedTeacher' => $ctx['section'] && $ctx['subject'] ? $this->access->teacherFor($ctx['section'], $ctx['subject']->id, $ctx['term']) : null,
        ]));
    }

    /**
     * Batch save (JSON). All or nothing: 403 without an assignment, 423 for
     * a closed term / historical year, 422 with per-cell messages for any
     * invalid value, 409 with the stored values for any stale cell.
     */
    public function save(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'section_id'           => ['required', 'integer'],
            'subject_id'           => ['required', 'integer'],
            'period'               => ['required', 'integer', Rule::in(AcademicTerm::termNumbers())],
            'reason'               => ['nullable', 'string', 'max:500'],
            'cells'                => ['required', 'array', 'min:1', 'max:5000'],
            'cells.*.assessment_id' => ['required', 'integer'],
            'cells.*.student_id'   => ['required', 'integer'],
            'cells.*.score'        => ['nullable'],
            // Optional: a submitted cell without a score is saved as Missing
            // either way; true alongside a score is refused as contradictory.
            'cells.*.missing'      => ['sometimes', 'boolean'],
            'cells.*.base_score'   => ['nullable'],
            'cells.*.base_missing' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'The save request was not in the expected shape.', 'details' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $section = Section::find((int) $request->input('section_id'));
        $term = (int) $request->input('period');
        $subject = $section ? Subject::forSection($section, $term)->whereKey((int) $request->input('subject_id'))->first() : null;

        if (!$section || !$subject || !$this->access->canEdit($user, $section, $subject, $term)) {
            return response()->json([
                'message' => $section ? TeachingAccess::notAssignedMessage($subject?->name, $section, $term) : 'That section does not exist.',
            ], 403);
        }
        if (!AcademicTerm::acceptsWrites($section->school_year, $term)) {
            return response()->json(['message' => AcademicTerm::writeRefusalReason($section->school_year, $term)], 423);
        }

        $cells = array_map(fn($c) => [
            'assessment_id' => (int) $c['assessment_id'],
            'student_id'    => (int) $c['student_id'],
            'score'         => isset($c['score']) ? (string) $c['score'] : null,
            'missing'       => filter_var($c['missing'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'base_score'    => isset($c['base_score']) ? (string) $c['base_score'] : null,
            'base_missing'  => filter_var($c['base_missing'], FILTER_VALIDATE_BOOLEAN),
        ], $request->input('cells'));

        $result = $this->records->saveBatch($section, $subject, $term, $cells, $user->id, $request->input('reason'));

        if ($result['status'] === 'invalid') {
            return response()->json([
                'message' => 'Nothing was saved. ' . count($result['errors']) . ' cell(s) need correcting.',
                'errors'  => $result['errors'],
            ], 422);
        }
        if ($result['status'] === 'conflict') {
            return response()->json([
                'message'   => 'Nothing was saved. ' . count($result['conflicts']) . ' cell(s) were changed by someone else (or in another tab) after you opened this record.',
                'conflicts' => array_map(fn($c) => [
                    'current_score'   => ClassRecordService::fmt($c['current_score']),
                    'current_missing' => $c['current_missing'],
                ], $result['conflicts']),
            ], 409);
        }

        // Monitoring after the evidence committed — a failure never undoes it.
        $monitoringWarning = null;
        try {
            (new AcademicMonitoringService())->synchronizeAssessments($result['assessment_ids']);
        } catch (\Throwable $e) {
            report($e);
            $monitoringWarning = 'Your changes were saved, but Academic Monitoring could not update its concerns for this assessment, '
                . 'so do not rely on the concern status shown for it until this is fixed. '
                . 'The recorded scores and Missing markers are correct. Please tell the administrator so the concerns can be re-evaluated.';
        }

        if ($result['changed'] > 0) {
            LogActivity::log(
                'class_record_save',
                'Saved ' . $result['changed'] . ' Class Record cell(s) for ' . $subject->name . ' — Term ' . $term . ' — Section ' . $section->name . ' (' . $section->school_year . ')',
                'assessment_scores',
                null
            );
        }

        // Fresh figures from GradingEngine for every learner touched.
        $record = $this->records->build($section, $subject, $term);
        $touched = array_unique(array_map(fn($c) => $c['student_id'], $cells));

        return response()->json([
            'message'  => $result['changed'] === 0 ? 'No changes to save.' : 'Saved ' . $result['changed'] . ' change(s).',
            'changed'  => $result['changed'],
            'monitoring_warning' => $monitoringWarning,
            // Text of each learner's read-only computed cells, formatted by
            // ClassRecordService::displayRow() — the page never calculates.
            'rows'     => collect($touched)->mapWithKeys(fn($sid) => [$sid => $record['display'][$sid] ?? []])->all(),
        ]);
    }

    /**
     * Live preview (JSON, READ ONLY): the computed cells of the given
     * learners as they would read if the draft cells were saved now. Same
     * refusals as the save — 403 without an assignment, 423 for a closed
     * term / past year, 422 for an invalid cell — and nothing is written.
     */
    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'section_id'            => ['required', 'integer'],
            'subject_id'            => ['required', 'integer'],
            'period'                => ['required', 'integer', Rule::in(AcademicTerm::termNumbers())],
            'cells'                 => ['present', 'array', 'max:5000'],
            'cells.*.assessment_id' => ['required', 'integer'],
            'cells.*.student_id'    => ['required', 'integer'],
            'cells.*.score'         => ['nullable'],
            'student_ids'           => ['sometimes', 'array', 'max:2000'],
            'student_ids.*'         => ['integer'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'The preview request was not in the expected shape.', 'details' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $section = Section::find((int) $request->input('section_id'));
        $term = (int) $request->input('period');
        $subject = $section ? Subject::forSection($section, $term)->whereKey((int) $request->input('subject_id'))->first() : null;
        if (!$section || !$subject || !$this->access->canEdit($user, $section, $subject, $term)) {
            return response()->json(['message' => $section ? TeachingAccess::notAssignedMessage($subject?->name, $section, $term) : 'That section does not exist.'], 403);
        }
        if (!AcademicTerm::acceptsWrites($section->school_year, $term)) {
            return response()->json(['message' => AcademicTerm::writeRefusalReason($section->school_year, $term)], 423);
        }

        $cells = array_map(fn($c) => [
            'assessment_id' => (int) $c['assessment_id'],
            'student_id'    => (int) $c['student_id'],
            'score'         => isset($c['score']) ? (string) $c['score'] : null,
        ], $request->input('cells', []));
        $result = $this->records->preview($section, $subject, $term, $cells, $request->input('student_ids', []));

        if ($result['status'] === 'invalid') {
            return response()->json(['message' => 'Preview unavailable — ' . count($result['errors']) . ' cell(s) need correcting.', 'errors' => $result['errors']], 422);
        }

        return response()->json(['preview' => true, 'rows' => $result['rows']]);
    }

    public function export(Request $request)
    {
        // An export names exactly what it exports — never a default guess.
        abort_unless($request->filled('section_id') && $request->filled('subject_id') && $request->filled('period'), 404);
        $ctx = $this->context($request);
        abort_if(!$ctx['section'] || !$ctx['subject'], 404);

        $record = $this->records->build($ctx['section'], $ctx['subject'], $ctx['term']);
        $book = (new ClassRecordExport())->build($record);
        $filename = preg_replace('#[\\\\/:*?"<>|]+#', '-', sprintf('Class Record - %s - Grade %s %s - %s - Term %d.xlsx',
            $ctx['section']->school_year, $ctx['section']->grade_level, $ctx['section']->name, $ctx['subject']->name, $ctx['term']));

        LogActivity::log('class_record_export', 'Downloaded the Class Record for ' . $ctx['subject']->name . ' — Term ' . $ctx['term'] . ' — Section ' . $ctx['section']->name, 'assessments', null);

        return response()->streamDownload(function () use ($book) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function copy(Request $request)
    {
        // An export names exactly what it exports — never a default guess.
        abort_unless($request->filled('section_id') && $request->filled('subject_id') && $request->filled('period'), 404);
        $ctx = $this->context($request);
        abort_if(!$ctx['section'] || !$ctx['subject'], 404);

        // mode=full (default): header row + HPS row + one row per learner with
        // LRN and name. mode=scores: the score block only, same learner and
        // column order, for pasting into an existing sheet's score area.
        $mode = $request->input('mode') === 'scores' ? 'scores' : 'full';

        return response($this->records->toTsv($this->records->build($ctx['section'], $ctx['subject'], $ctx['term']), $mode), 200, [
            'Content-Type'  => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Resolves the selectors to what THIS user may read. A requested
     * section/subject outside that scope is refused (403), never silently
     * swapped for another — except when nothing was requested, where the
     * first readable option is chosen.
     *
     * @return array{schoolYears: list<string>, schoolYear: ?string, sections: \Illuminate\Support\Collection, section: ?Section, subjects: \Illuminate\Support\Collection, subject: ?Subject, term: int, terms: list<int>}
     */
    private function context(Request $request): array
    {
        $user = auth()->user();
        $terms = AcademicTerm::termNumbers();

        $request->validate([
            'school_year' => ['nullable', 'string', 'max:9'],
            'section_id'  => ['nullable', 'integer'],
            'subject_id'  => ['nullable', 'integer'],
            'period'      => ['nullable', 'integer', Rule::in($terms)],
        ]);

        $schoolYears = $this->access->schoolYearsFor($user);
        $requestedSection = $request->filled('section_id') ? Section::find((int) $request->input('section_id')) : null;
        if ($request->filled('section_id') && (!$requestedSection || !$this->access->canReadSection($user, $requestedSection))) {
            abort(403);
        }

        $schoolYear = $requestedSection?->school_year ?? $this->access->resolveSchoolYear($user, $request->input('school_year'));
        $sections = $schoolYear ? $this->access->readableSections($user, $schoolYear) : collect();
        $section = $requestedSection ?? $sections->first();

        $defaultTerm = $section ? (AcademicTerm::currentOpenTerm($section->school_year) ?? $terms[0] ?? 1) : ($terms[0] ?? 1);
        $term = $request->filled('period') ? (int) $request->input('period') : (int) $defaultTerm;

        $subjects = $section ? $this->access->readableSubjects($user, $section, $term) : collect();
        $subject = null;
        if ($request->filled('subject_id')) {
            $subject = $subjects->firstWhere('id', (int) $request->input('subject_id'));
            abort_if(!$subject && $requestedSection, 403);
        }
        $subject ??= $subjects->first();

        return compact('schoolYears', 'schoolYear', 'sections', 'section', 'subjects', 'subject', 'term', 'terms');
    }
}
