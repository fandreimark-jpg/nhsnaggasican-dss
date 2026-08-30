<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentUpload;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads an uploaded assessment form (columns: lrn, last_name, first_name,
 * then one column per assessment item) in three phases, matching CLAUDE.md's
 * pipeline exactly (Detect -> Verify -> Preview -> Validate -> Import):
 *
 *   detectColumns() — header row only, with a best-guess component per
 *   column, for the adviser to verify/correct before anything is saved.
 *
 *   previewRows() — a DRY RUN using the adviser-confirmed column mapping:
 *   validates every row exactly like import() would, but touches NOTHING
 *   in the database (no Assessment or AssessmentScore rows, not even the
 *   item definitions) — purely for the adviser to see what will happen
 *   before committing to it.
 *
 *   import() — the same validation, for real: creates/updates Assessment
 *   items and AssessmentScore rows. Invalid rows are skipped and reported
 *   rather than aborting the whole import — same convention as
 *   GradesImport/StudentsImport elsewhere in this app.
 *
 * Column 0/1/2 are always lrn/last_name/first_name (matching the
 * downloadable template's format, same as GradeController's grade
 * template) — everything from column 3 onward is a candidate assessment
 * item.
 */
class AssessmentUploadService
{
    private const FIRST_ITEM_COLUMN = 3;

    public function __construct(private AssessmentColumnClassifier $classifier = new AssessmentColumnClassifier())
    {
    }

    /**
     * @return array{columns: array<int, array{name: string, guessed_component: ?string}>, row_count: int}
     */
    public function detectColumns(string $filePath): array
    {
        $rows = $this->readRows($filePath);

        if (empty($rows)) {
            return ['columns' => [], 'row_count' => 0];
        }

        $header  = $rows[0];
        $columns = [];

        foreach ($header as $index => $name) {
            if ($index < self::FIRST_ITEM_COLUMN) {
                continue;
            }

            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $columns[] = [
                'name'              => $name,
                'guessed_component' => $this->classifier->classify($name),
            ];
        }

        return [
            'columns'   => $columns,
            'row_count' => max(0, count($rows) - 1),
        ];
    }

    /**
     * Dry run — validates the whole file against the confirmed column
     * mapping WITHOUT writing anything to the database. Lets the adviser
     * see exactly which rows/cells will import cleanly and which won't,
     * before anything is committed.
     *
     * @param array<string, array{component: string, max_score: float}> $columnMapping keyed by column name
     * @return array{
     *     rows: array<int, array{
     *         excel_row: int, lrn: string, matched: bool, student_name: ?string,
     *         cells: array<int, array{column: string, value: mixed, status: string, message: ?string}>
     *     }>,
     *     total_rows: int, matched_rows: int, total_valid_cells: int, total_invalid_cells: int,
     * }
     */
    public function previewRows(string $filePath, array $columnMapping, Section $section): array
    {
        $rows = $this->readRows($filePath);

        if (empty($rows)) {
            return ['rows' => [], 'total_rows' => 0, 'matched_rows' => 0, 'total_valid_cells' => 0, 'total_invalid_cells' => 0];
        }

        $header = $rows[0];
        $dataRows = array_slice($rows, 1);
        $columnIndexMap = $this->buildColumnIndexMap($header, $columnMapping);
        $studentsByLrn = Student::where('section_id', $section->id)->get()->keyBy('lrn');

        $previewRows = [];
        $matchedRows = 0;
        $totalValid = 0;
        $totalInvalid = 0;
        $seenLrns = [];

        foreach ($dataRows as $rowIndex => $row) {
            $lrn = trim((string) ($row[0] ?? ''));
            if ($lrn === '') {
                continue; // fully blank row — not shown in preview, same as import's silent skip
            }

            $excelRow = $rowIndex + 2;
            $duplicate = isset($seenLrns[$lrn]);
            $seenLrns[$lrn] = true;

            $student = $studentsByLrn->get($lrn);
            if ($student) {
                $matchedRows++;
            }

            $cells = [];
            foreach ($columnIndexMap as $index => $col) {
                $value = $row[$index] ?? null;
                [$status, $message] = $this->evaluateCell($value, (float) $col['max_score'], $col['name'], $duplicate, $student);

                if ($status === 'ok') {
                    $totalValid++;
                } elseif ($status !== 'blank') {
                    $totalInvalid++;
                }

                $cells[] = ['column' => $col['name'], 'value' => $value, 'status' => $status, 'message' => $message];
            }

            $previewRows[] = [
                'excel_row'    => $excelRow,
                'lrn'          => $lrn,
                'matched'      => (bool) $student,
                'duplicate'    => $duplicate,
                'student_name' => $student ? $student->last_name . ', ' . $student->first_name : null,
                'cells'        => $cells,
            ];
        }

        return [
            'rows'                => $previewRows,
            'total_rows'          => count($previewRows),
            'matched_rows'        => $matchedRows,
            'total_valid_cells'   => $totalValid,
            'total_invalid_cells' => $totalInvalid,
        ];
    }

