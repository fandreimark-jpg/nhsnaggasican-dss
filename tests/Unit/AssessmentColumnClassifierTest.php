<?php

namespace Tests\Unit;

use App\Services\AssessmentColumnClassifier;
use Tests\TestCase;

class AssessmentColumnClassifierTest extends TestCase
{
    private AssessmentColumnClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new AssessmentColumnClassifier();
    }

    public function test_written_work_keywords(): void
    {
        $this->assertSame('written_work', $this->classifier->classify('Quiz 1'));
        $this->assertSame('written_work', $this->classifier->classify('Quiz 2'));
        $this->assertSame('written_work', $this->classifier->classify('Activity 1'));
        $this->assertSame('written_work', $this->classifier->classify('Written Activity'));
        $this->assertSame('written_work', $this->classifier->classify('Written Work 1'));
        $this->assertSame('written_work', $this->classifier->classify('Seatwork 1'));
    }

    public function test_classification_matrix_from_the_verify_screen(): void
    {
        $this->assertSame('written_work', $this->classifier->classify('Quiz 1'));
        $this->assertSame('written_work', $this->classifier->classify('Written Work 1'));
        $this->assertSame('written_work', $this->classifier->classify('Seatwork 1'));
        $this->assertSame('performance_task', $this->classifier->classify('Performance Task 1'));
        $this->assertSame('performance_task', $this->classifier->classify('Group Project'));
        $this->assertSame('performance_task', $this->classifier->classify('Practical Activity'));
        $this->assertSame('examination', $this->classifier->classify('Periodical Exam'));
        $this->assertNull($this->classifier->classify('Recitation'));
    }

    /**
     * TASK 3 of "DO 015 grading weights" — Summative Test moved from
     * Written Work to Examination (it's part of the Examination
     * component under DO 015, s. 2026, split further by role — see
     * ExamRoleShare). Long Test and Unit Test were removed from Written
     * Work entirely, not moved to Examination — schools use those names
     * for both kinds of assessment, so a wrong automatic guess in either
     * direction is worse than asking; they must return null.
     */
    public function test_summative_test_and_term_examination_classify_as_examination_with_the_right_role_preselected(): void
    {
        $this->assertSame('examination', $this->classifier->classify('Summative Test 1'));
        $this->assertSame('st1', $this->classifier->classifyExamRole('Summative Test 1'));

        $this->assertSame('examination', $this->classifier->classify('Summative Test 2'));
        $this->assertSame('st2', $this->classifier->classifyExamRole('Summative Test 2'));

        $this->assertSame('examination', $this->classifier->classify('Term Examination'));
        $this->assertSame('term_exam', $this->classifier->classifyExamRole('Term Examination'));
    }

    public function test_long_test_and_unit_test_are_no_longer_guessed_as_written_work(): void
    {
        $this->assertNull($this->classifier->classify('Long Test'));
        $this->assertNull($this->classifier->classify('Unit Test'));
    }

    public function test_a_bare_summative_test_column_classifies_as_examination_with_no_role_preselected(): void
    {
        // No "1"/"2" to name a role — the adviser picks it, if at all
        // (an item with no role falls back to equal weighting — see
        // GradingEngine::examinationPercentage()).
        $this->assertSame('examination', $this->classifier->classify('Summative Test'));
        $this->assertNull($this->classifier->classifyExamRole('Summative Test'));
    }

    public function test_performance_task_keywords(): void
    {
        $this->assertSame('performance_task', $this->classifier->classify('Performance Task 1'));
        $this->assertSame('performance_task', $this->classifier->classify('Project'));
        $this->assertSame('performance_task', $this->classifier->classify('Presentation'));
        $this->assertSame('performance_task', $this->classifier->classify('Practical Activity'));
    }

    public function test_examination_keywords(): void
    {
        $this->assertSame('examination', $this->classifier->classify('Exam'));
        $this->assertSame('examination', $this->classifier->classify('Periodical Exam'));
        $this->assertSame('examination', $this->classifier->classify('Final Exam'));
    }

    public function test_unrecognized_column_returns_null_rather_than_guessing(): void
    {
        $this->assertNull($this->classifier->classify('Column XYZ'));
        $this->assertNull($this->classifier->classify(''));
    }

    public function test_is_case_insensitive(): void
    {
        $this->assertSame('examination', $this->classifier->classify('FINAL EXAM'));
        $this->assertSame('written_work', $this->classifier->classify('quiz 3'));
    }
}
