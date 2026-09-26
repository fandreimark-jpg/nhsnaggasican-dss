<?php

namespace App\Services;

use App\Models\AcademicConcern;
use App\Models\Assessment;
use App\Models\Section;
use App\Monitoring\ConcernFinding;
use App\Monitoring\EvaluationScope;
use App\Monitoring\MonitoringRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE one writer of academic_concerns.
 *
 * Runs every ENABLED rule (config('dss.monitoring_rules')) over a scope and
 * reconciles what it observes with the stored concerns:
 *
 *   observed, no open concern     -> open a new concern (For Review)
 *   observed, open concern exists -> refresh its evidence (never a second row)
 *   open concern, not observed    -> resolve it: status, resolved_at and a
 *                                    reason from the rule; row and last
 *                                    evidence kept
 *
 * It is idempotent — evaluating the same evidence twice changes nothing but
 * last_evaluated_at — and it never creates, approves or changes an
 * intervention. A dry run computes the same plan and writes nothing.
 *
 * Two entry points:
 *   evaluate()               — a whole school year/term (optionally one
 *                              section): dss:evaluate-concerns, integrity.
 *   synchronizeAssessments() — Phase 2C automatic synchronization, only the
 *                              assessment items whose evidence just changed.
 */
class AcademicMonitoringService
{
    /** @var list<MonitoringRule> */
    private array $rules;

