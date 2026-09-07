<?php

namespace App\Services;

use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Carbon;

/**
 * Before/after comparison for a recorded Intervention — CLAUDE.md's
 * example: "Before: PT = 60%. After: PT = 78%. Change: +18 percentage
 * points." Deliberately reports only the numeric change, in neutral
 * language, per "Do not claim an intervention caused improvement unless
 * the available evidence supports that conclusion" — a change in a
 * component's percentage between two terms is NOT evidence of causation
 * (many things could explain it), so this service and anything that
 * displays its output must never say the intervention "caused",
 * "resulted in", or "fixed" anything. Just the numbers.
 *
 * "Before" is the weakest component's percentage at the grading period
 * that triggered the intervention (via its linked RiskResult); "after"
 * is the same component in the very next grading period, if that term's
 * assessment evidence exists yet. Comparing the SAME component across
 * terms is deliberate — comparing to a DIFFERENT component wouldn't be
 * a comparison of anything real.
 */
class ProgressMonitoringService
{
    public function __construct(private PerformanceAnalysisService $performanceAnalysis = new PerformanceAnalysisService())
    {
    }

    /** The last grading period in a school year — see the 'no_later_term' status below. */
    private const LAST_TERM = 3;

    /**
     * "Progress column honesty" work order — `status` tells the caller
     * WHY a comparison is or isn't available, instead of collapsing every
     * reason into a single null. Four statuses:
     *
     *   'not_applicable'   — no risk_result_id (recorded from in-term
     *                         evidence, e.g. every Principal-recorded
     *                         intervention today — see Intervention::
     *                         ORIGINS/isSystemGenerated()). There never
     *                         was a term-report baseline to compare from.
     *   'no_later_term'    — a baseline exists, but it was already the
     *                         last term of the school year (before_term
     *                         + 1 doesn't exist). Never fills, by
     *                         construction, for a last-term intervention.
     *   'awaiting_evidence' — a later term exists but has no assessment
     *                         evidence for this component yet. May fill
     *                         once that evidence is entered.
     *   'available'         — the comparison exists: before/after/change
     *                         are all populated.
     *
     * 'unavailable' is a fifth, unnamed-by-design catch-all for the rare
     * edge cases the four statuses above don't cover (no subject/section
     * on record, or a baseline term with literally no assessment evidence
     * for any component) — these aren't part of the normal Principal-
     * recorded-vs-report-baseline distinction the other four describe.
     *
     * @return array{
     *     status: 'not_applicable'|'no_later_term'|'awaiting_evidence'|'available'|'unavailable',
     *     component: string|null,
     *     before_period: int|null,
     *     before_percentage: float|null,
     *     after_period: int|null,
     *     after_percentage: float|null,
     *     change: float|null,
     * }
     */
    public function compare(Intervention $intervention): array
    {
        if (!$intervention->risk_result_id) {
            return $this->statusOnly('not_applicable');
        }

        if (!$intervention->subject_id) {
            return $this->statusOnly('unavailable');
        }

        $riskResult = $intervention->riskResult;
        if (!$riskResult) {
            return $this->statusOnly('unavailable');
        }

        $student    = $intervention->student;
        $subject    = $intervention->subject;
        $section    = $student->section;
        $beforeTerm = $riskResult->grading_period;
        $schoolYear = $riskResult->school_year;

        if (!$section) {
            return $this->statusOnly('unavailable');
        }

        $before = $this->performanceAnalysis->analyzeStudent($student, $subject, $section, $beforeTerm, $schoolYear);
        $componentKey = $before['weakest_component'];

        if (!$componentKey) {
            return $this->statusOnly('unavailable');
        }

        $beforePercentage = $before['components'][$componentKey]['percentage'];
        $afterTerm = $beforeTerm + 1;

        if ($afterTerm > self::LAST_TERM) {
            return $this->result('no_later_term', $componentKey, $beforeTerm, $beforePercentage, null, null);
        }

        $after = $this->performanceAnalysis->analyzeStudent($student, $subject, $section, $afterTerm, $schoolYear);
        $afterPercentage = $after['components'][$componentKey]['percentage'] ?? null;

        if ($afterPercentage === null) {
            return $this->result('awaiting_evidence', $componentKey, $beforeTerm, $beforePercentage, $afterTerm, null);
        }

        return $this->result('available', $componentKey, $beforeTerm, $beforePercentage, $afterTerm, $afterPercentage);
    }

