<?php

namespace Tests\Unit;

use App\Services\InterventionRecommender;
use Tests\TestCase;

class InterventionRecommenderTest extends TestCase
{
    private InterventionRecommender $recommender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recommender = new InterventionRecommender();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'risk_level'                 => 'moderate',
            'consecutive_decline'        => false,
            'failing_subjects'           => [],
            'weakest_subject'            => null,
            'weakest_subject_component'  => null,
        ], $overrides);
    }

    public function test_consecutive_decline_recommends_teacher_monitoring(): void
    {
        $result = $this->recommender->recommend($this->row(['consecutive_decline' => true]));

        $this->assertSame('teacher_monitoring', $result['type']);
        $this->assertStringContainsString('2 consecutive', $result['reason']);
    }

    public function test_weak_performance_task_recommends_additional_performance_task(): void
    {
        $row = $this->row([
            'weakest_subject' => 'Math',
            'weakest_subject_component' => ['key' => 'performance_task', 'percentage' => 60.0, 'gap' => -15.0, 'status' => 'Needs Attention'],
        ]);

        $result = $this->recommender->recommend($row);

        $this->assertSame('additional_performance_task', $result['type']);
        $this->assertStringContainsString('Math', $result['reason']);
        $this->assertStringContainsString('Performance Task', $result['reason']);
        $this->assertStringContainsString('60.0', $result['reason']);
    }

    public function test_weak_written_work_recommends_additional_learning_activity(): void
    {
        $row = $this->row([
            'weakest_subject_component' => ['key' => 'written_work', 'percentage' => 55.0, 'gap' => -20.0, 'status' => 'Needs Attention'],
        ]);

        $this->assertSame('additional_learning_activity', $this->recommender->recommend($row)['type']);
    }

    public function test_weak_examination_recommends_remediation(): void
    {
        $row = $this->row([
            'weakest_subject_component' => ['key' => 'examination', 'percentage' => 50.0, 'gap' => -25.0, 'status' => 'Needs Attention'],
        ]);

        $this->assertSame('remediation', $this->recommender->recommend($row)['type']);
    }

    public function test_a_component_that_is_on_track_does_not_trigger_a_component_based_recommendation(): void
    {
        $row = $this->row([
            'weakest_subject_component' => ['key' => 'performance_task', 'percentage' => 80.0, 'gap' => 5.0, 'status' => 'On Track'],
            'risk_level' => 'high',
        ]);

        $this->assertSame('parent_conference', $this->recommender->recommend($row)['type']);
    }

    public function test_failing_subjects_without_component_evidence_recommends_remediation(): void
    {
        $row = $this->row(['failing_subjects' => [['name' => 'Math', 'grade' => 65]]]);

        $result = $this->recommender->recommend($row);

        $this->assertSame('remediation', $result['type']);
        $this->assertStringContainsString('Math', $result['reason']);
    }

    public function test_high_risk_with_no_other_evidence_recommends_parent_conference(): void
    {
        $result = $this->recommender->recommend($this->row(['risk_level' => 'high']));

        $this->assertSame('parent_conference', $result['type']);
    }

    public function test_default_fallback_recommends_attendance_monitoring(): void
    {
        $result = $this->recommender->recommend($this->row());

        $this->assertSame('attendance_monitoring', $result['type']);
    }
}