    /** @param list<MonitoringRule>|null $rules defaults to the enabled rules in config */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? array_map(
            fn(string $class) => app($class),
            config('dss.monitoring_rules', [])
        );
    }

    /** @return list<MonitoringRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function ruleLabel(string $ruleKey): string
    {
        foreach ($this->rules as $rule) {
            if ($rule->key() === $ruleKey) {
                return $rule->label();
            }
        }

        return $ruleKey;
    }

    /**
     * @return array{
     *     dry_run: bool, school_year: string, grading_period: int, section_ids: list<int>,
     *     created: list<array{rule: string, student_id: int, subject_id: ?int, context_key: string, summary: string}>,
     *     refreshed: list<array{rule: string, concern_id: int, summary: string}>,
     *     unchanged: int,
     *     resolved: list<array{rule: string, concern_id: int, student_id: int, context_key: string, reason: string}>,
     * }
     */
    public function evaluate(string $schoolYear, int $gradingPeriod, ?int $sectionId = null, bool $dryRun = false): array
    {
        $sectionIds = Section::where('school_year', $schoolYear)
            ->when($sectionId, fn($q) => $q->whereKey($sectionId))
            ->pluck('id')->map(fn($id) => (int) $id)->all();

        return $this->evaluateScope(new EvaluationScope($schoolYear, $gradingPeriod, $sectionIds), $dryRun);
    }

    /**
     * Phase 2C — THE automatic synchronization entry point. Called AFTER
     * the assessment evidence it follows has been committed (manual Add /
     * Edit item, an ECR import), with the items whose evidence changed.
     *
     * Evaluates only those items, grouped by their own section, term and
     * school year — never the whole school, and never a concern outside
     * those items (MonitoringRule::constrainOpenConcerns()). An id that no
     * longer exists is skipped: nothing in the app deletes an assessment
     * item, and the full evaluate() resolves a concern whose item is gone.
     *
     * Exceptions propagate; the caller decides how to surface them.
     *
     * @param iterable<int> $assessmentIds
     * @return list<array> one evaluate()-shaped report per (section, term, year) group
     */
    public function synchronizeAssessments(iterable $assessmentIds): array
    {
        $ids = collect($assessmentIds)->map(fn($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty() || $this->rules === []) {
            return [];
        }

        $groups = Assessment::whereIn('id', $ids)
            ->get(['id', 'section_id', 'grading_period', 'school_year'])
            ->groupBy(fn($a) => $a->section_id . '|' . $a->grading_period . '|' . $a->school_year);

        $reports = [];
        foreach ($groups as $items) {
            $first = $items->first();
            $reports[] = $this->evaluateScope(new EvaluationScope(
                (string) $first->school_year,
                (int) $first->grading_period,
                [(int) $first->section_id],
                $items->pluck('id')->map(fn($id) => (int) $id)->values()->all(),
            ));
        }

        return $reports;
    }

    private function evaluateScope(EvaluationScope $scope, bool $dryRun = false): array
    {
        $report = [
            'dry_run' => $dryRun, 'school_year' => $scope->schoolYear, 'grading_period' => $scope->gradingPeriod,
            'section_ids' => $scope->sectionIds, 'created' => [], 'refreshed' => [], 'unchanged' => 0, 'resolved' => [],
        ];

        if ($dryRun) {
            foreach ($this->rules as $rule) {
                $this->reconcile($rule, $scope, $report, write: false);
            }

            return $report;
        }

        DB::transaction(function () use ($scope, &$report) {
            foreach ($this->rules as $rule) {
                $this->reconcile($rule, $scope, $report, write: true);
            }
        });

        return $report;
    }

    private function reconcile(MonitoringRule $rule, EvaluationScope $scope, array &$report, bool $write): void
    {
        /** @var Collection<string, ConcernFinding> $findings */
        $findings = collect($rule->findings($scope))
            ->keyBy(fn(ConcernFinding $f) => AcademicConcern::openKeyFor($rule->key(), $f->studentId, $f->contextKey));

        $open = $this->openConcerns($rule, $scope, lock: $write);
        $now = now();

        foreach ($findings as $openKey => $finding) {
            $concern = $open->get($openKey);

            if (!$concern && $write) {
                $concern = $this->openConcern($rule, $finding, $openKey, $now);
                if ($concern->wasRecentlyCreated) {
                    $report['created'][] = $this->createdEntry($rule, $finding);
                    continue;
                }
                // Otherwise a concurrent evaluation opened this exact
                // concern first; it is reconciled below like any other.
            }

            if (!$concern) {
                $report['created'][] = $this->createdEntry($rule, $finding); // dry run
                continue;
            }

            $changed = $concern->evidence != $finding->evidence || $concern->rule_version !== $rule->version();
            if ($changed) {
                $report['refreshed'][] = ['rule' => $rule->key(), 'concern_id' => $concern->id, 'summary' => $finding->summary];
            } else {
                $report['unchanged']++;
            }

            if ($write) {
                $concern->forceFill([
                    'evidence'          => $finding->evidence,
                    'rule_version'      => $rule->version(),
                    'last_evaluated_at' => $now,
                ])->save();
            }
        }

        foreach ($open as $openKey => $concern) {
            if ($findings->has($openKey)) {
                continue;
            }

            $reason = $rule->resolutionReason($concern);
            $report['resolved'][] = [
                'rule' => $rule->key(), 'concern_id' => $concern->id, 'student_id' => $concern->student_id,
                'context_key' => $concern->context_key, 'reason' => $reason,
            ];

            if ($write) {
                // The last evidence stays on the row: it records what the
                // concern was about when it was resolved.
                $concern->forceFill([
                    'status'            => AcademicConcern::STATUS_RESOLVED,
                    'open_key'          => null,
                    'resolved_at'       => $now,
                    'resolution_reason' => $reason,
                    'last_evaluated_at' => $now,
                ])->save();
            }
        }
    }

    /**
     * Opens one concern. The unique open_key is the final guard against a
     * duplicate: if another evaluation committed the same open concern
     * between our read and this insert (two saves of one item at once),
     * the insert fails on that index and the row it lost to is returned
     * instead. The insert runs in a savepoint so the failure cannot poison
     * the surrounding transaction, and the re-read is a locking read so it
     * sees the other transaction's committed row even under REPEATABLE
     * READ. Only a unique violation is caught, and only when the open row
     * it collided with is actually found; anything else is re-thrown.
     */
    private function openConcern(MonitoringRule $rule, ConcernFinding $finding, string $openKey, Carbon $now): AcademicConcern
    {
        try {
            return DB::transaction(fn() => AcademicConcern::create([
                'student_id'        => $finding->studentId,
                'subject_id'        => $finding->subjectId,
                'section_id'        => $finding->sectionId,
                'school_year'       => $finding->schoolYear,
                'grading_period'    => $finding->gradingPeriod,
                'rule_key'          => $rule->key(),
                'rule_version'      => $rule->version(),
                'context_key'       => $finding->contextKey,
                'open_key'          => $openKey,
                'evidence'          => $finding->evidence,
                'status'            => AcademicConcern::STATUS_OPEN,
                'first_detected_at' => $now,
                'last_evaluated_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            return AcademicConcern::where('open_key', $openKey)->lockForUpdate()->first() ?? throw $e;
        }
    }

    private function createdEntry(MonitoringRule $rule, ConcernFinding $finding): array
    {
        return [
            'rule' => $rule->key(), 'student_id' => $finding->studentId, 'subject_id' => $finding->subjectId,
            'context_key' => $finding->contextKey, 'summary' => $finding->summary,
        ];
    }

    /** @return Collection<string, AcademicConcern> keyed by open_key */
    private function openConcerns(MonitoringRule $rule, EvaluationScope $scope, bool $lock): Collection
    {
        if ($scope->sectionIds === []) {
            return collect();
        }

        $query = AcademicConcern::open()
            ->forRule($rule->key())
            ->forSchoolYear($scope->schoolYear)
            ->forTerm($scope->gradingPeriod)
            ->whereIn('section_id', $scope->sectionIds);

        $rule->constrainOpenConcerns($query, $scope);

        return $query
            ->when($lock, fn($q) => $q->lockForUpdate())
            ->get()
            ->keyBy('open_key');
    }
}
