<?php

namespace Tests\Feature;

use App\Models\DepedSubjectCatalog;
use App\Models\Subject;
use App\Services\EcrReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEcrFixture;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 5b/5c/5d — every fixture here is a real
 * copy of the checked-in official blank template (tests/Fixtures/SSHS-
 * E-Class-Record-SY-2026-2027.xlsx) with specific cells filled in, never a
 * hand-built approximation — see BuildsEcrFixture. Nothing in this file
 * touches a live import; toFlatRows() only ever returns an array.
 */
class EcrReaderServiceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEcrFixture;

    private EcrReaderService $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new EcrReaderService();
    }

    public function test_roster_and_scores_translate_into_the_flat_shape(): void
    {
        $path = $this->buildFilledEcrCopy(
            roster: [
                ['lrn' => '110000000001', 'name' => 'Cruz, Juan Miguel', 'gender' => 'male'],
                ['lrn' => '110000000002', 'name' => 'Reyes, Maria Clara', 'gender' => 'female'],
            ],
            scores: [
                'D' => [$this->maleTermRow(0) => 18, $this->femaleTermRow(0) => 20],
                'Q' => [$this->maleTermRow(0) => 45, $this->femaleTermRow(0) => 50],
                'AD' => [$this->maleTermRow(0) => 27, $this->femaleTermRow(0) => 30],
            ],
            maxScores: ['D' => 20, 'Q' => 50, 'AD' => 30],
        );

        $rows = $this->reader->toFlatRows($path, 1);
        @unlink($path);

        $this->assertSame(['lrn', 'last_name', 'first_name', 'Written Work 1', 'Performance Task 1', 'Summative Test 1'], $rows[0]);
        $this->assertSame(['MAX', '', '', 20.0, 50.0, 30.0], $rows[1]);

        $this->assertSame(['110000000001', 'Cruz', 'Juan Miguel', 18, 45, 27], $rows[2]);
        $this->assertSame(['110000000002', 'Reyes', 'Maria Clara', 20, 50, 30], $rows[3]);
    }

    public function test_a_column_with_no_score_in_any_learner_row_is_skipped_not_emitted_blank(): void
    {
        $path = $this->buildFilledEcrCopy(
            roster: [
                ['lrn' => '110000000001', 'name' => 'Cruz, Juan Miguel', 'gender' => 'male'],
            ],
            // Only WW item 1 (D) is ever scored -- items 2-10 (E..M) never
            // receive a score for anyone, so they must not appear at all.
            scores: [
                'D' => [$this->maleTermRow(0) => 15],
            ],
            maxScores: ['D' => 20],
        );

        $rows = $this->reader->toFlatRows($path, 1);
        $skipped = $this->reader->skippedItemCounts();
        @unlink($path);

        $this->assertSame(['lrn', 'last_name', 'first_name', 'Written Work 1'], $rows[0]);
        $this->assertSame(9, $skipped['written_work'], 'The other 9 WW slots were never scored and must be skipped.');
        $this->assertSame(10, $skipped['performance_task']);
        $this->assertSame(3, $skipped['examination']);
    }

    public function test_item_numbering_is_preserved_not_compacted_when_a_middle_item_is_skipped(): void
    {
        $path = $this->buildFilledEcrCopy(
            roster: [
                ['lrn' => '110000000001', 'name' => 'Cruz, Juan Miguel', 'gender' => 'male'],
            ],
            // WW items 1 and 3 used, item 2 (E) never scored -- the output
            // must read "Written Work 1" then "Written Work 3", not
            // renumber item 3 down to "Written Work 2".
            scores: [
                'D' => [$this->maleTermRow(0) => 10],
                'F' => [$this->maleTermRow(0) => 12],
            ],
            maxScores: ['D' => 20, 'F' => 20],
        );

        $rows = $this->reader->toFlatRows($path, 1);
        @unlink($path);

        $this->assertSame(['lrn', 'last_name', 'first_name', 'Written Work 1', 'Written Work 3'], $rows[0]);
    }

    public function test_emitted_column_names_are_recognised_by_the_existing_classifier_unchanged(): void
    {
        // The whole point of translating into the flat shape is that the
        // EXISTING AssessmentColumnClassifier, completely untouched,
        // correctly classifies every emitted column name -- proving the
        // integration needs no ECR-specific special-casing downstream.
        $classifier = new \App\Services\AssessmentColumnClassifier();

        $this->assertSame('written_work', $classifier->classify('Written Work 1'));
        $this->assertSame('performance_task', $classifier->classify('Performance Task 3'));
        $this->assertSame('examination', $classifier->classify('Summative Test 1'));
        $this->assertSame('st1', $classifier->classifyExamRole('Summative Test 1'));
        $this->assertSame('examination', $classifier->classify('Summative Test 2'));
        $this->assertSame('st2', $classifier->classifyExamRole('Summative Test 2'));
        $this->assertSame('examination', $classifier->classify('Term Exam'));
        $this->assertSame('term_exam', $classifier->classifyExamRole('Term Exam'));
    }

    public function test_a_missing_max_score_for_a_used_column_is_treated_as_not_supplied_not_an_error(): void
    {
        $path = $this->buildFilledEcrCopy(
            roster: [
                ['lrn' => '110000000001', 'name' => 'Cruz, Juan Miguel', 'gender' => 'male'],
            ],
            scores: ['D' => [$this->maleTermRow(0) => 15]],
            maxScores: [], // deliberately not supplied
        );

        $rows = $this->reader->toFlatRows($path, 1);
        @unlink($path);

        // MAX row's one item cell is blank, not zero, not an error --
        // same "not supplied" contract as the flat CSV's own MAX row.
        $this->assertSame('', $rows[1][3]);
    }

    public function test_weight_mismatch_names_both_figures_and_never_overwrites(): void
    {
        $path = $this->buildFilledEcrCopy(
            subjectCategory: 'SSHS - TECH-PRO',
            cluster: 'ICT SUPPORT AND COMPUTER PROGRAMMING TECHNOLOGIES',
            courseTitle: 'Broadband Installation',
        );

        $subject = Subject::factory()->create(['subject_group' => 'core_academic', 'catalog_id' => null]);

        $warning = $this->reader->checkWeightMismatch($path, $subject);
        @unlink($path);

        $this->assertNotNull($warning);
        $this->assertStringContainsString('Broadband Installation', $warning);
        $this->assertStringContainsString('15', $warning); // catalog's real ww_weight
        $this->assertStringContainsString('20', $warning); // core_academic's ww_weight

        // Never overwrites -- the subject's own stored values are untouched.
        $this->assertSame('core_academic', $subject->fresh()->subject_group);
        $this->assertNull($subject->fresh()->catalog_id);
    }

    public function test_weight_agreement_produces_no_warning(): void
    {
        $path = $this->buildFilledEcrCopy(
            subjectCategory: 'SSHS - CORE',
            cluster: 'CORE',
            courseTitle: 'General Mathematics',
        );

        // core_academic (20/50/30) genuinely matches General Mathematics'
        // real catalog row -- see Part 2's own verification of this exact pair.
        $subject = Subject::factory()->create(['subject_group' => 'core_academic', 'catalog_id' => null]);

        $warning = $this->reader->checkWeightMismatch($path, $subject);
        @unlink($path);

        $this->assertNull($warning);
    }

    public function test_other_elective_is_named_as_the_one_documented_exception(): void
    {
        $path = $this->buildFilledEcrCopy(
            subjectCategory: 'SSHS - ACADEMIC',
            cluster: 'OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM',
            courseTitle: 'Ignored For Other Elective',
        );

        // Write the teacher-typed weights directly -- buildFilledEcrCopy()
        // doesn't expose F43-F48, so set them here on the same loaded copy.
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $inputData = $spreadsheet->getSheetByName('INPUT DATA');
        $inputData->setCellValue('F39', 'Basic Robotics');
        $inputData->setCellValue('F43', 20);
        $inputData->setCellValue('F44', 60);
        $inputData->setCellValue('F45', 20);
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);

        $subject = Subject::factory()->create(['subject_group' => 'core_academic']);

        $warning = $this->reader->checkWeightMismatch($path, $subject);
        @unlink($path);

        $this->assertNotNull($warning);
        $this->assertStringContainsString('OTHER ELECTIVE', $warning);
        $this->assertStringContainsString('no weight', $warning);
    }

    public function test_describe_reports_the_real_metadata(): void
    {
        $path = $this->buildFilledEcrCopy(
            gradeLevel: '11',
            sectionName: 'Shakespeare',
            subjectCategory: 'SSHS - CORE',
            cluster: 'CORE',
            courseTitle: 'General Mathematics',
            roster: [
                ['lrn' => '110000000001', 'name' => 'Cruz, Juan Miguel', 'gender' => 'male'],
                ['lrn' => '110000000002', 'name' => 'Reyes, Maria Clara', 'gender' => 'female'],
            ],
        );

        $report = $this->reader->describe($path);
        @unlink($path);

        $this->assertSame('2026_v1.0', $report['version']);
        $this->assertSame(11, $report['grade_level']);
        $this->assertSame('Shakespeare', $report['section_name']);
        $this->assertSame('CORE', $report['cluster']);
        $this->assertSame('General Mathematics', $report['course_title']);
        $this->assertFalse($report['is_other_elective']);
        $this->assertSame(2, $report['roster_count']);
    }
}
