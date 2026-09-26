<?php

namespace App\Services;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only queries behind the Principal "Academic Monitoring" page
 * (Phase 2D). Source of truth: academic_concerns — never risk_results.
 *
 * Nothing here writes. Grouping by learner + subject is presentation only;
 * every concern row stays exactly as AcademicMonitoringService stored it.
 */
class AcademicMonitoringOverviewService
{
    public const STATUS_FILTERS = ['open', 'resolved', 'all'];

    public function __construct(
        private AcademicMonitoringService $monitoring = new AcademicMonitoringService(),
        private AssessmentStatusService $statuses = new AssessmentStatusService(),
    ) {
    }

    /**
     * Validated filters. Anything unrecognised — an unknown year, a section
     * of another year, a subject with no concern in the year — falls back
     * to "no filter", never to data from outside the selected year.
     *
     * @return array{school_year: string, term: ?int, grade_level: ?int, section_id: ?int, subject_id: ?int, status: string}
     */
    public function filtersFrom(Request $request): array
    {
        $schoolYear = AcademicYear::resolveSelected($request->input('school_year'));

        // Default term: the open term of the active year; a historical year
        // (or an explicit "All terms", term=) shows every term.
        if ($request->has('term')) {
            $term = in_array($request->integer('term'), AcademicTerm::termNumbers(), true) ? $request->integer('term') : null;
        } else {
            $term = $schoolYear === Section::activeSchoolYear() ? AcademicTerm::currentOpenTerm($schoolYear) : null;
        }

        $gradeLevel = $request->integer('grade_level') ?: null;
        if ($gradeLevel && !Section::where('school_year', $schoolYear)->where('grade_level', $gradeLevel)->exists()) {
            $gradeLevel = null;
        }

        $sectionId = $request->integer('section_id') ?: null;
        if ($sectionId && !Section::whereKey($sectionId)->where('school_year', $schoolYear)
                ->when($gradeLevel, fn($q) => $q->where('grade_level', $gradeLevel))->exists()) {
            $sectionId = null;
        }

        $subjectId = $request->integer('subject_id') ?: null;
        if ($subjectId && !$this->subjectOptions($schoolYear)->contains('id', $subjectId)) {
            $subjectId = null;
        }

        $status = in_array($request->input('status'), self::STATUS_FILTERS, true) ? $request->input('status') : 'open';

        return [
            'school_year' => $schoolYear,
            'term'        => $term,
            'grade_level' => $gradeLevel,
            'section_id'  => $sectionId,
            'subject_id'  => $subjectId,
            'status'      => $status,
        ];
    }

    /**
     * Concerns in scope, grouped by learner + subject + section for display.
     *
     * @return Collection<int, array{student: \App\Models\Student, subject: ?Subject, section: Section, concerns: Collection, open_count: int, resolved_count: int}>
     */
    public function groups(array $filters): Collection
    {
        $concerns = $this->scoped($filters)
            ->when($filters['status'] !== 'all', fn($q) => $q->where('status', $filters['status']))
            ->with(['student', 'subject', 'section.track', 'section.specialization'])
            // Open concerns first, then newest first — the Principal reads
            // what needs review before history.
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('first_detected_at')
            ->orderByDesc('id')
            ->get();

        $current = $this->currentItemStatuses($concerns);
        $newerOpen = $this->newerOpenKeys($concerns);

        foreach ($concerns as $concern) {
            $assessmentId = (int) ($concern->evidence['assessment']['id'] ?? 0);
            $concern->setAttribute('concern_label', $this->monitoring->ruleLabel($concern->rule_key));
            $concern->setAttribute('current_item_status', $current[$assessmentId][$concern->student_id] ?? null);
            $concern->setAttribute('has_newer_open_concern',
                !$concern->isOpen() && isset($newerOpen[$concern->student_id . '|' . $concern->context_key]));
        }

        return $concerns
            ->groupBy(fn(AcademicConcern $c) => $c->student_id . '|' . ($c->subject_id ?? 0) . '|' . $c->section_id)
            ->map(fn(Collection $rows) => [
                'student'        => $rows->first()->student,
                'subject'        => $rows->first()->subject,
                'section'        => $rows->first()->section,
                'concerns'       => $rows->values(),
                'open_count'     => $rows->where('status', AcademicConcern::STATUS_OPEN)->count(),
                'resolved_count' => $rows->where('status', AcademicConcern::STATUS_RESOLVED)->count(),
            ])
            ->values();
    }

