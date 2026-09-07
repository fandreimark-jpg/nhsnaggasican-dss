<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 1 of "DO 015 grading weights" — weights now come from
 * subject_group_weights (keyed by scheme + the subject's subject_group),
 * never a single hardcoded 25/50/25. This file is the verify net for
 * that: a Grade 11 core_academic subject computes at 20/50/30, a Grade
 * 12 subject is untouched at 25/50/25, and a research_innovation subject
 * (no Examination component at all — ex_weight is null) computes a
 * complete grade from 2 components instead of being reported incomplete.
 */
class SubjectGroupWeightingTest extends TestCase
{
    use RefreshDatabase;

    private GradingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new GradingEngine();
    }

    private function scoreItem(Subject $subject, Section $section, Student $student, string $component, float $earned, float $max): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
    }

    public function test_all_seven_scheme_subject_group_rows_are_present_and_sum_to_100(): void
    {
        $rows = SubjectGroupWeight::all();
        $this->assertCount(7, $rows);

        foreach ($rows as $row) {
            $sum = (float) $row->ww_weight + (float) $row->pt_weight + (float) ($row->ex_weight ?? 0);
            $this->assertEqualsWithDelta(100.0, $sum, 0.001, "Row {$row->scheme}/{$row->subject_group} must sum to 100.");
        }
    }

    public function test_a_grade_11_core_academic_subject_computes_at_20_50_30(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->scoreItem($subject, $section, $student, 'written_work', 80, 100);
        $this->scoreItem($subject, $section, $student, 'performance_task', 80, 100);
        $this->scoreItem($subject, $section, $student, 'examination', 80, 100);

        $result = $this->engine->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertTrue($result['complete']);
        $this->assertEquals(16.0, $result['contributions']['written_work'], '80 * 20% = 16');
        $this->assertEquals(40.0, $result['contributions']['performance_task'], '80 * 50% = 40');
        $this->assertEquals(24.0, $result['contributions']['examination'], '80 * 30% = 24');
        $this->assertEquals(80.0, $result['computed_grade']);
    }

    public function test_a_grade_12_subject_still_computes_at_25_50_25(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['subject_group' => 'core_academic']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->scoreItem($subject, $section, $student, 'written_work', 80, 100);
        $this->scoreItem($subject, $section, $student, 'performance_task', 80, 100);
        $this->scoreItem($subject, $section, $student, 'examination', 80, 100);

        $result = $this->engine->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertSame('do8_2015', $result['transmutation_scheme']);
        $this->assertEquals(20.0, $result['contributions']['written_work'], '80 * 25% = 20 — unchanged from before this task');
        $this->assertEquals(40.0, $result['contributions']['performance_task'], '80 * 50% = 40 — unchanged from before this task');
        $this->assertEquals(20.0, $result['contributions']['examination'], '80 * 25% = 20 — unchanged from before this task');
        $this->assertEquals(80.0, $result['computed_grade']);
    }

    public function test_a_research_innovation_subject_computes_from_two_components_without_being_incomplete(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['subject_group' => 'research_innovation']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        // No Examination items at all — research_innovation has none.
        $this->scoreItem($subject, $section, $student, 'written_work', 90, 100);
        $this->scoreItem($subject, $section, $student, 'performance_task', 80, 100);

        $result = $this->engine->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertTrue($result['complete'], 'A subject group with no Examination component must not be reported incomplete for lacking one.');
        $this->assertNull($result['components']['examination']);
        $this->assertArrayNotHasKey('examination', $result['contributions']);
        // research_innovation: WW 40%, PT 60%, no Examination.
        $this->assertEquals(36.0, $result['contributions']['written_work'], '90 * 40% = 36');
        $this->assertEquals(48.0, $result['contributions']['performance_task'], '80 * 60% = 48');
        $this->assertEquals(84.0, $result['computed_grade']);
    }

    public function test_a_research_innovation_subject_is_still_incomplete_if_written_work_or_performance_task_is_missing(): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['subject_group' => 'research_innovation']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->scoreItem($subject, $section, $student, 'written_work', 90, 100);
        // No Performance Task evidence at all yet.

        $result = $this->engine->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertFalse($result['complete']);
        $this->assertNull($result['computed_grade']);
    }

    public function test_a_work_immersion_subject_also_has_no_examination_component(): void
    {
        $weights = SubjectGroupWeight::where('scheme', 'do015_2026')->where('subject_group', 'work_immersion')->first();

        $this->assertNotNull($weights);
        $this->assertNull($weights->ex_weight);
        $this->assertEquals(20.00, (float) $weights->ww_weight);
        $this->assertEquals(80.00, (float) $weights->pt_weight);
    }

    public function test_resolve_falls_back_to_the_scheme_wide_all_bucket_when_no_group_specific_row_exists(): void
    {
        // do8_2015 has no per-group rows at all, only 'all' — a subject
        // with any subject_group value must still resolve via that
        // fallback rather than throwing.
        $weights = SubjectGroupWeight::resolve('do8_2015', 'techpro');

        $this->assertSame('all', $weights->subject_group);
        $this->assertEquals(25.00, (float) $weights->ww_weight);
    }

    public function test_resolve_throws_when_neither_the_group_nor_all_is_seeded_for_a_scheme(): void
    {
        $this->expectException(\RuntimeException::class);

        SubjectGroupWeight::resolve('nonexistent_scheme', 'core_academic');
    }
}
