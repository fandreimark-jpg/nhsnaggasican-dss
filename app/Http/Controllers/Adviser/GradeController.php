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
        $section = Section::forAdviser(auth()->id())?->load(['track', 'specialization']);

        if (!$section) {
            return view('adviser.grades', [
                'section'        => null,
                'students'       => collect(),
                'subjects'       => collect(),
                'grades'         => collect(),
                'selectedPeriod' => 1,
                'openTerm'       => null,
                'editableSubjectIds' => [],
                'verifyUrl'      => route('adviser.verify-grades'),
            ]);
        }

        $students = Student::enrolledIn($section)
            ->orderBy('last_name')
            ->get();

        $selectedPeriod = (int) request('period', 1);

        // "Student identity and term-specific subject offerings" pass — the
        // columns are the selected TERM's offerings only.
        $subjects = Subject::forSection($section, $selectedPeriod)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $grades = Grade::where('section_id', $section->id)
            ->where('grading_period', $selectedPeriod)
            ->where('school_year', $section->school_year)
            ->get()
            ->keyBy(fn($g) => $g->student_id . '_' . $g->subject_id);

        // Which term is open right now, system-wide, for this school year?
        // A historical (non-active-year) section has no writable term at all —
        // its terms may still be flagged open, but AcademicTerm::acceptsWrites()
        // refuses every write, so the page must not offer one either.
        $openTerm = $section->isInActiveSchoolYear() ? AcademicTerm::currentOpenTerm($section->school_year) : null;

        // Typed grades need a teaching assignment (TeachingAccess); the page
        // still SHOWS every subject of the advisory section.
        $editableSubjectIds = (new \App\Services\TeachingAccess())->editableSubjectIds(auth()->user(), $section, $selectedPeriod);

        // Verification lives on its own page ("Encode / Verify split",
        // 2026-09-28). A subject_id carried here (an old #verify bookmark)
        // is passed on only if it is applicable to this section and term.
        $verifyUrl = route('adviser.verify-grades', array_filter([
            'period'     => $selectedPeriod,
            'subject_id' => $subjects->firstWhere('id', (int) request('subject_id'))?->id,
        ]));

        return view('adviser.grades', compact(
            'section', 'students', 'subjects', 'grades', 'selectedPeriod', 'openTerm', 'editableSubjectIds', 'verifyUrl'
        ));
    }

    /**
     * Verify Grades — the official-grade verification workspace (one
     * subject and term of an ADVISORY section at a time). Read-only page;
     * the verify endpoints below re-check everything. School Year and
     * Section offer only sections this adviser advises; an unknown value
     * falls back to the adviser's current section, never widens scope.
     * Anything but the adviser's current advisory section in a term that
     * accepts writes is shown read-only (historical viewing grants no write).
     */
    public function verification(Request $request)
    {
        $request->validate([
            'school_year'   => ['nullable', 'string', 'max:9'],
            'section_id'    => ['nullable', 'integer'],
            'period'        => ['nullable', 'integer'],
            'subject_id'    => ['nullable', 'integer'],
            'status_filter' => ['nullable', 'string', 'max:40'],
        ]);

        $userId = auth()->id();
        $current = Section::forAdviser($userId);
        $advised = Section::where('adviser_id', $userId)->with(['track', 'specialization'])
            ->orderByDesc('school_year')->orderBy('name')->get();
        $schoolYears = $advised->pluck('school_year')->unique()->values();

        $schoolYear = $schoolYears->contains($request->input('school_year')) ? $request->input('school_year') : $current?->school_year;
        $sections = $advised->where('school_year', $schoolYear)->values();
        $section = $sections->firstWhere('id', (int) $request->input('section_id'))
            ?? ($current ? $sections->firstWhere('id', $current->id) : null)
            ?? $sections->first();

        $terms = AcademicTerm::termNumbers();
        $selectedPeriod = in_array((int) $request->input('period'), $terms, true)
            ? (int) $request->input('period')
            : ($section ? (int) (AcademicTerm::currentOpenTerm($section->school_year) ?? $terms[0] ?? 1) : (int) ($terms[0] ?? 1));

        $subjects = $section ? Subject::forSection($section, $selectedPeriod)->orderBy('type')->orderBy('name')->get() : collect();
        $selectedSubject = $subjects->firstWhere('id', (int) $request->input('subject_id')) ?? $subjects->first();

        // Writable only for the section the verify endpoints act on (the
        // adviser's current advisory section) in a term that accepts writes.
        $isCurrentSection = $section && $current && $section->id === $current->id;
        $termAcceptsWrites = $section && AcademicTerm::acceptsWrites($section->school_year, $selectedPeriod);
        $isTermOpen = $isCurrentSection && $termAcceptsWrites;
        $lockReason = !$section ? null : (!$isCurrentSection
            ? 'Read-only — this is not your current advisory section, so its grades cannot be verified here.'
            : (!$termAcceptsWrites ? 'Read-only — ' . AcademicTerm::writeRefusalReason($section->school_year, $selectedPeriod) : null));

        $verification = $section && $selectedSubject
            ? (new \App\Services\GradeVerificationBoard())->build($section, $selectedSubject, $selectedPeriod, $request->input('status_filter'))
            : ['performance' => collect(), 'itemCount' => 0, 'statusFilter' => null, 'monitoringByStudentId' => collect(),
               'counts' => ['learners' => 0, 'verified' => 0, 'eligible' => 0, 'incomplete' => 0]];

        return view('adviser.verify-grades', array_merge(compact(
            'schoolYears', 'schoolYear', 'sections', 'section', 'terms', 'selectedPeriod', 'subjects', 'selectedSubject',
            'isTermOpen', 'lockReason'
        ), $verification));
    }

    /** Verify Grades URL for a class, keeping the filter the adviser was using. */
    private function verifyPageUrl(int $gradingPeriod, ?int $subjectId, ?string $statusFilter = null, ?int $studentId = null): string
    {
        $status = in_array($statusFilter, \App\Services\GradeVerificationBoard::STATUS_FILTERS, true) ? $statusFilter : null;

        return route('adviser.verify-grades', array_filter([
            'period' => $gradingPeriod, 'subject_id' => $subjectId, 'status_filter' => $status,
        ])) . ($studentId ? '#learner-' . $studentId : '');
    }

    public function store(Request $request)
    {
        $section = Section::forAdviser(auth()->id()) ?? abort(404);

        $request->validate([
            'grading_period'          => 'required|in:1,2,3',
            'grades'                  => 'required|array',
            'grades.*.student_id'     => 'required|exists:students,id',
            'grades.*.subject_id'     => 'required|exists:subjects,id',
            'grades.*.grade'          => 'nullable|numeric|min:60|max:100',
        ]);

        // Server-side enforcement — never trust the disabled inputs on the
        // front end alone. Someone could re-enable them via devtools.
        if (!AcademicTerm::acceptsWrites($section->school_year, (int) $request->grading_period)) {
            return redirect()->route('adviser.grades', ['period' => $request->grading_period])
                ->with('error', AcademicTerm::writeRefusalReason($section->school_year, (int) $request->grading_period));
        }

        $validStudentIds = Student::enrolledIn($section)
            ->pluck('id')
            ->toArray();

        // Same reasoning as $validStudentIds above: 'exists:subjects,id' alone
        // only proves the subject exists SOMEWHERE, not that it's actually
        // offered to this section (grade level / track / specialization).
        // Without this, an adviser could POST a subject_id belonging to a
        // different grade level or track and have it silently accepted.
        $validSubjectIds = Subject::forSection($section, (int) $request->grading_period)->pluck('id')->toArray();

        // "Subject Teacher and Class Record" pass — a typed grade is an
        // edit, so it needs a teaching assignment for that subject in this
        // section and term. Advising the section is not enough; a row for
        // any other subject is refused exactly like a foreign subject.
        $validSubjectIds = array_values(array_intersect(
            $validSubjectIds,
            (new \App\Services\TeachingAccess())->editableSubjectIds(auth()->user(), $section, (int) $request->grading_period)
        ));

        // Final pre-demo audit (2026-09-20) — a row for a learner outside
        // this section or a subject not applicable in this term is still
        // REFUSED (never written), but it used to be skipped silently while
        // the response said "saved successfully" and the log said "Encoded
        // grades" even when nothing was written. Count both outcomes and
        // say so.
        $saved = 0;
        $refused = 0;

        foreach ($request->grades as $gradeData) {
            if (!isset($gradeData['grade']) || $gradeData['grade'] === null || $gradeData['grade'] === '') {
                continue;
            }

            if (!in_array($gradeData['student_id'], $validStudentIds) || !in_array($gradeData['subject_id'], $validSubjectIds)) {
                $refused++;
                continue;
            }

            $saved++;
            Grade::logOfficialCorrection(Grade::where([
                'student_id' => $gradeData['student_id'], 'subject_id' => $gradeData['subject_id'], 'section_id' => $section->id,
                'grading_period' => $request->grading_period, 'school_year' => $section->school_year,
            ])->first(), $gradeData['grade'], 'Encode Grades');
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
            'Encoded ' . $saved . ' grade(s) for Term ' . $request->grading_period . ' — Section ' . $section->name
                . ($refused > 0 ? ' (' . $refused . ' row(s) refused: learner or subject outside this section for the term)' : ''),
            'grades',
            null
        );

        $redirect = redirect()->route('adviser.grades', ['period' => $request->grading_period]);

        if ($refused > 0) {
            return $redirect->with(
                $saved > 0 ? 'warning' : 'error',
                'Saved ' . $saved . ' grade(s) for Term ' . $request->grading_period . '. ' . $refused
                    . ' row(s) were not saved because the learner is not in your section, the subject is not applicable to it in this term, or you are not assigned to teach that subject.'
            );
        }

        return $redirect->with('success', 'Grades for Term ' . $request->grading_period . ' saved successfully!');
    }

    /**
     * Bulk import grades from an uploaded Excel/CSV file.
     * Blocked server-side if the term is not currently open — same
     * rule as the manual encoding form above.
     */
    public function importGrades(Request $request)
    {
        $section = Section::forAdviser(auth()->id()) ?? abort(404);
        $gradingPeriod = (int) $request->input('grading_period', 1);

        if (!AcademicTerm::acceptsWrites($section->school_year, $gradingPeriod)) {
            return redirect()->route('adviser.grades', ['period' => $gradingPeriod])
                ->with('error', AcademicTerm::writeRefusalReason($section->school_year, $gradingPeriod));
        }

        $request->validateWithBag('gradeImport', [
            'file' => $this->spreadsheetFileRule(),
        ]);

        // Only subjects this user is assigned to teach here and this term —
        // a column for any other subject is reported as not applicable.
        $subjects = Subject::forSection($section, $gradingPeriod)
            ->whereIn('id', (new \App\Services\TeachingAccess())->editableSubjectIds(auth()->user(), $section, $gradingPeriod))
            ->orderBy('type')->orderBy('name')->get();
        $students = Student::enrolledIn($section)->get();

        $import = new GradesImport($section->id, $gradingPeriod, $section->school_year, $subjects, $students);
        if ($unreadable = $this->importSpreadsheetOrRefuse($import, $request->file('file'))) {
            return redirect()->route('adviser.grades', ['period' => $gradingPeriod])->with('error', $unreadable);
        }

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
        $section = Section::forAdviser(auth()->id()) ?? abort(404);
        $gradingPeriod = (int) $request->input('period', 1);

        $subjects = Subject::forSection($section, $gradingPeriod)->orderBy('type')->orderBy('name')->get();
        $students = Student::enrolledIn($section)->orderBy('last_name')->get();

        // Names are free text: a leading = + - @ is neutralised exactly as
        // the Class Record export does, so Excel never runs it as a formula.
        // GradesImport accepts the escaped subject header on the way back.
        $safe = fn($text) => \App\Services\ClassRecordService::safeText((string) $text);
        $headers = array_merge(['lrn', 'last_name', 'first_name'], $subjects->pluck('name')->map($safe)->toArray());

        return response()->streamDownload(function () use ($headers, $students, $subjects, $safe) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($students as $student) {
                $row = array_merge(
                    [$student->lrn, $safe($student->last_name), $safe($student->first_name)],
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
     * verification table on Encode Grades (formerly adviser.assessments) could already
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
        $section = Section::forAdviser(auth()->id()) ?? abort(404);
        $gradingPeriod = (int) $request->input('grading_period', 1);

        $request->validate([
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
        ]);

        $subject = Subject::forSection($section, $gradingPeriod)->where('id', $request->subject_id)->first();
        if (!$subject) {
            return $this->verifyFailureResponse($request, (new \App\Services\SubjectOfferingService())
                ->notOfferedMessage(Subject::find($request->subject_id)?->name ?? 'That subject', $section, $gradingPeriod));
        }

        $student = Student::where('id', $request->student_id)->enrolledIn($section)->first();
        if (!$student) {
            return $this->verifyFailureResponse($request, 'That student is not in your section.');
        }

        if (!AcademicTerm::acceptsWrites($section->school_year, $gradingPeriod)) {
            return $this->verifyFailureResponse($request, AcademicTerm::writeRefusalReason($section->school_year, $gradingPeriod));
        }

        // One learner at a time, under a row lock, so a double-submitted or
        // repeated request cannot write a second verification: a grade that
        // is already verified with exactly the figures the evidence gives
        // now is reported unchanged, with no write and no second log entry.
        // A verified grade whose evidence CHANGED can still be re-verified
        // individually (unchanged behaviour).
        $outcome = DB::transaction(function () use ($student, $subject, $section, $gradingPeriod) {
            Student::whereKey($student->id)->lockForUpdate()->first();
            $existing = Grade::where('student_id', $student->id)->where('subject_id', $subject->id)
                ->where('section_id', $section->id)->where('grading_period', $gradingPeriod)
                ->where('school_year', $section->school_year)->first();
            if ($existing && $existing->is_verified) {
                $result = $this->gradingEngine->computeGrade($student, $subject, $section, $gradingPeriod, $section->school_year);
                if ($result['complete'] && $result['transmuted_grade'] !== null
                    && round((float) $existing->grade, 2) === round((float) $result['transmuted_grade'], 2)
                    && round((float) $existing->computed_grade, 2) === round((float) $result['computed_grade'], 2)
                    && (bool) $existing->is_provisional === (bool) $result['transmutation_provisional']) {
                    return ['success' => true, 'unchanged' => true, 'result' => $result, 'is_provisional' => (bool) $existing->is_provisional, 'grade' => $existing,
                        'message' => 'Already verified — the official grade for ' . $student->last_name . ', ' . $student->first_name . ' is ' . number_format((float) $existing->grade, 2) . '. Nothing changed.'];
                }
            }

            return $this->verifyOneGrade($student, $subject, $section, $gradingPeriod);
        }, 3); // a deadlock with a simultaneous verification is retried, never shown as an error

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
                'unchanged'      => $outcome['unchanged'] ?? false,
                'message'        => $outcome['message'],
            ]);
        }

        return redirect()->to($this->verifyPageUrl($gradingPeriod, $subject->id, $request->input('status_filter'), $student->id))
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
                'message' => !empty($result['blockers'])
                    ? 'Cannot verify ' . $subject->name . ' — ' . implode(' ', $result['blockers'])
                    : 'Cannot verify — assessment evidence for ' . $subject->name . ' is not complete yet (a component has no scores).',
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
        $students = Student::enrolledIn($section)->orderBy('last_name')->get();

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
        $section = Section::forAdviser(auth()->id()) ?? abort(404);
        $gradingPeriod = (int) $request->input('grading_period', 1);

        $request->validate(['subject_id' => 'required|exists:subjects,id']);

        // TASK 1f — scoped to THIS adviser's own section via
        // Subject::forSection($section): a subject_id for a subject not
        // offered to this section (including one belonging entirely to
        // another section's track/grade level) never resolves, so a
        // crafted request naming a foreign subject_id gets exactly the
        // same 403 a foreign section_id would.
        $subject = Subject::forSection($section, $gradingPeriod)->where('id', $request->subject_id)->first();
        abort_if(!$subject, 403);

        $classification = $this->classifyForVerifyAll($section, $subject, $gradingPeriod);

        return response()->json([
            'eligible_count'   => $classification['eligible']->count(),
            // The exact target set shown in the confirmation; the batch
            // refuses to run if the set it recomputes differs from it.
            'eligible_student_ids' => $classification['eligible']->pluck('id')->map(fn($id) => (int) $id)->sort()->values()->all(),
            'eligible_names'   => $this->namesOf($classification['eligible']),
            'subject_name'     => $subject->name,
            'section_name'     => $section->name,
            'school_year'      => $section->school_year,
            'grading_period'   => $gradingPeriod,
            'term_open'        => AcademicTerm::acceptsWrites($section->school_year, $gradingPeriod),
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
        $section = Section::forAdviser(auth()->id()) ?? abort(404);
        $gradingPeriod = (int) $request->input('grading_period', 1);

        $request->validate(['subject_id' => 'required|exists:subjects,id']);

        $subject = Subject::forSection($section, $gradingPeriod)->where('id', $request->subject_id)->first();
        abort_if(!$subject, 403);

        if (!AcademicTerm::acceptsWrites($section->school_year, $gradingPeriod)) {
            return response()->json(['message' => AcademicTerm::writeRefusalReason($section->school_year, $gradingPeriod)], 422);
        }

        $request->validate([
            'expected_student_ids'   => ['nullable', 'array'],
            'expected_student_ids.*' => ['integer'],
        ]);
        // The confirmed target set (from the preview). It is never used to
        // SELECT learners — only compared with the set recomputed under the
        // lock, so a changed set stops the batch instead of silently
        // growing or shrinking it. A caller that sends none keeps the old
        // behaviour (verify whatever is eligible now).
        $expected = $request->has('expected_student_ids')
            ? collect($request->input('expected_student_ids', []))->map(fn($id) => (int) $id)->unique()->sort()->values()->all()
            : null;

        $verifiedRows = [];
        // Read the roster BEFORE the transaction: under MySQL's REPEATABLE
        // READ the first plain read inside a transaction fixes its snapshot,
        // so reading anything before the lock let a second, simultaneous
        // batch wait for the lock and then still see "no grades yet" (final
        // audit, 2026-09-28: a duplicate-key error reached the adviser).
        $rosterIds = Student::enrolledIn($section)->pluck('id')->all();

        try {
            // 3 attempts: InnoDB can pick this batch as a deadlock victim against a
            // simultaneous individual verify (pre-demo audit 2026-10-06,
            // verify-race: 2 of 4 runs showed "database error"). The rollback
            // is total, so a retry re-locks and re-classifies from scratch.
            $refusal = DB::transaction(function () use ($subject, $section, $gradingPeriod, $expected, $rosterIds, &$verifiedRows) {
                $verifiedRows = [];
                // Serialise concurrent batches for this class: the lock is the
                // transaction's FIRST read, then classify fresh under it.
                Student::whereIn('id', $rosterIds)->lockForUpdate()->get();
                $eligible = $this->classifyForVerifyAll($section, $subject, $gradingPeriod)['eligible'];

                if ($eligible->isEmpty()) {
                    return ['message' => 'No students are currently eligible to verify.', 'status' => 422];
                }
                $now = $eligible->pluck('id')->map(fn($id) => (int) $id)->sort()->values()->all();
                if ($expected !== null && $now !== $expected) {
                    return ['message' => 'The eligible learners changed since the confirmation was shown (' . count($expected) . ' then, '
                        . count($now) . ' now). Nothing was verified — review the updated confirmation.', 'status' => 409, 'changed' => true];
                }

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

                return null;
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Another verification of the same learners committed first.
            // Everything this request wrote was rolled back; say so plainly.
            return response()->json([
                'message' => 'The eligible learners changed while verifying (another verification finished first). Nothing was verified by this request — review the updated confirmation.',
                'changed' => true,
            ], 409);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json(['message' => 'Verification stopped and nothing was saved because of a database error. Please try again.'], 422);
        } catch (\RuntimeException $e) {
            // 1e: a half-verified section is worse than an unverified one
            // — the transaction above already rolled back every write
            // this request made, so nothing partial survives.
            return response()->json([
                'message' => 'Verification stopped and nothing was saved — ' . $e->getMessage(),
            ], 422);
        }

        if ($refusal !== null) {
            return response()->json(['message' => $refusal['message'], 'changed' => $refusal['changed'] ?? false], $refusal['status']);
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

        return redirect()->to($this->verifyPageUrl(
            (int) $request->input('grading_period', 1),
            $request->filled('subject_id') ? (int) $request->input('subject_id') : null,
            $request->input('status_filter')
        ))->with('error', $message);
    }
}