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

    /**
     * Below this many valid scores, there isn't enough evidence to flag a
     * column's declared maximum as suspicious — a couple of low scores could
     * just be a couple of low scores.
     */
    private const SUSPICIOUS_MAX_MIN_SAMPLES = 3;

    /**
     * Heuristic threshold for "the declared max looks too high": if the best
     * score in the whole file is under this fraction of the declared max,
     * it's flagged. This is tuned to catch order-of-magnitude data-entry
     * typos (a max of 30 typed as 100), not to judge whether an assessment
     * was hard — a genuinely hard task can legitimately produce low scores,
     * which is why this only ever produces a warning, never a rejection.
     */
    private const SUSPICIOUS_MAX_RATIO = 0.5;

    /**
     * Common file-naming words that carry no subject identity — excluded
     * from both sides of detectFilenameSubjectMismatch()'s matching so a
     * generic word like "term" never counts as a "recognisable token."
     */
    private const FILENAME_STOPWORDS = [
        'assessment', 'assessments', 'term', 'file', 'upload', 'uploaded',
        'score', 'scores', 'grade', 'grades', 'quiz', 'quizzes', 'activity',
        'activities', 'exam', 'exams', 'final', 'midterm', 'test', 'tests',
        'sheet', 'data', 'copy', 'section', 'class', 'form', 'template',
    ];

    public function __construct(private AssessmentColumnClassifier $classifier = new AssessmentColumnClassifier())
    {
    }

    /**
     * Shared with the "add an assessment item by hand" manual-entry path
     * (AdviserAssessmentController::storeItem()) so both routes into the
     * same data flag a too-high max the same way, from one place — see
     * SUSPICIOUS_MAX_RATIO/SUSPICIOUS_MAX_MIN_SAMPLES above for the
     * reasoning. previewRows() below is the file-upload caller.
     */
    public function isSuspiciousMax(int $sampleCount, float $highestScore, float $maxScore): bool
    {
        return $sampleCount >= self::SUSPICIOUS_MAX_MIN_SAMPLES
            && $maxScore > 0
            && ($highestScore / $maxScore) < self::SUSPICIOUS_MAX_RATIO;
    }

    /**
     * @return array{
     *     columns: array<int, array{name: string, guessed_component: ?string, guessed_exam_role: ?string, file_max_score: ?float}>,
     *     row_count: int, max_row_present: bool,
     *     max_row_errors: array<string, string>,
     * }
     */
    public function detectColumns(string $filePath): array
    {
        $rows = $this->readRows($filePath);

        if (empty($rows)) {
            return ['columns' => [], 'row_count' => 0, 'max_row_present' => false, 'max_row_errors' => []];
        }

        ['header' => $header, 'maxRow' => $maxRow, 'dataRows' => $dataRows] = $this->splitRows($rows);

        $columns = [];
        $maxRowErrors = [];

        foreach ($header as $index => $name) {
            if ($index < self::FIRST_ITEM_COLUMN) {
                continue;
            }

            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $fileMaxScore = null;
            if ($maxRow !== null) {
                $rawMax = trim((string) ($maxRow[$index] ?? ''));
                if ($rawMax !== '') {
                    if (!is_numeric($rawMax) || (float) $rawMax <= 0) {
                        // A wrong max is exactly what this feature exists to
                        // prevent — never silently ignored, never defaulted.
                        $maxRowErrors[$name] = $rawMax;
                    } else {
                        $fileMaxScore = (float) $rawMax;
                    }
                }
                // A blank MAX cell just means "not supplied for this column"
                // — the adviser fills it in on the Verify screen as before,
                // same as a file with no MAX row at all.
            }

            $guessedComponent = $this->classifier->classify($name);

            $columns[] = [
                'name'              => $name,
                'guessed_component' => $guessedComponent,
                // Only meaningful when guessed_component is
                // 'examination' — see AssessmentColumnClassifier::classifyExamRole().
                'guessed_exam_role' => $guessedComponent === 'examination' ? $this->classifier->classifyExamRole($name) : null,
                'file_max_score'    => $fileMaxScore,
            ];
        }

        return [
            'columns'         => $columns,
            'row_count'       => count($dataRows),
            'max_row_present' => $maxRow !== null,
            'max_row_errors'  => $maxRowErrors,
        ];
    }

    /**
     * TASK 3a of "dashboard structure and upload safeguards" — the system
     * cannot know a file's TRUE subject, only whether its NAME looks like
     * it names a DIFFERENT subject than the one selected. Deliberately
     * conservative — see the ground rule "a false positive must never
     * stop legitimate work" — so this NEVER blocks anything; it only ever
     * returns a subject to name in a dismissible notice, or null.
     *
     * Matching: both the filename and every candidate subject's name are
     * reduced to lowercase alphabetic words (extractSignificantWords() —
     * punctuation, underscores, and digits are separators; short/common
     * filler words like "term" or "assessment" are dropped — see
     * FILENAME_STOPWORDS). A filename word "recognises" a subject word
     * when the two are equal, OR the filename word is a >=4-letter PREFIX
     * of it — so an abbreviation like "comm" still recognises
     * "Communication".
     *
     * A candidate subject only counts if it has a recognised word that is
     * NOT also a word in the SELECTED subject's own name: a word the two
     * share (e.g. "Mathematics" in both "General Mathematics" and
     * "Business Mathematics") proves nothing about which one the file
     * actually belongs to, so it can't be the deciding signal. If more
     * than one OTHER subject ends up with a distinguishing match, or none
     * does, this returns null — an ambiguous or absent signal is not
     * treated as a mismatch, per "must not fire on legitimately named
     * files."
     *
     * @param Collection<int, Subject> $sectionSubjects every subject offered to this section (the selected one included — it's excluded internally)
     */
    public function detectFilenameSubjectMismatch(string $originalFilename, Subject $selectedSubject, Collection $sectionSubjects): ?Subject
    {
        $filenameWords = $this->significantWords(pathinfo($originalFilename, PATHINFO_FILENAME));
        if (empty($filenameWords)) {
            return null;
        }

        $selectedWords = $this->significantWords($selectedSubject->name);

        $matches = [];
        foreach ($sectionSubjects as $subject) {
            if ($subject->id === $selectedSubject->id) {
                continue;
            }

            foreach ($this->significantWords($subject->name) as $subjectWord) {
                if (in_array($subjectWord, $selectedWords, true)) {
                    continue; // shared with the selected subject — not distinguishing
                }

                $recognised = collect($filenameWords)->contains(
                    fn($fw) => $fw === $subjectWord || (strlen($fw) >= 4 && str_starts_with($subjectWord, $fw))
                );

                if ($recognised) {
                    $matches[$subject->id] = $subject;
                    break;
                }
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    /** @return array<int, string> lowercase alphabetic words, length >= 4, filler words removed — see FILENAME_STOPWORDS */
    private function significantWords(string $text): array
    {
        preg_match_all('/[a-z]+/', strtolower($text), $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            fn($word) => strlen($word) >= 4 && !in_array($word, self::FILENAME_STOPWORDS, true)
        )));
    }

    /**
     * Dry run — validates the whole file against the confirmed column
     * mapping WITHOUT writing anything to the database. Lets the adviser
     * see exactly which rows/cells will import cleanly and which won't,
     * before anything is committed.
     *
     * @param array<string, array{component: string, max_score: float, exam_role?: ?string}> $columnMapping keyed by column name
     * @return array{
     *     rows: array<int, array{
     *         excel_row: int, lrn: string, matched: bool, student_name: ?string,
     *         cells: array<int, array{column: string, value: mixed, status: string, message: ?string}>
     *     }>,
     *     total_rows: int, matched_rows: int, total_valid_cells: int, total_invalid_cells: int,
     *     column_stats: array<string, array{highest: ?float, lowest: ?float, count: int, max_score: float, suspicious_max: bool}>,
     * }
     */
    public function previewRows(string $filePath, array $columnMapping, Section $section): array
    {
        $rows = $this->readRows($filePath);

        if (empty($rows)) {
            return ['rows' => [], 'total_rows' => 0, 'matched_rows' => 0, 'total_valid_cells' => 0, 'total_invalid_cells' => 0, 'column_stats' => []];
        }

        ['header' => $header, 'maxRow' => $maxRow, 'dataRows' => $dataRows] = $this->splitRows($rows);
        $columnIndexMap = $this->buildColumnIndexMap($header, $columnMapping);
        $studentsByLrn = Student::where('section_id', $section->id)->get()->keyBy('lrn');
        // A MAX row (if present) occupies row 2, pushing every data row
        // down by one from where it'd sit in a file with no MAX row —
        // excel_row in the preview must still point at the real row.
        $rowOffset = $maxRow !== null ? 1 : 0;

        $previewRows = [];
        $matchedRows = 0;
        $totalValid = 0;
        $totalInvalid = 0;
        $seenLrns = [];

        $columnStats = [];
        foreach ($columnIndexMap as $col) {
            $columnStats[$col['name']] = [
                'highest'         => null,
                'lowest'          => null,
                'count'           => 0,
                'max_score'       => (float) $col['max_score'],
                'any_exceeds_max' => false,
            ];
        }

        foreach ($dataRows as $rowIndex => $row) {
            $lrn = trim((string) ($row[0] ?? ''));
            if ($lrn === '') {
                continue; // fully blank row — not shown in preview, same as import's silent skip
            }

            $excelRow = $rowIndex + 2 + $rowOffset;
            $duplicate = isset($seenLrns[$lrn]);
            $seenLrns[$lrn] = true;

            $student = $studentsByLrn->get($lrn);
            if ($student) {
                $matchedRows++;
            }

            $cells = [];
            foreach ($columnIndexMap as $index => $col) {
                $value = $row[$index] ?? null;
                [$status, $message, $exceedsMax] = $this->evaluateCell($value, (float) $col['max_score'], $col['name'], $duplicate, $student);

                if ($status === 'ok') {
                    $totalValid++;
                    $stat = &$columnStats[$col['name']];
                    $numeric = (float) $value;
                    $stat['count']++;
                    $stat['highest'] = $stat['highest'] === null ? $numeric : max($stat['highest'], $numeric);
                    $stat['lowest']  = $stat['lowest']  === null ? $numeric : min($stat['lowest'], $numeric);
                    unset($stat);
                } elseif ($status !== 'blank') {
                    $totalInvalid++;
                    if ($exceedsMax) {
                        $columnStats[$col['name']]['any_exceeds_max'] = true;
                    }
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

        foreach ($columnStats as $name => &$stat) {
            $stat['suspicious_max'] =
                !$stat['any_exceeds_max']
                && $stat['highest'] !== null
                && $this->isSuspiciousMax($stat['count'], $stat['highest'], $stat['max_score']);
        }
        unset($stat);

        return [
            'rows'                => $previewRows,
            'total_rows'          => count($previewRows),
            'matched_rows'        => $matchedRows,
            'total_valid_cells'   => $totalValid,
            'total_invalid_cells' => $totalInvalid,
            'column_stats'        => $columnStats,
        ];
    }

    /**
     * @param array<string, array{component: string, max_score: float, exam_role?: ?string, is_additional_support?: bool}> $columnMapping keyed by column name
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

        ['header' => $header, 'maxRow' => $maxRow, 'dataRows' => $dataRows] = $this->splitRows($rows);
        $columnIndexMap = $this->buildColumnIndexMap($header, $columnMapping);
        $studentsByLrn = Student::where('section_id', $section->id)->get()->keyBy('lrn');
        $rowOffset = $maxRow !== null ? 1 : 0;

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
                    // Only relevant for Examination items — a Written
                    // Work/Performance Task column mapping never sets
                    // this key at all, so it stays null (see
                    // GradingEngine::examinationPercentage()'s "no role
                    // set" fallback).
                    'exam_role'       => $col['exam_role'] ?? null,
                    // "Workflow completion pass" TASK 3b — set explicitly
                    // by the Adviser on the Verify screen, never inferred
                    // from the column's name.
                    'is_additional_support' => $col['is_additional_support'] ?? false,
                    'max_score'       => $col['max_score'],
                    'import_batch_id' => (string) $upload->id,
                    'uploaded_by'     => $uploaderId,
                ]
            );
            $assessmentsByColumnIndex[$index] = $assessment;
        }

        $seenLrns = [];

        foreach ($dataRows as $rowIndex => $row) {
            $excelRowNumber = $rowIndex + 2 + $rowOffset;
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

    /** @return array{0: string, 1: ?string, 2: bool} [status, message, exceedsMax] — status is one of blank|ok|invalid|duplicate|unmatched */
    private function evaluateCell($value, float $maxScore, string $columnName, bool $duplicateRow, ?Student $student): array
    {
        if (!$student) {
            return ['unmatched', 'No matching student in this section.', false];
        }

        if ($duplicateRow) {
            return ['duplicate', 'Duplicate LRN in this file — only the first occurrence will be used.', false];
        }

        if ($value === null || trim((string) $value) === '') {
            return ['blank', null, false];
        }

        if (!is_numeric($value) || (float) $value < 0) {
            return ['invalid', "Invalid score '{$value}' (must be a non-negative number).", false];
        }

        if ((float) $value > $maxScore) {
            return ['invalid', "Score {$value} exceeds the maximum of {$maxScore}.", true];
        }

        return ['ok', null, false];
    }

    /**
     * Splits raw sheet rows into header / optional MAX row / data rows, so
     * every one of detectColumns()/previewRows()/import() consumes data
     * rows with the MAX row already stripped — otherwise it would be
     * treated as a student row and reported as an unmatched LRN.
     *
     * @return array{header: array<int, mixed>, maxRow: ?array<int, mixed>, dataRows: array<int, array<int, mixed>>}
     */
    private function splitRows(array $rows): array
    {
        $header = $rows[0] ?? [];
        $dataRows = array_slice($rows, 1);
        [$maxRow, $dataRows] = $this->extractMaxRow($dataRows);

        return ['header' => $header, 'maxRow' => $maxRow, 'dataRows' => $dataRows];
    }

    /**
     * The row immediately after the header may be a MAX row — identified
     * by the literal string "MAX" (case-insensitive, trimmed) in column 0,
     * where an LRN would otherwise be. A real student row always has a
     * 12-digit LRN (see the Student validation rules), so "MAX" can never
     * collide with an actual student.
     *
     * @param array<int, array<int, mixed>> $dataRows
     * @return array{0: ?array<int, mixed>, 1: array<int, array<int, mixed>>} [maxRow or null, remaining data rows]
     */
    private function extractMaxRow(array $dataRows): array
    {
        if (empty($dataRows)) {
            return [null, $dataRows];
        }

        $firstCell = strtoupper(trim((string) ($dataRows[0][0] ?? '')));
        if ($firstCell !== 'MAX') {
            return [null, $dataRows];
        }

        $maxRow = array_shift($dataRows);

        return [$maxRow, array_values($dataRows)];
    }

    /** @return array<int, array<int, mixed>> */
    private function readRows(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        return $sheet->toArray(null, true, true, false);
    }
}
