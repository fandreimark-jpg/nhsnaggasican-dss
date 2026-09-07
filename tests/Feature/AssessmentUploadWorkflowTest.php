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
 * End-to-end HTTP round trip through the three-phase upload workflow:
 * detect() (upload -> classification-verification screen), preview()
 * (dry-run row-level check, nothing saved), then import() (confirmed
 * mapping -> saved evidence). AssessmentUploadServiceTest covers
 * import/preview-logic edge cases directly against the service; this
 * covers the controller's authorization, term-gating, and the actual
 * file hand-off across all three requests.
 */
class AssessmentUploadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $content, string $name = 'assessment.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_full_upload_detect_verify_preview_import_round_trip(): void
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

        $confirmedColumns = [
            ['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20],
            ['name' => 'Final Exam', 'component' => 'examination', 'max_score' => 50],
        ];

        $previewResponse = $this->actingAs($adviser)->post('/adviser/assessments/preview', [
            'subject_id'         => $subject->id,
            'grading_period'     => 1,
            'stored_filename'    => $storedFilename,
            'original_filename'  => 'assessment.csv',
            'columns'            => $confirmedColumns,
        ]);

        $previewResponse->assertOk();
        $previewResponse->assertViewIs('adviser.assessments-preview');
        $previewResponse->assertViewHas('preview', fn($p) => $p['total_rows'] === 1 && $p['matched_rows'] === 1 && $p['total_valid_cells'] === 2);
        // Nothing written yet — preview is a dry run.
        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('assessment_scores', 0);

        $importResponse = $this->actingAs($adviser)->post('/adviser/assessments/import', [
            'subject_id'      => $subject->id,
            'grading_period'  => 1,
            'stored_filename' => $storedFilename,
            'original_filename' => 'assessment.csv',
            'columns' => $confirmedColumns,
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

    /**
     * TASK 2 of "DO 015 grading weights" — the Verify screen guesses
     * each Examination column's role from its header (see
     * AssessmentColumnClassifier::classifyExamRole()), and that role
     * must survive the Preview -> Import round trip into
     * assessments.exam_role, exactly like `component` already does.
     */
    public function test_exam_role_is_guessed_on_detect_and_persisted_through_the_full_round_trip(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000030']);

        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv("lrn,last_name,first_name,Summative Test 1,Term Examination\n100000000030,Dela Cruz,Juan,40,45\n");

        $detectResponse = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $subject->id, 'grading_period' => 1, 'file' => $file,
        ]);

        $detectResponse->assertViewHas('columns', function ($columns) {
            $roles = array_column($columns, 'guessed_exam_role', 'name');
            return $roles['Summative Test 1'] === 'st1' && $roles['Term Examination'] === 'term_exam';
        });

        $storedFilename = $detectResponse->viewData('storedFilename');

        $confirmedColumns = [
            ['name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1', 'max_score' => 50],
            ['name' => 'Term Examination', 'component' => 'examination', 'exam_role' => 'term_exam', 'max_score' => 50],
        ];

        $this->actingAs($adviser)->post('/adviser/assessments/preview', [
            'subject_id' => $subject->id, 'grading_period' => 1, 'stored_filename' => $storedFilename,
            'original_filename' => 'assessment.csv', 'columns' => $confirmedColumns,
        ])->assertOk();

        $this->actingAs($adviser)->post('/adviser/assessments/import', [
            'subject_id' => $subject->id, 'grading_period' => 1, 'stored_filename' => $storedFilename,
            'original_filename' => 'assessment.csv', 'columns' => $confirmedColumns,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('assessments', ['name' => 'Summative Test 1', 'component' => 'examination', 'exam_role' => 'st1']);
        $this->assertDatabaseHas('assessments', ['name' => 'Term Examination', 'component' => 'examination', 'exam_role' => 'term_exam']);
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

    public function test_preview_rejects_a_tampered_stored_filename(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor($section->school_year);

        $response = $this->actingAs($adviser)->post('/adviser/assessments/preview', [
            'subject_id'      => $subject->id,
            'grading_period'  => 1,
            'stored_filename' => '../../.env',
            'columns' => [
                ['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20],
            ],
        ]);

        $response->assertSessionHasErrors('stored_filename');
    }

    public function test_preview_is_blocked_when_the_term_is_closed(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor($section->school_year);
        $this->actingAs($admin)->post('/admin/academic-terms/1/close');

        $response = $this->actingAs($adviser)->post('/adviser/assessments/preview', [
            'subject_id'      => $subject->id,
            'grading_period'  => 1,
            'stored_filename' => '11111111-1111-1111-1111-111111111111.csv',
            'columns' => [
                ['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20],
            ],
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

    public function test_a_max_row_prefills_the_verify_screen_and_the_upload_still_imports(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000021']);

        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv(
            "lrn,last_name,first_name,Quiz 1,Final Exam\n" .
            "MAX,,,20,50\n" .
            "100000000021,Dela Cruz,Juan,18,45\n"
        );

        $detectResponse = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $file,
        ]);

        $detectResponse->assertOk();
        $detectResponse->assertViewHas('maxRowPresent', true);
        $detectResponse->assertViewHas('columns', function ($columns) {
            $byName = array_column($columns, 'file_max_score', 'name');
            return $byName['Quiz 1'] === 20.0 && $byName['Final Exam'] === 50.0;
        });
        // Rendered fields carry the prefilled value straight from the file.
        $detectResponse->assertSee('value="20"', false);
        $detectResponse->assertSee('value="50"', false);
        $detectResponse->assertSee('from file');

        $storedFilename = $detectResponse->viewData('storedFilename');
        $confirmedColumns = [
            ['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20],
            ['name' => 'Final Exam', 'component' => 'examination', 'max_score' => 50],
        ];

        $importResponse = $this->actingAs($adviser)->post('/adviser/assessments/import', [
            'subject_id'      => $subject->id,
            'grading_period'  => 1,
            'stored_filename' => $storedFilename,
            'original_filename' => 'assessment.csv',
            'columns' => $confirmedColumns,
        ]);

        $importResponse->assertSessionHas('success');
        // The MAX row itself must never be treated as a student.
        $this->assertDatabaseMissing('assessment_scores', ['student_id' => null]);
        $this->assertDatabaseHas('assessment_scores', ['student_id' => $student->id, 'score' => 18]);
        $this->assertDatabaseHas('assessment_scores', ['student_id' => $student->id, 'score' => 45]);
        $this->assertDatabaseCount('assessment_scores', 2);
    }

    public function test_a_file_without_a_max_row_leaves_the_verify_fields_empty(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv("lrn,last_name,first_name,Quiz 1\n100000000022,Dela Cruz,Juan,18\n");

        $detectResponse = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $file,
        ]);

        $detectResponse->assertOk();
        $detectResponse->assertViewHas('maxRowPresent', false);
        // "Column (from file)" is a pre-existing table header unrelated to
        // this feature — assert against the actual per-row provenance
        // label's markup instead of the bare phrase.
        $detectResponse->assertDontSee('mt-0.5">from file<', false);
    }

    public function test_an_invalid_max_row_value_blocks_the_upload_and_names_the_column(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);

        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv(
            "lrn,last_name,first_name,Quiz 1\n" .
            "MAX,,,abc\n" .
            "100000000023,Dela Cruz,Juan,18\n"
        );

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $file,
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Quiz 1', session('error'));
        $this->assertDatabaseCount('assessments', 0);
    }
}
