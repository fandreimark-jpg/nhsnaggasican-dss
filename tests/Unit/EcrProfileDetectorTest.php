<?php

namespace Tests\Unit;

use App\Services\EcrProfileDetector;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 5a — recognises the official DepEd
 * workbook by three marks together (sheet name set, HELPER!B4, INPUT
 * DATA!T64). Tested against the REAL checked-in blank template, not a
 * mock — the whole point of "verified directly," not assumed.
 */
class EcrProfileDetectorTest extends TestCase
{
    private function realFixturePath(): string
    {
        return base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx');
    }

    public function test_the_real_workbook_is_detected_with_its_actual_version_tag(): void
    {
        $version = (new EcrProfileDetector())->detect($this->realFixturePath());

        $this->assertSame('2026_v1.0', $version);
    }

    public function test_a_flat_csv_is_not_detected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'flat_') . '.csv';
        file_put_contents($path, "lrn,last_name,first_name,Quiz 1\n110000000001,Doe,Jane,18\n");

        $version = (new EcrProfileDetector())->detect($path);
        @unlink($path);

        $this->assertNull($version);
    }

    public function test_a_nonexistent_file_returns_null_rather_than_throwing(): void
    {
        $version = (new EcrProfileDetector())->detect('/no/such/file.xlsx');

        $this->assertNull($version);
    }

    public function test_an_xlsx_with_the_right_sheet_names_but_no_marker_is_not_detected(): void
    {
        // A file that merely LOOKS like it by sheet name alone must not
        // pass -- all three marks are required together.
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $names = ['INSTRUCTIONS', 'INPUT DATA', 'Term 1', 'Term 2', 'Term 3', 'FINAL GRADES', 'HELPER'];
        foreach ($names as $i => $name) {
            $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($name);
        }

        $path = tempnam(sys_get_temp_dir(), 'fakeecr_') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $version = (new EcrProfileDetector())->detect($path);
        @unlink($path);

        $this->assertNull($version);
    }
}
