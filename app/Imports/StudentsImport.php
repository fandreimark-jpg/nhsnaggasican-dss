<?php

namespace App\Imports;

use App\Models\Student;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;

class StudentsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure
{
    use SkipsFailures;

    protected int $sectionId;

    public function __construct(int $sectionId)
    {
        $this->sectionId = $sectionId;
    }

    /**
     * Ito ang tatakbo per row ng Excel/CSV.
     * Ang heading row (unang linya ng file) ay dapat:
     * lrn | last_name | first_name | middle_name | gender | birthdate
     */
    public function model(array $row)
    {
        return new Student([
            'lrn'         => (string) $row['lrn'],
            'last_name'   => trim($row['last_name']),
            'first_name'  => trim($row['first_name']),
            'middle_name' => $row['middle_name'] ?? null,
            'gender'      => strtolower(trim($row['gender'])),
            'birthdate'   => $this->parseDate($row['birthdate'] ?? null),
            'section_id'  => $this->sectionId, // forced — parehong security measure sa manual add
        ]);
    }

    /**
     * Parehong validation rules gaya ng manual "Add Student" form —
     * kaya kahit sa bulk upload, hindi pa rin makakapasok ang letters
     * sa LRN o future birthdate.
     */
    public function rules(): array
    {
        return [
            '*.lrn'         => ['required', 'digits:12', 'unique:students,lrn'],
            '*.last_name'   => ['required', 'string', 'max:255'],
            '*.first_name'  => ['required', 'string', 'max:255'],
            '*.gender'      => ['required', 'in:male,female,Male,Female,MALE,FEMALE'],
            '*.birthdate'   => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            '*.lrn.digits'              => 'LRN must be exactly 12 digits (numbers only).',
            '*.lrn.unique'              => 'A student with this LRN already exists.',
            '*.birthdate.before_or_equal' => 'Birthdate cannot be a future date.',
        ];
    }

    private function parseDate($value)
    {
        if (!$value) return null;

        // Excel dates minsan naka-store bilang numeric serial —
        // kailangan i-convert muna bago i-parse.
        if (is_numeric($value)) {
            return Carbon::instance(
                \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)
            )->format('Y-m-d');
        }

        return Carbon::parse($value)->format('Y-m-d');
    }
}