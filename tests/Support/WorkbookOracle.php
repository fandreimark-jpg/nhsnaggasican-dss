<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * An INDEPENDENT oracle for the client workbook (tests/Fixtures/SSHS-E-Class-
 * Record-SY-2026-2027.xlsx): the workbook's OWN learner-row formulas in
 * sheet "Term 1" (N17, O17, P17, AA17, AB17, AC17, AG17:AI17, AJ17, AK17,
 * AL17), evaluated by PhpSpreadsheet's calculation engine — no code from
 * App\Services is involved.
 *
 * Inputs are written where a teacher would type them (row 14 HPS, row 17
 * scores; "M" for Missing, null for blank). The class configuration that
 * the workbook derives from INPUT DATA / HELPER lookups is written as
 * literals: C17 (learner name), D12/Q12/AD12 (weight / 100) and AG14:AI14
 * (exam shares; null = the workbook's "" for a type the subject does not
 * weigh). Those configured values are checked against HELPER separately
 * (WorkbookParityTest::test_helper_*).
 *
 * AM17 = XLOOKUP(AL17, HELPER!C7:C47, HELPER!D7:D47, , 1) — PhpSpreadsheet
 * 1.30 does not implement XLOOKUP (Functions::DUMMY), so termGrade()
 * reproduces match mode 1 ("exact match or the next larger item") over the
 * table values read from the same file.
 */
final class WorkbookOracle
{
    private static ?Spreadsheet $book = null;

