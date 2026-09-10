<?php

namespace App\Imports;

use App\Models\Specialization;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\Track;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Illuminate\Validation\Rule;

/**
 * Bulk subject import.
 *
 * Expected heading row: name | type | grade_level | subject_group | track | specialization
 * subject_group is optional — defaults to 'core_academic' (same default
 * the subjects table column itself has) so an older import file with no
 * such column keeps working exactly as before this column existed. When
 * given, it must be one of the groups seeded in subject_group_weights
 * (see SubjectGroupWeight) — a typo here would silently mis-weight every
 * grade computed for that subject, so it is validated, not guessed.
 * track/specialization are optional and matched by name OR code
 * (case-insensitive) — required only for elective subjects that need one.
 *
 * WithChunkReading/WithBatchInserts (200 rows at a time) — see
 * StudentsImport's class docblock for why $seen below lives on $this
 * rather than as a closure-local in withValidator(): once chunking is
 * active, that closure only ever sees the current chunk's rows, so
 * cross-row duplicate detection has to survive chunk boundaries by
 * accumulating on the import instance instead.
 */
class SubjectsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, WithChunkReading, WithBatchInserts
{
    use SkipsFailures;

    private const CHUNK_SIZE = 200;

    public int $importedCount = 0;

    /**
     * "ECR alignment" work order, PART 4a — names of every row whose
     * subject_group cell was blank or absent and fell back to
     * core_academic, so Admin\SubjectController::import() can report this
     * by name instead of the fallback happening invisibly. Populated in
     * model() below.
     */
    public array $defaultedSubjectGroupNames = [];

    /** "name|grade_level" pairs already seen during this import run, across every chunk. */
    private array $seen = [];

    public function chunkSize(): int
    {
        return self::CHUNK_SIZE;
    }

    public function batchSize(): int
    {
        return self::CHUNK_SIZE;
    }

    public function model(array $row)
    {
        $type = strtolower(trim($row['type']));
        $trackId = null;
        $specializationId = null;

        if ($type === 'elective' && !empty($row['track'])) {
            $track = $this->findTrack($row['track']);
            $trackId = $track?->id;

            if ($track && !empty($row['specialization'])) {
                $specializationId = Specialization::where('track_id', $track->id)
                    ->where(function ($q) use ($row) {
                        $needle = trim($row['specialization']);
                        $q->whereRaw('LOWER(name) = ?', [strtolower($needle)])
                          ->orWhereRaw('LOWER(code) = ?', [strtolower($needle)]);
                    })
                    ->value('id');
            }
        }

        $this->importedCount++;

        $subjectGroup = trim((string) ($row['subject_group'] ?? ''));

        if ($subjectGroup === '') {
            $this->defaultedSubjectGroupNames[] = trim($row['name']);
        }

        return new Subject([
            'name'              => trim($row['name']),
            'type'              => $type,
            'grade_level'       => (int) $row['grade_level'],
            'subject_group'     => $subjectGroup !== '' ? $subjectGroup : 'core_academic',
            'track_id'          => $trackId,
            'specialization_id' => $specializationId,
        ]);
    }

    /** Every subject_group actually seeded (excluding do8_2015's 'all' fallback bucket, never a real per-subject value). */
    private function validSubjectGroups(): array
    {
        return SubjectGroupWeight::where('subject_group', '!=', 'all')->distinct()->pluck('subject_group')->all();
    }

    private function findTrack(string $needle): ?Track
    {
        $needle = trim($needle);

        return Track::whereRaw('LOWER(name) = ?', [strtolower($needle)])
            ->orWhereRaw('LOWER(code) = ?', [strtolower($needle)])
            ->first();
    }

    public function rules(): array
    {
        return [
            '*.name'           => ['required', 'string', 'max:255'],
            '*.type'           => ['required', 'in:core,elective,Core,Elective,CORE,ELECTIVE'],
            '*.grade_level'    => ['required', 'in:11,12'],
            '*.subject_group'  => ['nullable', 'string', Rule::in($this->validSubjectGroups())],
            '*.track'          => ['nullable', 'string', 'max:255'],
            '*.specialization' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            '*.type.in'          => 'Type must be either core or elective.',
            '*.grade_level.in'   => 'Grade level must be 11 or 12.',
            '*.subject_group.in' => 'Subject group must be one of: ' . implode(', ', $this->validSubjectGroups()) . '.',
        ];
    }

    /**
     * Cross-row and DB duplicate checks. There is no dedicated "subject
     * code" column in this schema, so name + grade_level is the natural
     * composite key (matches how Admin\SubjectController::store() already
     * treats subject identity).
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            foreach ($validator->getData() as $index => $row) {
                $name       = strtolower(trim($row['name'] ?? ''));
                $gradeLevel = $row['grade_level'] ?? null;

                if ($name === '' || !$gradeLevel) {
                    continue; // already flagged by the required/in rules above
                }

                $key = $name . '|' . $gradeLevel;

                if (isset($this->seen[$key])) {
                    $validator->errors()->add("{$index}.name", 'This subject name and grade level appears more than once in the uploaded file.');
                    continue;
                }
                $this->seen[$key] = true;

                if (Subject::where('grade_level', $gradeLevel)->whereRaw('LOWER(name) = ?', [$name])->exists()) {
                    $validator->errors()->add("{$index}.name", 'A subject with this name already exists for this grade level.');
                }
            }
        });
    }
}
