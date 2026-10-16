<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * DSS Assessment Record — a teacher-friendly working spreadsheet the DSS
 * generates for one section, subject and term, so a teacher never has to
 * type the same scores twice (the third assessment workflow, beside manual
 * entry and the official E-Class Record upload).
 *
 * The visible sheet borrows the official Strengthened SHS E-Class Record's
 * look (Arial Narrow, the WW/PT/EX band headers, a HIGHEST POSSIBLE SCORE
 * row, males first) but keeps ONLY raw scores: no TOTAL/PS/WS/grade
 * columns, because the DSS never imports computed figures. Below its title
 * banner it is exactly the existing flat upload format — `LRN, LAST NAME,
 * FIRST NAME, <items>` then a MAX row — so it is read by the SAME
 * Detect → Verify → Preview → Import pipeline as any other upload.
 *
 * A hidden `DSS INFO` sheet records which section/subject/term/school year
 * it belongs to, and each existing item's stored component, exam role,
 * max and additional-support flag. On upload the identity must match the
 * selected destination or the file is refused (identityConflicts()); the
 * item metadata only PRE-FILLS the Verify screen, which the adviser still
 * confirms, and AssessmentItemConflictDetector still refuses any change to
 * an existing item's protected metadata.
 *
 * Blank is never Missing: a learner the adviser marked Missing gets a
 * blank, shaded cell with a note — and a blank cell on upload changes
 * nothing, exactly like every other upload.
 */
class DssAssessmentRecord
{
    public const MARKER = 'NAGGASICAN-DSS-ASSESSMENT-RECORD';
    public const VERSION = '1';
    public const SHEET = 'Assessment Record';
    public const INFO_SHEET = 'DSS INFO';

    /** The header row on the visible sheet (1-based); the MAX row follows it. */
    public const HEADER_ROW = 9;

    public const COMPONENTS = ['written_work', 'performance_task', 'examination'];
    public const SCOPES = ['all', 'written_work', 'performance_task', 'examination'];

    /** Blank columns left under each band so a teacher can add new items in place. */
    private const SPARE_COLUMNS = 3;

    private const BAND_LABELS = [
        'written_work'     => 'WRITTEN / ORAL WORKS (WWs)',
        'performance_task' => 'PRODUCT / PERFORMANCE TASKS (PTs)',
        'examination'      => 'EXAMINATIONS (EXs)',
    ];

    private const SCOPE_LABELS = [
        'all'              => 'All components',
        'written_work'     => 'Written Work',
        'performance_task' => 'Performance Task',
        'examination'      => 'Examination',
    ];

    private const TERM_LABELS = [1 => 'FIRST TERM', 2 => 'SECOND TERM', 3 => 'THIRD TERM'];

    public function __construct(private AssessmentStatusService $statuses = new AssessmentStatusService())
    {
    }

    public static function scopeLabel(string $scope): string
    {
        return self::SCOPE_LABELS[$scope] ?? $scope;
    }

    // ------------------------------------------------------------------ build

