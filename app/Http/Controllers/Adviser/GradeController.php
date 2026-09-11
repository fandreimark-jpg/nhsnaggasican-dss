<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Section;
use App\Models\AcademicTerm;
use App\Http\Controllers\Concerns\ValidatesSpreadsheetUpload;
use App\Imports\GradesImport;
use App\Helpers\LogActivity;
use App\Services\GradingEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * GradeController (Adviser)
 *
 * Handles grade encoding for the adviser's assigned section.
 * Advisers can only encode grades for students in their own section,
 * AND only while the selected term is the currently open term
 * (system-wide, controlled by the Admin via AcademicTermController).
 */
class GradeController extends Controller
{
    use ValidatesSpreadsheetUpload;

    public function __construct(private GradingEngine $gradingEngine = new GradingEngine())
    {
    }

    public function index()
    {
        $section = Section::where('adviser_id', auth()->id())
            ->with(['track', 'specialization'])
            ->first();

        if (!$section) {
            return view('adviser.grades', [
                'section'        => null,
                'students'       => collect(),
                'subjects'       => collect(),
                'grades'         => collect(),
                'selectedPeriod' => 1,
                'openTerm'       => null,
            ]);
        }

        $students = Student::where('section_id', $section->id)
            ->orderBy('last_name')
            ->get();

        $subjects = Subject::forSection($section)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $selectedPeriod = (int) request('period', 1);

        $grades = Grade::where('section_id', $section->id)
            ->where('grading_period', $selectedPeriod)
            ->where('school_year', $section->school_year)
            ->get()
            ->keyBy(fn($g) => $g->student_id . '_' . $g->subject_id);

        // Which term is open right now, system-wide, for this school year?
        $openTerm = AcademicTerm::currentOpenTerm($section->school_year);

        return view('adviser.grades', compact(
            'section', 'students', 'subjects', 'grades', 'selectedPeriod', 'openTerm'
        ));
    }

    public function store(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();

        $request->validate([
            'grading_period'          => 'required|in:1,2,3',
            'grades'                  => 'required|array',
            'grades.*.student_id'     => 'required|exists:students,id',
            'grades.*.subject_id'     => 'required|exists:subjects,id',
            'grades.*.grade'          => 'nullable|numeric|min:60|max:100',
        ]);

        // Server-side enforcement — never trust the disabled inputs on the
        // front end alone. Someone could re-enable them via devtools.
        if (!AcademicTerm::isOpen($section->school_year, (int) $request->grading_period)) {
            return redirect()->route('adviser.grades', ['period' => $request->grading_period])
                ->with('error', 'Term ' . $request->grading_period . ' is currently closed for encoding. Contact the admin.');
        }

        $validStudentIds = Student::where('section_id', $section->id)
            ->pluck('id')
            ->toArray();

        // Same reasoning as $validStudentIds above: 'exists:subjects,id' alone
        // only proves the subject exists SOMEWHERE, not that it's actually
        // offered to this section (grade level / track / specialization).
        // Without this, an adviser could POST a subject_id belonging to a
        // different grade level or track and have it silently accepted.
        $validSubjectIds = Subject::forSection($section)->pluck('id')->toArray();

        foreach ($request->grades as $gradeData) {
            if (!isset($gradeData['grade']) || $gradeData['grade'] === null || $gradeData['grade'] === '') {
                continue;
            }

            if (!in_array($gradeData['student_id'], $validStudentIds)) continue;
            if (!in_array($gradeData['subject_id'], $validSubjectIds)) continue;

            Grade::updateOrCreate(
                [
                    'student_id'     => $gradeData['student_id'],
                    'subject_id'     => $gradeData['subject_id'],
                    'section_id'     => $section->id,
                    'grading_period' => $request->grading_period,
                    'school_year'    => $section->school_year,
                ],
                [
                    'grade'      => $gradeData['grade'],
                    'encoded_by' => auth()->id(),
                ]
            );
        }

        LogActivity::log(
            'encode_grades',
            'Encoded grades for Term ' . $request->grading_period . ' — Section ' . $section->name,
            'grades',
            null
        );

        return redirect()
            ->route('adviser.grades', ['period' => $request->grading_period])
            ->with('success', 'Grades for Term ' . $request->grading_period . ' saved successfully!');
    }

