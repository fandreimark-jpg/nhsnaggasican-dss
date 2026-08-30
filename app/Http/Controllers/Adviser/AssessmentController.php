<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\Section;
use App\Models\Subject;
use App\Models\AssessmentUpload;
use App\Models\Student;
use App\Helpers\LogActivity;
use App\Services\AssessmentUploadService;
use App\Services\PerformanceAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    private const TEMP_DIR = 'temp_assessment_uploads';

    public function __construct(
        private AssessmentUploadService $uploads = new AssessmentUploadService(),
        private PerformanceAnalysisService $analysis = new PerformanceAnalysisService()
    ) {
    }

    public function index(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->first();

        if (!$section) {
            return view('adviser.assessments', [
                'section' => null, 'subjects' => collect(), 'selectedSubject' => null,
                'selectedPeriod' => 1, 'items' => collect(), 'openTerm' => null, 'performance' => collect(),
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

        $openTerm = AcademicTerm::currentOpenTerm($section->school_year);

        // Only worth analyzing once at least one assessment item exists —
        // otherwise every student would just show "incomplete" for nothing.
        $performance = collect();
        if ($selectedSubject && $items->isNotEmpty()) {
            $performance = Student::where('section_id', $section->id)
                ->orderBy('last_name')
                ->get()
                ->map(fn($student) => array_merge(
                    ['student' => $student],
                    $this->analysis->analyzeStudent($student, $selectedSubject, $section, $selectedPeriod, $section->school_year)
                ));
        }

        return view('adviser.assessments', compact(
            'section', 'subjects', 'selectedSubject', 'selectedPeriod', 'items', 'openTerm', 'performance'
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

        $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'file'       => 'required|mimes:xlsx,xls,csv,txt|max:2048',
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
        $detected = $this->uploads->detectColumns($absolutePath);

        if (empty($detected['columns'])) {
            Storage::disk('local')->delete(self::TEMP_DIR . '/' . $storedFilename);
            return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
                ->with('error', 'No assessment columns were found in that file. Expected: lrn, last_name, first_name, then one column per assessment item.');
        }

        return view('adviser.assessments-verify', [
            'section'        => $section,
            'subject'        => $subject,
            'gradingPeriod'  => $gradingPeriod,
            'storedFilename' => $storedFilename,
            'columns'        => $detected['columns'],
            'rowCount'       => $detected['row_count'],
            'originalName'   => $request->file('file')->getClientOriginalName(),
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
                'max_score' => (float) $col['max_score'],
            ];
        }

        $preview = $this->uploads->previewRows(
            Storage::disk('local')->path($relativePath),
            $columnMapping,
            $section
        );

        return view('adviser.assessments-preview', [
            'section'         => $section,
            'subject'         => $subject,
            'gradingPeriod'   => $gradingPeriod,
            'storedFilename'  => $request->input('stored_filename'),
            'originalName'    => $request->input('original_filename'),
            'columns'         => $request->input('columns'),
            'preview'         => $preview,
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
            'status'         => 'imported',
            'imported_count' => $result['imported'],
            'error_count'    => count($result['errors']),
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
}