    public function build(Section $section, Subject $subject, int $term, string $scope): Spreadsheet
    {
        $components = $scope === 'all' ? self::COMPONENTS : [$scope];

        $items = Assessment::where('subject_id', $subject->id)
            ->where('section_id', $section->id)
            ->where('grading_period', $term)
            ->where('school_year', $section->school_year)
            ->whereIn('component', $components)
            ->orderBy('id')
            ->get()
            ->groupBy('component');

        // Males first, then females — the E-Class Record's own order — each
        // alphabetical. No separator rows: they would read as unknown LRNs.
        $students = Student::enrolledIn($section)->get()
            ->sortBy(fn(Student $s) => [strtolower((string) $s->gender) === 'female' ? 1 : 0, $s->last_name, $s->first_name])
            ->values();

        $allItemIds = $items->flatten()->pluck('id');
        $scores = AssessmentScore::whereIn('assessment_id', $allItemIds)->get()
            ->groupBy('assessment_id')->map(fn($rows) => $rows->pluck('score', 'student_id'));
        $missing = $this->statuses->missingStudentIdsByAssessment($allItemIds);

        $book = new Spreadsheet();
        $book->getProperties()->setCreator('Naggasican NHS DSS')->setTitle('Assessment Record');
        $book->getDefaultStyle()->getFont()->setName('Arial Narrow')->setSize(11);

        $sheet = $book->getActiveSheet();
        $sheet->setTitle(self::SHEET);

        // Column layout: A LRN, B LAST NAME, C FIRST NAME, then per component
        // its existing items followed by a few blank spare columns.
        $col = 4; // D
        $columns = [];   // colIndex => ['item' => ?Assessment, 'component' => string]
        $bands = [];     // component => [firstCol, lastCol]
        foreach ($components as $component) {
            $first = $col;
            foreach ($items->get($component, collect()) as $item) {
                $columns[$col++] = ['item' => $item, 'component' => $component];
            }
            for ($i = 0; $i < self::SPARE_COLUMNS; $i++) {
                $columns[$col++] = ['item' => null, 'component' => $component];
            }
            $bands[$component] = [$first, $col - 1];
        }
        $lastCol = max(3, $col - 1);
        $lastLetter = Coordinate::stringFromColumnIndex($lastCol);

        $this->banner($sheet, $section, $subject, $term, $scope, $lastLetter);

        // Band row (8) and header row (9) and MAX row (10).
        $h = self::HEADER_ROW;
        foreach ($bands as $component => [$first, $last]) {
            $range = Coordinate::stringFromColumnIndex($first) . ($h - 1) . ':' . Coordinate::stringFromColumnIndex($last) . ($h - 1);
            $sheet->mergeCells($range);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($first) . ($h - 1), self::BAND_LABELS[$component]);
            $sheet->getStyle($range)->applyFromArray([
                'font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }

        $sheet->setCellValue("A{$h}", 'LRN');
        $sheet->setCellValue("B{$h}", 'LAST NAME');
        $sheet->setCellValue("C{$h}", 'FIRST NAME');
        $sheet->setCellValue('A' . ($h + 1), 'MAX');
        $sheet->setCellValue('B' . ($h + 1), 'HIGHEST POSSIBLE SCORE');
        foreach ($columns as $index => $meta) {
            $letter = Coordinate::stringFromColumnIndex($index);
            if ($meta['item']) {
                $sheet->setCellValueExplicit("{$letter}{$h}", $meta['item']->name, DataType::TYPE_STRING);
                $sheet->setCellValue($letter . ($h + 1), (float) $meta['item']->max_score);
                // The Examination Type is identity, not a label: carried in
                // DSS INFO (restored on upload) and shown here for the teacher.
                if ($meta['item']->component === 'examination' && $meta['item']->exam_role) {
                    $sheet->getComment("{$letter}{$h}")->getText()->createTextRun(
                        'Examination Type: ' . ExaminationRoles::label($meta['item']->exam_role)
                        . '. Keep this column for this exam; its type is restored on upload.'
                    );
                }
            }
        }
        $sheet->getStyle("A{$h}:{$lastLetter}{$h}")->applyFromArray([
            'font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DFEBF7']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle('A' . ($h + 1) . ":{$lastLetter}" . ($h + 1))->applyFromArray([
            'font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D6DCE4']],
        ]);

        // Learner rows.
        $row = $h + 2;
        foreach ($students as $student) {
            $sheet->setCellValueExplicit("A{$row}", (string) $student->lrn, DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", $student->last_name);
            $sheet->setCellValue("C{$row}", $student->first_name);
            foreach ($columns as $index => $meta) {
                if (!$meta['item']) {
                    continue;
                }
                $letter = Coordinate::stringFromColumnIndex($index);
                $score = $scores->get($meta['item']->id)?->get($student->id);
                if ($score !== null) {
                    $sheet->setCellValue("{$letter}{$row}", (float) $score);
                } elseif (in_array($student->id, $missing[$meta['item']->id] ?? [], true)) {
                    // Marked Missing in the DSS: left BLANK (a blank never
                    // changes anything on upload), shaded, with a note.
                    $sheet->getStyle("{$letter}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
                    $sheet->getComment("{$letter}{$row}")->getText()->createTextRun(
                        'Marked Missing in the DSS. Enter the score here if the learner has submitted; leaving it blank changes nothing.'
                    );
                }
            }
            $row++;
        }
        $lastRow = max($h + 1, $row - 1);

        $sheet->getStyle("A{$h}:{$lastLetter}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(22);
        for ($i = 4; $i <= $lastCol; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(12);
        }
        $sheet->freezePane('D' . ($h + 2));

        $this->info($book, $section, $subject, $term, $scope, $columns, $bands);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function banner(Worksheet $sheet, Section $section, Subject $subject, int $term, string $scope, string $lastLetter): void
    {
        $sheet->setCellValue('A1', 'Strengthened Senior High School — Assessment Record');
        $sheet->setCellValue('A2', 'Generated by the Naggasican NHS DSS. Enter RAW scores only — the DSS computes the grades. A blank cell changes nothing.');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A2')->getFont()->setItalic(true);

        $track = collect([$section->track?->name, $section->specialization?->name])->filter()->implode(' / ');
        $pairs = [
            4 => ['SCHOOL YEAR', $section->school_year, 'TERM', self::TERM_LABELS[$term] ?? "TERM {$term}"],
            5 => ['GRADE & SECTION', 'Grade ' . $section->grade_level . ' — ' . $section->name, 'SUBJECT', $subject->name],
            6 => ['TRACK / SPECIALIZATION', $track !== '' ? $track : '—', 'COMPONENTS', self::scopeLabel($scope)],
        ];
        foreach ($pairs as $r => [$l1, $v1, $l2, $v2]) {
            $sheet->setCellValue("A{$r}", $l1);
            $sheet->setCellValueExplicit("B{$r}", (string) $v1, DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", $l2);
            $sheet->setCellValueExplicit("E{$r}", (string) $v2, DataType::TYPE_STRING);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->getStyle("D{$r}")->getFont()->setBold(true);
        }
    }

    /** @param array<int, array{item: ?Assessment, component: string}> $columns */
    private function info(Spreadsheet $book, Section $section, Subject $subject, int $term, string $scope, array $columns, array $bands): void
    {
        $info = $book->createSheet();
        $info->setTitle(self::INFO_SHEET);

        $rows = [
            ['template', self::MARKER],
            ['version', self::VERSION],
            ['school_year', $section->school_year],
            ['term', (string) $term],
            ['section_id', (string) $section->id],
            ['section_name', $section->name],
            ['grade_level', (string) $section->grade_level],
            ['subject_id', (string) $subject->id],
            ['subject_name', $subject->name],
            ['component_scope', $scope],
            ['header_row', (string) self::HEADER_ROW],
            ['generated_at', now()->toIso8601String()],
        ];
        foreach ($bands as $component => [$first, $last]) {
            // 0-based flat-row indexes (A = 0), the same indexes the reader uses.
            $rows[] = ['band', $component, (string) ($first - 1), (string) ($last - 1)];
        }
        foreach ($columns as $meta) {
            if ($item = $meta['item']) {
                $rows[] = ['item', $item->name, $item->component, (string) ($item->exam_role ?? ''), (string) (float) $item->max_score, $item->is_additional_support ? '1' : '0'];
            }
        }

        foreach ($rows as $i => $values) {
            foreach ($values as $c => $value) {
                $info->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . ($i + 1), (string) $value, DataType::TYPE_STRING);
            }
        }
        $info->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $info->getProtection()->setSheet(true);
    }

    // ------------------------------------------------------------------ read

    /** Cheap check (no sheet is loaded): does this workbook carry a DSS INFO sheet? */
    public function isRecord(string $filePath): bool
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            if (!method_exists($reader, 'listWorksheetNames')) {
                return false;
            }

            return in_array(self::INFO_SHEET, $reader->listWorksheetNames($filePath), true)
                && ($this->identity($filePath)['template'] ?? null) === self::MARKER;
        } catch (\Throwable) {
            return false; // not a workbook this reader understands — handled by the ordinary path
        }
    }

    /**
     * The DSS INFO key/value pairs, plus 'bands' and 'items'.
     *
     * @return array<string, mixed>
     */
    public function identity(string $filePath): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([self::INFO_SHEET]);
        $sheet = $reader->load($filePath)->getSheetByName(self::INFO_SHEET);

        $identity = ['bands' => [], 'items' => []];
        foreach ($sheet->toArray(null, false, false, false) as $row) {
            $key = trim((string) ($row[0] ?? ''));
            if ($key === 'band') {
                $identity['bands'][(string) $row[1]] = [(int) $row[2], (int) $row[3]];
            } elseif ($key === 'item') {
                $identity['items'][mb_strtolower(trim((string) $row[1]))] = [
                    'component'             => (string) $row[2],
                    'exam_role'             => ($row[3] ?? '') !== '' ? (string) $row[3] : null,
                    'max_score'             => (float) $row[4],
                    'is_additional_support' => (string) ($row[5] ?? '0') === '1',
                ];
            } elseif ($key !== '') {
                $identity[$key] = trim((string) ($row[1] ?? ''));
            }
        }

        return $identity;
    }

    /**
     * Where the record's identity CONTRADICTS the selected destination.
     * Any entry blocks the upload: a record for another section, subject,
     * term or school year is the wrong file, never a warning.
     *
     * @return list<string>
     */
    public function identityConflicts(array $identity, Section $section, Subject $subject, int $term): array
    {
        if (($identity['version'] ?? null) !== self::VERSION) {
            return ['This Assessment Record was made by a different version of the DSS (version ' . ($identity['version'] ?? 'unknown') . '). Download a fresh one and copy the scores into it.'];
        }

        $conflicts = [];
        if (($identity['school_year'] ?? '') !== $section->school_year) {
            $conflicts[] = "school year {$identity['school_year']}, not {$section->school_year}";
        }
        if ((int) ($identity['term'] ?? 0) !== $term) {
            $conflicts[] = "Term {$identity['term']}, not Term {$term}";
        }
        if ((int) ($identity['section_id'] ?? 0) !== $section->id) {
            $conflicts[] = 'section ' . ($identity['section_name'] ?? '?') . ", not {$section->name}";
        }
        if ((int) ($identity['subject_id'] ?? 0) !== $subject->id) {
            $conflicts[] = 'subject ' . ($identity['subject_name'] ?? '?') . ", not {$subject->name}";
        }

        return $conflicts === [] ? [] : ['This Assessment Record belongs to ' . implode('; ', $conflicts) . '. Nothing was imported — upload the record made for this section, subject and term.'];
    }

    /**
     * The visible sheet from its header row down, as the flat rows the
     * existing upload pipeline reads: [header, MAX row, learner rows...].
     *
     * @return array<int, array<int, mixed>>
     */
    public function toFlatRows(string $filePath): array
    {
        $headerRow = max(1, (int) ($this->identity($filePath)['header_row'] ?? self::HEADER_ROW));

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([self::SHEET]);
        $sheet = $reader->load($filePath)->getSheetByName(self::SHEET);
        if (!$sheet) {
            return [];
        }

        $lastRow = $sheet->getHighestDataRow();
        $lastCol = $sheet->getHighestDataColumn();
        if ($lastRow < $headerRow) {
            return [];
        }

        return $sheet->rangeToArray("A{$headerRow}:{$lastCol}{$lastRow}", null, true, false, false);
    }
}