    /**
     * Bulk import grades from an uploaded Excel/CSV file.
     * Blocked server-side if the term is not currently open — same
     * rule as the manual encoding form above.
     */
    public function importGrades(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('grading_period', 1);

        if (!AcademicTerm::isOpen($section->school_year, $gradingPeriod)) {
            return redirect()->route('adviser.grades', ['period' => $gradingPeriod])
                ->with('error', 'Term ' . $gradingPeriod . ' is currently closed for encoding. Contact the admin.');
        }

        $request->validateWithBag('gradeImport', [
            'file' => $this->spreadsheetFileRule(),
        ]);

        $subjects = Subject::forSection($section)->orderBy('type')->orderBy('name')->get();
        $students = Student::where('section_id', $section->id)->get();

        $import = new GradesImport($section->id, $gradingPeriod, $section->school_year, $subjects, $students);
        Excel::import($import, $request->file('file'));

        LogActivity::log(
            action:      'import_grades',
            description: 'Bulk imported grades for Term ' . $gradingPeriod . ' — Section ' . $section->name,
            tableName:   'grades',
            recordId:    null
        );

        if (!empty($import->errors)) {
            return redirect()->route('adviser.grades', ['period' => $gradingPeriod])
                ->with('warning', 'Imported ' . $import->importedCount . ' grade(s). ' . count($import->errors) . ' entr' . (count($import->errors) === 1 ? 'y' : 'ies') . ' were skipped:')
                ->with('import_errors', $import->errors);
        }

        return redirect()->route('adviser.grades', ['period' => $gradingPeriod])
            ->with('success', 'Successfully imported ' . $import->importedCount . ' grade(s) for Term ' . $gradingPeriod . '!');
    }

