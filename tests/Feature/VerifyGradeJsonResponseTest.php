<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 3 of "UI cleanup and correctness pass" — Adviser\GradeController::
 * verifyComputedGrade() must content-negotiate: an Accept: application/json
 * request (the row's fetch() submit) gets a JSON body it can use to update
 * the row in place, while a normal browser form submit keeps the original
 * full-page redirect — same business logic, only the response shape
 * branches.
 */
// NOTE ('SSHS ECR grading correction', 2026-09-20): these Grade 12
// sections carry an EXPLICIT k12_2013 curriculum because this file's
// intent is the DO 8, s. 2015 (legacy) path. An unset curriculum in
// SY 2026-2027 now resolves to DO 015 for both grade levels.
class VerifyGradeJsonResponseTest extends TestCase
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
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }
    }

    public function test_a_json_accept_header_gets_a_json_body_on_success(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['curriculum' => 'k12_2013', 'adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        $this->fullyScore($section, $subject, $student);

        $response = $this->actingAs($adviser)
            ->postJson('/adviser/grades/verify', [
                'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            ]);

        $response->assertOk();
        $response->assertJson([
            'student_id'     => $student->id,
            'official_grade' => 80.0,
            'computed_grade' => 68.5,
            'provisional'    => false,
        ]);
        $response->assertJsonStructure(['student_id', 'official_grade', 'computed_grade', 'provisional', 'message']);

        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grade' => 80.0, 'is_verified' => 1,
        ]);
    }

    public function test_without_a_json_accept_header_it_still_redirects(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['curriculum' => 'k12_2013', 'adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 12]);
        $subject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        $this->fullyScore($section, $subject, $student);

        $response = $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_a_json_request_that_fails_gets_a_non_2xx_json_error_not_a_redirect(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        // Incomplete evidence — only 2 of 3 components scored.
        foreach (['written_work' => 90, 'performance_task' => 90] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => $section->school_year,
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $response = $this->actingAs($adviser)->postJson('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
        $this->assertDatabaseMissing('grades', ['student_id' => $student->id]);
    }
}
