<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\DepedSubjectCatalog;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 2 — proves the resolution PRIORITY, not
 * just that a linked catalog row's numbers are readable: a subject stamped
 * with the 'core_academic' subject_group (20/50/30) but linked to a
 * Tech-Pro-pattern catalog row (15/65/20, e.g. "Broadband Installation")
 * must compute using 15/65/20. Written Work is scored 100%, Performance
 * Task and Examination both 0%, so the computed grade equals the Written
 * Work weight alone — 15.0 if the catalog won, 20.0 if the stale
 * subject_group default won instead.
 */
class CatalogWeightsBeatSubjectGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_linked_catalog_row_overrides_the_subject_group_default(): void
    {
        $catalogRow = DepedSubjectCatalog::where('course_title', 'Broadband Installation')->first();
        $this->assertNotNull($catalogRow);
        $this->assertEquals(15.0, (float) $catalogRow->ww_weight);
        $this->assertEquals(65.0, (float) $catalogRow->pt_weight);
        $this->assertEquals(20.0, (float) $catalogRow->ex_weight);

        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic', 'catalog_id' => $catalogRow->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $ww = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'WW', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $ww->id, 'student_id' => $student->id, 'score' => 100]);

        $pt = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'PT', 'component' => 'performance_task', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $pt->id, 'student_id' => $student->id, 'score' => 0]);

        $ex = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'EX', 'component' => 'examination', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $ex->id, 'student_id' => $student->id, 'score' => 0]);

        $result = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertTrue($result['complete']);
        // WW 100% * 15% + PT 0% * 65% + EX 0% * 20% = 15.0 -- the catalog's
        // weight, not core_academic's 20.0.
        $this->assertEquals(15.0, $result['computed_grade']);
    }

    public function test_an_unlinked_subject_still_uses_the_subject_group_default_unchanged(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic', 'catalog_id' => null]);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $ww = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'WW', 'component' => 'written_work', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $ww->id, 'student_id' => $student->id, 'score' => 100]);

        $pt = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'PT', 'component' => 'performance_task', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $pt->id, 'student_id' => $student->id, 'score' => 0]);

        $ex = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'EX', 'component' => 'examination', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $ex->id, 'student_id' => $student->id, 'score' => 0]);

        $result = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertTrue($result['complete']);
        // core_academic's own WW weight, 20.0 -- unchanged from before Part 2.
        $this->assertEquals(20.0, $result['computed_grade']);
    }
}