    /**
     * Downloadable template — pre-filled with this adviser's actual
     * students so they only need to type in the grade columns.
     */
    public function downloadGradeTemplate(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('period', 1);

        $subjects = Subject::forSection($section)->orderBy('type')->orderBy('name')->get();
        $students = Student::where('section_id', $section->id)->orderBy('last_name')->get();

        $headers = array_merge(['lrn', 'last_name', 'first_name'], $subjects->pluck('name')->toArray());

        return response()->streamDownload(function () use ($headers, $students, $subjects) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($students as $student) {
                $row = array_merge(
                    [$student->lrn, $student->last_name, $student->first_name],
                    array_fill(0, $subjects->count(), '') // blank grade cells to fill in
                );
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'grade_template_term_' . $gradingPeriod . '.csv');
    }

    /**
     * The missing half of CLAUDE.md's pipeline: "computed_grade ->
     * Adviser verification -> official grade." GradingEngine and the
     * Performance Analysis table (adviser.assessments) could already
     * COMPUTE and DISPLAY a grade from assessment evidence, but nothing
     * ever turned that into an action — this is that action. The
     * adviser explicitly accepts a specific computed value; it is never
     * applied automatically, and it OVERWRITES whatever official grade
     * already exists for that student/subject/term (the confirm-before-
     * submit dialog on the button that posts here says so).
     *
     * Recomputes server-side rather than trusting a client-submitted
     * grade value — the adviser is confirming "yes, use what the system
     * just showed me," not supplying their own number here.
     */
    public function verifyComputedGrade(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('grading_period', 1);

        $request->validate([
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
        ]);

        $subject = Subject::forSection($section)->where('id', $request->subject_id)->first();
        if (!$subject) {
            return $this->verifyFailureResponse($request, 'That subject is not offered to your section.');
        }

        $student = Student::where('id', $request->student_id)->where('section_id', $section->id)->first();
        if (!$student) {
            return $this->verifyFailureResponse($request, 'That student is not in your section.');
        }

        if (!AcademicTerm::isOpen($section->school_year, $gradingPeriod)) {
            return $this->verifyFailureResponse($request, 'Term ' . $gradingPeriod . ' is currently closed for encoding. Contact the admin.');
        }

        $outcome = $this->verifyOneGrade($student, $subject, $section, $gradingPeriod);

        if (!$outcome['success']) {
            return $this->verifyFailureResponse($request, $outcome['message']);
        }

        // TASK 3 of "UI cleanup and correctness pass" — a JS-driven
        // caller (the row's own fetch() submit) asks for JSON via the
        // Accept header and updates just that row in place, instead of
        // the full-page redirect a non-JS submit still gets below. Same
        // business logic either way — this only branches on response
        // shape, never recomputes or re-validates anything differently.
        if ($request->expectsJson()) {
            return response()->json([
                'student_id'     => $student->id,
                'official_grade' => $outcome['result']['transmuted_grade'],
                'computed_grade' => $outcome['result']['computed_grade'],
                'provisional'    => $outcome['is_provisional'],
                'is_failing'     => \App\Services\InTermStatusService::isFailing($outcome['grade']),
                'message'        => $outcome['message'],
            ]);
        }

        return redirect()->route('adviser.assessments', ['period' => $gradingPeriod, 'subject_id' => $subject->id])
            ->with('success', $outcome['message']);
    }

    /**
     * TASK 1 of "workflow completion pass" — the same mechanical
     * transformation as verifyComputedGrade() (computed grade -> official
     * grade via the subject's real transmutation table), extracted so
     * verifyAllRemaining() below can reuse it verbatim rather than
     * duplicating the computation/transmutation/write logic. Writes
     * exactly one Grade row and exactly one LogActivity entry per call —
     * callers that verify many students must call this once per student,
     * never batch the logging.
     *
     * Does NOT check whether an official grade already exists — that
     * exclusion is the CALLER's responsibility (see
     * classifyForVerifyAll()'s "already encoded" bucket), since
     * overwriting an existing official grade must stay an individual,
     * deliberate act reached only through this same method via the
     * single-student endpoint above, never through the batch path.
     *
     * @return array{success: bool, message: string, result?: array, is_provisional?: bool, grade?: Grade}
     */
    private function verifyOneGrade(Student $student, Subject $subject, Section $section, int $gradingPeriod): array
    {
        $result = $this->gradingEngine->computeGrade($student, $subject, $section, $gradingPeriod, $section->school_year);

        if (!$result['complete']) {
            return [
                'success' => false,
                'message' => 'Cannot verify — assessment evidence for ' . $subject->name . ' is not complete yet (a component has no scores).',
            ];
        }

        // TASK 2 of "terminology, transmutation, and interface cleanup" —
        // grades.grade must hold the TRANSMUTED grade (see the comment
        // below); when the section's scheme has no band data for this
        // computed grade yet (see TransmutationRangesSeeder's TODO),
        // there is nothing correct to write, so verification is blocked
        // rather than writing a fabricated or wrong value.
        if (!$result['transmutation_available']) {
            return [
                'success' => false,
                'message' => 'Cannot verify — no transmuted grade is available yet for the ' . $result['transmutation_scheme'] .
                    ' scheme at a computed grade of ' . $result['computed_grade'] . '. The transmutation table for this ' .
                    'curriculum has not been entered by the system administrator yet.',
            ];
        }

        // TASK 1 of "unblock verification" — a fallback-produced transmuted
        // grade (see config('dss.transmutation_fallback_scheme')) must be
        // stored as findable, never indistinguishable from a real one.
        $isProvisional = $result['transmutation_provisional'];

        $grade = Grade::updateOrCreate(
            [
                'student_id'     => $student->id,
                'subject_id'     => $subject->id,
                'section_id'     => $section->id,
                'grading_period' => $gradingPeriod,
                'school_year'    => $section->school_year,
            ],
            [
                // The TRANSMUTED grade is the reported grade — grade.grade
                // must never hold the raw weighted percentage. computed_grade
                // keeps the raw value unchanged, since that's what the
                // component analysis / risk classifier read (see
                // TransmutationService and GradingEngine).
                'grade'               => $result['transmuted_grade'],
                'computed_grade'      => $result['computed_grade'],
                'is_verified'         => true,
                'verified_at'         => now(),
                'encoded_by'          => auth()->id(),
                'is_provisional'      => $isProvisional,
                'provisional_scheme'  => $isProvisional ? $result['transmutation_fallback_scheme'] : null,
            ]
        );

        LogActivity::log(
            'verify_computed_grade',
            'Verified computed grade (' . $result['computed_grade'] . ' -> transmuted ' . $result['transmuted_grade'] . ($isProvisional ? ', PROVISIONAL' : '') . ') as official for ' .
                $student->last_name . ', ' . $student->first_name . ' — ' . $subject->name,
            'grades',
            null
        );

        $message = 'Official grade for ' . $student->last_name . ', ' . $student->first_name . ' set to ' . $result['transmuted_grade'] . ' (transmuted from a computed grade of ' . $result['computed_grade'] . ').';

        if ($isProvisional) {
            $message .= ' PROVISIONAL — computed using the ' . \App\Services\TransmutationService::schemeLabel($result['transmutation_fallback_scheme']) .
                ' table because the ' . \App\Services\TransmutationService::schemeLabel($result['transmutation_scheme']) .
                ' bands are not yet entered. Recompute once the real table is seeded (php artisan dss:recompute-grades).';
        }

        return ['success' => true, 'message' => $message, 'result' => $result, 'is_provisional' => $isProvisional, 'grade' => $grade];
    }

    /**
     * TASK 1 of "workflow completion pass" — read-only classification of
     * every student in $section for $subject/$gradingPeriod into exactly
     * one bucket: eligible for Verify All Remaining, or excluded under
     * exactly one of three named reasons. Shared by the preview endpoint
     * (verifyAllRemainingPreview(), which only ever reads) and the actual
     * batch (verifyAllRemaining()), so the two can never disagree about
     * who is eligible between the confirmation dialog and the write.
     *
     * "Already has an official grade" is checked FIRST and is exclusive
     * with the other two reasons — an already-verified student is never
     * also reported as incomplete or missing a transmutation band, since
     * that distinction doesn't matter once the row is excluded for good.
     *
     * @return array{eligible: Collection, excludedAlreadyEncoded: Collection, excludedIncomplete: Collection, excludedNoTransmutation: Collection}
     */
    private function classifyForVerifyAll(Section $section, Subject $subject, int $gradingPeriod): array
    {
        $students = Student::where('section_id', $section->id)->orderBy('last_name')->get();

        $existingGrades = Grade::where('subject_id', $subject->id)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $section->school_year)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        $eligible = collect();
        $excludedAlreadyEncoded = collect();
        $excludedIncomplete = collect();
        $excludedNoTransmutation = collect();

        foreach ($students as $student) {
            $existing = $existingGrades->get($student->id);
            if ($existing && $existing->is_verified) {
                $excludedAlreadyEncoded->push($student);
                continue;
            }

            $result = $this->gradingEngine->computeGrade($student, $subject, $section, $gradingPeriod, $section->school_year);

            if (!$result['complete']) {
                $excludedIncomplete->push($student);
                continue;
            }

            // 1b: a transmuted grade must be available AND not provisional
            // — a fallback-scheme value is not the subject's real official
            // grade, so it is excluded here exactly like "not available".
            if (!$result['transmutation_available'] || $result['transmutation_provisional']) {
                $excludedNoTransmutation->push($student);
                continue;
            }

            $eligible->push($student);
        }

        return compact('eligible', 'excludedAlreadyEncoded', 'excludedIncomplete', 'excludedNoTransmutation');
    }

