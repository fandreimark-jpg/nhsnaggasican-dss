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

    public function test_no_term_exam_item_yet_adds_nothing_and_is_not_rescaled(): void
    {
        $this->fillWrittenWorkAndPerformanceTask();
        // No Term Examination item exists yet. Workbook (D2, 2026-10-04):
        // AG17 = ROUND(80/100*30,2) = 24, AH17 = 27, AI17 = "" ->
        // AJ17 = (24 + 27) / (30 + 30 + 40) * 100 = 51 — the configured
        // shares are the denominator; ST1/ST2 are NOT rescaled to 50/50 (85).
        $this->score($this->examItem('st1', '1'), 80);
        $this->score($this->examItem('st2', '2'), 90);

        $result = $this->engine->computeGrade($this->student, $this->subject, $this->section, 1, '2026-2027');

        $this->assertTrue($result['complete'], 'no TE item exists, so none is awaited');
        $this->assertEquals(51.0, $result['components']['examination']);
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

        // Workbook AJ17 is filled from the scored types (51.00, AK17 = 15.30);
        // the system shows that as a running figure but keeps the grade out
        // of computed_grade / verification until the Term Exam is scored.
        $this->assertEquals(51.0, $result['components']['examination']);
        $this->assertSame(['term_exam'], $result['component_detail']['examination']['unscored_roles']);
        $this->assertTrue($result['workbook_complete']);
        $this->assertFalse($result['complete']);
        $this->assertNull($result['computed_grade']);
        $this->assertSame(20.0 + 50.0 + 15.3, $result['running_grade']);
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

    public function test_a_second_regular_item_of_one_type_blocks_the_examination_figure(): void
    {
        $this->fillWrittenWorkAndPerformanceTask();
        // Two REGULAR items both marked st1. The workbook has ONE ST 1 cell
        // (AD17), so neither is chosen: the class has no Examination figure.
        $st1a = $this->examItem('st1', '1a');
        $st1a->update(['max_score' => 50]);
        $this->score($st1a, 40);
        $st1b = $this->examItem('st1', '1b');
        $st1b->update(['max_score' => 50]);
        $this->score($st1b, 45);
        $this->score($this->examItem('st2', '2'), 90);
        $this->score($this->examItem('term_exam', '3'), 70);

        $result = $this->engine->computeGrade($this->student, $this->subject, $this->section, 1, '2026-2027');

        // Two REGULAR ST1 items are ambiguous (the workbook has one ST 1 cell):
        // no Examination figure, no grade, and a correction message — never "first wins".
        $this->assertNull($result['components']['examination']);
        $this->assertSame('blocked', $result['component_detail']['examination']['method']);
        $this->assertStringContainsString('More than one regular ST1', $result['blockers'][0]);
        $this->assertFalse($result['complete']);
        $this->assertNull($result['computed_grade']);
        $this->assertArrayNotHasKey('examination', $result['partial_contributions'], 'no Examination WS in the running grade');

        // Verify Grades names the blocker; a direct verify POST is refused and writes nothing.
        \App\Models\AcademicYear::firstOrCreate(['school_year' => '2026-2027'], ['is_active' => true]);
        \App\Models\AcademicTerm::ensureExistFor('2026-2027');
        \App\Models\AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $adviser = \App\Models\User::factory()->create(['role' => 'adviser']);
        $this->section->update(['adviser_id' => $adviser->id]);
        $this->subject->update(['grade_level' => 11, 'type' => 'core']);
        $this->subject->syncTerms([1, 2, 3]);
        $row = (new \App\Services\GradeVerificationBoard())->build($this->section, $this->subject, 1, null)['performance']
            ->first(fn($r) => $r['student']->id === $this->student->id);
        $this->assertSame('incomplete', $row['verify_state']);
        $this->assertStringContainsString('More than one regular ST1', $row['verify_reason']);
        $this->assertStringContainsString('Edit the extra item to its correct Examination Type', $row['verify_reason']);

        $this->actingAs($adviser)->postJson(route('adviser.grades.verify'), ['student_id' => $this->student->id, 'subject_id' => $this->subject->id, 'grading_period' => 1])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Cannot verify ' . $this->subject->name . ' — ' . $result['blockers'][0]]);
        $this->assertSame(0, \App\Models\Grade::where('is_verified', true)->count(), 'no official grade from ambiguous evidence');
        $this->assertSame(2, Assessment::where('exam_role', 'st1')->count(), 'both items kept — never deleted');
    }
}
