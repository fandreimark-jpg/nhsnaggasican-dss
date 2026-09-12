<?php

namespace Tests\Feature;

use App\Services\HistoricalEcrFormatDetector;
use Tests\TestCase;

/**
 * "ML architecture preparation" pass, Phase 6 — proves format detection
 * for the future historical-dataset importer is real (against the two
 * genuine fixtures already used to prove the live grading path's own
 * detectors), extensible (named identifiers exist for formats with no
 * detector yet), and safe (an unrecognised file never guesses a format).
 */
class HistoricalEcrFormatDetectorTest extends TestCase
{
    private HistoricalEcrFormatDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new HistoricalEcrFormatDetector();
    }

    public function test_the_real_strengthened_shs_fixture_is_detected(): void
    {
        $path = base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx');

        $this->assertSame(HistoricalEcrFormatDetector::STRENGTHENED_SHS_ECR, $this->detector->detect($path));
    }

    public function test_the_real_grade_12_fixture_is_detected(): void
    {
        $path = base_path('tests/Fixtures/GRADE-12-SANITIZED.xlsx');

        $this->assertSame(HistoricalEcrFormatDetector::GRADE12_CLASS_RECORD, $this->detector->detect($path));
    }

    public function test_an_unrecognised_workbook_is_reported_unsupported_not_guessed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'unrelated_workbook_') . '.xlsx';
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Sheet1');
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Not an ECR of any kind');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $format = $this->detector->detect($path);
        @unlink($path);

        $this->assertSame(HistoricalEcrFormatDetector::UNSUPPORTED, $format);
        $this->assertSame('Unsupported historical ECR format', $this->detector->describe($format));
    }

    public function test_an_unreadable_file_is_unsupported_not_a_crash(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'garbage_') . '.xlsx';
        file_put_contents($path, 'this is not a real spreadsheet file at all');

        $format = $this->detector->detect($path);
        @unlink($path);

        $this->assertSame(HistoricalEcrFormatDetector::UNSUPPORTED, $format);
    }

    public function test_quarterly_and_three_term_are_named_future_formats_not_silently_absent(): void
    {
        $this->assertContains(HistoricalEcrFormatDetector::QUARTERLY_ECR, HistoricalEcrFormatDetector::PLANNED_FORMATS);
        $this->assertContains(HistoricalEcrFormatDetector::THREE_TERM_ECR, HistoricalEcrFormatDetector::PLANNED_FORMATS);
        $this->assertNotContains(HistoricalEcrFormatDetector::QUARTERLY_ECR, HistoricalEcrFormatDetector::IMPLEMENTED_FORMATS);
    }
}
