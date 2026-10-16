<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Monitoring\Rules\MissingRequirementRule;
use Illuminate\Support\Collection;

/**
 * Principal dashboard, "Performance by Grade and Section" (2026-10-07).
 * A presentation of figures the system already defines — no new formula:
 *  - learners      = student_enrollments of the section (the roster history);
 *  - expected      = learners × SectionElectiveStatus::expectedSubjectsForTerm()
 *                    (Subject::forSection: grade level, track/electives, Terms Taught),
 *                    never derived from existing grade rows or teaching assignments;
 *  - For Review / Missing = DISTINCT learners with an open concern from
 *                    MonitoringClassificationService::openConcerns() (Missing = that
 *                    rule only) — the two groups may overlap;
 *  - passed/failed = SUBJECT GRADES (not learners): official (verified, not
 *                    provisional) grades for an enrolled learner and an expected
 *                    subject, EXCLUDING ones whose evidence changed since
 *                    verification (SubjectResultSummary::staleOfficial(), counted as
 *                    re-verify instead); Failing = InTermStatusService::isFailing().
 * Read only. A constant number of queries per section (subject resolver,
 * configuration check), plus one each for enrollments, grades and concerns.
 */
class SectionPerformanceService
{
    public function __construct(
        private SectionElectiveStatus $electives = new SectionElectiveStatus(),
        private MonitoringClassificationService $classification = new MonitoringClassificationService(),
    ) {
    }

    /**
     * @return array{rows: list<array>, totals: array}
     */
    public function build(string $schoolYear, int $term, ?int $gradeLevel = null, ?int $subjectId = null): array
    {
        $sections = Section::where('school_year', $schoolYear)
            ->when($gradeLevel, fn($q) => $q->where('grade_level', $gradeLevel))
            ->orderBy('grade_level')->orderBy('name')->get();
        if ($sections->isEmpty()) {
            return ['rows' => [], 'totals' => $this->totals([])];
        }

        $roster = StudentEnrollment::whereIn('section_id', $sections->pluck('id'))->where('school_year', $schoolYear)
            ->get(['student_id', 'section_id'])->groupBy('section_id')
            ->map(fn(Collection $rows) => $rows->pluck('student_id')->map(fn($id) => (int) $id)->unique()->values());
        $allIds = $roster->flatten()->unique()->values();

        $open = $this->classification->openConcerns($schoolYear, $term)
            ->whereIn('student_id', $allIds)
            ->when($subjectId, fn($q) => $q->where('subject_id', $subjectId))
            ->get(['student_id', 'rule_key']);
        $forReview = $open->pluck('student_id')->map(fn($id) => (int) $id)->unique()->flip();
        $missing = $open->where('rule_key', MissingRequirementRule::KEY)->pluck('student_id')->map(fn($id) => (int) $id)->unique()->flip();

        $official = Grade::where('school_year', $schoolYear)->where('grading_period', $term)
            ->whereIn('student_id', $allIds)
            ->when($subjectId, fn($q) => $q->where('subject_id', $subjectId))
            ->where('is_verified', true)->where('is_provisional', false)->whereNotNull('grade')
            ->get();

        $rows = [];
        foreach ($sections as $section) {
            $ids = $roster->get($section->id, collect());
            $subjects = $this->electives->expectedSubjectsForTerm($section, $term)->pluck('id')->map(fn($id) => (int) $id);
            $subjectApplies = $subjectId === null || $subjects->contains($subjectId);
            if ($subjectId !== null) {
                $subjects = $subjects->filter(fn($id) => $id === $subjectId)->values();
            }
            $idSet = $ids->flip();
            $subjectSet = $subjects->flip();
            // Only grades of THIS roster and an expected subject count — a
            // stray row (subject no longer applicable, learner moved) never
            // inflates the numerator past the denominator.
            $grades = $official->filter(fn(Grade $g) => isset($idSet[(int) $g->student_id]) && isset($subjectSet[(int) $g->subject_id]))->values();
            $stale = SubjectResultSummary::staleOfficial($grades)->pluck('id')->flip();
            $current = $grades->reject(fn(Grade $g) => isset($stale[$g->id]));
            $failed = $current->filter(fn(Grade $g) => InTermStatusService::isFailing($g))->count();

            $rows[] = [
                'section'        => $section,
                'learners'       => $ids->count(),
                'for_review'     => $ids->filter(fn($id) => isset($forReview[$id]))->count(),
                'missing'        => $ids->filter(fn($id) => isset($missing[$id]))->count(),
                'passed'         => $current->count() - $failed,
                'failed'         => $failed,
                'verified'       => $current->count(),
                'reverify'       => $stale->count(),
                'expected'       => $ids->count() * $subjects->count(),
                'subject_applies' => $subjectApplies,
                'configured'     => $this->electives->isFullyConfigured($section),
            ];
        }

        return ['rows' => $rows, 'totals' => $this->totals($rows)];
    }

    /** Sums of the underlying counts (never an average of section percentages). */
    private function totals(array $rows): array
    {
        $sum = fn(string $k) => array_sum(array_column($rows, $k));

        return [
            'learners' => $sum('learners'), 'for_review' => $sum('for_review'), 'missing' => $sum('missing'),
            'passed' => $sum('passed'), 'failed' => $sum('failed'), 'verified' => $sum('verified'),
            'reverify' => $sum('reverify'), 'expected' => $sum('expected'),
            'unconfigured' => count(array_filter($rows, fn($r) => !$r['configured'])),
        ];
    }

    /**
     * "No verified grades yet" / "Partial data" / "Complete", or why there
     * is nothing to expect. Never turns an absent grade into a zero.
     */
    public static function coverageState(array $row): string
    {
        return match (true) {
            !($row['subject_applies'] ?? true) => 'not_offered',
            $row['expected'] === 0 => 'nothing_expected',
            $row['verified'] === 0 => 'none',
            $row['verified'] < $row['expected'] => 'partial',
            default => 'complete',
        };
    }
}
