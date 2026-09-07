<?php

namespace Tests\Unit;

use App\Models\Section;
use App\Models\Student;
use App\Services\AssessmentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The optional MAX row: row 2 (immediately after the header) may carry
 * each column's maximum score, identified by the literal string "MAX"
 * (case-insensitive, trimmed) in column 0 — where an LRN would otherwise
 * be. Lets the max travel with the scores instead of being retyped by
 * hand on the Verify screen every single upload (see
 * AssessmentUploadService::detectColumns()/splitRows()/extractMaxRow()).
 */
class AssessmentMaxRowTest extends TestCase
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
        $path = tempnam(sys_get_temp_dir(), 'maxrow_') . '.csv';
        file_put_contents($path, $content);
        return $path;
    }

    public function test_a_max_row_is_parsed_and_excluded_from_the_student_count(): void
    {
        $path = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1,Quiz 2\n" .
            "MAX,,,20,15\n" .
            "110000000001,Agbayani,Rhea Mae,18,13\n" .
            "110000000002,Cruz,Juan,19,14\n"
        );

        $result = $this->service->detectColumns($path);

        $this->assertTrue($result['max_row_present']);
        $this->assertSame(2, $result['row_count'], 'The MAX row must not be counted as a student row.');

        $maxByName = array_column($result['columns'], 'file_max_score', 'name');
        $this->assertSame(20.0, $maxByName['Quiz 1']);
        $this->assertSame(15.0, $maxByName['Quiz 2']);

        @unlink($path);
    }

    public function test_a_file_without_a_max_row_leaves_every_file_max_score_null(): void
    {
        $path = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1,Quiz 2\n" .
            "110000000001,Agbayani,Rhea Mae,18,13\n" .
            "110000000002,Cruz,Juan,19,14\n"
        );

        $result = $this->service->detectColumns($path);

        $this->assertFalse($result['max_row_present']);
        $this->assertSame(2, $result['row_count'], 'Student count must be unchanged from before this feature existed.');

        foreach ($result['columns'] as $col) {
            $this->assertNull($col['file_max_score']);
        }

        @unlink($path);
    }

    public function test_the_max_sentinel_is_recognised_case_insensitively_and_with_surrounding_whitespace(): void
    {
        $lowercase = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1\n" .
            "max,,,20\n" .
            "110000000001,Agbayani,Rhea Mae,18\n"
        );
        $whitespace = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1\n" .
            "  MAX  ,,,20\n" .
            "110000000001,Agbayani,Rhea Mae,18\n"
        );

        $lowerResult = $this->service->detectColumns($lowercase);
        $whitespaceResult = $this->service->detectColumns($whitespace);

        $this->assertTrue($lowerResult['max_row_present']);
        $this->assertSame(1, $lowerResult['row_count']);
        $this->assertSame(20.0, $lowerResult['columns'][0]['file_max_score']);

        $this->assertTrue($whitespaceResult['max_row_present']);
        $this->assertSame(1, $whitespaceResult['row_count']);
        $this->assertSame(20.0, $whitespaceResult['columns'][0]['file_max_score']);

        @unlink($lowercase);
        @unlink($whitespace);
    }

    public function test_a_blank_cell_in_the_max_row_leaves_only_that_column_null(): void
    {
        $path = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1,Quiz 2\n" .
            "MAX,,,20,\n" .
            "110000000001,Agbayani,Rhea Mae,18,13\n"
        );

        $result = $this->service->detectColumns($path);

        $maxByName = array_column($result['columns'], 'file_max_score', 'name');
        $this->assertSame(20.0, $maxByName['Quiz 1']);
        $this->assertNull($maxByName['Quiz 2']);
        $this->assertEmpty($result['max_row_errors'], 'A blank MAX cell is "not supplied", not an error.');

        @unlink($path);
    }

    public function test_a_non_numeric_max_row_value_blocks_the_upload_naming_the_column(): void
    {
        $path = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1,Quiz 2\n" .
            "MAX,,,20,abc\n" .
            "110000000001,Agbayani,Rhea Mae,18,13\n"
        );

        $result = $this->service->detectColumns($path);

        $this->assertArrayHasKey('Quiz 2', $result['max_row_errors']);
        $this->assertSame('abc', $result['max_row_errors']['Quiz 2']);
        $this->assertArrayNotHasKey('Quiz 1', $result['max_row_errors']);

        @unlink($path);
    }

    public function test_a_zero_max_row_value_blocks_the_upload_naming_the_column(): void
    {
        // A zero max is exactly as dangerous as a non-numeric one — every
        // score would divide by zero or read as "exceeds max" — so it must
        // be blocked the same way, not silently accepted as "no max".
        $path = $this->csvPath(
            "lrn,last_name,first_name,Quiz 1\n" .
            "MAX,,,0\n" .
            "110000000001,Agbayani,Rhea Mae,18\n"
        );

        $result = $this->service->detectColumns($path);

        $this->assertArrayHasKey('Quiz 1', $result['max_row_errors']);
        $this->assertSame('0', $result['max_row_errors']['Quiz 1']);

        @unlink($path);
    }

    /**
     * The MAX-row sentinel only works because a real student can never
     * collide with it: LRN is validated as exactly 12 digits everywhere a
     * student is created, so the literal text "MAX" is rejected long
     * before it could ever reach the assessment-upload path and be
     * mistaken for a max-score row instead of a student.
     */
    public function test_a_student_cannot_be_created_with_the_literal_lrn_max(): void
    {
        $section = Section::factory()->create();

        $student = Student::factory()->make(['lrn' => 'MAX', 'section_id' => $section->id]);
        $validator = validator($student->toArray(), ['lrn' => 'required|digits:12']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('lrn', $validator->errors()->toArray());
    }
}
