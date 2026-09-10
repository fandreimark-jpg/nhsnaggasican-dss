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
 * "ECR alignment" work order, PART 2c — a subject whose catalog row has a
 * null st1_share/st2_share (Term Exam at 100, no summative tests — 9 such
 * Academic subjects exist, e.g. "Basic Calculus") must not expect ST1/ST2
 * evidence to ever exist. Only a term_exam-role item is created here — no
 * ST1 or ST2 item at all — and the grade still computes as complete, using
 * only that one role at its catalog-supplied 100% share.
 */
class TermExamOnlySubjectExpectsNoSummativeTestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_grade_completes_with_only_a_term_exam_item_no_st1_st2_expected(): void
    {
        $catalogRow = DepedSubjectCatalog::where('course_title', 'Basic Calculus')->first();
        $this->assertNotNull($catalogRow);
        $this->assertEquals(30.0, (float) $catalogRow->ex_weight);
        $this->assertNull($catalogRow->st1_share);
        $this->assertNull($catalogRow->st2_share);
        $this->assertEquals(100.0, (float) $catalogRow->te_share);

        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['catalog_id' => $catalogRow->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);

        foreach (['written_work', 'performance_task'] as $component) {
            $item = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $item->id, 'student_id' => $student->id, 'score' => 100]);
        }

        // Only ONE Examination item, role = term_exam. No st1, no st2, ever.
        $termExam = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Term Exam', 'component' => 'examination', 'exam_role' => 'term_exam', 'max_score' => 100,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $termExam->id, 'student_id' => $student->id, 'score' => 88]);

        $result = (new GradingEngine())->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertTrue($result['complete']);
        $this->assertEquals(88.0, $result['components']['examination']);
        // WW 100%*20 + PT 100%*50 + EX 88%*30 = 20 + 50 + 26.4 = 96.4.
        $this->assertEquals(96.4, $result['computed_grade']);
    }
}
