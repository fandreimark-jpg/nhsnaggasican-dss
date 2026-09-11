<?php

namespace App\Services;

use App\Models\DepedSubjectCatalog;
use App\Models\Subject;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * "ECR alignment" work order, PART 5b-5f — translates the official DepEd
 * Strengthened SHS Electronic Class Record workbook into the SAME flat
 * lrn/last_name/first_name/item… shape AssessmentUploadService's existing
 * reader already produces, so Detect -> Verify -> Preview -> Validate ->
 * Import runs completely unchanged downstream of this class. This class
 * ONLY produces rows and a metadata report; it never touches the database
 * itself — AssessmentUploadService::readRows() is the sole caller that
 * feeds this translation into the real pipeline.
 *
 * Every cell coordinate below was confirmed by reading the actual
 * instrument directly (tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx),
 * not assumed from documentation. Only PLAIN, teacher-typed cells are ever
 * read — never a formula. Roster names/LRNs and the grade/section/subject
 * metadata live only on INPUT DATA (the Term sheet's own name column is a
 * formula pointing back there); raw scores and per-item highest-possible-
 * scores are typed directly on the Term sheet and are never formulas
 * either. This means this class never needs PhpSpreadsheet's formula
 * evaluator at all, which matters: several formulas elsewhere in the
 * workbook use newer Excel functions (_xlfn.XLOOKUP, _xlfn.IFNA) that a
 * spreadsheet library's own recalculation may not support — reading only
 * plain cells sidesteps that risk entirely.
 */
class EcrReaderService
{
    /** INPUT DATA metadata cells — all plain teacher-typed values, never formulas. */
    private const CELL_TEACHER = 'F22';
    private const CELL_GRADE_LEVEL = 'F24';
    private const CELL_SECTION_NAME = 'F25';
    private const CELL_SUBJECT_CATEGORY = 'F28'; // track, e.g. "SSHS - ACADEMIC"
    private const CELL_CLUSTER = 'F29';
    private const CELL_SUBJECT = 'F30'; // course title, unless OTHER ELECTIVE
    private const CELL_OTHER_ELECTIVE_NAME = 'F39';
    private const OTHER_ELECTIVE_CLUSTER = 'OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM';

    /** OTHER ELECTIVE'S teacher-typed weights — the one place in the whole workbook a teacher supplies them. */
    private const CELL_OTHER_WW = 'F43';
    private const CELL_OTHER_PT = 'F44';
    private const CELL_OTHER_EX = 'F45';
    private const CELL_OTHER_ST1 = 'F46';
    private const CELL_OTHER_ST2 = 'F47';
    private const CELL_OTHER_TE = 'F48';

    /** INPUT DATA roster block — male and female occupy the SAME 50 rows, in parallel columns, not sequential blocks. */
    private const ROSTER_FIRST_ROW = 11;
    private const ROSTER_SLOTS = 50;
    private const COL_MALE_LRN = 'N';
    private const COL_MALE_NAME = 'O';
    private const COL_FEMALE_LRN = 'R';
    private const COL_FEMALE_NAME = 'S';

