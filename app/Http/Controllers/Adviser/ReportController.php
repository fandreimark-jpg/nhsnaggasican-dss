<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Section;
use App\Models\RiskResult;
use App\Models\ReportSubmission;
use App\Models\AcademicTerm;
use App\Helpers\LogActivity;
use App\Services\RiskFeatureExtractor;
use App\Services\SectionElectiveStatus;
use App\Services\TermReadinessService;
use Illuminate\Http\Request;

/**
 * ReportController (Adviser)
 *
 * Handles report submission and grade summary display.
 * When a report is submitted — triggers the Python risk classifier
 * to generate Low/Moderate/High risk levels for each student.
 */
class ReportController extends Controller
{
    public function __construct(
        private RiskFeatureExtractor $riskFeatures = new RiskFeatureExtractor(),
        private TermReadinessService $termReadiness = new TermReadinessService(),
        private SectionElectiveStatus $electiveStatus = new SectionElectiveStatus()
    ) {
    }

    /**
     * Show the Submit Report page.
     * Displays term submission status and grade summary per student.
     * Uses a single pre-loaded grades collection to avoid N+1 queries.
     */
    public function show()
    {
        $section = Section::forAdviser(auth()->id())?->load(['track', 'specialization']);

        if (!$section) {
            return view('adviser.submit-report', [
                'section'       => null,
                'submissions'   => collect(),
                'gradeSummary'  => collect(),
                'subjects'      => collect(),
                'termStatus'    => [],
                'totalExpected' => 0,
            ]);
        }

        $subjects = Subject::forSection($section)->get();
        $students = Student::enrolledIn($section)->orderBy('last_name')->get();

        // Not read by adviser.submit-report.blade.php — the view derives
        // its own per-term $totalExpected from $termStatus[$period]['expected']
        // below (see the @php block near the top of that file). Kept here,
        // unused, only because compact() below already names it and
        // removing the key is a bigger change than this part's scope.
        $totalExpected = $students->count() * $subjects->count();

        // Load all submissions for this section — keyed by grading_period
        $submissions = ReportSubmission::where('section_id', $section->id)
            ->where('school_year', $section->school_year)
            ->get()
            ->keyBy('grading_period');

        // Single query — load ALL grades for this section and school year
        // Avoids separate queries per student per term
        $allGrades = Grade::where('section_id', $section->id)
            ->where('school_year', $section->school_year)
            ->get();

        // Build grade summary per student using the pre-loaded collection
        $gradeSummary = $students->map(function ($student) use ($allGrades) {
            $studentGrades = $allGrades->where('student_id', $student->id);

            // Average per term — null if no grades yet for that term
            $term1 = $studentGrades->where('grading_period', 1)->avg('grade');
            $term2 = $studentGrades->where('grading_period', 2)->avg('grade');
            $term3 = $studentGrades->where('grading_period', 3)->avg('grade');

            // Flag if student has any failing grade (below 75)
            $hasFailingGrade = $studentGrades->where('grade', '<', 75)->count() > 0;

            return [
                'student'     => $student,
                'term1'       => $term1 ? round($term1, 2) : null,
                'term2'       => $term2 ? round($term2, 2) : null,
                'term3'       => $term3 ? round($term3, 2) : null,
                'has_failing' => $hasFailingGrade,
            ];
        });

        // Build term status for each of the 3 terms. "ECR alignment" work
        // order, PART 6 — expected is now computed PER TERM via
        // SectionElectiveStatus (an elective doesn't necessarily run every
        // term), and a section whose SSHS electives aren't assigned yet
        // reports 0 expected / not complete rather than a core-only figure
        // that would read as achievable.
        $termStatus = [];
        $isConfigured = $this->electiveStatus->isFullyConfigured($section);
        foreach ([1, 2, 3] as $term) {
            $encoded  = $allGrades->where('grading_period', $term)->count();
            $expected = $isConfigured ? $this->electiveStatus->expectedGradeCount($section, $term) : 0;

            $termStatus[$term] = [
                'encoded'            => $encoded,
                'expected'           => $expected,
                'complete'           => $isConfigured && $expected > 0 && $encoded >= $expected,
                'configured'         => $isConfigured,
                'submitted'          => isset($submissions[$term]),
                'submission'         => $submissions[$term] ?? null,
                // Informational only — see TermReadinessService's doc
                // comment for why this never blocks submission.
                'assessment_evidence' => $this->termReadiness->assessmentEvidenceStatus($section, $term),
            ];
        }

        return view('adviser.submit-report', compact(
            'section', 'submissions', 'gradeSummary',
            'subjects', 'termStatus', 'totalExpected'
        ));
    }

