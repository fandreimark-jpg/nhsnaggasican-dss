<?php

namespace App\Imports;

use App\Models\Student;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;

/**
 * WithChunkReading/WithBatchInserts (200 rows at a time) keep an 800-row
 * file from holding the whole sheet in memory and inserting one row at a
 * time — see the "remaining system issues" Task 3c prompt. Once chunking
 * is active, Maatwebsite validates and models each chunk as its own
 * batch, so cross-row duplicate detection can no longer rely on Laravel's
 * 'distinct' rule (which only ever sees the rows in the CURRENT chunk) —
 * $seenLrns below accumulates on $this instead, the same
 * across-the-whole-file-regardless-of-chunking pattern TracksImport
 * already uses.
 */
class StudentsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, WithChunkReading, WithBatchInserts
{
    use SkipsFailures;

    private const CHUNK_SIZE = 200;

    protected int $sectionId;

    /** LRNs already seen during this import run, across every chunk. */
    private array $seenLrns = [];

    public function __construct(int $sectionId)
    {
        $this->sectionId = $sectionId;
    }

    public function chunkSize(): int
    {
        return self::CHUNK_SIZE;
    }

    public function batchSize(): int
    {
        return self::CHUNK_SIZE;
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
            // 'unique:students,lrn' only checks against rows already in the
            // DB — two NEW rows in the SAME file sharing an LRN would both
            // pass that check independently (neither exists yet at
            // validation time), then the second insert would crash on the
            // students.lrn unique index instead of failing gracefully.
            // Cross-row duplicates within the file itself are caught by
            // withValidator() below (via $seenLrns) rather than Laravel's
            // 'distinct' rule, since 'distinct' only ever sees the rows in
            // whatever chunk is currently being validated once
            // WithChunkReading is active — it can't see across chunks.
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

    /**
     * Cross-row duplicate LRN check, surviving chunk boundaries because
     * $seenLrns lives on $this (the same import instance for the whole
     * file, regardless of how many chunks Maatwebsite splits it into) —
     * see the class docblock.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            foreach ($validator->getData() as $index => $row) {
                $lrn = trim((string) ($row['lrn'] ?? ''));
                if ($lrn === '') {
                    continue; // already flagged by the required rule above
                }

                if (isset($this->seenLrns[$lrn])) {
                    $validator->errors()->add("{$index}.lrn", 'This LRN appears more than once in the uploaded file.');
                } else {
                    $this->seenLrns[$lrn] = true;
                }
            }
        });
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