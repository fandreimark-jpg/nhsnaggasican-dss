<?php

namespace Tests\Unit;

use App\Services\Grade12EcrProfileDetector;
use Tests\TestCase;

/**
 * Recognises the school's actual Grade 12 class-record workbook by the
 * sheet-name set (INPUT, TERM1, TERM2, TERM3, SUMMARY OF GRADES) plus
 * INPUT!B8's marker text, tested against a fixture matching the real
 * checked-in instrument's structure (tests/Fixtures/GRADE-12-SANITIZED.xlsx
 * -- built with entirely fake names, never the real school file).
 */
class Grade12EcrProfileDetectorTest extends TestCase
{
    private function fixturePath(): string
    {
        return base_path('tests/Fixtures/GRADE-12-SANITIZED.xlsx');
    }

    public function test_the_real_shaped_workbook_is_detected(): void
    {
        $this->assertTrue((new Grade12EcrProfileDetector())->detect($this->fixturePath()));
    }

    public function test_the_strengthened_shs_workbook_is_not_detected_as_grade_12(): void
    {
        $sshsPath = base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx');

        $this->assertFalse((new Grade12EcrProfileDetector())->detect($sshsPath));
    }

    public function test_a_flat_csv_is_not_detected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'flat_') . '.csv';
        file_put_contents($path, "lrn,last_name,first_name,Quiz 1\n110000000001,Doe,Jane,18\n");

        $result = (new Grade12EcrProfileDetector())->detect($path);
        @unlink($path);

        $this->assertFalse($result);
    }

    public function test_a_nonexistent_file_returns_false_rather_than_throwing(): void
    {
        $this->assertFalse((new Grade12EcrProfileDetector())->detect('/no/such/file.xlsx'));
    }

    public function test_an_xlsx_with_the_right_sheet_names_but_no_marker_is_not_detected(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $names = ['INPUT', 'TERM1', 'TERM2', 'TERM3', 'SUMMARY OF GRADES'];
        foreach ($names as $i => $name) {
            $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($name);
        }

        $path = tempnam(sys_get_temp_dir(), 'fakegr12_') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $result = (new Grade12EcrProfileDetector())->detect($path);
        @unlink($path);

        $this->assertFalse($result);
    }

    public function test_missing_one_required_sheet_is_not_detected(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        // SUMMARY OF GRADES deliberately omitted.
        $names = ['INPUT', 'TERM1', 'TERM2', 'TERM3'];
        foreach ($names as $i => $name) {
            $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($name);
        }
        $spreadsheet->getSheetByName('INPUT')->setCellValue('B8', "LEARNERS' NAMES");

        $path = tempnam(sys_get_temp_dir(), 'incomplete_') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $result = (new Grade12EcrProfileDetector())->detect($path);
        @unlink($path);

        $this->assertFalse($result);
    }
}
