<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 25/50/25 (Written Work / Performance Task / Examination) grading
 * calculation, verified against the two worked examples from the master
 * spec. GradingEngine itself never hard-codes these numbers — they only
 * exist here, as test fixtures.
 */
class GradingEngineTest extends TestCase
{
    use RefreshDatabase;

    private GradingEngine $engine;
    private Section $section;
    private Subject $subject;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine  = new GradingEngine();
        $this->section = Section::factory()->create(['school_year' => '2026-2027']);
        $this->subject = Subject::factory()->create();
        $this->student = Student::factory()->create(['section_id' => $this->section->id]);
    }

    private function score(string $component, float $earned, float $max): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id'      => $this->subject->id,
            'section_id'      => $this->section->id,
            'grading_period'  => 1,
            'school_year'     => '2026-2027',
            'name'            => $component . '-' . uniqid(),
            'component'       => $component,
            'max_score'       => $max,
        ]);

        AssessmentScore::factory()->create([
            'assessment_id' => $assessment->id,
            'student_id'    => $this->student->id,
            'score'         => $earned,
        ]);
    }

    private function compute(): array
    {
        return $this->engine->computeGrade($this->student, $this->subject, $this->section, 1, '2026-2027');
    }

    public function test_worked_example_one_ww_84_44_pt_85_exam_70_gives_81_11(): void
    {
        $this->score('written_work', 76, 90);      // 76/90 = 84.44%
        $this->score('performance_task', 85, 100); // 85%
        $this->score('examination', 70, 100);      // 70%

        $result = $this->compute();

        $this->assertTrue($result['complete']);
        $this->assertEquals(84.44, $result['components']['written_work']);
        $this->assertEquals(85.0, $result['components']['performance_task']);
        $this->assertEquals(70.0, $result['components']['examination']);
        $this->assertEquals(21.11, $result['contributions']['written_work']);
        $this->assertEquals(42.5, $result['contributions']['performance_task']);
        $this->assertEquals(17.5, $result['contributions']['examination']);
        $this->assertEquals(81.11, $result['computed_grade']);
    }

    public function test_worked_example_two_ww_84_pt_60_exam_70_gives_68_5(): void
    {
        $this->score('written_work', 84, 100);
        $this->score('performance_task', 60, 100);
        $this->score('examination', 70, 100);

        $result = $this->compute();

        $this->assertEquals(21.0, $result['contributions']['written_work']);
        $this->assertEquals(30.0, $result['contributions']['performance_task']);
        $this->assertEquals(17.5, $result['contributions']['examination']);
        $this->assertEquals(68.5, $result['computed_grade']);
    }

    public function test_zero_score(): void
    {
        $this->score('written_work', 0, 100);
        $this->score('performance_task', 0, 100);
        $this->score('examination', 0, 100);

        $result = $this->compute();

        $this->assertSame(0.0, $result['computed_grade']);
    }

    public function test_perfect_score(): void
    {
        $this->score('written_work', 100, 100);
        $this->score('performance_task', 100, 100);
        $this->score('examination', 100, 100);

        $result = $this->compute();

        $this->assertSame(100.0, $result['computed_grade']);
    }

    public function test_score_equal_to_max_score_is_100_percent(): void
    {
        $this->score('written_work', 45, 45);
        $this->score('performance_task', 45, 45);
        $this->score('examination', 45, 45);

        $result = $this->compute();

        $this->assertSame(100.0, $result['components']['written_work']);
        $this->assertSame(100.0, $result['computed_grade']);
    }

    public function test_missing_component_makes_the_grade_incomplete_not_a_guess(): void
    {
        $this->score('written_work', 90, 100);
        $this->score('performance_task', 90, 100);
        // No examination items uploaded at all for this term yet.

        $result = $this->compute();

        $this->assertFalse($result['complete']);
        $this->assertNull($result['computed_grade']);
        $this->assertNull($result['components']['examination']);
        $this->assertEquals(90.0, $result['components']['written_work']);
    }

    public function test_component_with_items_but_no_scores_yet_for_this_student_is_also_missing(): void
    {
        // An assessment item exists for Examination, but this student
        // hasn't been scored on it yet — still incomplete, not zero.
        Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Periodical Exam', 'component' => 'examination', 'max_score' => 100,
        ]);
        $this->score('written_work', 90, 100);
        $this->score('performance_task', 90, 100);

        $result = $this->compute();

        $this->assertFalse($result['complete']);
        $this->assertNull($result['components']['examination']);
    }

    public function test_multiple_assessments_in_the_same_component_are_aggregated(): void
    {
        // Two quizzes (both Written Work) combine into one percentage,
        // not averaged as two separate percentages.
        $this->score('written_work', 18, 20); // Quiz 1
        $this->score('written_work', 14, 20); // Quiz 2 -> combined 32/40 = 80%
        $this->score('performance_task', 90, 100);
        $this->score('examination', 90, 100);

        $result = $this->compute();

        $this->assertEquals(80.0, $result['components']['written_work']);
    }

    public function test_decimal_rounding_to_two_places(): void
    {
        $this->score('written_work', 1, 3);   // 33.333...%
        $this->score('performance_task', 2, 3); // 66.666...%
        $this->score('examination', 1, 3);    // 33.333...%

        $result = $this->compute();

        $this->assertEquals(33.33, $result['components']['written_work']);
        $this->assertEquals(66.67, $result['components']['performance_task']);
        $this->assertEquals(33.33, $result['components']['examination']);
    }
}
