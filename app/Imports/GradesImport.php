<?php

namespace App\Imports;

use App\Models\Grade;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class GradesImport implements ToCollection, WithMultipleSheets
{
    /**
     * I-proseso lang ang UNANG sheet (index 0) ng na-upload na file.
     * Kung wala ito, tatakbo ang collection() PER SHEET sa buong workbook —
     * kaya kung may extra tab (tulad ng "Read Me" notes), mababasa itong
     * parang mga estudyante at magbibigay ng maling error.
     */
    public function sheets(): array
    {
        return [0 => $this];
    }
    protected int $sectionId;
    protected int $gradingPeriod;
    protected string $schoolYear;
    protected Collection $subjectsByName;
    protected Collection $studentsByLrn;

    public array $errors = [];
    public int $importedCount = 0;

    public function __construct(int $sectionId, int $gradingPeriod, string $schoolYear, $subjects, $students)
    {
        $this->sectionId      = $sectionId;
        $this->gradingPeriod  = $gradingPeriod;
        $this->schoolYear     = $schoolYear;
        $this->subjectsByName = $subjects->keyBy(fn($s) => strtolower(trim($s->name)));
        $this->studentsByLrn  = $students->keyBy('lrn');
    }

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            $this->errors[] = 'The uploaded file is empty.';
            return;
        }

        $header   = $rows->first();
        $dataRows = $rows->slice(1);

        // Map column index (3 onward) to the matching Subject
        $columnSubjectMap = [];
        foreach ($header as $colIndex => $colName) {
            if ($colIndex < 3) continue; // 0=lrn, 1=last_name, 2=first_name
            $subject = $this->subjectsByName->get(strtolower(trim((string) $colName)));
            if ($subject) {
                $columnSubjectMap[$colIndex] = $subject;
            }
        }

        foreach ($dataRows as $rowIndex => $row) {
            $excelRowNumber = $rowIndex + 2;

            $lrn = trim((string) ($row[0] ?? ''));
            if ($lrn === '') continue; // fully blank row — skip silently

            $student = $this->studentsByLrn->get($lrn);
            if (!$student) {
                $this->errors[] = "Row {$excelRowNumber}: No student with LRN {$lrn} found in your section.";
                continue;
            }

            foreach ($columnSubjectMap as $colIndex => $subject) {
                $value = $row[$colIndex] ?? null;
                if ($value === null || trim((string) $value) === '') continue; // blank grade, not an error

                if (!is_numeric($value) || $value < 60 || $value > 100) {
                    $this->errors[] = "Row {$excelRowNumber}: Invalid grade '{$value}' for {$subject->name} (must be 60–100).";
                    continue;
                }

                Grade::updateOrCreate(
                    [
                        'student_id'     => $student->id,
                        'subject_id'     => $subject->id,
                        'section_id'     => $this->sectionId,
                        'grading_period' => $this->gradingPeriod,
                        'school_year'    => $this->schoolYear,
                    ],
                    [
                        'grade'      => $value,
                        'encoded_by' => auth()->id(),
                    ]
                );

                $this->importedCount++;
            }
        }
    }
}