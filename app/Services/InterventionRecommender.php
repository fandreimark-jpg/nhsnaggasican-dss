<?php

namespace App\Services;

/**
 * Turns one row from DashboardAnalyticsService::getAtRiskStudentsData()
 * into a suggested intervention type + a plain-language reason — the
 * DSS's recommendation. Per CLAUDE.md: "The DSS recommends. The
 * Principal decides." Nothing here writes anything; it's a pure
 * suggestion the Principal can accept, change, or ignore when creating
 * an Intervention record.
 *
 * Rules are intentionally simple and transparent (no black box) — each
 * one is checked in order, most specific/urgent first, and the reason
 * string always says exactly which evidence drove the suggestion.
 */
class InterventionRecommender
{
    private const COMPONENT_TO_TYPE = [
        'performance_task' => 'additional_performance_task',
        'written_work'      => 'additional_learning_activity',
        'examination'       => 'remediation',
    ];

    private const COMPONENT_LABELS = [
        'written_work'      => 'Written Work',
        'performance_task'  => 'Performance Task',
        'examination'       => 'Examination',
    ];

    /** @return array{type: string, reason: string} */
    public function recommend(array $atRiskRow): array
    {
        if (!empty($atRiskRow['consecutive_decline'])) {
            return [
                'type'   => 'teacher_monitoring',
                'reason' => 'Performance has declined for 2 consecutive grading periods — closer monitoring is recommended before the risk escalates further.',
            ];
        }

        $component = $atRiskRow['weakest_subject_component'] ?? null;
        if ($component && ($component['status'] ?? null) === 'Needs Attention') {
            $label = self::COMPONENT_LABELS[$component['key']] ?? $component['key'];

            return [
                'type'   => self::COMPONENT_TO_TYPE[$component['key']] ?? 'other',
                'reason' => sprintf(
                    'Weakest component in %s: %s at %s%% (%.1f points below target) — this specific area, not the overall grade, is driving the risk.',
                    $atRiskRow['weakest_subject'] ?? 'the weakest subject',
                    $label,
                    number_format($component['percentage'], 1),
                    $component['gap']
                ),
            ];
        }

        if (!empty($atRiskRow['failing_subjects'])) {
            $names = collect($atRiskRow['failing_subjects'])->pluck('name')->implode(', ');

            return [
                'type'   => 'remediation',
                'reason' => "Currently failing: {$names}. Remediation is recommended to address the specific subject(s) before the term ends.",
            ];
        }

        if (($atRiskRow['risk_level'] ?? null) === 'high') {
            return [
                'type'   => 'parent_conference',
                'reason' => 'Classified High Risk overall. A parent/guardian conference is recommended to coordinate support.',
            ];
        }

        return [
            'type'   => 'attendance_monitoring',
            'reason' => 'Classified as needing monitoring. No single failing subject or component stands out yet — general monitoring is recommended while the situation develops.',
        ];
    }
}