    /**
     * Submit a term report.
     * Checks if all grades are complete, then runs Python risk classification.
     * Records the submission and logs the action.
     */
   public function submit(Request $request)
    {
        $section = Section::forAdviser(auth()->id()) ?? abort(404);

        $request->validate([
            'grading_period' => 'required|in:1,2,3',
        ]);

        $gradingPeriod = (int) $request->grading_period;

        // Same server-side rule as grade encoding — a term that's been
        // closed by the admin (or was never opened) must not accept a
        // submission, even a resubmission, regardless of what the UI shows.
        if (!AcademicTerm::acceptsWrites($section->school_year, $gradingPeriod)) {
            return back()->with('error',
                AcademicTerm::writeRefusalReason($section->school_year, $gradingPeriod)
            );
        }

        // AcademicTermController::open() only requires the PREVIOUS term to
        // be fully ENCODED before the next one can open — it's still
        // possible to reach Term 3 without ever having clicked "Submit"
        // for Term 1 or 2. Submission itself must stay sequential too, so
        // the admin/DSS can rely on submission history actually being
        // complete, not just term-opening history.
        if ($gradingPeriod > 1) {
            $previousSubmitted = ReportSubmission::where('section_id', $section->id)
                ->where('school_year', $section->school_year)
                ->where('grading_period', $gradingPeriod - 1)
                ->exists();

            if (!$previousSubmitted) {
                return back()->with('error',
                    'Term ' . ($gradingPeriod - 1) . ' must be submitted before Term ' . $gradingPeriod . ' can be submitted.'
                );
            }
        }

        // "ECR alignment" work order, PART 6 — a section whose SSHS
        // electives haven't been assigned yet must not be submittable at
        // all; there's no honest "expected" figure to check completeness
        // against yet.
        if (!$this->electiveStatus->isFullyConfigured($section)) {
            return back()->with('error',
                'This section\'s electives have not been assigned yet. Contact the admin before submitting Term ' . $gradingPeriod . '.'
            );
        }

        $students      = Student::enrolledIn($section)->get();
        $totalExpected = $this->electiveStatus->expectedGradeCount($section, $gradingPeriod);

        $encoded = Grade::where('section_id', $section->id)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $section->school_year)
            ->count();

        if ($encoded < $totalExpected) {
            return back()->with('error',
                'Please complete all grades before submitting. ' .
                ($totalExpected - $encoded) . ' grade(s) remaining.'
            );
        }

