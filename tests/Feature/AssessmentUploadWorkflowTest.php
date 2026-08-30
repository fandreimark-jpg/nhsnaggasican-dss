<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * End-to-end HTTP round trip through the two-phase upload workflow:
 * detect() (upload -> classification-verification screen) then import()
 * (confirmed mapping -> saved evidence). AssessmentUploadServiceTest
 * covers import-logic edge cases directly against the service; this
 * covers the controller's authorization, term-gating, and the actual
 * file hand-off between the two requests.
 */
class AssessmentUploadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $content, string $name = 'assessment.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_full_upload_detect_verify_import_round_trip(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000020']);

        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv("lrn,last_name,first_name,Quiz 1,Final Exam\n100000000020,Dela Cruz,Juan,18,45\n");

        $detectResponse = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $file,
        ]);

        $detectResponse->assertOk();
        $detectResponse->assertViewIs('adviser.assessments-verify');
        $detectResponse->assertViewHas('columns', function ($columns) {
            $names = array_column($columns, 'name');
            return $names === ['Quiz 1', 'Final Exam'];
        });

        $storedFilename = $detectResponse->viewData('storedFilename');

        $importResponse = $this->actingAs($adviser)->post('/adviser/assessments/import', [
            'subject_id'      => $subject->id,
            'grading_period'  => 1,
            'stored_filename' => $storedFilename,
            'original_filename' => 'assessment.csv',
            'columns' => [
                ['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20],
                ['name' => 'Final Exam', 'component' => 'examination', 'max_score' => 50],
            ],
        ]);

        $importResponse->assertSessionHas('success');
        $this->assertDatabaseHas('assessments', ['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20]);
        $this->assertDatabaseHas('assessments', ['name' => 'Final Exam', 'component' => 'examination', 'max_score' => 50]);
        $this->assertDatabaseHas('assessment_scores', ['student_id' => $student->id, 'score' => 18]);
        $this->assertDatabaseHas('assessment_scores', ['student_id' => $student->id, 'score' => 45]);
        $this->assertDatabaseHas('assessment_uploads', [
            'section_id' => $section->id, 'subject_id' => $subject->id, 'status' => 'imported', 'imported_count' => 2,
        ]);
    }

    public function test_adviser_cannot_upload_for_a_subject_outside_their_sections_scope(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11]);
        $foreignSubject = Subject::factory()->create(['grade_level' => 12, 'type' => 'core']);

        AcademicTerm::ensureExistFor($section->school_year);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $foreignSubject->id,
            'grading_period' => 1,
            'file'           => $this->csv("lrn,last_name,first_name,Quiz 1\n"),
        ]);

        $response->assertSessionHas('error');
        $response->assertRedirect();
    }

    public function test_upload_is_blocked_when_the_term_is_closed(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor($section->school_year);
        $this->actingAs($admin)->post('/admin/academic-terms/1/close');

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $this->csv("lrn,last_name,first_name,Quiz 1\n"),
        ]);

        $response->assertSessionHas('error');
    }

    public function test_import_rejects_a_tampered_stored_filename(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor($section->school_year);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/import', [
            'subject_id'      => $subject->id,
            'grading_period'  => 1,
            'stored_filename' => '../../.env',
            'columns' => [
                ['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20],
            ],
        ]);

        $response->assertSessionHasErrors('stored_filename');
    }

    public function test_an_unclassified_column_cannot_be_imported_without_a_component_choice(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor($section->school_year);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/import', [
            'subject_id'      => $subject->id,
            'grading_period'  => 1,
            'stored_filename' => 'nonexistent.csv',
            'columns' => [
                ['name' => 'Mystery Column', 'component' => '', 'max_score' => 20],
            ],
        ]);

        $response->assertSessionHasErrors('columns.0.component');
    }

    public function test_admin_cannot_access_adviser_assessment_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/adviser/assessments')->assertForbidden();
    }
}
