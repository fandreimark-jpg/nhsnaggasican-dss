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
 * "ECR alignment" work order, PART 3a — proves GradingEngine::computeGrade()
 * actually reads $section->curriculum, not just that TransmutationService::
 * schemeFor() accepts it in isolation (see TransmutationServiceTest). A
 * Grade 12 section explicitly marked 'sshs' must compute on do015_2026
 * despite being Grade 12 — the scenario the whole column exists for: a
 * transition cohort that doesn't follow "Grade 11 = new curriculum."
 */
class SectionCurriculumDrivesGradingSchemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_grade_12_section_explicitly_marked_sshs_computes_on_do015_2026(): void
    {
        $section = Section::factory()->create(['grade_level' => 12, 'school_year' => '2026-2027', 'curriculum' => 'sshs']);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $item = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $item->id, 'student_id' => $student->id, 'score' => 100]);
        }

        $result = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertSame('do015_2026', $result['transmutation_scheme'], 'Grade 12 does not default to do015_2026 by grade level alone -- only an explicit sshs curriculum gets here.');
    }

    public function test_a_grade_11_section_explicitly_marked_k12_2013_stays_on_do8_2015(): void
    {
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027', 'curriculum' => 'k12_2013']);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $item = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $item->id, 'student_id' => $student->id, 'score' => 100]);
        }

        $result = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertSame('do8_2015', $result['transmutation_scheme'], 'Grade 11 in SY 2026-2027 does not automatically get do015_2026 once curriculum is explicit -- k12_2013 wins.');
    }

    public function test_a_section_with_no_curriculum_set_falls_back_to_grade_level_inference_unchanged(): void
    {
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027', 'curriculum' => null]);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $item = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $item->id, 'student_id' => $student->id, 'score' => 100]);
        }

        $result = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertSame('do015_2026', $result['transmutation_scheme'], 'Unchanged pre-Part-3a behavior: Grade 11 + SY 2026-2027 -> do015_2026 by inference.');
    }
}
