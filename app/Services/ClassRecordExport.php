<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "Download Excel Class Record" — a SYSTEM-GENERATED class record laid out
 * like the client's SSHS E-Class Record "Term N" sheet, clearly labelled as
 * a system export. It is NOT the official DepEd workbook: it carries no
 * formulas, no INPUT DATA / HELPER sheets and no school header fields the
 * system does not store (Region, Division, School ID, School Name).
 *
 * Grid (as the workbook's rows 11–14 and learner rows):
 *   WW slots 1..10 (+11, 12, … when more) | TOTAL | PS | WS
 *   PT slots 1..10 (+…)                   | TOTAL | PS | WS
 *   ST 1 | ST 2 | TE | (Other: items not in the workbook formula)
 *   WS ST1 | WS ST2 | WS TE | PS | WS | INITIAL GRADE | TERM GRADE
 * then, kept apart from the workbook grid: Initial Grade status (provisional
 * / running · partial), Official Grade, Official status, Evidence.
 * Learners in a MALE block then a FEMALE block by recorded gender.
 *
 * Every figure is ClassRecordService::build() — the record the page shows,
 * i.e. GradingEngine's workbook-parity figures — written as a VALUE.
 * Representation: a number (0 included) = recorded score; MISSING (text,
 * shaded) = recorded Missing; empty = blank (No Evidence Yet). INITIAL
 * GRADE holds a running figure only with its "running · partial" status
 * beside it; TERM GRADE is filled only for a complete grade; the official
 * grade is only ever a verified one. Every text cell is an explicit string
 * (formula-injection safe).
 */
class ClassRecordExport
{
    private const BAND_ROW = 11;
    private const SLOT_ROW = 13;
    private const NAME_ROW = 14;
    private const HPS_ROW = 15;
    private const FIRST_DATA_ROW = 16;

    public function build(array $record): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Term ' . $record['term']);
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $section = $record['section'];
        $subject = $record['subject'];
        $profile = $record['profile'];
        $cols = $record['columns'];
        $shares = $record['exam_shares'] ?? [];
        $weight = fn(string $c) => $profile[match ($c) { 'written_work' => 'ww_weight', 'performance_task' => 'pt_weight', default => 'ex_weight' }];

        $this->text($sheet, 'A1', 'SYSTEM-GENERATED CLASS RECORD — Naggasican NHS Decision Support System');
        $this->text($sheet, 'A2', 'Laid out like the SSHS E-Class Record "Term ' . $record['term'] . '" sheet. This is a system export, NOT the official DepEd E-Class Record workbook: it has no formulas, and its figures are the DSS grading engine\'s.');
        $this->text($sheet, 'A3', 'School Year: ' . $record['school_year'] . '    Grade Level: ' . $section->grade_level . '    Section: ' . $section->name
            . ($section->track ? '    Track: ' . $section->track->name : '') . '    Term: ' . $record['term']);
        $this->text($sheet, 'A4', 'Subject: ' . $subject->name . '    Teacher: ' . ($record['teacher']?->full_name ?? 'Not assigned'));
        $this->text($sheet, 'A5', 'Grading profile: ' . $profile['source_label'] . ' — WW ' . ClassRecordService::fmt($profile['ww_weight']) . '% / PT '
            . ClassRecordService::fmt($profile['pt_weight']) . '% / EX ' . ($profile['ex_weight'] === null ? 'none' : ClassRecordService::fmt($profile['ex_weight']) . '%')
            . ($shares ? '    Examination shares: ' . implode(' / ', array_map(fn($r, $v) => (ClassRecordService::EXAM_COLUMNS[$r] ?? $r) . ' ' . ClassRecordService::fmt($v), array_keys($shares), $shares)) : ''));
        $this->text($sheet, 'A6', 'Generated: ' . now()->format('Y-m-d H:i') . '    Not in this export: Region, Division, School ID, School Name (not stored in the system).');
        $this->text($sheet, 'A8', 'Legend: a number (including 0) = recorded score · MISSING = recorded Missing (earns 0; its HPS still counts) · empty = blank, No Evidence Yet.');
        $this->text($sheet, 'A9', 'INITIAL GRADE marked "running · partial" is not a Term Grade and never official. TERM GRADE appears only when every required component, and every Examination Type given, has a score. "Official" is a verified grade.');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('9A3412');

        foreach ([[1, 'No.'], [2, "LEARNERS' NAMES"], [3, 'LRN']] as [$c, $label]) {
            $this->text($sheet, [$c, self::SLOT_ROW], $label);
        }
        $this->text($sheet, [2, self::HPS_ROW], 'HIGHEST POSSIBLE SCORE');

        // [col => spec]: ['item', id] | ['sum', component, key] | ['ws_role', role] | ['grade', key] | ['official'] | ...
        $columns = [];
        $col = 4;
        $missingCells = [];
        foreach (['written_work' => 'WRITTEN / ORAL WORKS (WWs)', 'performance_task' => 'PRODUCT / PERFORMANCE TASKS (PTs)'] as $component => $band) {
            $start = $col;
            $hpsTotal = 0.0;
            foreach ($cols[$component] as $i => $item) {
                $this->text($sheet, [$col, self::SLOT_ROW], (string) ($i + 1));
                if ($item) {
                    $this->text($sheet, [$col, self::NAME_ROW], $item->name);
                    $this->number($sheet, [$col, self::HPS_ROW], (float) $item->max_score);
                    $hpsTotal += (float) $item->max_score;
                    $columns[$col] = ['item', $item->id];
                }
                $col++;
            }
            foreach (['TOTAL' => 'earned', 'PS' => 'percentage', 'WS' => 'contribution'] as $label => $key) {
                $this->text($sheet, [$col, self::SLOT_ROW], $label);
                $columns[$col] = ['sum', $component, $key];
                match ($key) {
                    'earned'       => $this->number($sheet, [$col, self::HPS_ROW], $hpsTotal),
                    'percentage'   => $this->number($sheet, [$col, self::HPS_ROW], 100),
                    'contribution' => $weight($component) === null ? null : $this->text($sheet, [$col, self::HPS_ROW], ClassRecordService::fmt($weight($component)) . '%'),
                };
                $col++;
            }
            $this->band($sheet, $start, $col - 1, $band . ' — ' . ($weight($component) === null ? 'no weight' : ClassRecordService::fmt($weight($component)) . '%'));
        }

        $start = $col;
        foreach (ClassRecordService::EXAM_COLUMNS as $role => $label) {
            $item = $cols['exam_roles'][$role] ?? null;
            $this->text($sheet, [$col, self::SLOT_ROW], $label);
            if ($item) {
                $this->text($sheet, [$col, self::NAME_ROW], $item->name);
                $this->number($sheet, [$col, self::HPS_ROW], (float) $item->max_score);
                $columns[$col] = ['item', $item->id];
            }
            $col++;
        }
        // How the engine treated the items outside ST 1 / ST 2 / TE (the same
        // for every learner of the class): left out, or — with no typed item
        // at all — pooled as the legacy Examination.
        $examDetail = collect($record['computed'])->first()['components']['examination'] ?? [];
        $pooled = ($examDetail['method'] ?? null) === 'pooled';
        foreach ($cols['exam_other'] as $item) {
            $role = $item->exam_role ? (ClassRecordService::EXAM_COLUMNS[$item->exam_role] ?? $item->exam_role) : null;
            $this->text($sheet, [$col, self::SLOT_ROW], 'Other');
            $this->text($sheet, [$col, self::NAME_ROW], $item->name . match (true) {
                $role !== null && $item->is_additional_support => " (additional support — added to {$role} for the learners scored on it)",
                $role !== null                                 => " (second regular {$role} — no Examination figure until resolved)",
                $pooled                                        => ' (no Examination Type — counted in a pooled Examination)',
                default                                        => ' (no Examination Type — not counted)',
            });
            $this->number($sheet, [$col, self::HPS_ROW], (float) $item->max_score);
            $columns[$col] = ['item', $item->id];
            $col++;
        }
        foreach (ClassRecordService::EXAM_COLUMNS as $role => $label) {
            $this->text($sheet, [$col, self::SLOT_ROW], 'WS ' . str_replace(' ', '', $label));
            if (isset($shares[$role])) {
                $this->number($sheet, [$col, self::HPS_ROW], $shares[$role]);
            }
            $columns[$col] = ['ws_role', $role];
            $col++;
        }
        foreach (['PS' => 'percentage', 'WS' => 'contribution'] as $label => $key) {
            $this->text($sheet, [$col, self::SLOT_ROW], $label);
            $columns[$col] = ['sum', 'examination', $key];
            if ($key === 'percentage' && $profile['ex_weight'] !== null) {
                $this->number($sheet, [$col, self::HPS_ROW], 100);
            } elseif ($key === 'contribution' && $profile['ex_weight'] !== null) {
                $this->text($sheet, [$col, self::HPS_ROW], ClassRecordService::fmt($profile['ex_weight']) . '%');
            }
            $col++;
        }
        $this->band($sheet, $start, $col - 1, 'EXAMINATIONS (EXs) — ' . ($profile['ex_weight'] === null ? 'no weight' : ClassRecordService::fmt($profile['ex_weight']) . '%'));

        foreach (['INITIAL GRADE' => ['initial'], 'TERM GRADE' => ['term']] as $label => $spec) {
            $this->text($sheet, [$col, self::SLOT_ROW], $label);
            $columns[$col] = ['grade', $spec[0]];
            $col++;
        }
        $statusStart = $col;
        foreach (['Initial Grade status' => 'initial_note', 'Official Grade' => 'official', 'Official status' => 'official_status', 'Evidence' => 'evidence'] as $label => $key) {
            $this->text($sheet, [$col, self::SLOT_ROW], $label);
            $columns[$col] = ['status', $key];
            $col++;
        }
        $this->band($sheet, $statusStart, $col - 1, 'DSS STATUS (not part of the E-Class Record)');
        $lastCol = $col - 1;

        $row = self::FIRST_DATA_ROW;
        foreach (['male' => 'MALE', 'female' => 'FEMALE', null => 'GENDER NOT RECORDED'] as $gender => $groupLabel) {
            $group = $record['students']->filter(fn($s) => $gender === '' ? !in_array($s->gender, ['male', 'female'], true) : $s->gender === $gender)->values();
            if ($group->isEmpty()) {
                continue;
            }
            $this->text($sheet, [2, $row], $groupLabel);
            $sheet->getStyle("A{$row}:" . Coordinate::stringFromColumnIndex($lastCol) . $row)->getFont()->setBold(true);
            $row++;
            foreach ($group as $i => $student) {
                $this->learnerRow($sheet, $row, $i + 1, $student, $record, $columns, $missingCells);
                $row++;
            }
        }

        $lastRow = max($row - 1, self::FIRST_DATA_ROW);
        $lastLetter = Coordinate::stringFromColumnIndex($lastCol);
        $head = 'A' . self::BAND_ROW . ":{$lastLetter}" . self::HPS_ROW;
        $sheet->getStyle($head)->getFont()->setBold(true);
        $sheet->getStyle($head)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2F6');
        $sheet->getStyle('A' . self::BAND_ROW . ":{$lastLetter}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9D1DB');
        $sheet->getStyle('D' . self::BAND_ROW . ":{$lastLetter}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D' . self::NAME_ROW . ":{$lastLetter}" . self::NAME_ROW)->getAlignment()->setWrapText(true);
        foreach ($missingCells as $coordinate) {
            $sheet->getStyle($coordinate)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDE2E1');
            $sheet->getStyle($coordinate)->getFont()->setBold(true)->getColor()->setRGB('B42318');
        }
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(32);
        $sheet->getColumnDimension('C')->setWidth(15);
        for ($c = 4; $c <= $lastCol; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($c >= $statusStart ? 18 : 9);
        }
        $sheet->getStyle('C' . self::FIRST_DATA_ROW . ":C{$lastRow}")->getNumberFormat()->setFormatCode('@');
        $sheet->freezePane('D' . self::FIRST_DATA_ROW);

        return $book;
    }

    private function learnerRow(Worksheet $sheet, int $row, int $number, $student, array $record, array $columns, array &$missingCells): void
    {
        $computed = $record['computed'][$student->id];
        $display = $record['display'][$student->id];
        $official = $record['official'][$student->id] ?? null;

        $this->number($sheet, [1, $row], $number);
        $this->text($sheet, [2, $row], $student->last_name . ', ' . $student->first_name . ($student->middle_name ? ' ' . $student->middle_name : ''));
        // LRN as TEXT: a 12-digit identifier keeps its leading zeros.
        $this->text($sheet, [3, $row], (string) $student->lrn);

        foreach ($columns as $c => $spec) {
            $value = match ($spec[0]) {
                'item'    => $record['scores'][$spec[1]][$student->id] ?? (isset($record['missing'][$spec[1]][$student->id]) ? ClassRecordService::MISSING_TOKEN : null),
                'sum'     => $computed['components'][$spec[1]][$spec[2]] ?? null,
                'ws_role' => $computed['exam_roles'][$spec[1]]['weighted'] ?? null,
                'grade'   => $spec[1] === 'initial' ? ($computed['computed_grade'] ?? $computed['running_grade']) : $computed['transmuted_grade'],
                'status'  => match ($spec[1]) {
                    'initial_note'    => $display['grade:initial:note'],
                    'official'        => ClassRecordService::isOfficial($official) ? (float) $official->grade : null,
                    'official_status' => $official ? $display['grade:official:note'] : 'No grade recorded',
                    'evidence'        => trim($display['grade:evidence'] . ($display['grade:evidence:note'] ? ' (' . $display['grade:evidence:note'] . ')' : '')),
                },
            };
            if ($value === null || $value === '') {
                continue;
            }
            $coordinate = Coordinate::stringFromColumnIndex($c) . $row;
            if (is_string($value)) {
                $this->text($sheet, $coordinate, $value);
                if ($value === ClassRecordService::MISSING_TOKEN) {
                    $missingCells[] = $coordinate;
                }
            } else {
                $this->number($sheet, $coordinate, (float) $value);
            }
        }
    }

    private function band(Worksheet $sheet, int $from, int $to, string $label): void
    {
        $this->text($sheet, [$from, self::BAND_ROW], $label);
        if ($to > $from) {
            $sheet->mergeCells([$from, self::BAND_ROW, $to, self::BAND_ROW]);
        }
    }

    /** Explicit STRING — never interpreted as a formula, whatever it starts with. */
    private function text(Worksheet $sheet, string|array $cell, string $value): void
    {
        $sheet->setCellValueExplicit($this->coord($cell), $value, DataType::TYPE_STRING);
    }

    private function number(Worksheet $sheet, string|array $cell, float $value): void
    {
        $sheet->setCellValueExplicit($this->coord($cell), $value, DataType::TYPE_NUMERIC);
    }

    private function coord(string|array $cell): string
    {
        return is_array($cell) ? Coordinate::stringFromColumnIndex($cell[0]) . $cell[1] : $cell;
    }
}
