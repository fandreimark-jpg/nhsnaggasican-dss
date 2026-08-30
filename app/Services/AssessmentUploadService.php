<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentUpload;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads an uploaded assessment form (columns: lrn, last_name, first_name,
 * then one column per assessment item) in two phases:
 *
 *   detectColumns() — header row only, with a best-guess component per
 *   column, for the adviser to verify/correct before anything is saved.
 *
 *   import() — the full file, using the adviser-CONFIRMED column mapping,
 *   creating/updating Assessment items and AssessmentScore rows. Invalid
 *   rows are skipped and reported rather than aborting the whole import —
 *   same convention as GradesImport/StudentsImport elsewhere in this app.
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

        // Map column index -> [name, component, max_score] for every
        // column the adviser confirmed (unmapped columns are ignored,
        // same as GradesImport ignoring columns that don't match a subject).
        $columnIndexMap = [];
        foreach ($header as $index => $name) {
            $name = trim((string) $name);
            if (isset($columnMapping[$name])) {
                $columnIndexMap[$index] = array_merge(['name' => $name], $columnMapping[$name]);
            }
        }

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

    /** @return array<int, array<int, mixed>> */
    private function readRows(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        return $sheet->toArray(null, true, true, false);
    }
}
