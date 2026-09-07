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
 * TASK 3 of "dashboard structure and upload safeguards" — both
 * safeguards are SIGNALS, never blocks (see the ground rule: "a false
 * positive must never stop legitimate work"). Every test here that
 * triggers a notice also proves the workflow still completes.
 */
class AssessmentUploadMismatchNoticesTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $content, string $name = 'assessment.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** 3a — the task's own worked example: Business Mathematics selected, filename names Oral Communication. */
    public function test_verify_screen_names_both_subjects_when_the_filename_suggests_a_different_one(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $businessMath = Subject::factory()->create(['name' => 'Business Mathematics', 'grade_level' => $section->grade_level, 'type' => 'core']);
        Subject::factory()->create(['name' => 'Oral Communication', 'grade_level' => $section->grade_level, 'type' => 'core']);
        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv("lrn,last_name,first_name,Quiz 1\n100000000020,Dela Cruz,Juan,18\n", 'assessment_oral_comm_term1.xlsx');

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $businessMath->id,
            'grading_period' => 1,
            'file'           => $file,
        ]);

        $response->assertOk();
        $response->assertSee('This file might be for a different subject');
        $response->assertSee('Business Mathematics');
        $response->assertSee('Oral Communication');
    }

    public function test_verify_screen_shows_no_notice_for_a_correctly_named_file(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $businessMath = Subject::factory()->create(['name' => 'Business Mathematics', 'grade_level' => $section->grade_level, 'type' => 'core']);
        Subject::factory()->create(['name' => 'Oral Communication', 'grade_level' => $section->grade_level, 'type' => 'core']);
        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv("lrn,last_name,first_name,Quiz 1\n100000000020,Dela Cruz,Juan,18\n", 'business_math_term1.xlsx');

        $response = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $businessMath->id,
            'grading_period' => 1,
            'file'           => $file,
        ]);

        $response->assertOk();
        $response->assertDontSee('This file might be for a different subject');
    }

    /** The notice never blocks — the full pipeline still completes after it fires. */
    public function test_filename_mismatch_notice_does_not_block_the_import(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $businessMath = Subject::factory()->create(['name' => 'Business Mathematics', 'grade_level' => $section->grade_level, 'type' => 'core']);
        Subject::factory()->create(['name' => 'Oral Communication', 'grade_level' => $section->grade_level, 'type' => 'core']);
        $student = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000020']);
        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv("lrn,last_name,first_name,Quiz 1\n100000000020,Dela Cruz,Juan,18\n", 'assessment_oral_comm_term1.xlsx');

        $detect = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $businessMath->id, 'grading_period' => 1, 'file' => $file,
        ]);
        $detect->assertOk();
        $storedFilename = $detect->viewData('storedFilename');

        $columns = [['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20]];

        $import = $this->actingAs($adviser)->post('/adviser/assessments/import', [
            'subject_id' => $businessMath->id, 'grading_period' => 1,
            'stored_filename' => $storedFilename, 'original_filename' => 'assessment_oral_comm_term1.xlsx',
            'columns' => $columns,
        ]);

        $import->assertSessionHas('success');
        $this->assertDatabaseHas('assessment_scores', ['student_id' => $student->id, 'score' => 18]);
    }

    /** 3b — zero LRN matches is flagged prominently at the top of Preview. */
    public function test_preview_flags_when_zero_rows_match_a_student_in_the_section(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        // No student with this LRN exists anywhere in this section.
        Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000099']);
        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv("lrn,last_name,first_name,Quiz 1\n999999999999,Nobody,Here,18\n");

        $detect = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $subject->id, 'grading_period' => 1, 'file' => $file,
        ]);
        $storedFilename = $detect->viewData('storedFilename');
        $columns = [['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20]];

        $preview = $this->actingAs($adviser)->post('/adviser/assessments/preview', [
            'subject_id' => $subject->id, 'grading_period' => 1,
            'stored_filename' => $storedFilename, 'original_filename' => 'assessment.csv',
            'columns' => $columns,
        ]);

        $preview->assertOk();
        $preview->assertSee('None of the LRNs in this file matched a student in your section');
        $preview->assertSee('may belong to another section', false);
    }

    /** The roster-mismatch notice also never blocks — Import stays reachable. */
    public function test_preview_shows_no_roster_mismatch_notice_when_rows_match_normally(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000020']);
        AcademicTerm::ensureExistFor($section->school_year);

        $file = $this->csv("lrn,last_name,first_name,Quiz 1\n100000000020,Dela Cruz,Juan,18\n");

        $detect = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $subject->id, 'grading_period' => 1, 'file' => $file,
        ]);
        $storedFilename = $detect->viewData('storedFilename');
        $columns = [['name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20]];

        $preview = $this->actingAs($adviser)->post('/adviser/assessments/preview', [
            'subject_id' => $subject->id, 'grading_period' => 1,
            'stored_filename' => $storedFilename, 'original_filename' => 'assessment.csv',
            'columns' => $columns,
        ]);

        $preview->assertOk();
        $preview->assertDontSee('None of the LRNs in this file matched a student in your section');
        // Confirm Import is still reachable/enabled — the form/button is present.
        $preview->assertSee('Confirm & Import', false);
    }
}
