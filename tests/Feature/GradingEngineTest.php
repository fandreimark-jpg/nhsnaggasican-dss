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
 * The 25/50/25 (Written Work / Performance Task / Examination) grading
 * calculation, verified against the two worked examples from the master
 * spec. GradingEngine itself never hard-codes these numbers — they only
 * exist here, as test fixtures.
 */
class GradingEngineTest extends TestCase
{
    use RefreshDatabase;

    private GradingEngine $engine;
    private Section $section;
    private Subject $subject;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine  = new GradingEngine();
        // grade_level pinned to 12 (not the factory's random 11/12) —
        // TASK 2 of "terminology, transmutation, and interface cleanup"
        // wired scheme selection to grade level + school year, and this
        // suite's `do8_2015` assertions must stay deterministic rather
        // than flipping to `do015_2026` on a random Grade 11 roll. Grade
        // 12 in SY 2026-2027 correctly stays on do8_2015 either way — see
        // TransmutationServiceSchemeTest for Grade 11's do015_2026 wiring.
        $this->section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 12]);
        $this->subject = Subject::factory()->create();
        $this->student = Student::factory()->create(['section_id' => $this->section->id]);
    }

    private function score(string $component, float $earned, float $max): void
    {
        $assessment = Assessment::factory()->create([
            'subject_id'      => $this->subject->id,
            'section_id'      => $this->section->id,
            'grading_period'  => 1,
            'school_year'     => '2026-2027',
            'name'            => $component . '-' . uniqid(),
            'component'       => $component,
            'max_score'       => $max,
        ]);

        AssessmentScore::factory()->create([
            'assessment_id' => $assessment->id,
            'student_id'    => $this->student->id,
            'score'         => $earned,
        ]);
    }

    private function compute(): array
    {
        return $this->engine->computeGrade($this->student, $this->subject, $this->section, 1, '2026-2027');
    }

    public function test_worked_example_one_ww_84_44_pt_85_exam_70_gives_81_11(): void
    {
        $this->score('written_work', 76, 90);      // 76/90 = 84.44%
        $this->score('performance_task', 85, 100); // 85%
        $this->score('examination', 70, 100);      // 70%

        $result = $this->compute();

        $this->assertTrue($result['complete']);
        $this->assertEquals(84.44, $result['components']['written_work']);
        $this->assertEquals(85.0, $result['components']['performance_task']);
        $this->assertEquals(70.0, $result['components']['examination']);
        $this->assertEquals(21.11, $result['contributions']['written_work']);
        $this->assertEquals(42.5, $result['contributions']['performance_task']);
        $this->assertEquals(17.5, $result['contributions']['examination']);
        $this->assertEquals(81.11, $result['computed_grade']);
    }

    public function test_worked_example_two_ww_84_pt_60_exam_70_gives_68_5(): void
    {
        $this->score('written_work', 84, 100);
        $this->score('performance_task', 60, 100);
        $this->score('examination', 70, 100);

        $result = $this->compute();

        $this->assertEquals(21.0, $result['contributions']['written_work']);
        $this->assertEquals(30.0, $result['contributions']['performance_task']);
        $this->assertEquals(17.5, $result['contributions']['examination']);
        $this->assertEquals(68.5, $result['computed_grade']);
    }

    public function test_zero_score(): void
    {
        $this->score('written_work', 0, 100);
        $this->score('performance_task', 0, 100);
        $this->score('examination', 0, 100);

        $result = $this->compute();

        $this->assertSame(0.0, $result['computed_grade']);
    }

    public function test_perfect_score(): void
    {
        $this->score('written_work', 100, 100);
        $this->score('performance_task', 100, 100);
        $this->score('examination', 100, 100);

        $result = $this->compute();

        $this->assertSame(100.0, $result['computed_grade']);
    }

    public function test_score_equal_to_max_score_is_100_percent(): void
    {
        $this->score('written_work', 45, 45);
        $this->score('performance_task', 45, 45);
        $this->score('examination', 45, 45);

        $result = $this->compute();

        $this->assertSame(100.0, $result['components']['written_work']);
        $this->assertSame(100.0, $result['computed_grade']);
    }

    public function test_missing_component_makes_the_grade_incomplete_not_a_guess(): void
    {
        $this->score('written_work', 90, 100);
        $this->score('performance_task', 90, 100);
        // No examination items uploaded at all for this term yet.

        $result = $this->compute();

        $this->assertFalse($result['complete']);
        $this->assertNull($result['computed_grade']);
        $this->assertNull($result['components']['examination']);
        $this->assertEquals(90.0, $result['components']['written_work']);
    }

    public function test_component_with_items_but_no_scores_yet_for_this_student_is_also_missing(): void
    {
        // An assessment item exists for Examination, but this student
        // hasn't been scored on it yet — still incomplete, not zero.
        Assessment::factory()->create([
            'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
            'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Periodical Exam', 'component' => 'examination', 'max_score' => 100,
        ]);
        $this->score('written_work', 90, 100);
        $this->score('performance_task', 90, 100);

        $result = $this->compute();

        $this->assertFalse($result['complete']);
        $this->assertNull($result['components']['examination']);
    }

    public function test_multiple_assessments_in_the_same_component_are_aggregated(): void
    {
        // Two quizzes (both Written Work) combine into one percentage,
        // not averaged as two separate percentages.
        $this->score('written_work', 18, 20); // Quiz 1
        $this->score('written_work', 14, 20); // Quiz 2 -> combined 32/40 = 80%
        $this->score('performance_task', 90, 100);
        $this->score('examination', 90, 100);

        $result = $this->compute();

        $this->assertEquals(80.0, $result['components']['written_work']);
    }

    /**
     * Task 2 in the "remaining system issues" prompt: wiring
     * TransmutationService into GradingEngine must never change
     * computed_grade — the risk classifier and the component analysis
     * both read it, and their inputs must not shift. transmuted_grade
     * is a genuinely NEW, additional field alongside it, not a
     * replacement.
     */
    public function test_computed_grade_is_unchanged_and_transmuted_grade_is_the_do8_2015_value(): void
    {
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);

        $this->score('written_work', 84, 100);
        $this->score('performance_task', 60, 100);
        $this->score('examination', 70, 100);

        $result = $this->compute();

        $this->assertEquals(68.5, $result['computed_grade'], 'computed_grade must be exactly what it was before transmutation was wired in.');
        $this->assertEquals(80.0, $result['transmuted_grade'], '68.5 falls in the DO 8, s. 2015 band 68.00-69.59, which transmutes to 80.');
    }

    /**
     * TASK 2 of "terminology, transmutation, and interface cleanup" —
     * Grade 11 in SY 2026-2027 resolves to the do015_2026 scheme, which
     * is now fully seeded 0.00-100.00 (Do015TransmutationSeeder). A
     * computed grade of exactly 70.00 lands on its passing anchor band
     * (70.00-71.17 -> 75).
     */
    public function test_grade_11_sy_2026_2027_resolves_to_do015_2026_and_transmutes_its_seeded_anchor(): void
    {
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);

        $grade11Section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $student = Student::factory()->create(['section_id' => $grade11Section->id]);

        // WW=70 (17.5) + PT=70 (35) + Exam=70 (17.5) = 70.00 exactly —
        // the one seeded do015_2026 anchor.
        foreach (['written_work', 'performance_task', 'examination'] as $component) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $this->subject->id, 'section_id' => $grade11Section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 70]);
        }

        $result = $this->engine->computeGrade($student, $this->subject, $grade11Section, 1, '2026-2027');

        $this->assertSame('do015_2026', $result['transmutation_scheme']);
        $this->assertEquals(70.0, $result['computed_grade']);
        $this->assertTrue($result['transmutation_available']);
        $this->assertEquals(75.0, $result['transmuted_grade']);
    }

    /**
     * The do015_2026 scheme has only one seeded band (see above) — any
     * OTHER computed grade under it must come back with NO transmuted
     * grade, never a silently-wrong fallback value.
     */
    public function test_grade_11_sy_2026_2027_off_the_seeded_anchor_has_no_transmuted_grade(): void
    {
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);

        $grade11Section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $student = Student::factory()->create(['section_id' => $grade11Section->id]);

        // WW=84 + PT=60 + Exam=70 -> under do015_2026's core_academic
        // weights (20/50/30 — this->subject has no subject_group
        // override, defaulting to 'core_academic'): 84*.20 + 60*.50 +
        // 70*.30 = 16.8 + 30 + 21 = 67.8, which does not match
        // do015_2026's single seeded band (70.00-70.00 only).
        foreach ([['written_work', 84], ['performance_task', 60], ['examination', 70]] as [$component, $score]) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $this->subject->id, 'section_id' => $grade11Section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $score]);
        }

        $result = $this->engine->computeGrade($student, $this->subject, $grade11Section, 1, '2026-2027');

        $this->assertSame('do015_2026', $result['transmutation_scheme']);
        $this->assertEquals(67.8, $result['computed_grade'], 'computed_grade must still be populated even when transmutation is unavailable.');
        $this->assertFalse($result['transmutation_available']);
        $this->assertNull($result['transmuted_grade'], 'A wrong transmuted grade is worse than a missing one — must be null, never a fabricated fallback.');
    }

    public function test_grade_12_sy_2026_2027_stays_on_do8_2015(): void
    {
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);

        $this->score('written_work', 84, 100);
        $this->score('performance_task', 60, 100);
        $this->score('examination', 70, 100);

        $result = $this->compute();

        $this->assertSame('do8_2015', $result['transmutation_scheme']);
        $this->assertTrue($result['transmutation_available']);
        $this->assertEquals(80.0, $result['transmuted_grade']);
    }

    public function test_decimal_rounding_to_two_places(): void
    {
        $this->score('written_work', 1, 3);   // 33.333...%
        $this->score('performance_task', 2, 3); // 66.666...%
        $this->score('examination', 1, 3);    // 33.333...%

        $result = $this->compute();

        $this->assertEquals(33.33, $result['components']['written_work']);
        $this->assertEquals(66.67, $result['components']['performance_task']);
        $this->assertEquals(33.33, $result['components']['examination']);
    }
}
