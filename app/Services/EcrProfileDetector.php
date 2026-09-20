<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * "ECR alignment" work order, PART 5a — recognises the official DepEd
 * Strengthened SHS Electronic Class Record workbook by three marks
 * TOGETHER, verified directly against the real instrument
 * (tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx), not guessed:
 *
 *  1. The sheet name set: INSTRUCTIONS, INPUT DATA, Term 1, Term 2, Term 3,
 *     FINAL GRADES, HELPER (order not required — checked by name).
 *  2. HELPER!B4 literally reads "ECRSHS2026".
 *  3. INPUT DATA!T64 carries a non-blank version tag (e.g. "2026_v1.0").
 *
 * If any of the three is absent, this returns null and the caller falls
 * through to the existing flat lrn/last_name/first_name/item… reader
 * completely unchanged — this class never throws for "not an ECR file,"
 * only for a genuinely unreadable one, which also resolves to null.
 *
 * Deliberately cheap: `listWorksheetNames()` reads the sheet index without
 * loading any cell data, and `setLoadSheetsOnly()` restricts the one real
 * load to just the two sheets the two remaining marks live on — a 7-sheet,
 * multi-hundred-row workbook is never fully parsed just to answer "is this
 * an ECR file."
 */
class EcrProfileDetector
{
    private const REQUIRED_SHEETS = ['INSTRUCTIONS', 'INPUT DATA', 'Term 1', 'Term 2', 'Term 3', 'FINAL GRADES', 'HELPER'];

    private const HELPER_MARKER_CELL = 'B4';
    private const HELPER_MARKER_VALUE = 'ECRSHS2026';
    private const VERSION_TAG_CELL = 'T64';

    /**
     * "Performance audit" pass — detection results, keyed by the file's
     * CONTENT (md5) rather than its path, so the three services that each
     * ask this question about the same upload in one request (the term
     * resolver, the upload service, EcrReaderService::describe()) share one
     * answer. Content-addressed so a temp path reused for a different
     * workbook can never return the previous file's verdict. md5 of the
     * 434KB instrument costs ~2ms; the load it replaces costs ~400ms.
     *
     * @var array<string, ?string>
     */
    private static array $verdictByContent = [];

    /** Test hook: forget every memoised verdict. */
    public static function flushMemo(): void
    {
        self::$verdictByContent = [];
    }

    /** Null means "not this profile" (or unreadable) — never throws for that case. */
    public function detect(string $filePath): ?string
    {
        $contentKey = @md5_file($filePath);
        if ($contentKey !== false && array_key_exists($contentKey, self::$verdictByContent)) {
            return self::$verdictByContent[$contentKey];
        }

        $verdict = $this->detectUncached($filePath);

        if ($contentKey !== false) {
            // Bounded: a long-running process (the test suite) must not
            // accumulate one entry per fixture ever seen.
            if (count(self::$verdictByContent) >= 16) {
                array_shift(self::$verdictByContent);
            }
            self::$verdictByContent[$contentKey] = $verdict;
        }

        return $verdict;
    }

    private function detectUncached(string $filePath): ?string
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $sheetNames = $reader->listWorksheetNames($filePath);
        } catch (\Throwable $e) {
            return null;
        }

        foreach (self::REQUIRED_SHEETS as $required) {
            if (!in_array($required, $sheetNames, true)) {
                return null;
            }
        }

        try {
            $reader->setLoadSheetsOnly(['HELPER', 'INPUT DATA']);
            $reader->setReadDataOnly(true);
            // Only the two cells this method reads are materialised — the
            // HELPER catalog (141 rows of formulas) is otherwise the single
            // most expensive sheet in the workbook to load, and nothing
            // else on it is read here. The values read are unchanged.
            $reader->setReadFilter(new class implements IReadFilter {
                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return ($worksheetName === 'HELPER' && $columnAddress === 'B' && $row === 4)
                        || ($worksheetName === 'INPUT DATA' && $columnAddress === 'T' && $row === 64);
                }
            });
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable $e) {
            return null;
        }

        $helper = $spreadsheet->getSheetByName('HELPER');
        $marker = $helper ? trim((string) $helper->getCell(self::HELPER_MARKER_CELL)->getValue()) : '';
        if ($marker !== self::HELPER_MARKER_VALUE) {
            return null;
        }

        $inputData = $spreadsheet->getSheetByName('INPUT DATA');
        $version = $inputData ? trim((string) $inputData->getCell(self::VERSION_TAG_CELL)->getValue()) : '';

        return $version !== '' ? $version : null;
    }
}