    /**
     * @param array<string, array{component: string, max_score: float}> $columnMapping keyed by column name
     * @return array{imported: int, errors: array<int, string>}
     */
    public function import(
        string $filePath,
        array $columnMapping,
        Section $section,
        Subject $subject,
        int $gradingPeriod,
        string $schoolYear,
        ?int $uploaderId,
        AssessmentUpload $upload
    ): array {
        $rows = $this->readRows($filePath);
        $errors = [];
        $importedCount = 0;

        if (empty($rows)) {
            return ['imported' => 0, 'errors' => ['The uploaded file is empty.']];
        }

        $header = $rows[0];
        $dataRows = array_slice($rows, 1);
        $columnIndexMap = $this->buildColumnIndexMap($header, $columnMapping);
        $studentsByLrn = Student::where('section_id', $section->id)->get()->keyBy('lrn');

        // One Assessment item per confirmed column, created/updated once
        // up front — not per row — since it's the same item for every student.
        $assessmentsByColumnIndex = [];
        foreach ($columnIndexMap as $index => $col) {
            $assessment = Assessment::updateOrCreate(
                [
                    'subject_id'     => $subject->id,
                    'section_id'     => $section->id,
                    'grading_period' => $gradingPeriod,
                    'school_year'    => $schoolYear,
                    'name'           => $col['name'],
                ],
                [
                    'assessment_type' => $col['name'],
                    'component'       => $col['component'],
                    'max_score'       => $col['max_score'],
                    'import_batch_id' => (string) $upload->id,
                    'uploaded_by'     => $uploaderId,
                ]
            );
            $assessmentsByColumnIndex[$index] = $assessment;
        }

        $seenLrns = [];

        foreach ($dataRows as $rowIndex => $row) {
            $excelRowNumber = $rowIndex + 2;
            $lrn = trim((string) ($row[0] ?? ''));

            if ($lrn === '') {
                continue; // fully blank row — skip silently
            }

            if (isset($seenLrns[$lrn])) {
                $errors[] = "Row {$excelRowNumber}: LRN {$lrn} appears more than once in this file — only the first occurrence was used.";
                continue;
            }
            $seenLrns[$lrn] = true;

            $student = $studentsByLrn->get($lrn);
            if (!$student) {
                $errors[] = "Row {$excelRowNumber}: No student with LRN {$lrn} found in your section.";
                continue;
            }

            foreach ($assessmentsByColumnIndex as $index => $assessment) {
                $value = $row[$index] ?? null;
                if ($value === null || trim((string) $value) === '') {
                    continue; // blank score, not an error — not yet scored
                }

                if (!is_numeric($value) || (float) $value < 0) {
                    $errors[] = "Row {$excelRowNumber}: Invalid score '{$value}' for {$assessment->name} (must be a non-negative number).";
                    continue;
                }

                if ((float) $value > (float) $assessment->max_score) {
                    $errors[] = "Row {$excelRowNumber}: Score {$value} for {$assessment->name} exceeds its maximum of {$assessment->max_score}.";
                    continue;
                }

                AssessmentScore::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'student_id' => $student->id],
                    ['score' => $value]
                );

                $importedCount++;
            }
        }

        return ['imported' => $importedCount, 'errors' => $errors];
    }

    /**
     * Column index -> [name, component, max_score] for every column the
     * adviser confirmed (unmapped columns are ignored, same as
     * GradesImport ignoring columns that don't match a subject). Shared
     * by previewRows() and import() so the two can never validate
     * differently against the same confirmed mapping.
     */
    private function buildColumnIndexMap(array $header, array $columnMapping): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $name = trim((string) $name);
            if (isset($columnMapping[$name])) {
                $map[$index] = array_merge(['name' => $name], $columnMapping[$name]);
            }
        }
        return $map;
    }

    /** @return array{0: string, 1: ?string} [status, message] — status is one of blank|ok|invalid|duplicate|unmatched */
    private function evaluateCell($value, float $maxScore, string $columnName, bool $duplicateRow, ?Student $student): array
    {
        if (!$student) {
            return ['unmatched', 'No matching student in this section.'];
        }

        if ($duplicateRow) {
            return ['duplicate', 'Duplicate LRN in this file — only the first occurrence will be used.'];
        }

        if ($value === null || trim((string) $value) === '') {
            return ['blank', null];
        }

        if (!is_numeric($value) || (float) $value < 0) {
            return ['invalid', "Invalid score '{$value}' (must be a non-negative number)."];
        }

        if ((float) $value > $maxScore) {
            return ['invalid', "Score {$value} exceeds the maximum of {$maxScore}."];
        }

        return ['ok', null];
    }

    /** @return array<int, array<int, mixed>> */
    private function readRows(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        return $sheet->toArray(null, true, true, false);
    }
}
