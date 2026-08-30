<?php

namespace App\Services;

use App\Models\Intervention;

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

    /**
     * @return array{
     *     component: string,
     *     before_period: int,
     *     before_percentage: float,
     *     after_period: int|null,
     *     after_percentage: float|null,
     *     change: float|null,
     * }|null null when there isn't enough information to compare anything
     * (no linked subject/risk result, or no weakest component was ever
     * identified at baseline).
     */
    public function compare(Intervention $intervention): ?array
    {
        $riskResult = $intervention->riskResult;

        if (!$intervention->subject_id || !$riskResult) {
            return null;
        }

        $student    = $intervention->student;
        $subject    = $intervention->subject;
        $section    = $student->section;
        $beforeTerm = $riskResult->grading_period;
        $schoolYear = $riskResult->school_year;

        if (!$section) {
            return null;
        }

        $before = $this->performanceAnalysis->analyzeStudent($student, $subject, $section, $beforeTerm, $schoolYear);
        $componentKey = $before['weakest_component'];

        if (!$componentKey) {
            return null;
        }

        $beforePercentage = $before['components'][$componentKey]['percentage'];
        $afterTerm = $beforeTerm + 1;

        if ($afterTerm > 3) {
            return $this->result($componentKey, $beforeTerm, $beforePercentage, null, null);
        }

        $after = $this->performanceAnalysis->analyzeStudent($student, $subject, $section, $afterTerm, $schoolYear);
        $afterPercentage = $after['components'][$componentKey]['percentage'] ?? null;

        if ($afterPercentage === null) {
            return $this->result($componentKey, $beforeTerm, $beforePercentage, $afterTerm, null);
        }

        return $this->result($componentKey, $beforeTerm, $beforePercentage, $afterTerm, $afterPercentage);
    }

    private function result(string $component, int $beforeTerm, float $beforePct, ?int $afterTerm, ?float $afterPct): array
    {
        return [
            'component'         => $component,
            'before_period'     => $beforeTerm,
            'before_percentage' => $beforePct,
            'after_period'      => $afterTerm,
            'after_percentage'  => $afterPct,
            'change'            => $afterPct === null ? null : round($afterPct - $beforePct, 2),
        ];
    }
}
