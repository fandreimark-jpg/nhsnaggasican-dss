<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Recognises the school's actual Grade 12 class-record workbook (real
 * instrument inspected directly: GRADE-12-AGILA.xlsx, provided by the
 * school — not guessed from documentation), the SAME "sheet-name-set
 * plus one marker cell" rigor EcrProfileDetector already uses for the
 * Strengthened SHS instrument, but this format has no published
 * version tag anywhere in the workbook (unlike HELPER!B4="ECRSHS2026"
 * on the SSHS file), so a structural marker cell is used instead.
 *
 * Required sheets: INPUT, TERM1, TERM2, TERM3, SUMMARY OF GRADES.
 * "Helper (Do Not Delete)" and "DO NOT DELETE" are real but NOT
 * required for detection — they hold generic subject-weight/
 * transmutation lookup tables the workbook's own formulas reference,
 * not this class's actual data, and a school could plausibly rename or
 * remove them without the file stopping being a real Grade 12 class
 * record.
 *
 * Marker: INPUT!B8 reads "LEARNERS' NAMES" (trimmed) -- confirmed
 * against the real file. Combined with the 5-sheet set, this is
 * specific enough that a false positive against some unrelated
 * workbook is very unlikely, matching EcrProfileDetector's own
 * standard.
 */
class Grade12EcrProfileDetector
{
    private const REQUIRED_SHEETS = ['INPUT', 'TERM1', 'TERM2', 'TERM3', 'SUMMARY OF GRADES'];

    private const MARKER_SHEET = 'INPUT';
    private const MARKER_CELL = 'B8';
    private const MARKER_VALUE = "LEARNERS' NAMES";

    public function detect(string $filePath): bool
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $sheetNames = $reader->listWorksheetNames($filePath);
        } catch (\Throwable $e) {
            return false;
        }

        foreach (self::REQUIRED_SHEETS as $required) {
            if (!in_array($required, $sheetNames, true)) {
                return false;
            }
        }

        try {
            $reader->setLoadSheetsOnly([self::MARKER_SHEET]);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable $e) {
            return false;
        }

        $sheet = $spreadsheet->getSheetByName(self::MARKER_SHEET);
        $marker = $sheet ? trim((string) $sheet->getCell(self::MARKER_CELL)->getValue()) : '';

        return $marker === self::MARKER_VALUE;
    }
}