    /** Student names, last name first — the shape every excluded-reason list in the dialog uses. */
    private function namesOf(Collection $students): array
    {
        return $students->map(fn(Student $s) => $s->last_name . ', ' . $s->first_name)->values()->all();
    }

    /**
     * TASK 1d of "workflow completion pass" — read-only preview for the
     * confirmation dialog: the exact eligible count and every excluded
     * student grouped by reason, computed but NEVER written. The dialog
     * must be able to show this before the Adviser commits to anything.
     */
    public function verifyAllRemainingPreview(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('grading_period', 1);

        $request->validate(['subject_id' => 'required|exists:subjects,id']);

        // TASK 1f — scoped to THIS adviser's own section via
        // Subject::forSection($section): a subject_id for a subject not
        // offered to this section (including one belonging entirely to
        // another section's track/grade level) never resolves, so a
        // crafted request naming a foreign subject_id gets exactly the
        // same 403 a foreign section_id would.
        $subject = Subject::forSection($section)->where('id', $request->subject_id)->first();
        abort_if(!$subject, 403);

        $classification = $this->classifyForVerifyAll($section, $subject, $gradingPeriod);

        return response()->json([
            'eligible_count'   => $classification['eligible']->count(),
            'subject_name'     => $subject->name,
            'section_name'     => $section->name,
            'grading_period'   => $gradingPeriod,
            'term_open'        => AcademicTerm::isOpen($section->school_year, $gradingPeriod),
            'excluded'         => [
                'already_encoded'     => $this->namesOf($classification['excludedAlreadyEncoded']),
                'incomplete_evidence' => $this->namesOf($classification['excludedIncomplete']),
                'no_transmutation'    => $this->namesOf($classification['excludedNoTransmutation']),
            ],
        ]);
    }