    private function statusOnly(string $status): array
    {
        return [
            'status' => $status, 'component' => null,
            'before_period' => null, 'before_percentage' => null,
            'after_period' => null, 'after_percentage' => null, 'change' => null,
        ];
    }

    private function result(string $status, string $component, int $beforeTerm, float $beforePct, ?int $afterTerm, ?float $afterPct): array
    {
        return [
            'status'            => $status,
            'component'         => $component,
            'before_period'     => $beforeTerm,
            'before_percentage' => $beforePct,
            'after_period'      => $afterTerm,
            'after_percentage'  => $afterPct,
            'change'            => $afterPct === null ? null : round($afterPct - $beforePct, 2),
        ];
    }

    /**
     * TASK 3 of "close the delivery loop" — this is the point of the
     * whole feature: compare()'s term-over-term view cannot show a
     * student recovering INSIDE the term they're still in, which is
     * exactly the window help can still change their grade in. Requires
     * an intervention that has actually been marked delivered
     * (delivered_at) — before that, there is no "before" moment to
     * measure from.
     *
     * "before" is the FOCUS component's percentage using only
     * AssessmentScore rows recorded (created_at) on or before
     * delivered_at; "after" is the SAME component using every scored
     * item on file now — deliberately the cumulative total, not just
     * what's new, so it reads as "where the student stands today,"
     * matching GradingEngine::componentPercentage()'s own cumulative-
     * average math (re-derived here, not reused, only because it needs
     * an extra created_at cutoff GradingEngine has no reason to support).
     *
     * TASK 2 of "status clarity and progress consistency" — the compared
     * component is $intervention->focus_component, the one the recorded
     * reason actually named at creation (see Intervention::
     * extractNamedComponent() and Principal\InterventionController::
     * store()/storeBulk()) — NEVER "whichever is weakest right now."
     * Recomputing "weakest now" was the bug this task fixes: an
     * intervention recorded with "Focus Area: Examination" would silently
     * report on Written Work the moment Written Work evidence made it the
     * new weakest, even though the Principal's decision was never about
     * Written Work. When focus_component is null (an old row whose
     * reason named no component, and couldn't be backfilled), this
     * returns a distinct 'not_recorded' result rather than falling back
     * to a guessed component — see the ground rule "show 'focus component
     * not recorded' rather than silently substituting a different one."
     *
     * Also reports which OTHER components (if any) received new
     * AssessmentScore evidence since delivery — never blocking, but the
     * adviser scoring a different component than the one the intervention
     * was about is exactly the case where the intervention would look
     * ineffective simply because it was never measured; see
     * 'other_components_with_new_evidence' below.
     *
     * Per CLAUDE.md and this class's own docblock: never claim the
     * intervention CAUSED the change — report the two numbers and let the
     * reader judge. "Not enough evidence yet" replaces the delta (not the
     * raw percentages) when fewer than 2 NEW items have been scored since
     * delivery — a single new score swinging an average is not something
     * to draw a conclusion from.
     *
     * TASK 3 of "bulk dialog and intervention closure" — also reports the
     * raw earned/possible POINTS behind each percentage, not just the
     * percentage itself. A remedial item is added to the term's existing
     * denominator, not swapped in for the score it targets (34/60 -> a
     * 34/40 remedial item makes it 68/100, not 85%) — the percentage
     * alone reads as the system failing to register the extra work
     * unless the arithmetic is shown alongside it.
     *
     * @return array{
     *     component: string|null, not_recorded: bool,
     *     before_percentage: float|null, before_item_count: int, before_earned: float, before_max: float,
     *     after_percentage: float|null, after_item_count: int, after_earned: float, after_max: float,
     *     new_item_count: int, has_enough_evidence: bool, change: float|null,
     *     other_components_with_new_evidence: array<int, string>,
     * }|null null when there's nothing to compare at all (not delivered
     * yet, or no subject/term/section on record).
     */
    public function compareWithinTerm(Intervention $intervention): ?array
    {
        if (!$intervention->delivered_at || !$intervention->subject_id || !$intervention->grading_period) {
            return null;
        }

        $student = $intervention->student;
        $subject = $intervention->subject;
        $section = $student?->section;

        if (!$student || !$subject || !$section) {
            return null;
        }

        $gradingPeriod = $intervention->grading_period;
        $schoolYear = $section->school_year;
        $deliveredAt = $intervention->delivered_at;
        $componentKey = $intervention->focus_component;

        if (!$componentKey) {
            return [
                'component' => null,
                'not_recorded' => true,
                'before_percentage' => null, 'before_item_count' => 0, 'before_earned' => 0.0, 'before_max' => 0.0,
                'after_percentage' => null, 'after_item_count' => 0, 'after_earned' => 0.0, 'after_max' => 0.0,
                'new_item_count' => 0, 'has_enough_evidence' => false, 'change' => null,
                'other_components_with_new_evidence' => [],
            ];
        }

        $before = $this->componentPercentageAsOf($student, $subject, $section, $gradingPeriod, $schoolYear, $componentKey, $deliveredAt);
        $after  = $this->componentPercentageAsOf($student, $subject, $section, $gradingPeriod, $schoolYear, $componentKey, null);

        $newItemCount = $after['item_count'] - $before['item_count'];
        $hasEnoughEvidence = $newItemCount >= 2;

        $otherComponentsWithNewEvidence = [];
        foreach (['written_work', 'performance_task', 'examination'] as $key) {
            if ($key === $componentKey) {
                continue;
            }
            $beforeOther = $this->componentPercentageAsOf($student, $subject, $section, $gradingPeriod, $schoolYear, $key, $deliveredAt);
            $afterOther  = $this->componentPercentageAsOf($student, $subject, $section, $gradingPeriod, $schoolYear, $key, null);
            if ($afterOther['item_count'] > $beforeOther['item_count']) {
                $otherComponentsWithNewEvidence[] = $key;
            }
        }

        return [
            'component'           => $componentKey,
            'not_recorded'        => false,
            'before_percentage'   => $before['percentage'],
            'before_item_count'   => $before['item_count'],
            'before_earned'       => $before['earned'],
            'before_max'          => $before['max'],
            'after_percentage'    => $after['percentage'],
            'after_item_count'    => $after['item_count'],
            'after_earned'        => $after['earned'],
            'after_max'           => $after['max'],
            'new_item_count'      => max(0, $newItemCount),
            'has_enough_evidence' => $hasEnoughEvidence,
            'change'              => ($hasEnoughEvidence && $before['percentage'] !== null && $after['percentage'] !== null)
                ? round($after['percentage'] - $before['percentage'], 2)
                : null,
            'other_components_with_new_evidence' => $otherComponentsWithNewEvidence,
        ];
    }

