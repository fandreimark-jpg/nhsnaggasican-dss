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
