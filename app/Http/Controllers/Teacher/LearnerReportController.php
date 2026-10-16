<?php

namespace App\Http\Controllers\Teacher;

use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\Intervention;
use App\Models\Student;
use App\Models\Subject;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use App\Services\LearnerResultsService;
use App\Services\MonitoringClassificationService;
use App\Services\TeachingAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Individual learner report — printable, for a parent/guardian
 * consultation ("Subject Teacher and Class Record" pass).
 *
 * Scope is the learner's HISTORICAL section for the chosen school year
 * (student_enrollments via Student::sectionFor()), never their current
 * one, so a promoted learner's past report still shows that year's class.
 *
 *  - the section's ADVISER gets a consolidated report: every applicable
 *    subject of that term;
 *  - a teacher ASSIGNED to subjects in that section and term gets a report
 *    limited to those subjects;
 *  - anyone else is refused (403).
 *
 * Parent-facing: it carries no other learner's record and none of the
 * internal notes (the Principal's review note or intervention notes, the
 * adviser's delivery notes). Viewing it is logged.
 */
class LearnerReportController extends Controller
{
    public function show(Request $request, Student $student, TeachingAccess $access)
    {
        $request->validate([
            'school_year' => ['nullable', 'string', 'max:9'],
            'period'      => ['nullable', 'integer', Rule::in(AcademicTerm::termNumbers())],
        ]);

        $user = auth()->user();
        $schoolYear = $request->input('school_year') ?: \App\Models\Section::activeSchoolYear();
        $section = $student->sectionFor($schoolYear);
        abort_if(!$section, 404);

        $term = (int) ($request->input('period') ?: (AcademicTerm::currentOpenTerm($schoolYear) ?? 1));

        $consolidated = $access->isAdviserOf($user, $section);
        $subjects = $consolidated
            ? Subject::forSection($section, $term)->orderBy('type')->orderBy('name')->get()
            : $access->readableSubjects($user, $section, $term);
        abort_if(!$consolidated && $subjects->isEmpty(), 403);
        abort_unless(\App\Models\Student::enrolledIn($section)->whereKey($student->id)->exists(), 404);

        $subjectIds = $subjects->pluck('id')->all();
        $results = (new LearnerResultsService())->forSection($section, collect([$student]), $subjects, $term)[$student->id] ?? [];

        // The learner's own evidence on each item of the authorized subjects.
        $items = Assessment::where('section_id', $section->id)->where('grading_period', $term)
            ->where('school_year', $schoolYear)->whereIn('subject_id', $subjectIds)
            ->orderBy('subject_id')->orderBy('component')->orderBy('id')->get();
        $statuses = (new AssessmentStatusService())->statusesFor($items, [$student->id]);
        $scores = \App\Models\AssessmentScore::whereIn('assessment_id', $items->pluck('id'))->where('student_id', $student->id)
            ->pluck('score', 'assessment_id');

        $monitoringService = new AcademicMonitoringService();
        $concerns = AcademicConcern::activeRules()->forStudent($student->id)->forSchoolYear($schoolYear)->forTerm($term)
            ->where(fn($q) => $q->whereIn('subject_id', $subjectIds)->when($consolidated, fn($q) => $q->orWhereNull('subject_id')))
            ->orderBy('status')->orderByDesc('first_detected_at')->get()
            ->map(fn($c) => [
                'label'    => $monitoringService->ruleLabel($c->rule_key),
                'evidence' => MonitoringClassificationService::evidenceLine((array) $c->evidence),
                'open'     => $c->status === AcademicConcern::STATUS_OPEN,
                'since'    => $c->first_detected_at,
                'resolved' => $c->resolved_at,
                'reason'   => $c->resolution_reason,
            ]);

        // Interventions are an adviser/Principal matter (2026-10-07): a subject
        // teacher's report omits them entirely (null = section not shown).
        $interventions = $request->user()->role !== 'adviser' ? null : Intervention::with('subject')->where('student_id', $student->id)
            ->where('school_year', $schoolYear)->where('grading_period', $term)
            ->where(fn($q) => $q->whereIn('subject_id', $subjectIds)->when($consolidated, fn($q) => $q->orWhereNull('subject_id')))
            ->orderBy('id')->get();

        $monitoring = $consolidated
            ? (new MonitoringClassificationService())->forStudent($student->id, $schoolYear, $term)
            : null;

        LogActivity::log('view_learner_report', 'Viewed the learner report of ' . $student->last_name . ', ' . $student->first_name
            . ' — ' . $section->name . ' (' . $schoolYear . '), Term ' . $term . ($consolidated ? ' (consolidated)' : ' (assigned subjects only)'), 'students', $student->id);

        return view('teacher.learner-report', compact(
            'student', 'section', 'schoolYear', 'term', 'subjects', 'results', 'items', 'statuses', 'scores',
            'concerns', 'interventions', 'consolidated', 'monitoring'
        ) + ['terms' => AcademicTerm::termNumbers(), 'years' => $access->schoolYearsFor($user)]);
    }
}
