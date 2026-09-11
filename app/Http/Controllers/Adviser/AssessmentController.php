<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Subject;
use App\Models\AssessmentUpload;
use App\Models\Grade;
use App\Models\Student;
use App\Helpers\LogActivity;
use App\Http\Controllers\Concerns\ValidatesSpreadsheetUpload;
use App\Services\AssessmentUploadService;
use App\Services\InTermStatusService;
use App\Services\PerformanceAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * AssessmentController (Adviser)
 *
 * The assessment upload workflow, matching CLAUDE.md's pipeline exactly:
 * select term/subject -> upload file -> Detect columns -> adviser
 * Verifies/corrects classification and sets max score -> Preview (dry
 * run, nothing saved) -> Import. An uploaded assessment is EVIDENCE (see
 * Assessment/AssessmentScore) — it never touches grades.grade, the
 * official final grade, which stays under the existing adviser-verified
 * grade workflow.
 *
 * detect()/preview()/import() are three separate requests because the
 * adviser must see and can correct the classification before anything is
 * saved (CLAUDE.md: "ambiguous columns must not be silently classified"),
 * then see exactly what will happen before committing to it. The
 * uploaded file is held in a temp disk location across all three
 * requests, referenced by a token in a hidden field — never trust a
 * client-supplied path directly (see the token validation in preview()
 * and import()).
 */
class AssessmentController extends Controller
{
    use ValidatesSpreadsheetUpload;

    private const TEMP_DIR = 'temp_assessment_uploads';

    private const COMPONENT_LABELS = [
        'written_work'     => 'Written Work',
        'performance_task' => 'Performance Task',
        'examination'      => 'Examination',
    ];

    public function __construct(
        private AssessmentUploadService $uploads = new AssessmentUploadService(),
        private PerformanceAnalysisService $analysis = new PerformanceAnalysisService(),
        private InTermStatusService $inTermStatus = new InTermStatusService()
    ) {
    }

