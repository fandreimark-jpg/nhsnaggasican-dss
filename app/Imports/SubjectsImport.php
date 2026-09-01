<?php

namespace App\Imports;

use App\Models\Specialization;
use App\Models\Subject;
use App\Models\Track;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Bulk subject import.
 *
 * Expected heading row: name | type | grade_level | track | specialization
 * track/specialization are optional and matched by name OR code
 * (case-insensitive) — required only for elective subjects that need one.
 */
class SubjectsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure
{
    use SkipsFailures;

    public int $importedCount = 0;

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

        return new Subject([
            'name'              => trim($row['name']),
            'type'              => $type,
            'grade_level'       => (int) $row['grade_level'],
            'track_id'          => $trackId,
            'specialization_id' => $specializationId,
        ]);
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
            '*.track'          => ['nullable', 'string', 'max:255'],
            '*.specialization' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            '*.type.in'        => 'Type must be either core or elective.',
            '*.grade_level.in' => 'Grade level must be 11 or 12.',
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
            $seen = [];

            foreach ($validator->getData() as $index => $row) {
                $name       = strtolower(trim($row['name'] ?? ''));
                $gradeLevel = $row['grade_level'] ?? null;

                if ($name === '' || !$gradeLevel) {
                    continue; // already flagged by the required/in rules above
                }

                $key = $name . '|' . $gradeLevel;

                if (isset($seen[$key])) {
                    $validator->errors()->add("{$index}.name", 'This subject name and grade level appears more than once in the uploaded file.');
                    continue;
                }
                $seen[$key] = true;

                if (Subject::where('grade_level', $gradeLevel)->whereRaw('LOWER(name) = ?', [$name])->exists()) {
                    $validator->errors()->add("{$index}.name", 'A subject with this name already exists for this grade level.');
                }
            }
        });
    }
}
