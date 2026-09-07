<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Workflow completion pass" TASK 1 — "Verify All Remaining." Eligible:
 * no official grade yet, complete evidence, a non-provisional transmuted
 * grade available. Everything else is excluded under one of three named
 * reasons and reported, not silently skipped.
 */
class VerifyAllRemainingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
    }

    private function fullyScore(Section $section, Subject $subject, Student $student, int $term = 1): void
    {
        foreach (['written_work' => 84, 'performance_task' => 60, 'examination' => 70] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => $term, 'school_year' => $section->school_year,
                'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }
    }

    private function partiallyScore(Section $section, Subject $subject, Student $student, int $term = 1): void
    {
        // Only 2 of 3 components — incomplete evidence.
        foreach (['written_work' => 90, 'performance_task' => 90] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => $term, 'school_year' => $section->school_year,
                'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }
    }

    public function test_verifies_only_eligible_rows_and_leaves_already_encoded_grades_untouched(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        AcademicTerm::ensureExistFor('2026-2027');

        $eligibleA = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Eligible1']);
        $eligibleB = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Eligible2']);
        $this->fullyScore($section, $subject, $eligibleA);
        $this->fullyScore($section, $subject, $eligibleB);

        $alreadyEncoded = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'AlreadyDone']);
        $this->fullyScore($section, $subject, $alreadyEncoded);
        Grade::factory()->create([
            'student_id' => $alreadyEncoded->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 95.0, 'is_verified' => true, 'is_provisional' => false,
        ]);

        $incomplete = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Incomplete']);
        $this->partiallyScore($section, $subject, $incomplete);

        $response = $this->actingAs($adviser)->postJson('/adviser/grades/verify-all', [
            'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertOk();
        $response->assertJson(['verified_count' => 2]);

        $this->assertDatabaseHas('grades', ['student_id' => $eligibleA->id, 'is_verified' => 1]);
        $this->assertDatabaseHas('grades', ['student_id' => $eligibleB->id, 'is_verified' => 1]);
        // Already-encoded grade is UNTOUCHED — still the original 95, not overwritten.
        $this->assertDatabaseHas('grades', ['student_id' => $alreadyEncoded->id, 'grade' => 95.0]);
        $this->assertDatabaseMissing('grades', ['student_id' => $incomplete->id]);
    }

    public function test_preview_reports_excluded_rows_grouped_by_reason_with_names(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        AcademicTerm::ensureExistFor('2026-2027');

        $eligible = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Ready']);
        $this->fullyScore($section, $subject, $eligible);

        $alreadyEncoded = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Zamora']);
        $this->fullyScore($section, $subject, $alreadyEncoded);
        Grade::factory()->create([
            'student_id' => $alreadyEncoded->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'grade' => 88.0, 'is_verified' => true, 'is_provisional' => false,
        ]);

        $incomplete = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Reyes']);
        $this->partiallyScore($section, $subject, $incomplete);

        $response = $this->actingAs($adviser)->getJson('/adviser/grades/verify-all/preview?subject_id=' . $subject->id . '&grading_period=1');

        $response->assertOk();
        $response->assertJson([
            'eligible_count' => 1,
            'subject_name'   => $subject->name,
            'section_name'   => $section->name,
            'grading_period' => 1,
        ]);
        $data = $response->json();
        $this->assertContains('Zamora, ' . $alreadyEncoded->first_name, $data['excluded']['already_encoded']);
        $this->assertContains('Reyes, ' . $incomplete->first_name, $data['excluded']['incomplete_evidence']);
    }

    public function test_a_transmutation_provisional_row_is_excluded_as_no_transmutation_available(): void
    {
        $adviser = User::factory()->create();
        // Grade 11, SY 2026-2027 -> do015_2026, deliberately NOT seeded in
        // this test's setUp() (only do8_2015 is) — so this section's
        // students can never resolve a real transmuted grade.
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 11]);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        AcademicTerm::ensureExistFor('2026-2027');

        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'NoBand']);
        $this->fullyScore($section, $subject, $student);

        $response = $this->actingAs($adviser)->getJson('/adviser/grades/verify-all/preview?subject_id=' . $subject->id . '&grading_period=1');

        $response->assertOk();
        $response->assertJson(['eligible_count' => 0]);
        $this->assertContains('NoBand, ' . $student->first_name, $response->json('excluded.no_transmutation'));

        $execResponse = $this->actingAs($adviser)->postJson('/adviser/grades/verify-all', [
            'subject_id' => $subject->id, 'grading_period' => 1,
        ]);
        $execResponse->assertStatus(422);
        $this->assertDatabaseMissing('grades', ['student_id' => $student->id]);
    }

    public function test_button_has_nothing_to_do_when_zero_rows_eligible(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        AcademicTerm::ensureExistFor('2026-2027');

        $response = $this->actingAs($adviser)->getJson('/adviser/grades/verify-all/preview?subject_id=' . $subject->id . '&grading_period=1');
        $response->assertOk();
        $response->assertJson(['eligible_count' => 0]);
    }
}