    public function index(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->first();

        if (!$section) {
            return view('adviser.assessments', [
                'section' => null, 'subjects' => collect(), 'selectedSubject' => null,
                'selectedPeriod' => 1, 'items' => collect(), 'openTerm' => null, 'performance' => collect(),
                'staleRiskTerms' => [], 'sectionStudents' => collect(), 'modalStudents' => collect(),
                'itemScoresByAssessment' => collect(), 'autoOpenAddItem' => false, 'prefillComponent' => null,
                'performanceByStudentId' => collect(), 'duplicateExamRoleWarnings' => collect(),
                'statusFilter' => null,
            ]);
        }

        $subjects = Subject::forSection($section)->orderBy('type')->orderBy('name')->get();
        $selectedPeriod = (int) $request->input('period', 1);
        $selectedSubjectId = $request->input('subject_id') ?: $subjects->first()?->id;
        $selectedSubject = $subjects->firstWhere('id', (int) $selectedSubjectId);

        $items = collect();
        if ($selectedSubject) {
            $items = Assessment::where('section_id', $section->id)
                ->where('subject_id', $selectedSubject->id)
                ->where('grading_period', $selectedPeriod)
                ->where('school_year', $section->school_year)
                ->withCount('scores')
                ->orderBy('component')
                ->orderBy('name')
                ->get();
        }

        // TASK 2 of "DO 015 grading weights" — GradingEngine already
        // combines same-role items correctly (summed earned/max, "equally"
        // within the role's share — see examinationPercentage()); this is
        // purely a heads-up so the adviser notices they named the same
        // role twice, which is very likely a mistake (e.g. two columns
        // both marked Summative Test 1) even though the math handles it.
        $duplicateExamRoleWarnings = $items
            ->where('component', 'examination')
            ->whereNotNull('exam_role')
            ->groupBy('exam_role')
            ->filter(fn($group) => $group->count() > 1)
            ->map(fn($group, $role) => $group->pluck('name')->implode(', '));

        $openTerm = AcademicTerm::currentOpenTerm($section->school_year);

        // Whole-section roster, needed for the Add/Edit Assessment Item
        // modals regardless of whether $performance below ends up
        // computed — every student is a candidate score row even before
        // any item exists yet to have a percentage from.
        $sectionStudents = Student::where('section_id', $section->id)->orderBy('last_name')->get();

        // Only worth analyzing once at least one assessment item exists —
        // otherwise every student would just show "incomplete" for nothing.
        $performance = collect();
        if ($selectedSubject && $items->isNotEmpty()) {
            $officialGrades = Grade::where('section_id', $section->id)
                ->where('subject_id', $selectedSubject->id)
                ->where('grading_period', $selectedPeriod)
                ->where('school_year', $section->school_year)
                ->get()
                ->keyBy('student_id');

            // "Workflow completion pass" TASK 3a — display only, computed
            // independently of GradingEngine: which students have at
            // least one SCORED item marked is_additional_support for this
            // subject/term. One query for the whole section, not per row.
            $additionalSupportStudentIds = AssessmentScore::whereHas('assessment', function ($q) use ($selectedSubject, $section, $selectedPeriod) {
                $q->where('subject_id', $selectedSubject->id)
                    ->where('section_id', $section->id)
                    ->where('grading_period', $selectedPeriod)
                    ->where('school_year', $section->school_year)
                    ->where('is_additional_support', true);
            })->distinct()->pluck('student_id');

            $performance = $sectionStudents
                ->map(function ($student) use ($selectedSubject, $section, $selectedPeriod, $officialGrades, $additionalSupportStudentIds) {
                    $result = $this->analysis->analyzeStudent($student, $selectedSubject, $section, $selectedPeriod, $section->school_year);
                    // Reuses $result rather than calling analyzeStudent()
                    // a second time — see InTermStatusService::fromAnalysis().
                    $inTermStatus = $this->inTermStatus->fromAnalysis($result, $student, $selectedSubject, $section, $selectedPeriod, $section->school_year);
                    $officialGrade = $officialGrades->get($student->id);

                    return array_merge(
                        [
                            'student'        => $student,
                            'official_grade' => $officialGrade,
                            'in_term_status' => $inTermStatus,
                            'is_failing'     => InTermStatusService::isFailing($officialGrade),
                            'has_additional_support' => $additionalSupportStudentIds->contains($student->id),
                        ],
                        $result
                    );
                });
        }

        // TASK 2 of "add an assessment item by hand" — a link on the
        // adviser Interventions page reaches here with these query
        // params to pre-fill the Add Assessment Item modal's Component
        // and narrow its roster to only the students who actually have
        // an intervention for this exact subject/term, instead of the
        // whole section. See Intervention::focusComponent() and the
        // link in resources/views/adviser/interventions.blade.php.
        $autoOpenAddItem = $request->boolean('add_item') && $selectedSubject && $openTerm === $selectedPeriod;
        $prefillComponent = array_key_exists($request->input('component'), self::COMPONENT_LABELS)
            ? $request->input('component')
            : null;

        $modalStudents = $sectionStudents;
        if ($request->boolean('from_intervention') && $selectedSubject) {
            $interventionStudentIds = Intervention::where('subject_id', $selectedSubject->id)
                ->where('grading_period', $selectedPeriod)
                ->whereHas('student', fn($q) => $q->where('section_id', $section->id))
                ->pluck('student_id')
                ->unique();

            $modalStudents = $sectionStudents->whereIn('id', $interventionStudentIds)->values();
        }

        // Precomputed once per item (not per row in the Blade) so the
        // Edit Assessment Item modal can be pre-filled with each item's
        // actual scores without an N+1 query per row.
        $itemScoresByAssessment = $items->isEmpty()
            ? collect()
            : AssessmentScore::whereIn('assessment_id', $items->pluck('id'))
                ->get()
                ->groupBy('assessment_id')
                ->map(fn($rows) => $rows->pluck('score', 'student_id'));

        $staleRiskTerms = AcademicTerm::staleRiskTerms($section->school_year);

        // Keyed once here rather than a firstWhere() per roster row inside
        // the Add Assessment Item modal's Blade loop. Sourced from the
        // FULL (pre-filter, pre-sort) $performance set below — modals
        // that look up an arbitrary student must work regardless of
        // whatever status filter is currently narrowing the visible table.
        $performanceByStudentId = $performance->keyBy(fn($row) => $row['student']->id);

        // "The Failing layer" TASK 2d — same shared priority rule as the
        // Principal Students page (Failing -> At Risk -> Needs Attention
        // -> everyone else), PHP 8's stable sort preserving last_name
        // order within each bucket.
        $performance = $performance->sortBy(
            fn($row) => \App\Services\InTermStatusService::priority($row['official_grade'], $row['in_term_status']['status'])
        )->values();

        // "The Failing layer" TASK 2e — same single status filter as the
        // Principal Students page; 'Failing' reads the official grade,
        // everything else reads In-Term Status.
        $statusFilter = $request->input('status_filter');
        if (in_array($statusFilter, ['On Track', 'Needs Attention', 'At Risk', 'Failing'], true)) {
            $performance = $statusFilter === 'Failing'
                ? $performance->filter(fn($row) => $row['is_failing'])->values()
                : $performance->filter(fn($row) => ($row['in_term_status']['status'] ?? null) === $statusFilter)->values();
        }

        return view('adviser.assessments', compact(
            'section', 'subjects', 'selectedSubject', 'selectedPeriod', 'items', 'openTerm', 'performance',
            'staleRiskTerms', 'sectionStudents', 'modalStudents', 'itemScoresByAssessment',
            'autoOpenAddItem', 'prefillComponent', 'performanceByStudentId', 'duplicateExamRoleWarnings',
            'statusFilter'
        ));
    }

