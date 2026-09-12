<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Services\Grade12EcrReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tested against tests/Fixtures/GRADE-12-SANITIZED.xlsx -- a synthetic
 * fixture matching the real GRADE-12-AGILA.xlsx's structure exactly
 * (sheet names, header rows, column layout, HPS row, analysis-grid
 * position) but with entirely fake names, never the real school file.
 */
class Grade12EcrReaderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(): string
    {
        return base_path('tests/Fixtures/GRADE-12-SANITIZED.xlsx');
    }

    private function makeSectionWithMatchingStudents(): Section
    {
        $section = Section::factory()->create(['name' => 'AGILA', 'grade_level' => 12]);

        // Names matching the fixture's fake roster exactly.
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ALPHA', 'first_name' => 'JUAN']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'BRAVO', 'first_name' => 'PEDRO']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'CHARLIE', 'first_name' => 'MARK']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'DELTA', 'first_name' => 'JOSE']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ECHO', 'first_name' => 'MARIA']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'FOXTROT', 'first_name' => 'ANA']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'GOLF', 'first_name' => 'ROSA']);

        return $section;
    }

    public function test_metadata_extraction_matches_the_real_workbooks_layout(): void
    {
        $meta = (new Grade12EcrReaderService())->extractMetadata($this->fixturePath());

        $this->assertSame('II', $meta['region']);
        $this->assertSame('2026-2027', $meta['school_year']);
        $this->assertSame('12-AGILA', $meta['grade_section']);
        $this->assertSame(12, $meta['grade_level']);
        $this->assertSame('AGILA', $meta['section_name']);
        $this->assertSame('COMMUNITY ENGAGEMENT SOLIDARITY AND CITIZENSHIP', $meta['subject']);
        $this->assertSame(7, $meta['roster_count']);
    }

    public function test_learners_are_matched_to_existing_students_by_normalized_name_never_a_fabricated_lrn(): void
    {
        $section = $this->makeSectionWithMatchingStudents();

        $matches = (new Grade12EcrReaderService())->matchLearners($this->fixturePath(), $section->id);

        $this->assertCount(7, $matches);
        foreach ($matches as $entry) {
            $this->assertNotNull($entry['student'], "Every fixture name has a matching student and must resolve: {$entry['name']}");
        }
    }

    public function test_an_unmatched_learner_name_is_reported_not_guessed(): void
    {
        // Only 6 of the 7 fixture names have a matching student -- GOLF, ROSA is missing.
        $section = Section::factory()->create(['name' => 'AGILA', 'grade_level' => 12]);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ALPHA', 'first_name' => 'JUAN']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'BRAVO', 'first_name' => 'PEDRO']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'CHARLIE', 'first_name' => 'MARK']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'DELTA', 'first_name' => 'JOSE']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ECHO', 'first_name' => 'MARIA']);
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'FOXTROT', 'first_name' => 'ANA']);

        $reader = new Grade12EcrReaderService();
        $matches = $reader->matchLearners($this->fixturePath(), $section->id);

        $goldEntry = collect($matches)->firstWhere('name', 'GOLF, ROSA MENDOZA');
        $this->assertNull($goldEntry['student']);
        $this->assertContains('GOLF, ROSA MENDOZA', $reader->unresolvedNames());
    }

    public function test_an_ambiguous_name_match_two_candidates_is_reported_not_guessed(): void
    {
        $section = $this->makeSectionWithMatchingStudents();
        // A second student who ALSO matches ALPHA/JUAN -- now ambiguous.
        Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ALPHA', 'first_name' => 'JUAN']);

        $reader = new Grade12EcrReaderService();
        $matches = $reader->matchLearners($this->fixturePath(), $section->id);

        $alphaEntry = collect($matches)->firstWhere('name', 'ALPHA, JUAN CRUZ');
        $this->assertNull($alphaEntry['student']);
        $this->assertContains('ALPHA, JUAN CRUZ', $reader->unresolvedNames());
    }

    public function test_component_weights_are_read_from_the_workbook_not_hardcoded(): void
    {
        $weights = (new Grade12EcrReaderService())->extractComponentWeights($this->fixturePath(), 1);

        $this->assertEquals(0.2, $weights['written_work']);
        $this->assertEquals(0.6, $weights['performance_task']);
        $this->assertEquals(0.2, $weights['examination']);
    }

    public function test_to_flat_rows_produces_a_max_row_and_only_matched_students(): void
    {
        $section = $this->makeSectionWithMatchingStudents();

        $rows = (new Grade12EcrReaderService())->toFlatRows($this->fixturePath(), 1, $section->id);

        $this->assertSame(['lrn', 'last_name', 'first_name', 'WW1', 'WW2', 'WW3', 'WW4', 'PT1', 'PT2', 'PT3', 'ST1', 'ST2', 'TE'], $rows[0]);
        $this->assertSame('MAX', $rows[1][0]);
        // WW1=100, WW2=50, WW3=25, WW4=100 (item 5/col J unused -- excluded entirely, not a blank column).
        $this->assertSame([100.0, 50.0, 25.0, 100.0], array_slice($rows[1], 3, 4));
        // header + MAX row + 7 matched students.
        $this->assertCount(9, $rows);
    }

    public function test_a_blank_score_cell_is_preserved_as_null_not_converted_to_zero(): void
    {
        $section = $this->makeSectionWithMatchingStudents();

        $rows = (new Grade12EcrReaderService())->toFlatRows($this->fixturePath(), 1, $section->id);

        // Student 1 (ALPHA, JUAN CRUZ) has a genuinely blank WW3 cell in the fixture.
        $alphaRow = collect($rows)->slice(2)->firstWhere(2, 'JUAN');
        $this->assertNotNull($alphaRow);
        $ww3Index = array_search('WW3', $rows[0]);
        $this->assertNull($alphaRow[$ww3Index], 'A blank WW3 cell must read as null, never 0.');
    }

    public function test_excel_computed_grades_are_captured_as_reference_values(): void
    {
        $section = $this->makeSectionWithMatchingStudents();

        $reference = (new Grade12EcrReaderService())->excelComputedGrades($this->fixturePath(), 1, $section->id);

        $alpha = collect($reference)->firstWhere('name', 'ALPHA, JUAN CRUZ');
        $this->assertSame(86.0, $alpha['term_grade']);
        $this->assertSame('Benchmarking', $alpha['descriptor']);
    }
}
