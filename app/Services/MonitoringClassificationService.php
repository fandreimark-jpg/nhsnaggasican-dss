<?php

namespace App\Services;

use App\Models\AcademicConcern;
use App\Monitoring\MonitoringStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * THE one place a learner's current overall monitoring classification is
 * derived (see App\Monitoring\MonitoringStatus). Every page that shows it
 * — Adviser Dashboard and Assessments, Principal Dashboard, Students,
 * Student Detail and Academic Monitoring — calls this, so the same learner
 * in the same school year/term reads the same everywhere.
 *
 * Source of truth: OPEN academic_concerns only. Resolved concerns are
 * history and never make a learner For Review; legacy risk_results are
 * never read. Nothing is stored — the classification is recomputed from
 * the concerns, which the monitoring rules keep in step with the evidence.
 *
 * One query per call, however many learners: pages pass their whole roster.
 */
class MonitoringClassificationService
{
    public function __construct(private AcademicMonitoringService $monitoring = new AcademicMonitoringService())
    {
    }

    /**
     * @param iterable<int> $studentIds
     * @param int|null $term null = every term of the school year
     * @return Collection<int, MonitoringStatus> keyed by student id; every requested learner is present
     */
    public function forStudents(iterable $studentIds, string $schoolYear, ?int $term = null): Collection
    {
        $ids = collect($studentIds)->map(fn($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $open = $this->openConcerns($schoolYear, $term)
            ->whereIn('student_id', $ids)
            ->orderBy('id')
            ->get(['id', 'student_id', 'rule_key', 'evidence'])
            ->groupBy('student_id');

        return $ids->mapWithKeys(fn(int $id) => [$id => $this->statusFrom($id, $open->get($id, collect()))]);
    }

    public function forStudent(int $studentId, string $schoolYear, ?int $term = null): MonitoringStatus
    {
        return $this->forStudents([$studentId], $schoolYear, $term)->get($studentId);
    }

    /**
     * Totals for a scope of OPEN concerns. A learner with two open concerns
     * is ONE learner For Review and TWO open concerns; `learners_by_rule`
     * counts distinct learners per concern type.
     *
     * @return array{learners_for_review: int, open_concerns: int, learners_by_rule: array<string, int>}
     */
    public function summarize(Builder $openConcerns): array
    {
        $byRule = (clone $openConcerns)->selectRaw('rule_key, COUNT(DISTINCT student_id) as learners')
            ->groupBy('rule_key')->pluck('learners', 'rule_key')->map(fn($n) => (int) $n)->all();

        return [
            'learners_for_review' => (clone $openConcerns)->distinct()->count('student_id'),
            'open_concerns'       => (clone $openConcerns)->count(),
            'learners_by_rule'    => $byRule,
        ];
    }

    /** @return array{learners_for_review: int, open_concerns: int, learners_by_rule: array<string, int>} */
    public function summary(string $schoolYear, ?int $term = null): array
    {
        return $this->summarize($this->openConcerns($schoolYear, $term));
    }

    /**
     * The one definition of "current": open, of a rule monitoring still runs
     * (a retired rule's rows — the Attendance Concern — are history only),
     * in the school year (and term when given).
     */
    public function openConcerns(string $schoolYear, ?int $term = null): Builder
    {
        return AcademicConcern::open()
            ->activeRules()
            ->forSchoolYear($schoolYear)
            ->when($term !== null, fn($q) => $q->forTerm($term));
    }

    private function statusFrom(int $studentId, Collection $concerns): MonitoringStatus
    {
        $reasons = [];
        $details = [];
        foreach ($concerns as $concern) {
            $label = $this->monitoring->ruleLabel($concern->rule_key);
            $reasons[$label] = ($reasons[$label] ?? 0) + 1;
            $details[] = $label . ': ' . self::evidenceLine((array) $concern->evidence);
        }

        return new MonitoringStatus($studentId, $reasons, $concerns->pluck('id')->map(fn($id) => (int) $id)->values()->all(), $details);
    }

    /** "General Mathematics — official grade 72.00 (Failed)" · "Quiz 1 — General Mathematics" — from the stored evidence. */
    public static function evidenceLine(array $e): string
    {
        $subject = $e['subject']['name'] ?? 'subject';
        if (isset($e['official_grade'])) {
            return $subject . ' — official grade ' . number_format((float) $e['official_grade']['grade'], 2) . ' (' . ($e['official_grade']['result'] ?? 'Failed') . ')';
        }
        if (isset($e['assessment'])) {
            return ($e['assessment']['name'] ?? 'assessment') . ' — ' . $subject;
        }

        return $subject;
    }
}