    /**
     * Phase 1: read the header row only, guess each column's component,
     * and show the adviser a verification form. Nothing is saved yet.
     */
    public function detect(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('grading_period', 1);

        // Validated by EXTENSION, not `mimes:` (MIME-sniffing) — see
        // ValidatesSpreadsheetUpload and CLAUDE.md, "mimes: MIME-sniffing
        // rejects the official DepEd ECR." Content is verified for real
        // immediately after by EcrProfileDetector for a genuine ECR, and by
        // AssessmentColumnClassifier's own header parsing otherwise.
        $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'file'       => $this->spreadsheetFileRule(),
        ]);

        $subject = Subject::forSection($section)->where('id', $request->subject_id)->first();
        if (!$subject) {
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod])
                ->with('error', 'That subject is not offered to your section.');
        }

        if (!AcademicTerm::isOpen($section->school_year, $gradingPeriod)) {
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
                ->with('error', 'Term ' . $gradingPeriod . ' is currently closed for encoding. Contact the admin.');
        }

        $storedFilename = Str::uuid() . '.' . $request->file('file')->getClientOriginalExtension();
        $request->file('file')->storeAs(self::TEMP_DIR, $storedFilename, 'local');

        $absolutePath = Storage::disk('local')->path(self::TEMP_DIR . '/' . $storedFilename);
        $detected = $this->uploads->detectColumns($absolutePath, $gradingPeriod);

        if (empty($detected['columns'])) {
            Storage::disk('local')->delete(self::TEMP_DIR . '/' . $storedFilename);
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
                ->with('error', 'No assessment columns were found in that file. Expected: lrn, last_name, first_name, then one column per assessment item.');
        }

        // A wrong max in the MAX row is exactly what this feature exists to
        // prevent — block the upload rather than silently falling back to a
        // blank/typed-by-hand max for that column.
        if (!empty($detected['max_row_errors'])) {
            Storage::disk('local')->delete(self::TEMP_DIR . '/' . $storedFilename);
            $named = collect($detected['max_row_errors'])
                ->map(fn($badValue, $column) => "{$column} ('{$badValue}')")
                ->implode(', ');
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
                ->with('error', "The MAX row in that file has an invalid value for: {$named}. Each column's maximum must be a positive number — fix the file and upload again.");
        }

        // TASK 3a of "dashboard structure and upload safeguards" — a
        // dismissible, non-blocking notice when the filename looks like a
        // DIFFERENT subject than the one selected. Needs every subject
        // this section takes (not just the selected one) to have anything
        // to compare against — see AssessmentUploadService::detectFilenameSubjectMismatch().
        $sectionSubjects = Subject::forSection($section)->get();
        $filenameMismatch = $this->uploads->detectFilenameSubjectMismatch(
            $request->file('file')->getClientOriginalName(),
            $subject,
            $sectionSubjects
        );

        // "ECR alignment" work order, PART 5f — dismissible, non-blocking,
        // only ever populated for a file actually read through the ECR
        // profile. Never overwrites the subject's own weights — see
        // EcrReaderService::checkWeightMismatch().
        $weightMismatch = $this->uploads->checkEcrWeightMismatch($absolutePath, $subject);

        return view('adviser.assessments-verify', [
            'section'          => $section,
            'subject'          => $subject,
            'gradingPeriod'    => $gradingPeriod,
            'storedFilename'   => $storedFilename,
            'columns'          => $detected['columns'],
            'rowCount'         => $detected['row_count'],
            'maxRowPresent'    => $detected['max_row_present'],
            'originalName'     => $request->file('file')->getClientOriginalName(),
            'filenameMismatch' => $filenameMismatch,
            'weightMismatch'   => $weightMismatch,
        ]);
    }

    /**
     * Phase 2 (CLAUDE.md's "Preview" step): the adviser has confirmed each
     * column's component and max score — show exactly what will happen
     * (which rows match, which cells are valid/invalid) as a DRY RUN
     * before anything is written. The confirmed mapping is round-tripped
     * as hidden fields so the eventual import() call validates against
     * the exact same input the adviser previewed, not a re-detected one.
     */
    public function preview(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('grading_period', 1);

        $request->validate([
            'subject_id'                => 'required|exists:subjects,id',
            'stored_filename'           => ['required', 'string', 'regex:/^[a-f0-9\-]+\.(xlsx|xls|csv|txt)$/i'],
            'columns'                   => 'required|array|min:1',
            'columns.*.name'            => 'required|string',
            'columns.*.component'       => 'required|in:written_work,performance_task,examination',
            'columns.*.exam_role'       => 'nullable|in:st1,st2,term_exam',
            'columns.*.is_additional_support' => 'nullable|boolean',
            'columns.*.max_score'       => 'required|numeric|min:0.01',
        ]);

        $subject = Subject::forSection($section)->where('id', $request->subject_id)->first();
        if (!$subject) {
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod])
                ->with('error', 'That subject is not offered to your section.');
        }

        if (!AcademicTerm::isOpen($section->school_year, $gradingPeriod)) {
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
                ->with('error', 'Term ' . $gradingPeriod . ' is currently closed for encoding. Contact the admin.');
        }

        $relativePath = self::TEMP_DIR . '/' . $request->input('stored_filename');
        if (!Storage::disk('local')->exists($relativePath)) {
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
                ->with('error', 'The uploaded file has expired. Please upload it again.');
        }

        $columnMapping = [];
        foreach ($request->input('columns') as $col) {
            $columnMapping[$col['name']] = [
                'component' => $col['component'],
                // Only meaningful for Examination columns — see
                // GradingEngine::examinationPercentage(). Optional: an
                // adviser who leaves it unset falls back to equal
                // weighting within the component.
                'exam_role' => $col['component'] === 'examination' ? ($col['exam_role'] ?? null) : null,
                'max_score' => (float) $col['max_score'],
            ];
        }

        $preview = $this->uploads->previewRows(
            Storage::disk('local')->path($relativePath),
            $columnMapping,
            $section,
            $gradingPeriod
        );

        // TASK 3b of "dashboard structure and upload safeguards" — a
        // dismissible, non-blocking notice when NOT ONE row in the file
        // matched a student in this section: almost certainly the wrong
        // file or the wrong section, not a normal amount of typos.
        $rosterMismatch = $preview['total_rows'] > 0 && $preview['matched_rows'] === 0;

        return view('adviser.assessments-preview', [
            'section'         => $section,
            'subject'         => $subject,
            'gradingPeriod'   => $gradingPeriod,
            'storedFilename'  => $request->input('stored_filename'),
            'originalName'    => $request->input('original_filename'),
            'columns'         => $request->input('columns'),
            'preview'         => $preview,
            'rosterMismatch'  => $rosterMismatch,
        ]);
    }

    /**
     * Phase 3: the adviser has seen the preview and confirmed — now
     * actually import.
     */
    public function import(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('grading_period', 1);

        $request->validate([
            'subject_id'                => 'required|exists:subjects,id',
            'stored_filename'           => ['required', 'string', 'regex:/^[a-f0-9\-]+\.(xlsx|xls|csv|txt)$/i'],
            'columns'                   => 'required|array|min:1',
            'columns.*.name'            => 'required|string',
            'columns.*.component'       => 'required|in:written_work,performance_task,examination',
            'columns.*.exam_role'       => 'nullable|in:st1,st2,term_exam',
            'columns.*.is_additional_support' => 'nullable|boolean',
            'columns.*.max_score'       => 'required|numeric|min:0.01',
        ]);

        $subject = Subject::forSection($section)->where('id', $request->subject_id)->first();
        if (!$subject) {
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod])
                ->with('error', 'That subject is not offered to your section.');
        }

        if (!AcademicTerm::isOpen($section->school_year, $gradingPeriod)) {
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
                ->with('error', 'Term ' . $gradingPeriod . ' is currently closed for encoding. Contact the admin.');
        }

        // The token is validated by the regex above (hex-uuid + known
        // extension only) before it ever touches the filesystem, so a
        // crafted value can't escape the temp directory.
        $relativePath = self::TEMP_DIR . '/' . $request->input('stored_filename');
        if (!Storage::disk('local')->exists($relativePath)) {
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
                ->with('error', 'The uploaded file has expired. Please upload it again.');
        }

        $columnMapping = [];
        foreach ($request->input('columns') as $col) {
            $columnMapping[$col['name']] = [
                'component' => $col['component'],
                'exam_role' => $col['component'] === 'examination' ? ($col['exam_role'] ?? null) : null,
                // "Workflow completion pass" TASK 3b — set explicitly by
                // the Adviser on the Verify screen, never inferred from
                // the column's name (e.g. never a "%remedial%" match).
                'is_additional_support' => filter_var($col['is_additional_support'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'max_score' => (float) $col['max_score'],
            ];
        }

        $upload = AssessmentUpload::create([
            'section_id'        => $section->id,
            'subject_id'        => $subject->id,
            'uploaded_by'       => auth()->id(),
            'grading_period'    => $gradingPeriod,
            'school_year'       => $section->school_year,
            'original_filename' => $request->input('original_filename', $request->input('stored_filename')),
            'column_mapping'    => array_map(fn($c) => $c['component'], $columnMapping),
            'status'            => 'pending_review',
        ]);

        $absolutePath = Storage::disk('local')->path($relativePath);
        $result = $this->uploads->import(
            $absolutePath,
            $columnMapping,
            $section,
            $subject,
            $gradingPeriod,
            $section->school_year,
            auth()->id(),
            $upload
        );

        // 'imported' regardless of whether some individual rows had
        // errors — error_count already captures that; status here is
        // about whether the upload as a whole was processed at all.
        $upload->update([
            'status'               => 'imported',
            'imported_count'       => $result['imported'],
            'error_count'          => count($result['errors']),
            // "ECR alignment" work order, PART 5a — null for every upload
            // read through the existing flat path, unchanged.
            'ecr_profile_version'  => $result['ecr_profile_version'] ?? null,
        ]);

        Storage::disk('local')->delete($relativePath);

        LogActivity::log(
            'import_assessments',
            'Imported assessment scores for ' . $subject->name . ' — Term ' . $gradingPeriod . ' — Section ' . $section->name,
            'assessments',
            null
        );

        if (!empty($result['errors'])) {
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
                ->with('warning', 'Imported ' . $result['imported'] . ' score(s). ' . count($result['errors']) . ' entr' . (count($result['errors']) === 1 ? 'y' : 'ies') . ' were skipped:')
                ->with('import_errors', $result['errors']);
        }

        return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
            ->with('success', 'Successfully imported ' . $result['imported'] . ' score(s)!');
    }

    /**
     * "Add an assessment item by hand" — a second way into the exact same
     * Assessment/AssessmentScore shape the file-upload path (import(),
     * above) produces: same updateOrCreate keys, same audit trail via
     * AssessmentUpload, same term guard. Meant for a small batch (a
     * remedial item for two or three students), not a whole quarter's
     * assessments — the file path is unchanged and still the right tool
     * for that.
     *
     * A blank score field means the student did not take this item — it
     * is stored as NO AssessmentScore row at all, never a zero, so a
     * remedial item only ever affects the students who actually sat it
     * (same "missing means incomplete, not zero" rule GradingEngine
     * documents for the file path).
     */
    public function storeItem(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('grading_period', 1);

        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'item_name'  => 'required|string|max:255',
            'component'  => ['required', Rule::in(array_keys(self::COMPONENT_LABELS))],
            'exam_role'  => 'nullable|in:st1,st2,term_exam',
            'is_additional_support' => 'nullable|boolean',
            'max_score'  => 'required|numeric|min:0.01',
            'scores'     => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator, 'addItem')->withInput();
        }

        $subject = Subject::forSection($section)->where('id', $request->input('subject_id'))->first();
        if (!$subject) {
            return back()->withErrors(['item_name' => 'That subject is not offered to your section.'], 'addItem')->withInput();
        }

        if (!AcademicTerm::isOpen($section->school_year, $gradingPeriod)) {
            return back()->withErrors(['item_name' => 'Term ' . $gradingPeriod . ' is currently closed for encoding. Contact the admin.'], 'addItem')->withInput();
        }

        $itemName = trim($request->input('item_name'));
        $component = $request->input('component');
        $examRole = $component === 'examination' ? $request->input('exam_role') : null;
        $maxScore = (float) $request->input('max_score');
        // "Workflow completion pass" TASK 3b.
        $isAdditionalSupport = $request->boolean('is_additional_support');

        // Same (subject, section, term, school year, name) key
        // Assessment::updateOrCreate() below would upsert against — an
        // existing item under this exact name would be silently
        // overwritten by an unrelated new item unless caught here first.
        // Editing an existing item's max score/scores is Edit (see
        // updateItem() below), never a second Add with the same name.
        $existing = Assessment::where('subject_id', $subject->id)
            ->where('section_id', $section->id)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $section->school_year)
            ->where('name', $itemName)
            ->first();

        if ($existing) {
            return back()->withErrors([
                'item_name' => "An item named \"{$itemName}\" already exists for this subject and term (Component: "
                    . (self::COMPONENT_LABELS[$existing->component] ?? $existing->component)
                    . ', Max Score: ' . number_format((float) $existing->max_score, 2)
                    . ') — saving here would overwrite it. Choose a different name, or use Edit on that item instead.',
            ], 'addItem')->withInput();
        }

        $students = Student::where('section_id', $section->id)->get()->keyBy('id');
        $parsedScores = [];
        $invalidStudents = [];

        foreach ($request->input('scores', []) as $studentId => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value === null || $value === '') {
                continue; // blank -> not taken, no row at all — never a zero
            }

            $student = $students->get((int) $studentId);
            if (!$student) {
                continue; // not in this section — ignore a tampered id rather than trust it
            }

            if (!is_numeric($value) || (float) $value < 0 || (float) $value > $maxScore) {
                $invalidStudents[] = $student->last_name . ', ' . $student->first_name;
                continue;
            }

            $parsedScores[(int) $studentId] = (float) $value;
        }

        if (!empty($invalidStudents)) {
            return back()->withErrors([
                'scores' => 'Every entered score must be numeric, at least 0, and no more than the max score ('
                    . number_format($maxScore, 2) . '). Check: ' . implode('; ', $invalidStudents) . '.',
            ], 'addItem')->withInput();
        }

        // Same amber "declared max looks too high" warning the file
        // upload path shows in Preview — never blocks, see
        // AssessmentUploadService::SUSPICIOUS_MAX_RATIO.
        $suspicious = false;
        if (!empty($parsedScores)) {
            $suspicious = $this->uploads->isSuspiciousMax(count($parsedScores), max($parsedScores), $maxScore);
        }

        $assessment = DB::transaction(function () use ($section, $subject, $gradingPeriod, $itemName, $component, $examRole, $isAdditionalSupport, $maxScore, $parsedScores) {
            $upload = AssessmentUpload::create([
                'section_id'        => $section->id,
                'subject_id'        => $subject->id,
                'uploaded_by'       => auth()->id(),
                'grading_period'    => $gradingPeriod,
                'school_year'       => $section->school_year,
                'original_filename' => '(Manual entry — ' . $itemName . ')',
                'column_mapping'    => [$itemName => $component],
                'status'            => 'manual_entry',
                'imported_count'    => count($parsedScores),
                'error_count'       => 0,
            ]);

            $assessment = Assessment::updateOrCreate(
                [
                    'subject_id'     => $subject->id,
                    'section_id'     => $section->id,
                    'grading_period' => $gradingPeriod,
                    'school_year'    => $section->school_year,
                    'name'           => $itemName,
                ],
                [
                    'assessment_type' => $itemName,
                    'component'       => $component,
                    'exam_role'       => $examRole,
                    'is_additional_support' => $isAdditionalSupport,
                    'max_score'       => $maxScore,
                    'import_batch_id' => (string) $upload->id,
                    'uploaded_by'     => auth()->id(),
                ]
            );

            foreach ($parsedScores as $studentId => $score) {
                AssessmentScore::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'student_id' => $studentId],
                    ['score' => $score]
                );
            }

            return $assessment;
        });

        LogActivity::log(
            'add_assessment_item',
            'Manually added assessment item "' . $itemName . '" (' . self::COMPONENT_LABELS[$component] . ') for '
                . $subject->name . ' — Term ' . $gradingPeriod . ' — Section ' . $section->name
                . ' — ' . count($parsedScores) . ' score(s) entered.',
            'assessments',
            $assessment->id
        );

        $redirect = redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id]);

        if ($suspicious) {
            return $redirect->with('warning', 'Saved "' . $itemName . '" with ' . count($parsedScores) . ' score(s). '
                . 'Note: the highest score entered is well below half of the declared max score ('
                . number_format($maxScore, 2) . ') — double-check the max score is correct.');
        }

        return $redirect->with('success', 'Added "' . $itemName . '" with ' . count($parsedScores) . ' score(s).');
    }

    /**
     * The honest fix for a wrong max score (or a wrong individual score)
     * without a full re-upload. Max score and individual scores may
     * change; component and item name may NOT — both are part of the
     * updateOrCreate key storeItem() (and the file path's import())
     * upsert against, so changing either here would silently create a
     * SECOND item rather than correct this one.
     *
     * Recomputes and reports the affected students' OWN component
     * percentage before/after — never the whole grade — since editing
     * one item only ever changes the ONE component it belongs to.
     */
    public function updateItem(Request $request, Assessment $assessment)
    {
        $section = Section::where('adviser_id', auth()->id())->first();
        abort_if(!$section || $assessment->section_id !== $section->id, 403);

        if (!AcademicTerm::isOpen($section->school_year, $assessment->grading_period)) {
            return back()->with('error', 'Term ' . $assessment->grading_period . ' is currently closed for encoding. Contact the admin.');
        }

        $validator = Validator::make($request->all(), [
            'max_score' => 'required|numeric|min:0.01',
            'scores'    => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator, 'editItem')->withInput();
        }

        $newMaxScore = (float) $request->input('max_score');
        $students = Student::where('section_id', $section->id)->get()->keyBy('id');

        $parsedScores = []; // studentId => float|null (null = clear the score)
        $invalidStudents = [];

        foreach ($request->input('scores', []) as $studentId => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $student = $students->get((int) $studentId);
            if (!$student) {
                continue;
            }

            if ($value === null || $value === '') {
                $parsedScores[(int) $studentId] = null; // explicitly cleared
                continue;
            }

            if (!is_numeric($value) || (float) $value < 0 || (float) $value > $newMaxScore) {
                $invalidStudents[] = $student->last_name . ', ' . $student->first_name;
                continue;
            }

            $parsedScores[(int) $studentId] = (float) $value;
        }

        if (!empty($invalidStudents)) {
            return back()->withErrors([
                'scores' => 'Every entered score must be numeric, at least 0, and no more than the max score ('
                    . number_format($newMaxScore, 2) . '). Check: ' . implode('; ', $invalidStudents) . '.',
            ], 'editItem')->withInput();
        }

        $subject = $assessment->subject;
        $gradingPeriod = $assessment->grading_period;
        $schoolYear = $section->school_year;
        $component = $assessment->component;

        // "Affected" = every student who had a score on this item before,
        // union every student this submission touches — a student whose
        // score is being newly added, changed, or cleared.
        $existingScores = AssessmentScore::where('assessment_id', $assessment->id)->get()->keyBy('student_id');
        $affectedIds = $existingScores->keys()->merge(array_keys($parsedScores))->unique();
        $affectedStudents = $students->only($affectedIds->all());

        $before = $affectedStudents->mapWithKeys(function ($student) use ($subject, $section, $gradingPeriod, $schoolYear, $component) {
            $result = $this->analysis->analyzeStudent($student, $subject, $section, $gradingPeriod, $schoolYear);
            return [$student->id => $result['components'][$component]['percentage'] ?? null];
        });

        DB::transaction(function () use ($assessment, $newMaxScore, $parsedScores) {
            $assessment->update(['max_score' => $newMaxScore]);

            foreach ($parsedScores as $studentId => $value) {
                if ($value === null) {
                    AssessmentScore::where('assessment_id', $assessment->id)->where('student_id', $studentId)->delete();
                    continue;
                }

                AssessmentScore::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'student_id' => $studentId],
                    ['score' => $value]
                );
            }
        });

        $after = $affectedStudents->mapWithKeys(function ($student) use ($subject, $section, $gradingPeriod, $schoolYear, $component) {
            $result = $this->analysis->analyzeStudent($student, $subject, $section, $gradingPeriod, $schoolYear);
            return [$student->id => $result['components'][$component]['percentage'] ?? null];
        });

        $summary = $affectedStudents->map(fn($student) => [
            'name'   => $student->last_name . ', ' . $student->first_name,
            'before' => $before->get($student->id),
            'after'  => $after->get($student->id),
        ])->values()->all();

        LogActivity::log(
            'edit_assessment_item',
            'Edited assessment item "' . $assessment->name . '" (' . self::COMPONENT_LABELS[$component] . ') for '
                . $subject->name . ' — Term ' . $gradingPeriod . ' — Section ' . $section->name . ' — max score set to '
                . number_format($newMaxScore, 2) . ', ' . count($summary) . ' student(s) affected.',
            'assessments',
            $assessment->id
        );

        return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
            ->with('success', 'Updated "' . $assessment->name . '".')
            ->with('edit_summary', $summary);
    }
}
