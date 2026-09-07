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
 * TASK 2 of "DO 015 grading weights" — the Examination component splits
 * between two Summative Tests and a Term Examination (see ExamRoleShare,
 * assessments.exam_role, GradingEngine::examinationPercentage()). This is
 * the verify net the task's own prompt describes: 30/30/40 when all
 * three roles are present, renormalised to 50/50 when the Term
 * Examination hasn't happened yet, and the legacy flat aggregate when no
 * role is set at all.
 */
class ExamRoleWeightingTest extends TestCase
{
    use RefreshDatabase;

    private GradingEngine $engine;
    private Section $section;
    private Subject $subject;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        // exam_role_shares is deliberately NOT seeded by its migration
        // (unlike subject_group_weights) — its 30/30/40 split is
        // provisional (see ExamRoleSharesSeeder's own TODO), so a
        // RefreshDatabase test starts with an empty table just like real
        // production would before someone runs `php artisan db:seed`.
        // Seeded explicitly here to test the REAL configured split;
        // without it every role falls back to equal weighting, which is
        // exactly what test_no_role_set_at_all_falls_back_to_the_legacy_flat_aggregate
        // already covers for the "no role" case, not this file's point.
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);

        $this->engine = new GradingEngine();
        // Grade 11 SY 2026-2027 -> do015_2026, where ExamRoleShare is seeded.
        $this->section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $this->subject = Subject::factory()->create(['subject_group' => 'core_academic']);
        $this->student = Student::factory()->create(['section_id' => $this->section->id]);
    }

    private function examItem(?string $role, string $suffix): Assessment
    {
        return Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Exam-' . $suffix, 'component' => 'examination', 'exam_role' => $role, 'max_score' => 100,
        ]);
    }

    private function score(Assessment $assessment, float $earned): void
    {
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $this->student->id, 'score' => $earned]);
    }

    private function fillWrittenWorkAndPerformanceTask(): void
    {
        foreach (['written_work', 'performance_task'] as $component) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $this->student->id, 'score' => 100]);
        }
    }

    public function test_all_three_roles_present_weight_30_30_40(): void
    {
        $this->fillWrittenWorkAndPerformanceTask();
        $this->score($this->examItem('st1', '1'), 80);
        $this->score($this->examItem('st2', '2'), 90);
        $this->score($this->examItem('term_exam', '3'), 70);

        $result = $this->engine->computeGrade($this->student, $this->subject, $this->section, 1, '2026-2027');

        // 80*.30 + 90*.30 + 70*.40 = 24 + 27 + 28 = 79.
        $this->assertTrue($result['complete']);
        $this->assertEquals(79.0, $result['components']['examination']);
    }

    public function test_missing_term_exam_item_renormalises_st1_st2_to_50_50(): void
    {
        $this->fillWrittenWorkAndPerformanceTask();
        // No Term Examination item exists at all yet — the term is still
        // in progress. Must NOT read as though the student scored zero
        // on the missing 40%.
        $this->score($this->examItem('st1', '1'), 80);
        $this->score($this->examItem('st2', '2'), 90);

        $result = $this->engine->computeGrade($this->student, $this->subject, $this->section, 1, '2026-2027');

        // (80*30 + 90*30) / 60 = 85 — simple average, not 80*.3+90*.3=51.
        $this->assertTrue($result['complete']);
        $this->assertEquals(85.0, $result['components']['examination']);
    }

    public function test_a_term_exam_item_that_exists_but_this_student_has_no_score_for_yet_is_incomplete_not_renormalised(): void
    {
        $this->fillWrittenWorkAndPerformanceTask();
        $this->score($this->examItem('st1', '1'), 80);
        $this->score($this->examItem('st2', '2'), 90);
        // Term Examination item exists (the exam happened) but this
        // student has no score for it yet — different from "hasn't
        // happened," must stay incomplete.
        $this->examItem('term_exam', '3');

        $result = $this->engine->computeGrade($this->student, $this->subject, $this->section, 1, '2026-2027');

        $this->assertFalse($result['complete']);
        $this->assertNull($result['components']['examination']);
    }

    public function test_no_role_set_at_all_falls_back_to_the_legacy_flat_aggregate(): void
    {
        $this->fillWrittenWorkAndPerformanceTask();
        // Two plain Examination items, neither with a role — exactly the
        // pre-existing behavior every subject had before roles existed.
        $item1 = $this->examItem(null, '1');
        $item1->update(['max_score' => 50]);
        $this->score($item1, 40);
        $item2 = $this->examItem(null, '2');
        $item2->update(['max_score' => 50]);
        $this->score($item2, 45);

        $result = $this->engine->computeGrade($this->student, $this->subject, $this->section, 1, '2026-2027');

        // Flat aggregate: (40+45)/(50+50) = 85%.
        $this->assertEquals(85.0, $result['components']['examination']);
    }

    public function test_two_items_claiming_the_same_role_combine_equally_within_that_roles_share(): void
    {
        $this->fillWrittenWorkAndPerformanceTask();
        // Two items both marked st1 — combined via summed earned/max,
        // same convention as any other multi-item component.
        $st1a = $this->examItem('st1', '1a');
        $st1a->update(['max_score' => 50]);
        $this->score($st1a, 40);
        $st1b = $this->examItem('st1', '1b');
        $st1b->update(['max_score' => 50]);
        $this->score($st1b, 45);
        $this->score($this->examItem('st2', '2'), 90);
        $this->score($this->examItem('term_exam', '3'), 70);

        $result = $this->engine->computeGrade($this->student, $this->subject, $this->section, 1, '2026-2027');

        // st1 combined = (40+45)/(50+50) = 85%. Then 85*.30 + 90*.30 + 70*.40 = 25.5+27+28 = 80.5.
        $this->assertEquals(80.5, $result['components']['examination']);
    }
}
