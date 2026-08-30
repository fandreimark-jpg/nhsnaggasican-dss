<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\SubjectAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_no_assessment_evidence_exists(): void
    {
        Subject::factory()->create();

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027');

        $this->assertSame([], $summaries);
    }

    public function test_aggregates_component_averages_across_multiple_students(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['name' => 'Statistics']);
        $studentA = Student::factory()->create(['section_id' => $section->id]);
        $studentB = Student::factory()->create(['section_id' => $section->id]);

        $quiz = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $quiz->id, 'student_id' => $studentA->id, 'score' => 90]);
        AssessmentScore::factory()->create(['assessment_id' => $quiz->id, 'student_id' => $studentB->id, 'score' => 60]);

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027');

        $this->assertCount(1, $summaries);
        $this->assertSame('Statistics', $summaries[0]['subject']->name);
        $this->assertEquals(75.0, $summaries[0]['components']['written_work']['avg_percentage']); // (90+60)/2
        $this->assertSame(2, $summaries[0]['components']['written_work']['student_count']);
        $this->assertNull($summaries[0]['components']['performance_task']);
        $this->assertSame('written_work', $summaries[0]['weakest_component']);
    }

    public function test_identifies_the_weakest_component_across_all_three(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work' => 90, 'performance_task' => 55, 'examination' => 80] as $component => $score) {
            $a = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $a->id, 'student_id' => $student->id, 'score' => $score]);
        }

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries('2026-2027');

        $this->assertSame('performance_task', $summaries[0]['weakest_component']);
        $this->assertSame('Needs Attention', $summaries[0]['components']['performance_task']['status']);
        $this->assertSame('On Track', $summaries[0]['components']['written_work']['status']);
    }

    public function test_defaults_to_the_active_school_year_when_none_given(): void
    {
        $section = Section::factory()->create(['school_year' => '2027-2028']);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);
        $a = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2027-2028',
            'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $a->id, 'student_id' => $student->id, 'score' => 80]);

        $summaries = (new SubjectAnalysisService())->getSubjectSummaries();

        $this->assertCount(1, $summaries);
    }
}
