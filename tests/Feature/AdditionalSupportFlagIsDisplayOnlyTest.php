<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\GradingEngine;
use App\Services\InTermStatusService;
use App\Services\PerformanceAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Workflow completion pass" Hard Constraint 2 / TASK 3 — marking an
 * item as additional support is DISPLAY ONLY. It must never change any
 * computed grade, transmuted grade, or In-Term Status: the same
 * evidence, marked additional or not, must compute identically.
 */
class AdditionalSupportFlagIsDisplayOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    private function buildScenario(Section $section, Subject $subject, Student $student, bool $additionalSupport): array
    {
        foreach (['written_work' => 84, 'performance_task' => 60, 'examination' => 70] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => $section->school_year,
                'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
                'is_additional_support' => $additionalSupport,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $grade = (new GradingEngine())->computeGrade($student, $subject, $section, 1, $section->school_year);
        $analysis = (new PerformanceAnalysisService())->analyzeStudent($student, $subject, $section, 1, $section->school_year);
        $inTermStatus = (new InTermStatusService())->fromAnalysis($analysis, $student, $subject, $section, 1, $section->school_year);

        return [$grade, $inTermStatus];
    }

    public function test_marking_every_item_as_additional_support_does_not_change_the_computed_or_transmuted_grade(): void
    {
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);

        $studentA = Student::factory()->create(['section_id' => $section->id]);
        [$gradeA] = $this->buildScenario($section, $subject, $studentA, false);

        $studentB = Student::factory()->create(['section_id' => $section->id]);
        [$gradeB] = $this->buildScenario($section, $subject, $studentB, true);

        $this->assertSame($gradeA['computed_grade'], $gradeB['computed_grade']);
        $this->assertSame($gradeA['transmuted_grade'], $gradeB['transmuted_grade']);
        $this->assertSame($gradeA['complete'], $gradeB['complete']);
    }

    public function test_marking_every_item_as_additional_support_does_not_change_in_term_status(): void
    {
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);

        $studentA = Student::factory()->create(['section_id' => $section->id]);
        [, $statusA] = $this->buildScenario($section, $subject, $studentA, false);

        $studentB = Student::factory()->create(['section_id' => $section->id]);
        [, $statusB] = $this->buildScenario($section, $subject, $studentB, true);

        $this->assertSame($statusA['status'], $statusB['status']);
        $this->assertSame($statusA['components_below_target'], $statusB['components_below_target']);
    }

    public function test_the_column_defaults_to_false_and_existing_assessments_are_unaffected(): void
    {
        $section = Section::factory()->create();
        $subject = Subject::factory()->create();
        $assessment = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $section->id]);

        $this->assertFalse($assessment->fresh()->is_additional_support);
    }
}