        // Load grades WITH subject relationship — needed to identify the
        // weakest subject per student (lowest grade), not just the average.
        $termGrades = Grade::where('section_id', $section->id)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $section->school_year)
            ->with('subject')
            ->get()
            ->groupBy('student_id');

        $gradesData = $students->map(function ($student) use ($termGrades) {
            $studentGrades = $termGrades->get($student->id, collect());
            $avg = $studentGrades->count() > 0
                ? round($studentGrades->avg('grade'), 2)
                : 0;

            // Find the subject with the LOWEST grade for this student —
            // this becomes the specific "focus area" for intervention.
            $weakest = $studentGrades->sortBy('grade')->first();

            // Count how many subjects this student failed (below 75) —
            // used later as a rule-based override so a high overall average
            // can't mask a real failing subject (e.g. grades 100,100,100,60
            // average to 90, which would otherwise read as "Low Risk").
            $failingGrades = $studentGrades->where('grade', '<', 75)->sortBy('grade');
            $failingCount  = $failingGrades->count();

            // Full list of ALL failing subjects (not just the weakest one) —
            // a student can fail 2+ subjects and the adviser needs to see
            // every one of them, not only the single lowest grade.
            $failingSubjects = $failingGrades->map(fn($g) => [
                'name'  => $g->subject?->name,
                'grade' => $g->grade,
            ])->values()->toArray();

            return [
                'student_id'            => $student->id,
                'average_grade'         => $avg,
                'weakest_subject'       => $weakest?->subject?->name,
                'weakest_subject_id'    => $weakest?->subject_id,
                'weakest_subject_grade' => $weakest?->grade,
                'failing_count'         => $failingCount,
                'failing_subjects'      => $failingSubjects,
            ];
        })->toArray();

        $analyticsSucceeded = $this->runAnalytics($gradesData, $section, $gradingPeriod);

        ReportSubmission::updateOrCreate(
            [
                'section_id'     => $section->id,
                'grading_period' => $gradingPeriod,
                'school_year'    => $section->school_year,
            ],
            [
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'status'       => 'submitted',
            ]
        );

        $action = $request->resubmit ? 'resubmit_report' : 'submit_report';
        $label  = $request->resubmit ? 'Re-submitted' : 'Submitted';

        LogActivity::log(
            $action,
            $label . ' Term ' . $gradingPeriod . ' report — Section ' . $section->name,
            'report_submissions',
            null
        );

        if (!$analyticsSucceeded) {
            return redirect()->route('adviser.submit.report')
                ->with('warning', 'Term ' . $gradingPeriod . ' report was submitted, but risk analysis failed to generate. Contact the admin — grades are saved and the submission is recorded, but risk levels for this term are missing until analytics is re-run.');
        }

        return redirect()->route('adviser.submit.report')
            ->with('success', 'Term ' . $gradingPeriod . ' report submitted successfully!');
    }

    /**
     * Builds the JSON payload sent to classify.py — average_grade plus
     * the expanded feature set from RiskFeatureExtractor (see its doc
     * comment for why the trained model doesn't use them yet). Public
     * so the exact payload shape is directly unit-testable without
     * going through a real exec() call.
     */
    public function buildPythonPayload(array $gradesData, Section $section, int $gradingPeriod): array
    {
        $studentsById = Student::whereIn('id', collect($gradesData)->pluck('student_id'))->get()->keyBy('id');

        return array_map(function ($s) use ($studentsById, $section, $gradingPeriod) {
            $student = $studentsById->get($s['student_id']);
            $features = $student
                ? $this->riskFeatures->extract($student, $section, $gradingPeriod, $section->school_year, (float) $s['average_grade'])
                : [];

            return array_merge([
                'student_id'            => $s['student_id'],
                'average_grade'         => $s['average_grade'],
                'failing_subject_count' => $s['failing_count'] ?? null,
            ], $features);
        }, $gradesData);
    }

    /**
     * Run the Python Random Forest classifier.
     *
     * Flow:
     * 1. Write grades data as JSON to a temp file
     * 2. Call Python script via exec()
     * 3. Read the output JSON file
     * 4. Save risk results to the database
     * 5. Clean up temp files
     *
     * Uses an argument-array process with a bounded execution timeout.
     *
     * Returns true if risk results were generated and saved, false if the
     * classifier failed for any reason — the caller uses this to tell the
     * adviser their submission went through but risk levels didn't.
     */
   private function runAnalytics(array $gradesData, Section $section, int $gradingPeriod): bool
    {
        $executionId = (string) \Illuminate\Support\Str::uuid();
        $tempFile = storage_path('app/temp_grades_' . $executionId . '.json');
        $outputFile = storage_path('app/temp_results_' . $executionId . '.json');

        try {
            $pythonPayload = $this->buildPythonPayload($gradesData, $section, $gradingPeriod);
            if (file_put_contents($tempFile, json_encode($pythonPayload, JSON_THROW_ON_ERROR)) === false) {
                return false;
            }
            $process = new \Symfony\Component\Process\Process([
                config('services.python_path'), base_path('analytics/classify.py'), $tempFile, $outputFile,
            ]);
            $process->setTimeout(120);
            $process->run();
            if (!$process->isSuccessful() || !is_file($outputFile)) {
                \Log::error('Analytics process failed.', ['exit_code' => $process->getExitCode()]);
                return false;
            }
            $results = json_decode(file_get_contents($outputFile), true, 512, JSON_THROW_ON_ERROR);
            $expectedIds = array_column($gradesData, 'student_id');
            if (!is_array($results) || count($results) !== count($expectedIds)) return false;
            $seen = [];
            foreach ($results as $result) {
                if (!is_array($result) || !isset($result['student_id'], $result['risk_level'], $result['average_grade'])
                    || !in_array($result['student_id'], $expectedIds)
                    || isset($seen[$result['student_id']])
                    || !in_array($result['risk_level'], ['low', 'moderate', 'high'], true)
                    || !is_numeric($result['average_grade'])) return false;
                $seen[$result['student_id']] = true;
            }
        } catch (\Throwable $e) {
            \Log::error('Analytics execution failed.', ['exception' => get_class($e)]);
            return false;
        } finally {
            if (is_file($tempFile)) @unlink($tempFile);
            if (is_file($outputFile)) @unlink($outputFile);
        }

        // Keyed lookup so we can merge back the weakest-subject data
        // (which never left PHP) with the ML classification results.
        $extraDataByStudent = collect($gradesData)->keyBy('student_id');

        foreach ($results as $studentResult) {
            $extra = $extraDataByStudent->get($studentResult['student_id']);

            $mlRiskLevel = $studentResult['risk_level'];
            $finalRiskLevel = $this->applyFailingSubjectOverride(
                $mlRiskLevel,
                $extra['failing_count'] ?? 0
            );

            RiskResult::updateOrCreate(
                [
                    'student_id'     => $studentResult['student_id'],
                    'grading_period' => $gradingPeriod,
                    'school_year'    => $section->school_year,
                ],
                [
                    // "Multi-school-year academic history" work order,
                    // PART 11 — the section this report was submitted for,
                    // stored on the result so a promoted learner's old
                    // results keep their old section.
                    'section_id'            => $section->id,
                    'average_grade'         => $studentResult['average_grade'],
                    'risk_level'            => $finalRiskLevel,
                    'ml_risk_level'         => $mlRiskLevel,
                    'was_overridden'        => $finalRiskLevel !== $mlRiskLevel,
                    'weakest_subject'       => $extra['weakest_subject'] ?? null,
                    'weakest_subject_id'    => $extra['weakest_subject_id'] ?? null,
                    'weakest_subject_grade' => $extra['weakest_subject_grade'] ?? null,
                    'failing_subjects'      => $extra['failing_subjects'] ?? [],
                    'confidence'            => $studentResult['confidence'] ?? null,
                    'generated_at'          => now(),
                ]
            );
        }

        return true;
    }

    /**
     * Rule-based safety net on top of the ML classification.
     *
     * Problem: the Random Forest model only looks at the OVERALL AVERAGE.
     * A student with grades 100, 100, 100, 60 averages to 90 — which the
     * model reads as "Low Risk" even though they clearly failed a subject.
     * Averaging masks the failure.
     *
     * Fix: after the ML model gives its baseline classification, enforce
     * a floor based on how many subjects the student actually failed
     * (grade below 75), regardless of what the average says.
     *
     * - 0 failing subjects → keep the ML result as-is.
     * - 1 failing subject  → floor of "moderate" (can't be "low").
     * - 2+ failing subjects → floor of "high".
     *
     * Public (not private) so it's directly unit-testable without needing
     * a full HTTP request or a database.
     */
    public function applyFailingSubjectOverride(string $mlRiskLevel, int $failingCount): string
    {
        $rank = ['low' => 0, 'moderate' => 1, 'high' => 2];

        $floor = match (true) {
            $failingCount >= 2 => 'high',
            $failingCount === 1 => 'moderate',
            default => 'low',
        };

        // Take whichever is more severe — the ML result or the rule floor.
        return $rank[$floor] > $rank[$mlRiskLevel] ? $floor : $mlRiskLevel;
    }

}