    /**
     * One component's percentage — earned/max across every scored item
     * for it, exactly GradingEngine::componentPercentage()'s formula —
     * optionally cut off at a point in time via the score's created_at
     * (when the score was actually recorded, not when the assessment
     * item/column was defined).
     *
     * @return array{percentage: float|null, item_count: int, earned: float, max: float}
     */
    private function componentPercentageAsOf(
        Student $student,
        Subject $subject,
        Section $section,
        int $gradingPeriod,
        string $schoolYear,
        string $componentKey,
        ?Carbon $asOf
    ): array {
        $scores = AssessmentScore::where('student_id', $student->id)
            ->whereHas('assessment', function ($q) use ($subject, $section, $gradingPeriod, $schoolYear, $componentKey) {
                $q->where('subject_id', $subject->id)
                  ->where('section_id', $section->id)
                  ->where('grading_period', $gradingPeriod)
                  ->where('school_year', $schoolYear)
                  ->where('component', $componentKey);
            })
            ->when($asOf, fn($q) => $q->where('created_at', '<=', $asOf))
            ->with('assessment')
            ->get();

        if ($scores->isEmpty()) {
            return ['percentage' => null, 'item_count' => 0, 'earned' => 0.0, 'max' => 0.0];
        }

        $earned = $scores->sum(fn($s) => (float) $s->score);
        $max = $scores->sum(fn($s) => (float) $s->assessment->max_score);

        if ($max <= 0) {
            return ['percentage' => null, 'item_count' => $scores->count(), 'earned' => $earned, 'max' => $max];
        }

        return ['percentage' => round(($earned / $max) * 100, 2), 'item_count' => $scores->count(), 'earned' => $earned, 'max' => $max];
    }
}
