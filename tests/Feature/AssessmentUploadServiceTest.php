<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentUpload;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AssessmentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drives AssessmentUploadService's detectColumns()/import() directly
 * against a real temporary CSV file on disk (PhpSpreadsheet's IOFactory
 * needs an actual file path, unlike GradesImport's ToCollection which
 * takes an in-memory Collection) — this is the fast, non-HTTP path;
 * AssessmentUploadWorkflowTest covers the full controller round trip.
 */
class AssessmentUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    private AssessmentUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssessmentUploadService();
    }

    private function csvPath(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'assess_') . '.csv';
        file_put_contents($path, $content);
        return $path;
    }

    public function test_detect_columns_guesses_components_and_ignores_identity_columns(): void
    {
        $path = $this->csvPath("lrn,last_name,first_name,Quiz 1,Performance Task 1,Final Exam,Unrecognized Column\n");

        $result = $this->service->detectColumns($path);

        $names = array_column($result['columns'], 'name');
        $this->assertSame(['Quiz 1', 'Performance Task 1', 'Final Exam', 'Unrecognized Column'], $names);

        $guesses = array_column($result['columns'], 'guessed_component', 'name');
        $this->assertSame('written_work', $guesses['Quiz 1']);
        $this->assertSame('performance_task', $guesses['Performance Task 1']);
        $this->assertSame('examination', $guesses['Final Exam']);
        $this->assertNull($guesses['Unrecognized Column']);

        @unlink($path);
    }

    public function test_import_creates_assessment_items_and_scores(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000010']);
        $upload  = AssessmentUpload::factory()->create();

        $path = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1\n" .
            "100000000010,Dela Cruz,Juan,18\n"
        );

        $result = $this->service->import(
            $path,
            ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]],
            $section, $subject, 1, '2026-2027', $adviser->id, $upload
        );

        $this->assertSame(1, $result['imported']);
        $this->assertEmpty($result['errors']);

        $assessment = Assessment::where('name', 'Quiz 1')->firstOrFail();
        $this->assertSame('written_work', $assessment->component);
        $this->assertEquals(20.0, (float) $assessment->max_score);
        $this->assertSame((string) $upload->id, $assessment->import_batch_id);

        $this->assertDatabaseHas('assessment_scores', [
            'assessment_id' => $assessment->id,
            'student_id'    => $student->id,
            'score'         => 18.00,
        ]);

        @unlink($path);
    }

    public function test_unknown_lrn_is_reported_as_an_error_and_not_imported(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $upload  = AssessmentUpload::factory()->create();

        $path = $this->csvPath("lrn,last_name,first_name,Quiz 1\n999999999999,Nobody,Here,18\n");

        $result = $this->service->import(
            $path, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]],
            $section, $subject, 1, $section->school_year, $adviser->id, $upload
        );

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('999999999999', $result['errors'][0]);

        @unlink($path);
    }

    public function test_score_exceeding_max_score_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000011']);
        $upload  = AssessmentUpload::factory()->create();

        $path = $this->csvPath("lrn,last_name,first_name,Quiz 1\n100000000011,Dela Cruz,Juan,25\n");

        $result = $this->service->import(
            $path, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]],
            $section, $subject, 1, $section->school_year, $adviser->id, $upload
        );

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertDatabaseMissing('assessment_scores', ['student_id' => $student->id]);

        @unlink($path);
    }

    public function test_non_numeric_score_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000012']);
        $upload  = AssessmentUpload::factory()->create();

        $path = $this->csvPath("lrn,last_name,first_name,Quiz 1\n100000000012,Dela Cruz,Juan,abc\n");

        $result = $this->service->import(
            $path, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]],
            $section, $subject, 1, $section->school_year, $adviser->id, $upload
        );

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);

        @unlink($path);
    }

    public function test_blank_score_cell_is_skipped_silently_not_an_error(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000013']);
        $upload  = AssessmentUpload::factory()->create();

        $path = $this->csvPath("lrn,last_name,first_name,Quiz 1\n100000000013,Dela Cruz,Juan,\n");

        $result = $this->service->import(
            $path, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]],
            $section, $subject, 1, $section->school_year, $adviser->id, $upload
        );

        $this->assertSame(0, $result['imported']);
        $this->assertEmpty($result['errors']);

        @unlink($path);
    }

    public function test_duplicate_lrn_within_the_same_file_is_reported_and_only_first_used(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000014']);
        $upload  = AssessmentUpload::factory()->create();

        $path = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1\n" .
            "100000000014,Dela Cruz,Juan,18\n" .
            "100000000014,Dela Cruz,Juan,10\n"
        );

        $result = $this->service->import(
            $path, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]],
            $section, $subject, 1, $section->school_year, $adviser->id, $upload
        );

        $this->assertSame(1, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertDatabaseHas('assessment_scores', ['student_id' => $student->id, 'score' => 18.00]);

        @unlink($path);
    }

    public function test_preview_rows_reports_validity_without_writing_anything(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $goodStudent = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000020']);

        $path = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1\n" .
            "100000000020,Dela Cruz,Juan,18\n" .   // valid
            "999999999999,Nobody,Here,15\n" .      // unmatched student
            "100000000020,Dela Cruz,Juan,10\n" .   // duplicate LRN
            "100000000020x,Bad,Row,999\n"          // will be treated as its own unmatched lrn
        );

        $preview = $this->service->previewRows($path, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]], $section);

        $this->assertSame(0, Assessment::count());
        $this->assertSame(0, AssessmentScore::count());

        $this->assertSame(4, $preview['total_rows']);
        // Both the original row and its duplicate resolve to the same real
        // student — 2 rows "matched," even though only the first is usable.
        $this->assertSame(2, $preview['matched_rows']);
        $this->assertSame('ok', $preview['rows'][0]['cells'][0]['status']);
        $this->assertSame('unmatched', $preview['rows'][1]['cells'][0]['status']);
        $this->assertSame('duplicate', $preview['rows'][2]['cells'][0]['status']);

        @unlink($path);
    }

    public function test_preview_rows_marks_a_blank_cell_without_counting_it_as_invalid(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000021']);

        $path = $this->csvPath("lrn,last_name,first_name,Quiz 1\n100000000021,Dela Cruz,Juan,\n");

        $preview = $this->service->previewRows($path, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]], $section);

        $this->assertSame('blank', $preview['rows'][0]['cells'][0]['status']);
        $this->assertSame(0, $preview['total_valid_cells']);
        $this->assertSame(0, $preview['total_invalid_cells']);

        @unlink($path);
    }

    public function test_re_importing_the_same_column_updates_rather_than_duplicates(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000015']);
        $upload  = AssessmentUpload::factory()->create();

        $path = $this->csvPath("lrn,last_name,first_name,Quiz 1\n100000000015,Dela Cruz,Juan,15\n");
        $this->service->import(
            $path, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]],
            $section, $subject, 1, $section->school_year, $adviser->id, $upload
        );

        $path2 = $this->csvPath("lrn,last_name,first_name,Quiz 1\n100000000015,Dela Cruz,Juan,19\n");
        $this->service->import(
            $path2, ['Quiz 1' => ['component' => 'written_work', 'max_score' => 20]],
            $section, $subject, 1, $section->school_year, $adviser->id, $upload
        );

        $this->assertSame(1, Assessment::where('name', 'Quiz 1')->count());
        $this->assertSame(1, AssessmentScore::where('student_id', $student->id)->count());
        $this->assertDatabaseHas('assessment_scores', ['student_id' => $student->id, 'score' => 19.00]);

        @unlink($path);
        @unlink($path2);
    }
}