    /** Counts for the scope, ignoring the status filter — so the cards always show both sides. */
    public function counts(array $filters): array
    {
        $scoped = $this->scoped($filters);

        return [
            'open'              => (clone $scoped)->where('status', AcademicConcern::STATUS_OPEN)->count(),
            'resolved'          => (clone $scoped)->where('status', AcademicConcern::STATUS_RESOLVED)->count(),
            'learners_for_review' => (clone $scoped)->where('status', AcademicConcern::STATUS_OPEN)->distinct()->count('student_id'),
        ];
    }

    /** Open concerns for a whole school year — the Principal dashboard card. */
    public function openCountForYear(string $schoolYear): int
    {
        return AcademicConcern::open()->forSchoolYear($schoolYear)->count();
    }

    /** @return Collection<int, Section> sections of the year (optionally one grade), with track/specialization */
    public function sectionOptions(string $schoolYear, ?int $gradeLevel): Collection
    {
        return Section::where('school_year', $schoolYear)
            ->when($gradeLevel, fn($q) => $q->where('grade_level', $gradeLevel))
            ->with(['track', 'specialization'])
            ->orderBy('grade_level')->orderBy('name')
            ->get();
    }

    /** @return Collection<int, int> */
    public function gradeLevelOptions(string $schoolYear): Collection
    {
        return Section::where('school_year', $schoolYear)->distinct()->orderBy('grade_level')->pluck('grade_level');
    }

    /** Subjects that have any concern in the year — the only meaningful subject filter values. */
    public function subjectOptions(string $schoolYear): Collection
    {
        return Subject::whereIn('id', AcademicConcern::forSchoolYear($schoolYear)->whereNotNull('subject_id')->select('subject_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'grade_level']);
    }

    private function scoped(array $filters)
    {
        return AcademicConcern::query()
            ->forSchoolYear($filters['school_year'])
            ->when($filters['term'], fn($q) => $q->forTerm($filters['term']))
            ->when($filters['section_id'], fn($q) => $q->forSection($filters['section_id']))
            ->when($filters['grade_level'], fn($q) => $q->whereIn('section_id',
                Section::select('id')->where('school_year', $filters['school_year'])->where('grade_level', $filters['grade_level'])))
            ->when($filters['subject_id'], fn($q) => $q->where('subject_id', $filters['subject_id']));
    }

    /**
     * The learner's CURRENT status on each item a concern refers to — so a
     * resolved concern shows what the evidence is now (a recorded score of
     * 0 is Completed, never Missing). Two queries for the whole page.
     *
     * @return array<int, array<int, string>>
     */
    private function currentItemStatuses(Collection $concerns): array
    {
        $assessmentIds = $concerns->map(fn($c) => (int) ($c->evidence['assessment']['id'] ?? 0))->filter()->unique()->values();
        if ($assessmentIds->isEmpty()) {
            return [];
        }

        $items = Assessment::whereIn('id', $assessmentIds)->get(['id']);

        return $this->statuses->statusesFor($items, $concerns->pluck('student_id')->unique()->values()->all());
    }

    /** @return array<string, true> "student|context" pairs that currently have an OPEN concern */
    private function newerOpenKeys(Collection $concerns): array
    {
        $resolved = $concerns->where('status', AcademicConcern::STATUS_RESOLVED);
        if ($resolved->isEmpty()) {
            return [];
        }

        return AcademicConcern::open()
            ->whereIn('student_id', $resolved->pluck('student_id')->unique())
            ->whereIn('context_key', $resolved->pluck('context_key')->unique())
            ->get(['student_id', 'context_key'])
            ->mapWithKeys(fn($c) => [$c->student_id . '|' . $c->context_key => true])
            ->all();
    }
}
