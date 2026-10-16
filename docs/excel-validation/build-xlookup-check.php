<?php

/**
 * Builds docs/excel-validation/xlookup-sum-precision-check.xlsx — the ONE
 * question about the client workbook that cannot be settled without Excel.
 *
 *   php docs/excel-validation/build-xlookup-check.php
 *
 * The lookup table is HELPER!C7:D47 copied with its STORED values from
 * tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx. Each row adds three
 * Weighted Scores with SUM — exactly Term 1!AL17 = SUM(P17, AC17, AK17) —
 * and looks the result up exactly as Term 1!AM17 does. No values are
 * pre-calculated: Excel computes every formula when the file is opened.
 */

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// Round-trip float text: the stored maxima (e.g. 69.9899999999999) must not be
// written as their 14-digit display form (69.99).
ini_set('precision', '-1');

require __DIR__ . '/../../vendor/autoload.php';

$reader = IOFactory::createReader('Xlsx');
$reader->setLoadSheetsOnly(['HELPER']);
$helper = $reader->load(__DIR__ . '/../../tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx')->getSheetByName('HELPER');

$book = new Spreadsheet();
$sheet = $book->getActiveSheet()->setTitle('Check');
$sheet->setCellValue('A1', 'Excel check: does SUM of three 2-decimal Weighted Scores + XLOOKUP(…,1) over the stored band maxima give the same Term Grade as the exact 2-decimal Initial Grade?');
$sheet->setCellValue('A2', 'Open in Excel (Microsoft 365 / 2021+ for XLOOKUP), press Ctrl+Alt+F9, then read columns F, G, I and J. Do not edit cells.');
foreach (['A4' => 'Case', 'B4' => 'P17 (WW WS)', 'C4' => 'AC17 (PT WS)', 'D4' => 'AK17 (EX WS)', 'E4' => 'Exact Initial Grade',
          'F4' => 'AL17 =SUM(B:D)', 'G4' => 'AL17 = exact? (=F=E)', 'H4' => 'Term Grade of the exact value', 'I4' => 'AM17 =XLOOKUP(F, …, 1)',
          'J4' => '(F-E)*1E15 (binary noise)', 'K4' => 'System (exact decimal) Term Grade', 'L4' => 'Strict-IEEE evaluator Term Grade'] as $c => $v) {
    $sheet->setCellValue($c, $v);
}

// [label, P17, AC17, AK17, exact AL, system, strict evaluator]
$cases = [
    ['74.71 from 0 + 46 + 28.71 (the open case)', 0.00, 46.00, 28.71, 74.71, 78, 79],
    ['74.71 from 0.40 + 45 + 29.31 (exact sum)', 0.40, 45.00, 29.31, 74.71, 78, 78],
    ['69.99 from 0.20 + 40 + 29.79', 0.20, 40.00, 29.79, 69.99, 75, 75],
    ['69.99 from 0 + 40.50 + 29.49', 0.00, 40.50, 29.49, 69.99, 75, 75],
    ['71.17 from 14.60 + 50 + 6.57', 14.60, 50.00, 6.57, 71.17, 76, 76],
    ['70.00 from 0 + 40 + 30', 0.00, 40.00, 30.00, 70.00, 75, 75],
];
$r = 5;
foreach ($cases as [$label, $p, $ac, $ak, $exact, $system, $strict]) {
    $sheet->setCellValue("A{$r}", $label);
    foreach (['B' => $p, 'C' => $ac, 'D' => $ak, 'E' => $exact] as $c => $v) {
        $sheet->setCellValueExplicit("{$c}{$r}", $v, DataType::TYPE_NUMERIC);
    }
    $sheet->setCellValue("F{$r}", "=SUM(B{$r},C{$r},D{$r})");
    $sheet->setCellValue("G{$r}", "=F{$r}=E{$r}");
    $sheet->setCellValue("H{$r}", "=_xlfn.XLOOKUP(E{$r},\$N\$7:\$N\$47,\$O\$7:\$O\$47,,1)");
    $sheet->setCellValue("I{$r}", "=_xlfn.XLOOKUP(F{$r},\$N\$7:\$N\$47,\$O\$7:\$O\$47,,1)");
    $sheet->setCellValue("J{$r}", "=(F{$r}-E{$r})*1E+15");
    $sheet->setCellValue("K{$r}", $system);
    $sheet->setCellValue("L{$r}", $strict);
    $r++;
}

$sheet->setCellValue('N6', 'HELPER!C (stored max)');
$sheet->setCellValue('O6', 'HELPER!D');
for ($i = 7; $i <= 47; $i++) {
    // Copied as stored, e.g. C33 = 69.9899999999999 (prints 69.99).
    $sheet->setCellValueExplicit("N{$i}", (float) $helper->getCell("C{$i}")->getValue(), DataType::TYPE_NUMERIC);
    $sheet->setCellValueExplicit("O{$i}", (float) $helper->getCell("D{$i}")->getValue(), DataType::TYPE_NUMERIC);
}

$writer = IOFactory::createWriter($book, 'Xlsx');
$writer->setPreCalculateFormulas(false);
$writer->save(__DIR__ . '/xlookup-sum-precision-check.xlsx');
echo "Wrote docs/excel-validation/xlookup-sum-precision-check.xlsx\n";
