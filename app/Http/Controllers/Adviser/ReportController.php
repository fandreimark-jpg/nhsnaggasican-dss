<?php

namespace App\Http\Controllers\Adviser;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Section;
use App\Models\ReportSubmission;
use App\Models\AcademicTerm;
use App\Helpers\LogActivity;
use App\Services\SectionElectiveStatus;
use App\Services\TermReadinessService;
use App\Services\MonitoringClassificationService;
use App\Services\SubjectResultSummary;
use Illuminate\Http\Request;

/**
 * ReportController (Adviser)
 *
 * Handles report submission and grade summary display.
 *
 * Submitting a term report checks that the term is open, that the previous
 * term was submitted, that the section's subjects are configured and that
 * every expected grade is encoded, then records the submission. It runs no
 * classifier and writes no risk_results: ongoing academic monitoring is
 * rule-based (AcademicMonitoringService) and updates whenever assessment
 * evidence changes, not when a report is submitted.
 */
class ReportController extends Controller
{
    public function __construct(
        private TermReadinessService $termReadiness = new TermReadinessService(),
        private SectionElectiveStatus $electiveStatus = new SectionElectiveStatus(),
        private \App\Services\ReportSnapshotService $snapshots = new \App\Services\ReportSnapshotService(),
    ) {
    }

