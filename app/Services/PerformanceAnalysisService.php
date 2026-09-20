<?php

namespace App\Services;

use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;

/**
 * Turns GradingEngine's raw component percentages into something that
 * explains WHY a student needs attention — per CLAUDE.md: "The DSS must
 * not depend only on final grade... it must analyze the underlying
 * assessment components... The system should clearly show why the
 * student needs attention. Do not make unsupported claims."
 *
 * A component's gap is its percentage minus the target (a student can be
 * "on track" overall while one component quietly drags below target —
 * the whole point of not just looking at the final/computed grade). The
 * weakest component is whichever has the most negative gap among the
 * components that actually have data; a component with no data at all
 * contributes no claim either way (see GradingEngine's "missing means
 * incomplete, not zero" reasoning — the same principle applies here).
 */
class PerformanceAnalysisService
{
    public const DEFAULT_TARGET = 75.0;

    public function __construct(private GradingEngine $gradingEngine = new GradingEngine())
    {
    }

    /** The engine this service computes with — shared so a caller's other reads hit the same evidence cache. */
    public function engine(): GradingEngine
    {
        return $this->gradingEngine;
    }

    /**
     * @return array{
     *     complete: bool,
     *     computed_grade: float|null,
     *     transmuted_grade: float|null,
     *     transmutation_scheme: string,
     *     transmutation_available: bool,
     *     transmutation_provisional: bool,
     *     transmutation_fallback_scheme: ?string,
     *     target: float,
     *     components: array<string, array{percentage: float|null, gap: float|null, status: ?string}>,
     *     weakest_component: ?string,
     * }
     */
    public function analyzeStudent(
        Student $student,
        Subject $subject,
        Section $section,
        int $gradingPeriod,
        string $schoolYear,
        float $target = self::DEFAULT_TARGET
    ): array {
        $grade = $this->gradingEngine->computeGrade($student, $subject, $section, $gradingPeriod, $schoolYear);

        $components = [];
        foreach ($grade['components'] as $key => $percentage) {
            $components[$key] = [
                'percentage' => $percentage,
                'gap'        => $percentage === null ? null : round($percentage - $target, 2),
                'status'     => $this->statusFor($percentage, $target),
            ];
        }

        return [
            'complete'                => $grade['complete'],
            'computed_grade'          => $grade['computed_grade'],
            'transmuted_grade'        => $grade['transmuted_grade'],
            // TASK 2 of "terminology, transmutation, and interface
            // cleanup" — passed through so the Adviser Assessments and
            // Principal Students screens can show an explicit "not
            // available yet" note instead of silently rendering a blank
            // or fabricated Transmuted cell.
            'transmutation_scheme'    => $grade['transmutation_scheme'] ?? \App\Services\TransmutationService::DEFAULT_SCHEME,
            'transmutation_available' => $grade['transmutation_available'] ?? ($grade['transmuted_grade'] !== null),
            // TASK 1 of "unblock verification" — passed through so the
            // Adviser Assessments and Principal Students screens can mark
            // a transmuted grade PROVISIONAL rather than showing it as an
            // ordinary number. See config('dss.transmutation_fallback_scheme').
            'transmutation_provisional'     => $grade['transmutation_provisional'] ?? false,
            'transmutation_fallback_scheme' => $grade['transmutation_fallback_scheme'] ?? null,
            'target'            => $target,
            'components'        => $components,
            'weakest_component' => $this->weakestComponent($components),
            // "Performance audit" pass — how many scored items the figures
            // above rest on, read from the evidence GradingEngine already
            // loaded. InTermStatusService::fromAnalysis() reads this
            // instead of running its own per-row count query.
            'item_count'        => $this->gradingEngine->scoredItemCount($student, $subject, $section, $gradingPeriod, $schoolYear),
        ];
    }

    private function statusFor(?float $percentage, float $target): ?string
    {
        if ($percentage === null) {
            return null;
        }

        return $percentage >= $target ? 'On Track' : 'Needs Attention';
    }

    /** The component with the most negative gap, among those with data. Null if none have data. */
    private function weakestComponent(array $components): ?string
    {
        $withGaps = collect($components)->filter(fn($c) => $c['gap'] !== null);

        if ($withGaps->isEmpty()) {
            return null;
        }

        return $withGaps->sortBy('gap')->keys()->first();
    }
}
