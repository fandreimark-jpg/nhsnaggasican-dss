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
    public function __construct(private RiskFeatureExtractor $riskFeatures = new RiskFeatureExtractor())
    {
    }

    /**
     * Show the Submit Report page.
     * Displays term submission status and grade summary per student.
     * Uses a single pre-loaded grades collection to avoid N+1 queries.
     */
    public function show()
    {
        $section = Section::where('adviser_id', auth()->id())
            ->with(['track', 'specialization'])
            ->first();

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

        $subjects = $this->getSectionSubjects($section);
        $students = Student::where('section_id', $section->id)->orderBy('last_name')->get();

        // Total expected grades = students × subjects per term
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

        // Build term status for each of the 3 terms
        $termStatus = [];
        foreach ([1, 2, 3] as $term) {
            $encoded = $allGrades->where('grading_period', $term)->count();

            $termStatus[$term] = [
                'encoded'    => $encoded,
                'expected'   => $totalExpected,
                'complete'   => $totalExpected > 0 && $encoded >= $totalExpected,
                'submitted'  => isset($submissions[$term]),
                'submission' => $submissions[$term] ?? null,
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
        $section = Section::where('adviser_id', auth()->id())->firstOrFail();

        $request->validate([
            'grading_period' => 'required|in:1,2,3',
        ]);

        $gradingPeriod = (int) $request->grading_period;

        // Same server-side rule as grade encoding — a term that's been
        // closed by the admin (or was never opened) must not accept a
        // submission, even a resubmission, regardless of what the UI shows.
        if (!AcademicTerm::isOpen($section->school_year, $gradingPeriod)) {
            return back()->with('error',
                'Term ' . $gradingPeriod . ' is currently closed. Contact the admin to reopen it before submitting.'
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

        $subjects      = $this->getSectionSubjects($section);
        $students      = Student::where('section_id', $section->id)->get();
        $totalExpected = $students->count() * $subjects->count();

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
     * Note: exec() is used instead of Laravel Process facade
     * because Process facade causes WinError 10106 on Windows
     * when scikit-learn (joblib/asyncio) is involved.
     *
     * Returns true if risk results were generated and saved, false if the
     * classifier failed for any reason — the caller uses this to tell the
     * adviser their submission went through but risk levels didn't.
     */
   private function runAnalytics(array $gradesData, Section $section, int $gradingPeriod): bool
    {
        $tempFile   = storage_path('app/temp_grades_' . $section->id . '.json');
        $outputFile = storage_path('app/temp_results_' . $section->id . '.json');

        $pythonPayload = $this->buildPythonPayload($gradesData, $section, $gradingPeriod);

        file_put_contents($tempFile, json_encode($pythonPayload));

        $pythonPath = config('services.python_path');
        $scriptPath = base_path('analytics' . DIRECTORY_SEPARATOR . 'classify.py');

        $command    = "\"{$pythonPath}\" \"{$scriptPath}\" \"{$tempFile}\" \"{$outputFile}\"";
        $execOutput = [];
        $exitCode   = 0;

        exec($command, $execOutput, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputFile)) {
            \Log::error('Analytics failed (exit: ' . $exitCode . '). Output: ' . implode("\n", $execOutput));
            @unlink($tempFile);
            return false;
        }

        $raw = file_get_contents($outputFile);
        @unlink($tempFile);
        @unlink($outputFile);

        $results = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !$results) {
            \Log::error('JSON decode error: ' . json_last_error_msg());
            return false;
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

    /**
     * Get subjects for a section based on grade level, track, and specialization.
     * Core subjects appear for all sections of the same grade level.
     * Elective subjects are filtered by track and specialization.
     */
    private function getSectionSubjects(Section $section)
    {
        return Subject::where('grade_level', $section->grade_level)
            ->where(function ($query) use ($section) {
                $query->where('type', 'core')
                    ->orWhere(function ($q) use ($section) {
                        $q->where('type', 'elective')
                          ->where('track_id', $section->track_id)
                          ->where(function ($q2) use ($section) {
                              $q2->whereNull('specialization_id')
                                 ->orWhere('specialization_id', $section->specialization_id);
                          });
                    });
            })
            ->get();
    }
}