    /** Term sheet geometry — identical on Term 1/2/3. */
    private const MAX_SCORE_ROW = 14;
    private const MALE_FIRST_ROW = 17;
    private const FEMALE_FIRST_ROW = 68;
    private const WW_COLUMNS = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'];
    private const PT_COLUMNS = ['Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
    /** column => [flat column name, exam role] */
    private const EX_COLUMNS = [
        'AD' => ['Summative Test 1', 'st1'],
        'AE' => ['Summative Test 2', 'st2'],
        'AF' => ['Term Exam', 'term_exam'],
    ];

    private const ITEM_CEILING = ['written_work' => 10, 'performance_task' => 10, 'examination' => 3];

    /**
     * The flat rows AssessmentUploadService::readRows() would otherwise
     * build directly from the sheet: row 0 = header, row 1 = MAX row
     * (always present — every used item's highest possible score is
     * always known from the workbook), rows 2+ = one per roster entry that
     * has at least one LRN, in male-then-female order (matching the Term
     * sheet's own row order).
     *
     * @return array<int, array<int, mixed>>
     */
    public function toFlatRows(string $filePath, int $gradingPeriod): array
    {
        $spreadsheet = $this->load($filePath);
        $termSheet = $spreadsheet->getSheetByName('Term ' . $gradingPeriod);
        if (!$termSheet) {
            return [];
        }

        $roster = $this->readRoster($spreadsheet);
        $items = $this->usedItems($termSheet);

        $header = ['lrn', 'last_name', 'first_name'];
        $maxRow = ['MAX', '', ''];
        foreach ($items as $item) {
            $header[] = $item['flat_name'];
            $maxRow[] = $item['max_score'] ?? '';
        }

        $rows = [$header, $maxRow];

        foreach ($roster as $entry) {
            $row = [$entry['lrn'], $entry['last_name'], $entry['first_name']];
            foreach ($items as $item) {
                $row[] = $termSheet->getCell($item['column'] . $entry['term_row'])->getValue();
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * "ECR alignment" work order, PART 5f — the weight cross-check. Never
     * overwrites anything; returns a single warning string naming both
     * figures, or null when they agree (or there is nothing to compare).
     * The OTHER ELECTIVE cluster is the one documented exception where the
     * file genuinely is the only source, and says so explicitly rather
     * than being treated like any other subject.
     */
    public function checkWeightMismatch(string $filePath, ?Subject $selectedSubject): ?string
    {
        if (!$selectedSubject) {
            return null;
        }

        $spreadsheet = $this->load($filePath);
        $inputData = $spreadsheet->getSheetByName('INPUT DATA');
        if (!$inputData) {
            return null;
        }

        $cluster = trim((string) $inputData->getCell(self::CELL_CLUSTER)->getValue());
        $courseTitle = trim((string) $inputData->getCell(self::CELL_SUBJECT)->getValue());

        if (strcasecmp($cluster, self::OTHER_ELECTIVE_CLUSTER) === 0) {
            $ww = $this->numericOrNull($inputData->getCell(self::CELL_OTHER_WW)->getValue());
            $pt = $this->numericOrNull($inputData->getCell(self::CELL_OTHER_PT)->getValue());
            $ex = $this->numericOrNull($inputData->getCell(self::CELL_OTHER_EX)->getValue());

            if ($ww === null && $pt === null && $ex === null) {
                return null;
            }

            return 'OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM: DepEd publishes no weight for this cluster — the file\'s teacher-typed '
                . "{$ww}/{$pt}/{$ex} (WW/PT/EX) is the only source and cannot be cross-checked against a catalog.";
        }

        $catalogRow = DepedSubjectCatalog::where('scheme', 'do015_2026')
            ->whereRaw('LOWER(cluster) = ?', [strtolower($cluster)])
            ->whereRaw('LOWER(course_title) = ?', [strtolower($courseTitle)])
            ->first();

        if (!$catalogRow || $catalogRow->teacher_supplied) {
            return null;
        }

        $resolvedWw = null;
        $resolvedPt = null;
        $resolvedEx = null;

        if ($selectedSubject->catalog_id) {
            $linked = DepedSubjectCatalog::find($selectedSubject->catalog_id);
            if ($linked) {
                $resolvedWw = $linked->ww_weight;
                $resolvedPt = $linked->pt_weight;
                $resolvedEx = $linked->ex_weight;
            }
        }

        if ($resolvedWw === null) {
            $weights = \App\Models\SubjectGroupWeight::resolve('do015_2026', $selectedSubject->subject_group);
            $resolvedWw = $weights->ww_weight;
            $resolvedPt = $weights->pt_weight;
            $resolvedEx = $weights->ex_weight;
        }

        $fileEx = $catalogRow->ex_weight !== null ? (float) $catalogRow->ex_weight : null;
        $resolvedExFloat = $resolvedEx !== null ? (float) $resolvedEx : null;

        $agrees = (float) $catalogRow->ww_weight === (float) $resolvedWw
            && (float) $catalogRow->pt_weight === (float) $resolvedPt
            && $fileEx === $resolvedExFloat;

        if ($agrees) {
            return null;
        }

        $fileTriple = $this->formatTriple($catalogRow->ww_weight, $catalogRow->pt_weight, $catalogRow->ex_weight);
        $systemTriple = $this->formatTriple($resolvedWw, $resolvedPt, $resolvedEx);

        return "The file's subject (\"{$courseTitle}\", {$cluster}) carries {$fileTriple} (WW/PT/EX) in the official catalog, "
            . "but the selected subject \"{$selectedSubject->name}\" currently resolves to {$systemTriple}. "
            . 'Not overwritten — review the selected subject\'s group or catalog link.';
    }

    private function formatTriple($ww, $pt, $ex): string
    {
        $fmt = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        return $fmt($ww) . '/' . $fmt($pt) . '/' . $fmt($ex);
    }

    private function numericOrNull($value): ?float
    {
        $value = trim((string) $value);
        return $value !== '' && is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<int, array{lrn: string, last_name: string, first_name: string, term_row: int}>
     */
    private function readRoster(Spreadsheet $spreadsheet): array
    {
        $inputData = $spreadsheet->getSheetByName('INPUT DATA');
        if (!$inputData) {
            return [];
        }

        $roster = [];

        for ($i = 0; $i < self::ROSTER_SLOTS; $i++) {
            $inputRow = self::ROSTER_FIRST_ROW + $i;

            $lrn = trim((string) $inputData->getCell(self::COL_MALE_LRN . $inputRow)->getValue());
            $name = trim((string) $inputData->getCell(self::COL_MALE_NAME . $inputRow)->getValue());
            if ($lrn !== '' || $name !== '') {
                [$last, $first] = $this->splitName($name);
                $roster[] = ['lrn' => $lrn, 'last_name' => $last, 'first_name' => $first, 'term_row' => self::MALE_FIRST_ROW + $i];
            }
        }

        for ($i = 0; $i < self::ROSTER_SLOTS; $i++) {
            $inputRow = self::ROSTER_FIRST_ROW + $i;

            $lrn = trim((string) $inputData->getCell(self::COL_FEMALE_LRN . $inputRow)->getValue());
            $name = trim((string) $inputData->getCell(self::COL_FEMALE_NAME . $inputRow)->getValue());
            if ($lrn !== '' || $name !== '') {
                [$last, $first] = $this->splitName($name);
                $roster[] = ['lrn' => $lrn, 'last_name' => $last, 'first_name' => $first, 'term_row' => self::FEMALE_FIRST_ROW + $i];
            }
        }

        return $roster;
    }

    /** INPUT DATA carries "Last, First Middle" in one cell — split on the first comma. */
    private function splitName(string $fullName): array
    {
        if (!str_contains($fullName, ',')) {
            return [$fullName, ''];
        }

        [$last, $first] = array_map('trim', explode(',', $fullName, 2));
        return [$last, $first];
    }

    /**
     * "Draft roster from an E-Class Record" feature — same "Last, First
     * Middle" convention as splitName() above, one level further: of the
     * words after the comma, the LAST word is the middle name and
     * everything before it is the first name. This is a convention, not a
     * rule (a compound middle name, or a first name that's genuinely the
     * last word, both defeat it silently) — the admin reviews and corrects
     * the exported CSV before import, this never writes to the database
     * directly, and the export UI states the rule so there's something
     * concrete to check against.
     *
     * @return array{0: string, 1: string, 2: string} [last_name, first_name, middle_name]
     */
    private function splitNameWithMiddle(string $fullName): array
    {
        if (!str_contains($fullName, ',')) {
            return [$fullName, '', ''];
        }

        [$last, $remainder] = array_map('trim', explode(',', $fullName, 2));

        if ($remainder === '') {
            return [$last, '', ''];
        }

        $words = preg_split('/\s+/', $remainder);
        if (count($words) === 1) {
            return [$last, $words[0], ''];
        }

        $middle = array_pop($words);
        return [$last, implode(' ', $words), $middle];
    }

    /**
     * "Draft roster from an E-Class Record" feature — reads INPUT DATA's
     * roster (male N/O, female R/S, rows 11-60) and produces rows in the
     * exact shape Admin > Students > Import already accepts: lrn,
     * last_name, first_name, middle_name, gender, birthdate. Exports a
     * draft only; never touches the database. birthdate is always blank —
     * it isn't in the ECR at all. A row with a name but no LRN still
     * exports with lrn blank rather than an invented one; a row that is
     * entirely empty (no LRN, no name) is skipped and counted, never
     * emitted as a blank row.
     *
     * @return array{
     *     rows: array<int, array{lrn: string, last_name: string, first_name: string, middle_name: string, gender: string, birthdate: string}>,
     *     skipped_empty: int,
     *     missing_lrn_count: int,
     * }
     */
    public function extractDraftRoster(string $filePath): array
    {
        $spreadsheet = $this->load($filePath);
        $inputData = $spreadsheet->getSheetByName('INPUT DATA');

        if (!$inputData) {
            return ['rows' => [], 'skipped_empty' => 0, 'missing_lrn_count' => 0];
        }

        $rows = [];
        $skippedEmpty = 0;
        $missingLrn = 0;

        $blocks = [
            'male'   => [self::COL_MALE_LRN, self::COL_MALE_NAME],
            'female' => [self::COL_FEMALE_LRN, self::COL_FEMALE_NAME],
        ];

        foreach ($blocks as $gender => [$lrnCol, $nameCol]) {
            for ($i = 0; $i < self::ROSTER_SLOTS; $i++) {
                $inputRow = self::ROSTER_FIRST_ROW + $i;

                $lrn = trim((string) $inputData->getCell($lrnCol . $inputRow)->getValue());
                $name = trim((string) $inputData->getCell($nameCol . $inputRow)->getValue());

                if ($lrn === '' && $name === '') {
                    $skippedEmpty++;
                    continue;
                }

                if ($lrn === '') {
                    $missingLrn++;
                }

                [$last, $first, $middle] = $name !== '' ? $this->splitNameWithMiddle($name) : ['', '', ''];

                $rows[] = [
                    'lrn'         => $lrn,
                    'last_name'   => $last,
                    'first_name'  => $first,
                    'middle_name' => $middle,
                    'gender'      => $gender,
                    'birthdate'   => '',
                ];
            }
        }

        return ['rows' => $rows, 'skipped_empty' => $skippedEmpty, 'missing_lrn_count' => $missingLrn];
    }

    /**
     * "ECR alignment" work order, PART 5c/5d — the template ships 10 WW,
     * 10 PT, and 3 EX slots whether or not a teacher used them. A column
     * whose header number exists but holds no score in ANY learner row is
     * not an assessment item and is skipped, never emitted as a blank
     * column. The 10/10/3 ceiling is structurally guaranteed by the fixed
     * column layout above (there is no way to exceed it from a genuine
     * copy of this template) — the defensive check here exists for a
     * corrupted or hand-edited file, not normal use.
     *
     * @return array<int, array{column: string, flat_name: string, component: string, exam_role: ?string, max_score: ?float}>
     */
    private function usedItems($termSheet): array
    {
        $items = [];
        $skipped = ['written_work' => 0, 'performance_task' => 0, 'examination' => 0];

        foreach (self::WW_COLUMNS as $n => $col) {
            if ($this->columnHasAnyScore($termSheet, $col)) {
                $items[] = $this->buildItem($termSheet, $col, 'Written Work ' . ($n + 1), 'written_work', null);
            } else {
                $skipped['written_work']++;
            }
        }

        foreach (self::PT_COLUMNS as $n => $col) {
            if ($this->columnHasAnyScore($termSheet, $col)) {
                $items[] = $this->buildItem($termSheet, $col, 'Performance Task ' . ($n + 1), 'performance_task', null);
            } else {
                $skipped['performance_task']++;
            }
        }

        foreach (self::EX_COLUMNS as $col => [$flatName, $role]) {
            if ($this->columnHasAnyScore($termSheet, $col)) {
                $items[] = $this->buildItem($termSheet, $col, $flatName, 'examination', $role);
            } else {
                $skipped['examination']++;
            }
        }

        $this->lastSkippedCounts = $skipped;

        foreach (self::ITEM_CEILING as $component => $ceiling) {
            $used = count(array_filter($items, fn($i) => $i['component'] === $component));
            if ($used > $ceiling) {
                throw new \RuntimeException(
                    "This file has {$used} {$component} item(s), more than the {$ceiling} an SSHS class record can have — it is not a valid copy of this template."
                );
            }
        }

        return $items;
    }

    /** Populated as a side effect of usedItems() — see skippedItemCounts(). */
    private array $lastSkippedCounts = ['written_work' => 0, 'performance_task' => 0, 'examination' => 0];

    /** How many WW/PT/EX slots were skipped as unused on the last toFlatRows() call — for the Verify screen's "not used vs. forgot to fill in" distinction. */
    public function skippedItemCounts(): array
    {
        return $this->lastSkippedCounts;
    }

    private function buildItem($termSheet, string $column, string $flatName, string $component, ?string $examRole): array
    {
        $rawMax = trim((string) $termSheet->getCell($column . self::MAX_SCORE_ROW)->getValue());
        $maxScore = $rawMax !== '' && is_numeric($rawMax) ? (float) $rawMax : null;

        return [
            'column'     => $column,
            'flat_name'  => $flatName,
            'component'  => $component,
            'exam_role'  => $examRole,
            'max_score'  => $maxScore,
        ];
    }

    private function columnHasAnyScore($termSheet, string $column): bool
    {
        foreach (range(self::MALE_FIRST_ROW, self::MALE_FIRST_ROW + self::ROSTER_SLOTS - 1) as $row) {
            if (trim((string) $termSheet->getCell($column . $row)->getValue()) !== '') {
                return true;
            }
        }
        foreach (range(self::FEMALE_FIRST_ROW, self::FEMALE_FIRST_ROW + self::ROSTER_SLOTS - 1) as $row) {
            if (trim((string) $termSheet->getCell($column . $row)->getValue()) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{
     *     version: ?string, teacher: string, grade_level: ?int, section_name: string,
     *     subject_category: string, cluster: string, course_title: string, is_other_elective: bool,
     *     roster_count: int,
     * }
     */
    public function describe(string $filePath): array
    {
        $spreadsheet = $this->load($filePath);
        $inputData = $spreadsheet->getSheetByName('INPUT DATA');

        $cluster = $inputData ? trim((string) $inputData->getCell(self::CELL_CLUSTER)->getValue()) : '';
        $isOtherElective = strcasecmp($cluster, self::OTHER_ELECTIVE_CLUSTER) === 0;
        $courseTitle = $inputData
            ? trim((string) $inputData->getCell($isOtherElective ? self::CELL_OTHER_ELECTIVE_NAME : self::CELL_SUBJECT)->getValue())
            : '';

        $gradeLevelRaw = $inputData ? trim((string) $inputData->getCell(self::CELL_GRADE_LEVEL)->getValue()) : '';

        return [
            'version'           => (new EcrProfileDetector())->detect($filePath),
            'teacher'           => $inputData ? trim((string) $inputData->getCell(self::CELL_TEACHER)->getValue()) : '',
            'grade_level'       => is_numeric($gradeLevelRaw) ? (int) $gradeLevelRaw : null,
            'section_name'      => $inputData ? trim((string) $inputData->getCell(self::CELL_SECTION_NAME)->getValue()) : '',
            'subject_category'  => $inputData ? trim((string) $inputData->getCell(self::CELL_SUBJECT_CATEGORY)->getValue()) : '',
            'cluster'           => $cluster,
            'course_title'      => $courseTitle,
            'is_other_elective' => $isOtherElective,
            'roster_count'      => count($this->readRoster($spreadsheet)),
        ];
    }

    private function load(string $filePath): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        return $reader->load($filePath);
    }
}
