<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\PerformanceAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verified against CLAUDE.md's worked example: WW=84% (gap +9, On Track),
 * PT=60% (gap -15, Needs Attention), Exam=70% (gap -5, Needs Attention),
 * target=75% -> weakest component is Performance Task (the most negative
 * gap), even though the overall picture might look fine at a glance.
 */
class PerformanceAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    private PerformanceAnalysisService $service;
    private Section $section;
    private Subject $subject;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PerformanceAnalysisService();
        $this->section = Section::factory()->create(['school_year' => '2026-2027']);
        $this->subject = Subject::factory()->create();
        $this->student = Student::factory()->create(['section_id' => $this->section->id]);
    }

    private function score(string $component, float $earned, float $max): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);

        AssessmentScore::factory()->create([
            'assessment_id' => $assessment->id, 'student_id' => $this->student->id, 'score' => $earned,
        ]);
    }

    private function analyze(): array
    {
        return $this->service->analyzeStudent($this->student, $this->subject, $this->section, 1, '2026-2027');
    }

    public function test_the_claude_md_worked_example_identifies_performance_task_as_the_weakest_component(): void
    {
        $this->score('written_work', 84, 100);
        $this->score('performance_task', 60, 100);
        $this->score('examination', 70, 100);

        $result = $this->analyze();

        $this->assertEquals(9.0, $result['components']['written_work']['gap']);
        $this->assertSame('On Track', $result['components']['written_work']['status']);

        $this->assertEquals(-15.0, $result['components']['performance_task']['gap']);
        $this->assertSame('Needs Attention', $result['components']['performance_task']['status']);

        $this->assertEquals(-5.0, $result['components']['examination']['gap']);
        $this->assertSame('Needs Attention', $result['components']['examination']['status']);

        $this->assertSame('performance_task', $result['weakest_component']);
    }

    public function test_a_student_above_target_in_every_component_has_no_weakest_flagged_as_needing_attention(): void
    {
        $this->score('written_work', 90, 100);
        $this->score('performance_task', 85, 100);
        $this->score('examination', 95, 100);

        $result = $this->analyze();

        foreach ($result['components'] as $component) {
            $this->assertSame('On Track', $component['status']);
        }
        // Still identifies a "weakest" (relatively), it just isn't flagged as failing.
        $this->assertSame('performance_task', $result['weakest_component']);
    }

    public function test_missing_component_data_is_excluded_from_weakest_component_consideration(): void
    {
        $this->score('written_work', 60, 100); // Needs Attention, gap -15
        $this->score('performance_task', 90, 100); // On Track
        // No examination data at all yet.

        $result = $this->analyze();

        $this->assertFalse($result['complete']);
        $this->assertNull($result['components']['examination']['gap']);
        $this->assertSame('written_work', $result['weakest_component']);
    }

    public function test_custom_target_changes_the_gap_and_status(): void
    {
        $this->score('written_work', 80, 100);
        $this->score('performance_task', 80, 100);
        $this->score('examination', 80, 100);

        $result = $this->service->analyzeStudent($this->student, $this->subject, $this->section, 1, '2026-2027', target: 90.0);

        $this->assertEquals(-10.0, $result['components']['written_work']['gap']);
        $this->assertSame('Needs Attention', $result['components']['written_work']['status']);
    }
}