    /**
     * TASK 1e/1f of "workflow completion pass" — the actual batch write.
     * Re-classifies fresh (never trusts a client-submitted eligible list
     * — state could have changed since the preview was shown) inside a
     * single transaction; verifyOneGrade() is called once per eligible
     * student, so one Grade row and one LogActivity entry are written per
     * student, never one entry for the whole batch. Any failure among
     * the eligible rows (verifyOneGrade() reporting !success — should not
     * happen given they were JUST classified as eligible, but the
     * transaction exists precisely so a race never leaves a half-verified
     * section behind) rolls back every write from this request.
     */
    public function verifyAllRemaining(Request $request)
    {
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();
        $gradingPeriod = (int) $request->input('grading_period', 1);

        $request->validate(['subject_id' => 'required|exists:subjects,id']);

        $subject = Subject::forSection($section)->where('id', $request->subject_id)->first();
        abort_if(!$subject, 403);

        if (!AcademicTerm::isOpen($section->school_year, $gradingPeriod)) {
            return response()->json(['message' => 'Term ' . $gradingPeriod . ' is currently closed for encoding. Contact the admin.'], 422);
        }

        $classification = $this->classifyForVerifyAll($section, $subject, $gradingPeriod);
        $eligible = $classification['eligible'];

        if ($eligible->isEmpty()) {
            return response()->json(['message' => 'No students are currently eligible to verify.'], 422);
        }

        $verifiedRows = [];

        try {
            DB::transaction(function () use ($eligible, $subject, $section, $gradingPeriod, &$verifiedRows) {
                foreach ($eligible as $student) {
                    $outcome = $this->verifyOneGrade($student, $subject, $section, $gradingPeriod);

                    if (!$outcome['success']) {
                        throw new \RuntimeException($student->last_name . ', ' . $student->first_name . ' — ' . $outcome['message']);
                    }

                    $verifiedRows[] = [
                        'student_id'     => $student->id,
                        'official_grade' => $outcome['result']['transmuted_grade'],
                        'computed_grade' => $outcome['result']['computed_grade'],
                        'provisional'    => $outcome['is_provisional'],
                        // "The Failing layer" — computed via the single
                        // shared rule (InTermStatusService::isFailing()),
                        // never re-derived from a "<= 74" comparison here.
                        'is_failing'     => \App\Services\InTermStatusService::isFailing($outcome['grade']),
                    ];
                }
            });
        } catch (\RuntimeException $e) {
            // 1e: a half-verified section is worse than an unverified one
            // — the transaction above already rolled back every write
            // this request made, so nothing partial survives.
            return response()->json([
                'message' => 'Verification stopped and nothing was saved — ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'verified_count' => count($verifiedRows),
            'verified'       => $verifiedRows,
            'message'        => 'Verified ' . count($verifiedRows) . ' student' . (count($verifiedRows) === 1 ? '' : 's') . '.',
        ]);
    }

    /** JSON error for a fetch()-driven verify submit; redirect-with-error for a normal form submit — see verifyComputedGrade(). */
    private function verifyFailureResponse(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }
}