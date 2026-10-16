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
 * compareWithinTerm() compares the intervention's FOCUS component
 * ($intervention->focus_component, frozen on the row at creation) as it
 * stood from the evidence recorded up to delivery against the same
 * component now, within the intervention's own term. (A term-over-term
 * comparison anchored on the classifier's risk_results existed before the
 * rule-based cut-over and was removed with it.)
 */
class ProgressMonitoringService
{
    public function __construct(private PerformanceAnalysisService $performanceAnalysis = new PerformanceAnalysisService())
    {
    }

    /** The last grading period in a school year — see the 'no_later_term' status below. */
    private const LAST_TERM = 3;

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
     * over SCORED items only — a progress comparison of recorded scores,
     * not the grade (the grade divides by every item's HPS since
     * 2026-10-04; see GradingEngine::workbookComponent()).
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
        $section = $intervention->contextSection();

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
                'before_scores_changed_since_delivery' => 0,
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
            // Final release pass (2026-09-27): a score is edited IN PLACE
            // (created_at kept, updated_at bumped only on a real change), and
            // no score history is stored — so a pre-delivery score corrected
            // after delivery is counted in "Before" at its CURRENT value.
            // The true at-delivery value cannot be recovered; say so rather
            // than let "Before" pass for the evidence as it stood then.
            'before_scores_changed_since_delivery' => $before['changed_since'],
        ];
    }

    /**
     * One component's percentage — earned/max across every SCORED item
     * for it (a comparison of recorded scores over time; NOT the grade,
     * which since 2026-10-04 divides by every item's HPS — GradingEngine) —
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
        // "Performance audit" pass — the same rows the per-call query here
        // used to fetch (this student's scores for this subject/section/
        // term/year/component, cut off at $asOf on the score's created_at),
        // read from the evidence GradingEngine already holds for the
        // section/term. See GradingEngine::scoredItems().
        $scores = collect($this->performanceAnalysis->engine()->scoredItems(
            $student, $subject, $section, $gradingPeriod, $schoolYear, $componentKey, $asOf
        ));

        if ($scores->isEmpty()) {
            return ['percentage' => null, 'item_count' => 0, 'earned' => 0.0, 'max' => 0.0, 'changed_since' => 0];
        }

        $earned = $scores->sum(fn($row) => (float) $row['score']->score);
        $max = $scores->sum(fn($row) => (float) $row['assessment']->max_score);
        // Only meaningful for an as-of cut: rows recorded by then whose value
        // has changed since (see compareWithinTerm()).
        $changedSince = $asOf === null ? 0 : $scores->filter(
            fn($row) => $row['score']->updated_at !== null && $row['score']->updated_at->greaterThan($asOf)
                && ($row['score']->created_at === null || $row['score']->updated_at->greaterThan($row['score']->created_at))
        )->count();

        if ($max <= 0) {
            return ['percentage' => null, 'item_count' => $scores->count(), 'earned' => $earned, 'max' => $max, 'changed_since' => $changedSince];
        }

        return ['percentage' => round(($earned / $max) * 100, 2), 'item_count' => $scores->count(), 'earned' => $earned, 'max' => $max, 'changed_since' => $changedSince];
    }
}