    private static function book(): Spreadsheet
    {
        if (self::$book === null) {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setLoadSheetsOnly(['Term 1', 'HELPER']);
            self::$book = $reader->load(base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx'));
        }

        return self::$book;
    }

    /**
     * @param array{ww: float, pt: float, ex: ?float} $weights percentages (HELPER!W:Y); ex null = no Examination
     * @param array{st1: ?float, st2: ?float, term_exam: ?float} $shares HELPER!AA:AC; null = ""
     * @param list<array{0: float, 1: float|string|null}> $ww  [HPS, score|'M'|null] per WW slot (max 10)
     * @param list<array{0: float, 1: float|string|null}> $pt  same for PT
     * @param array<string, array{0: float, 1: float|string|null}> $exam role => [HPS, score|'M'|null]
     * @return array<string, mixed> cell => calculated value ("" = blank)
     */
    public static function row(array $weights, array $shares, array $ww, array $pt, array $exam = []): array
    {
        $book = self::book();
        $s = $book->getSheetByName('Term 1');

        // Clear every input of row 14 / 17 the formulas read.
        foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AD', 'AE', 'AF'] as $col) {
            $s->getCell($col . '14')->setValue(null);
            $s->getCell($col . '17')->setValue(null);
        }
        $s->getCell('C17')->setValue('LEARNER, TEST');
        $s->getCell('D12')->setValue($weights['ww'] / 100);
        $s->getCell('Q12')->setValue($weights['pt'] / 100);
        $s->getCell('AD12')->setValue($weights['ex'] === null ? 0 : $weights['ex'] / 100);
        foreach (['AG' => 'st1', 'AH' => 'st2', 'AI' => 'term_exam'] as $col => $role) {
            $s->getCell($col . '14')->setValue($shares[$role] ?? null);
        }

        self::fill($s, ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'], $ww);
        self::fill($s, ['Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'], $pt);
        foreach (['st1' => 'AD', 'st2' => 'AE', 'term_exam' => 'AF'] as $role => $col) {
            if (isset($exam[$role])) {
                $s->getCell($col . '14')->setValue($exam[$role][0]);
                $s->getCell($col . '17')->setValue($exam[$role][1]);
            }
        }

        Calculation::getInstance($book)->clearCalculationCache();
        $out = [];
        foreach (['N14', 'N17', 'O17', 'P17', 'AA14', 'AA17', 'AB17', 'AC17', 'AG17', 'AH17', 'AI17', 'AJ17', 'AK17', 'AL17'] as $cell) {
            $out[$cell] = $s->getCell($cell)->getCalculatedValue();
        }
        $out['AM17'] = self::termGrade($out['AL17']);

        return $out;
    }

    /** XLOOKUP(AL17, HELPER!$C$7:$C$47, HELPER!$D$7:$D$47, , 1): exact match or the next larger item. */
    public static function termGrade(mixed $initialGrade): mixed
    {
        if ($initialGrade === '' || $initialGrade === null) {
            return '';
        }
        $helper = self::book()->getSheetByName('HELPER');
        $best = null;
        for ($r = 7; $r <= 47; $r++) {
            $max = (float) $helper->getCell('C' . $r)->getValue();
            if ($max >= (float) $initialGrade && ($best === null || $max < $best[0])) {
                $best = [$max, (float) $helper->getCell('D' . $r)->getValue()];
            }
        }

        return $best === null ? '' : $best[1];
    }

    /**
     * The same lookup through a function the evaluator DOES implement:
     * INDEX(HELPER!D7:D47, MATCH(g, HELPER!C7:C47, -1)). MATCH type -1 = the
     * smallest value >= g in a DESCENDING column, which is XLOOKUP match mode 1
     * because C7:C47 is strictly descending. Evaluated by PhpSpreadsheet in the
     * HELPER sheet itself, over the stored maxima. The input is written with
     * its shortest round-trip representation, so a noisy double stays noisy.
     */
    public static function termGradeByMatch(float $initialGrade): mixed
    {
        $helper = self::book()->getSheetByName('HELPER');
        $cell = $helper->getCell('ZZ1');
        $cell->setValue('=INDEX($D$7:$D$47,MATCH(' . var_export($initialGrade, true) . ',$C$7:$C$47,-1))');
        Calculation::getInstance(self::book())->clearCalculationCache();
        $value = $cell->getCalculatedValue();
        $cell->setValue(null);

        return $value;
    }

    /** @return list<array{0: float, 1: float, 2: float}> HELPER!B7:D47 as [min, max, transmuted], raw stored values */
    public static function transmutationTable(): array
    {
        $helper = self::book()->getSheetByName('HELPER');
        $rows = [];
        for ($r = 7; $r <= 47; $r++) {
            $rows[] = [(float) $helper->getCell('B' . $r)->getValue(), (float) $helper->getCell('C' . $r)->getValue(), (float) $helper->getCell('D' . $r)->getValue()];
        }

        return $rows;
    }

    /** @return list<array{track: string, cluster: string, title: string, ww: mixed, pt: mixed, ex: mixed, st1: mixed, st2: mixed, te: mixed}> HELPER catalog rows */
    public static function helperCatalog(): array
    {
        $helper = self::book()->getSheetByName('HELPER');
        $rows = [];
        for ($r = 7; $r <= 400; $r++) {
            $title = $helper->getCell('L' . $r)->getValue();
            if ($title === null || $title === '') {
                break;
            }
            $v = fn($c) => $helper->getCell($c . $r)->getValue();
            $rows[] = ['track' => trim((string) $v('J')), 'cluster' => trim((string) $v('K')), 'title' => trim((string) $title),
                'ww' => $v('W'), 'pt' => $v('X'), 'ex' => $v('Y'), 'st1' => $v('AA'), 'st2' => $v('AB'), 'te' => $v('AC')];
        }

        return $rows;
    }

    private static function fill(Worksheet $s, array $cols, array $entries): void
    {
        if (count($entries) > count($cols)) {
            throw new \InvalidArgumentException('The workbook has ' . count($cols) . ' slots.');
        }
        foreach ($entries as $i => [$hps, $score]) {
            $s->getCell($cols[$i] . '14')->setValue($hps);
            $s->getCell($cols[$i] . '17')->setValue($score);
        }
    }
}