    /**
     * Show the Submit Report page.
     * Displays term submission status and grade summary per student.
     * Uses a single pre-loaded grades collection to avoid N+1 queries.
     */
    public function show(Request $request)
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
                'resultsPeriod' => 1,
                'officialResults' => collect(),
                'monitoring'    => collect(),
            ]);
        }

        // Year-wide union of every subject this section takes in any term
        // (Subject::forSection() with no term) — the per-term expected
        // figures below come from SectionElectiveStatus, per term.
        $subjects = Subject::forSection($section)->get();
        $students = Student::enrolledIn($section)->orderBy('last_name')->get();

        // Not read by adviser.submit-report.blade.php — the view derives
        // its own per-term $totalExpected from $termStatus[$period]['expected']
        // below (see the @php block near the top of that file). Kept here,
        // unused, only because compact() below already names it and
        // removing the key is a bigger change than this part's scope.
        $totalExpected = $students->count() * $subjects->count();

        // Explicit submissions only, with every submitted version (2026-10-07) — keyed by grading_period.
        $submissions = ReportSubmission::submitted()->where('section_id', $section->id)
            ->where('school_year', $section->school_year)
            ->when(ReportSubmission::versioningAvailable(), fn($q) => $q->with('versions.submitter'))
            ->get()
            ->keyBy('grading_period');

        // Single query — load ALL grades for this section and school year
        // Avoids separate queries per student per term
        $allGrades = Grade::with('subject:id,name')
            ->where('section_id', $section->id)
            ->where('school_year', $section->school_year)
            ->get();

        // Build grade summary per student using the pre-loaded collection
        $gradeSummary = $students->map(function ($student) use ($allGrades) {
            $studentGrades = $allGrades->where('student_id', $student->id);

            // Average per term — null if no grades yet for that term
            $term1 = $studentGrades->where('grading_period', 1)->avg('grade');
            $term2 = $studentGrades->where('grading_period', 2)->avg('grade');
            $term3 = $studentGrades->where('grading_period', 3)->avg('grade');

            return [
                'student'     => $student,
                'term1'       => $term1 ? round($term1, 2) : null,
                'term2'       => $term2 ? round($term2, 2) : null,
                'term3'       => $term3 ? round($term3, 2) : null,
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

        // Official Term results, subject by subject, for the report being
        // prepared — the same SubjectResultSummary and
        // MonitoringClassificationService the Principal's pages read. Display
        // only: submitting a report never computes or changes monitoring.
        $resultsPeriod = (int) $request->input('period', AcademicTerm::currentOpenTerm($section->school_year) ?? 1);
        if (!in_array($resultsPeriod, [1, 2, 3], true)) {
            $resultsPeriod = 1;
        }
        $gradesByStudent = $allGrades->where('grading_period', $resultsPeriod)->groupBy('student_id');
        $officialResults = $students->mapWithKeys(fn($student) => [
            $student->id => SubjectResultSummary::fromGrades($gradesByStudent->get($student->id, collect())),
        ]);
        $monitoring = app(MonitoringClassificationService::class)
            ->forStudents($students->pluck('id'), $section->school_year, $resultsPeriod);

        return view('adviser.submit-report', compact(
            'section', 'submissions', 'gradeSummary',
            'subjects', 'termStatus', 'totalExpected',
            'resultsPeriod', 'officialResults', 'monitoring'
        ));
    }

    /**
     * Submit a term report.
     * Checks the term and grade completeness, then records the submission
     * and logs the action.
     */
   public function submit(Request $request)
    {
        $section = Section::forAdviser(auth()->id()) ?? abort(404);

        $request->validate([
            'grading_period'   => 'required|in:1,2,3',
            // The number of versions the page showed: a second click, a retry
            // or a stale tab no longer matches and submits nothing.
            'expected_version' => 'required|integer|min:0',
        ]);

        $gradingPeriod = (int) $request->grading_period;

        // Submitted contents are kept as an immutable version (2026-10-07);
        // without that table a submission could not be preserved.
        if (!ReportSubmission::versioningAvailable()) {
            return back()->with('error', 'Report submission is unavailable until the administrator applies the pending database update (report versions). Nothing was submitted.');
        }

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
            $previousSubmitted = ReportSubmission::submitted()->where('section_id', $section->id)
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

        // One transaction: lock the section (serializes submissions and Admin
        // adviser changes), re-check the adviser AT WRITE TIME, compare the
        // version the page showed, freeze the contents, write the marker and
        // the new immutable version. Any failure (incl. building the snapshot)
        // rolls everything back: no partial, visible submission.
        $userId = (int) auth()->id();
        $expected = (int) $request->input('expected_version');
        $outcome = \Illuminate\Support\Facades\DB::transaction(function () use ($section, $gradingPeriod, $userId, $expected) {
            $locked = Section::whereKey($section->id)->lockForUpdate()->first();
            if (!$locked || (int) $locked->adviser_id !== $userId) {
                return 'not_adviser';
            }
            $row = ReportSubmission::where('section_id', $section->id)->where('grading_period', $gradingPeriod)
                ->where('school_year', $section->school_year)->lockForUpdate()->first();
            $current = $row ? (int) $row->versions()->max('version') : 0;
            if ($current !== $expected) {
                return $current;
            }
            $snapshot = $this->snapshots->build($locked, $gradingPeriod);
            $row ??= new ReportSubmission(['section_id' => $section->id, 'grading_period' => $gradingPeriod, 'school_year' => $section->school_year]);
            $row->fill(['submitted_by' => $userId, 'submitted_at' => now(), 'status' => 'submitted'])->save();

            return $row->versions()->create(['version' => $current + 1, 'submitted_by' => $userId, 'submitted_at' => $row->submitted_at, 'snapshot' => $snapshot]);
        });

        if ($outcome === 'not_adviser') {
            abort(403, 'You are no longer the adviser of this section.');
        }
        if (is_int($outcome)) {
            return redirect()->route('adviser.submit.report')->with('error', 'Term ' . $gradingPeriod . ' report already has '
                . ($outcome ? 'version ' . $outcome . ' submitted' : 'no submission') . ' since this page was opened, so nothing was submitted. Review the page and submit again if needed.');
        }

        // Logged only after the commit, once per new version.
        $label = $outcome->version > 1 ? 'Re-submitted' : 'Submitted';
        LogActivity::log(
            $outcome->version > 1 ? 'resubmit_report' : 'submit_report',
            $label . ' Term ' . $gradingPeriod . ' report — Section ' . $section->name . ' (version ' . $outcome->version . ')',
            'report_submissions',
            $outcome->report_submission_id
        );

        return redirect()->route('adviser.submit.report')
            ->with('success', 'Term ' . $gradingPeriod . ' report ' . strtolower($label) . ' (version ' . $outcome->version . '). The Principal and Admin now see exactly this version.');
    }
}
