<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\User;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Subject classification and grading weights cleanup" pass — the single
 * authoritative test file for the seven named DO 015, s. 2026 grading
 * profiles (CLAUDE.md / DO 015 Table 10) and the type/subject_group
 * consistency rule that eliminates the "TYPE: Elective, SUBJECT GROUP:
 * Core Academic" contradiction. Every profile is exercised end-to-end
 * through GradingEngine, not just checked as a seeded row — the same
 * "prove it through the real computation" standard SubjectGroupWeightingTest
 * already holds core_academic, research_innovation, and work_immersion to.
 */
class SubjectClassificationConsistencyTest extends TestCase
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

    /** @return array<string, array{0: string, 1: float, 2: float, 3: ?float}> */
    public static function profileProvider(): array
    {
        return [
            'A. Core (20/50/30)'                          => ['core_academic', 20.00, 50.00, 30.00],
            'B. Academic Elective — all other (20/50/30)'  => ['academic_other', 20.00, 50.00, 30.00],
            'C. Research/Design/Innovation (40/60/0)'      => ['research_innovation', 40.00, 60.00, null],
            'D. Arts/Sports/Health/Wellness (20/60/20)'    => ['arts_sports_wellness', 20.00, 60.00, 20.00],
            'E. Field Experience (15/70/15)'               => ['field_exposure', 15.00, 70.00, 15.00],
            'F. TechPro Elective — all other (15/65/20)'   => ['techpro', 15.00, 65.00, 20.00],
            'G. Work Immersion (20/80/0)'                  => ['work_immersion', 20.00, 80.00, null],
        ];
    }

    /** @dataProvider profileProvider */
    public function test_each_named_profile_is_seeded_at_its_documented_weights(string $group, float $ww, float $pt, ?float $ex): void
    {
        $w = SubjectGroupWeight::where('scheme', 'do015_2026')->where('subject_group', $group)->first();

        $this->assertNotNull($w, "subject_group_weights must have a do015_2026/{$group} row.");
        $this->assertEqualsWithDelta($ww, (float) $w->ww_weight, 0.001);
        $this->assertEqualsWithDelta($pt, (float) $w->pt_weight, 0.001);
        $this->assertSame($ex, $w->ex_weight !== null ? (float) $w->ex_weight : null);
    }

    /** @dataProvider profileProvider */
    public function test_each_named_profile_computes_a_grade_at_its_documented_weights(string $group, float $ww, float $pt, ?float $ex): void
    {
        $section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['type' => 'elective', 'grade_level' => 11, 'subject_group' => $group]);
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->scoreItem($subject, $section, $student, 'written_work', 100, 100);
        $this->scoreItem($subject, $section, $student, 'performance_task', 100, 100);
        if ($ex !== null) {
            $this->scoreItem($subject, $section, $student, 'examination', 100, 100);
        }

        $result = $this->engine->computeGrade($student, $subject, $section, 1, '2026-2027');

        $this->assertTrue($result['complete']);
        $this->assertEqualsWithDelta($ww, $result['contributions']['written_work'], 0.01);
        $this->assertEqualsWithDelta($pt, $result['contributions']['performance_task'], 0.01);
        if ($ex !== null) {
            $this->assertEqualsWithDelta($ex, $result['contributions']['examination'], 0.01);
        } else {
            $this->assertArrayNotHasKey('examination', $result['contributions']);
        }
        $this->assertEqualsWithDelta(100.0, $result['computed_grade'], 0.01);
    }

    // ---- Type / subject_group consistency (the actual contradiction fix) ----

    public function test_core_academic_is_rejected_for_an_elective(): void
    {
        $error = SubjectGroupWeight::classificationError('elective', 11, 'core_academic');

        $this->assertNotNull($error);
        $this->assertStringContainsString('cannot use', $error);
    }

    public function test_a_non_core_academic_group_is_rejected_for_a_core_subject(): void
    {
        $error = SubjectGroupWeight::classificationError('core', 11, 'academic_other');

        $this->assertNotNull($error);
        $this->assertStringContainsString('must use', $error);
    }

    public function test_core_paired_with_core_academic_is_valid(): void
    {
        $this->assertNull(SubjectGroupWeight::classificationError('core', 11, 'core_academic'));
    }

    public function test_elective_paired_with_any_non_core_academic_group_is_valid(): void
    {
        foreach (SubjectGroupWeight::electiveGroups() as $group) {
            $this->assertNull(SubjectGroupWeight::classificationError('elective', 11, $group), "elective + {$group} should be valid.");
        }
    }

    public function test_an_unrecognised_group_is_rejected(): void
    {
        $this->assertNotNull(SubjectGroupWeight::classificationError('core', 11, 'not_a_real_group'));
    }

    public function test_a_grade_11_subject_with_no_group_is_unresolved_not_defaulted_to_core(): void
    {
        $error = SubjectGroupWeight::classificationError('core', 11, null);

        $this->assertNotNull($error);
        $this->assertStringContainsString('required', $error);
    }

    public function test_a_grade_12_subject_must_have_a_null_group(): void
    {
        $this->assertNull(SubjectGroupWeight::classificationError('core', 12, null));
        $this->assertNull(SubjectGroupWeight::classificationError('elective', 12, null));

        $error = SubjectGroupWeight::classificationError('elective', 12, 'core_academic');
        $this->assertNotNull($error, 'A Grade 12 subject with any subject_group value must be rejected — DO 8, s. 2015 never reads it.');
    }

    public function test_subjectgroupweight_resolve_throws_for_an_unclassified_do015_subject_rather_than_defaulting_to_core(): void
    {
        $this->expectException(\RuntimeException::class);

        SubjectGroupWeight::resolve('do015_2026', null);
    }

    public function test_the_admin_form_never_lets_an_elective_be_saved_with_core_academic(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.subjects.store'), [
            'name' => 'Contradictory Elective', 'type' => 'elective', 'grade_level' => 11,
            'subject_group' => 'core_academic',
        ])->assertSessionHasErrors('subject_group');

        $this->assertDatabaseMissing('subjects', ['name' => 'Contradictory Elective']);
    }

    public function test_the_admin_form_rejects_a_core_subject_with_a_non_core_group(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.subjects.store'), [
            'name' => 'Contradictory Core', 'type' => 'core', 'grade_level' => 11,
            'subject_group' => 'work_immersion',
        ])->assertSessionHasErrors('subject_group');

        $this->assertDatabaseMissing('subjects', ['name' => 'Contradictory Core']);
    }

    public function test_a_grade_12_subject_submitted_with_a_subject_group_value_is_rejected_not_silently_dropped(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.subjects.store'), [
            'name' => 'Grade 12 With Stray Group', 'type' => 'core', 'grade_level' => 12,
            'subject_group' => 'core_academic',
        ])->assertSessionHasErrors('subject_group');

        $this->assertDatabaseMissing('subjects', ['name' => 'Grade 12 With Stray Group']);
    }

    public function test_a_grade_12_subject_with_no_subject_group_saves_cleanly(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.subjects.store'), [
            'name' => 'Genuine Grade 12 Subject', 'type' => 'core', 'grade_level' => 12,
        ])->assertRedirect(route('admin.subjects'));

        $this->assertDatabaseHas('subjects', ['name' => 'Genuine Grade 12 Subject', 'subject_group' => null]);
    }
}
