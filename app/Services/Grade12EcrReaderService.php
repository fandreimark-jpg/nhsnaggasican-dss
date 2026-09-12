<?php

namespace App\Services;

use App\Models\Student;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reads the school's actual Grade 12 class-record workbook (real
 * instrument inspected directly: GRADE-12-AGILA.xlsx, provided by the
 * school) and translates it into the SAME flat lrn/last_name/first_name/
 * item... shape AssessmentUploadService's existing reader already
 * accepts -- same architectural decision EcrReaderService already made
 * for the Strengthened SHS instrument (see that class's docblock),
 * applied to a genuinely different sheet layout rather than assuming
 * the two templates share geometry.
 *
 * Every cell coordinate below was read from the actual file, not
 * assumed. Real, structural differences from the SSHS template that
 * this class exists specifically to handle:
 *  - No LRN anywhere in the workbook -- INPUT and the TERM sheets carry
 *    only a slot number and a "LAST, First Middle" name. Per the work
 *    order's own instruction, an LRN is never invented: each roster
 *    name is matched against the ALREADY-ENROLLED students of the
 *    target section by normalized last name + first-name containment;
 *    zero or more than one candidate is an unresolved row, reported
 *    separately, never guessed.
 *  - Fixed slot counts, not a 10-slot ceiling: 5 Written/Oral Works
 *    columns (F:J), 3 Performance Task columns (N:P), and exactly 3
 *    named Examination roles (T=ST1, U=ST2, V=TE) -- a slot is "used"
 *    when its row-10 Highest Possible Score cell is non-blank, the
 *    same signal the HPS row already carries for every real item.
 *  - A "TEST/EXAM RESULT ANALYSIS" grid starting at column AD on every
 *    term sheet (Number of Examinees, criterion/norm-referenced
 *    statistics) -- never read here at all: every column this class
 *    touches (A, B, F:AB) is named explicitly, so that block is
 *    excluded by construction, not by a runtime "looks like an analysis
 *    cell" heuristic.
 *  - Column Z/AA/AB hold the workbook's OWN computed Initial Grade,
 *    Term Grade, and Descriptor -- captured as reference values only
 *    (excelComputedGrades()) for the discrepancy check against the
 *    DSS's own GradingEngine result; never imported as the student's
 *    official grade.
 */
class Grade12EcrReaderService
{
    private const INPUT_SHEET = 'INPUT';
    private const CELL_REGION = 'G4';
    private const CELL_DIVISION = 'L4';
    private const CELL_SCHOOL_NAME = 'G5';
    private const CELL_SCHOOL_ID = 'S5';
    private const CELL_SCHOOL_YEAR = 'Y5';
    private const CELL_GRADE_SECTION = 'J7';
    private const CELL_TEACHER = 'Q7';
    private const CELL_SUBJECT = 'Y7';

    /** INPUT roster block -- MALE and FEMALE are SEQUENTIAL blocks here (unlike SSHS's parallel-column layout). */
    private const MALE_FIRST_ROW = 12;
    private const FEMALE_FIRST_ROW = 63;
    private const ROSTER_SLOTS = 50;
    private const COL_NAME = 'B';

    /** Term sheet geometry -- identical on TERM1/TERM2/TERM3, confirmed directly. */
    private const TERM_CELL_SUBJECT = 'Z7';
    private const HPS_ROW = 10;
    private const WW_COLUMNS = ['F', 'G', 'H', 'I', 'J'];
    private const WW_WEIGHT_CELL = 'M' . self::HPS_ROW;
    private const PT_COLUMNS = ['N', 'O', 'P'];
    private const PT_WEIGHT_CELL = 'S' . self::HPS_ROW;
    /** column => [flat column name, exam role] */
    private const EX_COLUMNS = [
        'T' => ['ST1', 'st1'],
        'U' => ['ST2', 'st2'],
        'V' => ['TE', 'term_exam'],
    ];
    private const EX_WEIGHT_CELL = 'Y' . self::HPS_ROW;
    private const COL_INITIAL_GRADE = 'Z';
    private const COL_TERM_GRADE = 'AA';
    private const COL_DESCRIPTOR = 'AB';

    /** Populated by matchLearners()/toFlatRows() -- roster names that could not be resolved to exactly one student in the target section. */
    private array $lastUnresolvedNames = [];

    public function unresolvedNames(): array
    {
        return $this->lastUnresolvedNames;
    }

    /**
     * @return array{
     *     region: string, division: string, school_id: string, school_name: string, school_year: string,
     *     grade_section: string, grade_level: ?int, section_name: string, teacher: string, subject: string,
     *     roster_count: int,
     * }
     */
    public function extractMetadata(string $filePath): array
    {
        $spreadsheet = $this->load($filePath);
        $input = $spreadsheet->getSheetByName(self::INPUT_SHEET);

        $gradeSection = $input ? trim((string) $input->getCell(self::CELL_GRADE_SECTION)->getValue()) : '';
        [$gradeLevel, $sectionName] = $this->splitGradeSection($gradeSection);

        return [
            'region'        => $input ? trim((string) $input->getCell(self::CELL_REGION)->getValue()) : '',
            'division'      => $input ? trim((string) $input->getCell(self::CELL_DIVISION)->getValue()) : '',
            'school_id'     => $input ? trim((string) $input->getCell(self::CELL_SCHOOL_ID)->getValue()) : '',
            'school_name'   => $input ? trim((string) $input->getCell(self::CELL_SCHOOL_NAME)->getValue()) : '',
            'school_year'   => $input ? trim((string) $input->getCell(self::CELL_SCHOOL_YEAR)->getValue()) : '',
            'grade_section' => $gradeSection,
            'grade_level'   => $gradeLevel,
            'section_name'  => $sectionName,
            'teacher'       => $input ? trim((string) $input->getCell(self::CELL_TEACHER)->getValue()) : '',
            'subject'       => $input ? trim((string) $input->getCell(self::CELL_SUBJECT)->getValue()) : '',
            'roster_count'  => count($this->readRosterNames($spreadsheet)),
        ];
    }

    /**
     * "12-AGILA" -> [12, "AGILA"]. Splits on the FIRST hyphen only, since
     * a section name could itself legitimately contain one (e.g.
     * "12-DE LA CRUZ-A" is not something this file's real convention
     * uses, but splitting on the first hyphen rather than exploding on
     * every hyphen keeps the rest of a compound name intact). Returns
     * [null, $raw] unchanged if the value doesn't start with a
     * recognizable grade number -- never guessed.
     */
    private function splitGradeSection(string $raw): array
    {
        if (!preg_match('/^\s*(\d{1,2})\s*-\s*(.+)$/', $raw, $m)) {
            return [null, $raw];
        }

        return [(int) $m[1], trim($m[2])];
    }

    /** @return array<int, array{name: string, slot_row: int}> */
    private function readRosterNames(Spreadsheet $spreadsheet): array
    {
        $input = $spreadsheet->getSheetByName(self::INPUT_SHEET);
        if (!$input) {
            return [];
        }

        $roster = [];
        foreach ([self::MALE_FIRST_ROW, self::FEMALE_FIRST_ROW] as $firstRow) {
            for ($i = 0; $i < self::ROSTER_SLOTS; $i++) {
                $row = $firstRow + $i;
                $name = trim((string) $input->getCell(self::COL_NAME . $row)->getValue());
                // A blank slot's number-formula sometimes evaluates to
                // the literal string "0" rather than an empty cell (see
                // class docblock) -- never a real name either way.
                if ($name === '' || $name === '0') {
                    continue;
                }
                $roster[] = ['name' => $name, 'slot_row' => $row];
            }
        }

        return $roster;
    }

    /** Same "Last, First Middle" convention as EcrReaderService::splitName(). */
    private function splitName(string $fullName): array
    {
        if (!str_contains($fullName, ',')) {
            return [$fullName, ''];
        }

        [$last, $first] = array_map('trim', explode(',', $fullName, 2));
        return [$last, $first];
    }

    /**
     * Matches each roster name to an existing Student in $sectionId --
     * normalized last name (exact, case-insensitive) AND the student's
     * first_name appearing as a whole word in the Excel remainder,
     * since the workbook's own "First Middle" ordering isn't guaranteed
     * to match the DSS's separate first_name/middle_name columns
     * word-for-word. Zero or more than one candidate is unresolved --
     * never guessed, never assigned a fabricated LRN.
     *
     * @return array<int, array{slot_row: int, name: string, student: ?Student}>
     */
    public function matchLearners(string $filePath, int $sectionId): array
    {
        $spreadsheet = $this->load($filePath);
        $roster = $this->readRosterNames($spreadsheet);

        $candidates = Student::where('section_id', $sectionId)->get();
        $this->lastUnresolvedNames = [];

        $matched = [];
        foreach ($roster as $entry) {
            [$last, $remainder] = $this->splitName($entry['name']);
            $lastNorm = $this->normalize($last);
            $remainderNorm = $this->normalize($remainder);

            $hits = $candidates->filter(function (Student $student) use ($lastNorm, $remainderNorm) {
                if ($this->normalize($student->last_name) !== $lastNorm) {
                    return false;
                }
                $firstNorm = $this->normalize($student->first_name);
                return $firstNorm !== '' && str_contains(' ' . $remainderNorm . ' ', ' ' . $firstNorm . ' ');
            });

            if ($hits->count() === 1) {
                $matched[] = ['slot_row' => $entry['slot_row'], 'name' => $entry['name'], 'student' => $hits->first()];
            } else {
                $matched[] = ['slot_row' => $entry['slot_row'], 'name' => $entry['name'], 'student' => null];
                $this->lastUnresolvedNames[] = $entry['name'];
            }
        }

        return $matched;
    }

    private function normalize(string $value): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    /**
     * The flat rows AssessmentUploadService::readRows() already accepts:
     * row 0 = header, row 1 = MAX row (this workbook always states every
     * used item's Highest Possible Score, same as the SSHS reader), rows
     * 2+ = one per CONFIDENTLY MATCHED roster entry only -- an
     * unresolved name is excluded here and reported via
     * unresolvedNames(), never included with a guessed or blank LRN.
     *
     * @return array<int, array<int, mixed>>
     */
    public function toFlatRows(string $filePath, int $gradingPeriod, int $sectionId): array
    {
        $spreadsheet = $this->load($filePath);
        $termSheet = $spreadsheet->getSheetByName('TERM' . $gradingPeriod);
        if (!$termSheet) {
            return [];
        }

        $matches = $this->matchLearners($filePath, $sectionId);
        $items = $this->usedItems($termSheet);

        $header = ['lrn', 'last_name', 'first_name'];
        $maxRow = ['MAX', '', ''];
        foreach ($items as $item) {
            $header[] = $item['flat_name'];
            $maxRow[] = $item['max_score'] ?? '';
        }

        $rows = [$header, $maxRow];

        foreach ($matches as $entry) {
            if (!$entry['student']) {
                continue;
            }
            $student = $entry['student'];
            $row = [$student->lrn, $student->last_name, $student->first_name];
            foreach ($items as $item) {
                $row[] = $termSheet->getCell($item['column'] . $entry['slot_row'])->getValue();
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<int, array{column: string, flat_name: string, component: string, exam_role: ?string, max_score: ?float}> */
    private function usedItems($termSheet): array
    {
        $items = [];

        foreach (self::WW_COLUMNS as $n => $col) {
            $max = $this->hpsAt($termSheet, $col);
            if ($max !== null) {
                $items[] = ['column' => $col, 'flat_name' => 'WW' . ($n + 1), 'component' => 'written_work', 'exam_role' => null, 'max_score' => $max];
            }
        }

        foreach (self::PT_COLUMNS as $n => $col) {
            $max = $this->hpsAt($termSheet, $col);
            if ($max !== null) {
                $items[] = ['column' => $col, 'flat_name' => 'PT' . ($n + 1), 'component' => 'performance_task', 'exam_role' => null, 'max_score' => $max];
            }
        }

        foreach (self::EX_COLUMNS as $col => [$flatName, $role]) {
            $max = $this->hpsAt($termSheet, $col);
            if ($max !== null) {
                $items[] = ['column' => $col, 'flat_name' => $flatName, 'component' => 'examination', 'exam_role' => $role, 'max_score' => $max];
            }
        }

        return $items;
    }

    private function hpsAt($termSheet, string $column): ?float
    {
        $raw = trim((string) $termSheet->getCell($column . self::HPS_ROW)->getValue());
        return $raw !== '' && is_numeric($raw) ? (float) $raw : null;
    }

    /**
     * The term's WW/PT/Exam weight fractions as the workbook itself
     * states them (e.g. 0.20/0.60/0.20), read directly from the HPS
     * row's own WS cells -- never parsed out of the band-header text
     * ("WRITTEN / ORAL WORKS (20%)"), which is presentation text, not
     * data. Null for a component with no used items at all this term.
     *
     * @return array{written_work: ?float, performance_task: ?float, examination: ?float}
     */
    public function extractComponentWeights(string $filePath, int $gradingPeriod): array
    {
        $spreadsheet = $this->load($filePath);
        $termSheet = $spreadsheet->getSheetByName('TERM' . $gradingPeriod);
        if (!$termSheet) {
            return ['written_work' => null, 'performance_task' => null, 'examination' => null];
        }

        $read = function (string $cell) use ($termSheet): ?float {
            $raw = trim((string) $termSheet->getCell($cell)->getValue());
            return $raw !== '' && is_numeric($raw) ? (float) $raw : null;
        };

        return [
            'written_work'     => $read(self::WW_WEIGHT_CELL),
            'performance_task' => $read(self::PT_WEIGHT_CELL),
            'examination'      => $read(self::EX_WEIGHT_CELL),
        ];
    }

    /**
     * The workbook's OWN computed Initial Grade / Term Grade / Descriptor
     * per matched student -- reference values for the discrepancy check
     * against GradingEngine's independent result. Never written to
     * grades.grade directly.
     *
     * @return array<int, array{name: string, student: ?Student, initial_grade: ?float, term_grade: ?float, descriptor: string}>
     */
    public function excelComputedGrades(string $filePath, int $gradingPeriod, int $sectionId): array
    {
        $spreadsheet = $this->load($filePath);
        $termSheet = $spreadsheet->getSheetByName('TERM' . $gradingPeriod);
        if (!$termSheet) {
            return [];
        }

        $matches = $this->matchLearners($filePath, $sectionId);

        return array_map(function (array $entry) use ($termSheet) {
            $initial = trim((string) $termSheet->getCell(self::COL_INITIAL_GRADE . $entry['slot_row'])->getValue());
            $termGrade = trim((string) $termSheet->getCell(self::COL_TERM_GRADE . $entry['slot_row'])->getValue());
            $descriptor = trim((string) $termSheet->getCell(self::COL_DESCRIPTOR . $entry['slot_row'])->getValue());

            return [
                'name'          => $entry['name'],
                'student'       => $entry['student'],
                'initial_grade' => $initial !== '' && is_numeric($initial) ? (float) $initial : null,
                'term_grade'    => $termGrade !== '' && is_numeric($termGrade) ? (float) $termGrade : null,
                'descriptor'    => $descriptor,
            ];
        }, $matches);
    }

    private function load(string $filePath): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        return $reader->load($filePath);
    }
}
