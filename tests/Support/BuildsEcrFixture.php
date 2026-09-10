<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * "ECR alignment" work order, PART 5 — test fixtures are built by loading
 * the REAL checked-in blank template (tests/Fixtures/SSHS-E-Class-Record-
 * SY-2026-2027.xlsx) and writing plain values into known cells, then saving
 * to a throwaway temp file. This guarantees every test runs against the
 * actual sheet names, merged-cell layout, and geometry of the real
 * instrument rather than a hand-built approximation that could silently
 * diverge from it. Never touches the live database — these are local temp
 * files only, cleaned up by the caller.
 */
trait BuildsEcrFixture
{
    /**
     * @param array<int, array{lrn: string, name: string, gender: string}> $roster "name" as "Last, First" — gender 'male'|'female'
     * @param array<string, array<int, ?float>> $scores keyed by Term sheet column letter (e.g. 'D', 'Q', 'AD') => [actual_row => score] — use maleTermRow()/femaleTermRow() to compute the row
     * @param array<string, float> $maxScores keyed by Term sheet column letter => highest possible score
     */
    protected function buildFilledEcrCopy(
        string $gradeLevel = '11',
        string $sectionName = 'Test Section',
        string $subjectCategory = 'SSHS - CORE',
        string $cluster = 'CORE',
        string $courseTitle = 'General Mathematics',
        array $roster = [],
        array $scores = [],
        array $maxScores = [],
        int $gradingPeriod = 1
    ): string {
        $sourcePath = base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx');

        // setReadDataOnly(true) is the difference between ~2s and ~42s to
        // load this workbook (it skips style parsing, not formulas/values)
        // -- without it, this single call alone was slow enough to make
        // the whole test file take minutes.
        $reader = IOFactory::createReaderForFile($sourcePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($sourcePath);

        $inputData = $spreadsheet->getSheetByName('INPUT DATA');
        $inputData->setCellValue('F24', $gradeLevel);
        $inputData->setCellValue('F25', $sectionName);
        $inputData->setCellValue('F28', $subjectCategory);
        $inputData->setCellValue('F29', $cluster);
        $inputData->setCellValue('F30', $courseTitle);

        $maleIndex = 0;
        $femaleIndex = 0;
        foreach ($roster as $student) {
            if ($student['gender'] === 'male') {
                $row = 11 + $maleIndex;
                $inputData->setCellValue('N' . $row, $student['lrn']);
                $inputData->setCellValueExplicit('O' . $row, $student['name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $maleIndex++;
            } else {
                $row = 11 + $femaleIndex;
                $inputData->setCellValue('R' . $row, $student['lrn']);
                $inputData->setCellValueExplicit('S' . $row, $student['name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $femaleIndex++;
            }
        }

        $termSheet = $spreadsheet->getSheetByName('Term ' . $gradingPeriod);

        foreach ($maxScores as $col => $max) {
            $termSheet->setCellValue($col . '14', $max);
        }

        foreach ($scores as $col => $byOffset) {
            foreach ($byOffset as $offset => $value) {
                if ($value === null) {
                    continue;
                }
                // Caller passes offsets already split by gender block via
                // the $maleFirstRow/$femaleFirstRow helpers below — this
                // method just writes wherever it's told.
                $termSheet->setCellValue($col . $offset, $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ecr_fixture_') . '.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);

        return $path;
    }

    protected function maleTermRow(int $zeroBasedIndex): int
    {
        return 17 + $zeroBasedIndex;
    }

    protected function femaleTermRow(int $zeroBasedIndex): int
    {
        return 68 + $zeroBasedIndex;
    }
}
