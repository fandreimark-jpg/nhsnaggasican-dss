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
 * The previously-missing half of CLAUDE.md's pipeline: "computed_grade
 * -> Adviser verification -> official grade." GradingEngine could
 * already compute a grade and the Performance Analysis table could
 * already display it, but nothing ever turned that into an action that
 * actually sets grades.grade. This is that action.
 */
class GradeVerificationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_adviser_can_verify_a_complete_computed_grade_as_official(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        $this->fullyScore($section, $subject, $student);

        $response = $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'grade' => 68.5, 'computed_grade' => 68.5, 'is_verified' => 1,
        ]);
        $this->assertNotNull(Grade::first()->verified_at);
    }

    public function test_verifying_overwrites_an_existing_manually_encoded_grade(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'grade' => 95, 'school_year' => '2026-2027',
        ]);
        $this->fullyScore($section, $subject, $student);

        $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $this->assertDatabaseHas('grades', ['student_id' => $student->id, 'grade' => 68.5]);
        $this->assertDatabaseMissing('grades', ['student_id' => $student->id, 'grade' => 95]);
    }

    public function test_cannot_verify_an_incomplete_computed_grade(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        // Only 2 of 3 components scored.
        foreach (['written_work' => 90, 'performance_task' => 90] as $component => $earned) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id,
                'grading_period' => 1, 'school_year' => '2026-2027',
                'name' => $component, 'component' => $component, 'max_score' => 100,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $response = $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('grades', ['student_id' => $student->id]);
    }

    public function test_cannot_verify_for_a_student_outside_the_advisers_section(): void
    {
        $adviserA = User::factory()->create();
        $adviserB = User::factory()->create();
        $sectionA = Section::factory()->create(['adviser_id' => $adviserA->id]);
        $sectionB = Section::factory()->create(['adviser_id' => $adviserB->id, 'grade_level' => $sectionA->grade_level]);
        $otherStudent = Student::factory()->create(['section_id' => $sectionB->id]);
        $subject = Subject::factory()->create(['grade_level' => $sectionA->grade_level, 'type' => 'core']);

        $response = $this->actingAs($adviserA)->post('/adviser/grades/verify', [
            'student_id' => $otherStudent->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('grades', ['student_id' => $otherStudent->id]);
    }

    public function test_cannot_verify_a_subject_outside_the_sections_scope(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $foreignSubject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);

        $response = $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $foreignSubject->id, 'grading_period' => 1,
        ]);

        $response->assertSessionHas('error');
    }

    public function test_cannot_verify_when_the_term_is_closed(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        $this->fullyScore($section, $subject, $student);
        $this->actingAs($admin)->post('/admin/academic-terms/1/close');

        $response = $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('grades', ['student_id' => $student->id]);
    }

    public function test_verification_is_logged(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id]);

        AcademicTerm::ensureExistFor('2026-2027');
        $this->fullyScore($section, $subject, $student);

        $this->actingAs($adviser)->post('/adviser/grades/verify', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
        ]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'verify_computed_grade', 'user_id' => $adviser->id]);
    }
}
