<?php

namespace App\Imports;

use App\Models\Track;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Bulk track import.
 *
 * Expected heading row: name | code
 * Neither column has a DB-level unique constraint (see the
 * create_tracks_table migration), so — same reasoning as
 * SubjectsImport — duplicate protection lives here, checked against both
 * the database and the rest of the uploaded file.
 */
class TracksImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure
{
    use SkipsFailures;

    public int $importedCount = 0;

    public function model(array $row)
    {
        $this->importedCount++;

        return new Track([
            'name' => trim($row['name']),
            'code' => strtoupper(trim($row['code'])), // matches TrackController::store()'s auto-uppercase
        ]);
    }

    public function rules(): array
    {
        return [
            '*.name' => ['required', 'string', 'max:255'],
            '*.code' => ['required', 'string', 'max:20'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $seenNames = [];
            $seenCodes = [];

            foreach ($validator->getData() as $index => $row) {
                $name = strtolower(trim($row['name'] ?? ''));
                $code = strtolower(trim($row['code'] ?? ''));

                if ($name === '' || $code === '') {
                    continue; // already flagged by the required rules above
                }

                if (isset($seenNames[$name])) {
                    $validator->errors()->add("{$index}.name", 'This track name appears more than once in the uploaded file.');
                } else {
                    $seenNames[$name] = true;
                    if (Track::whereRaw('LOWER(name) = ?', [$name])->exists()) {
                        $validator->errors()->add("{$index}.name", 'A track with this name already exists.');
                    }
                }

                if (isset($seenCodes[$code])) {
                    $validator->errors()->add("{$index}.code", 'This track code appears more than once in the uploaded file.');
                } else {
                    $seenCodes[$code] = true;
                    if (Track::whereRaw('LOWER(code) = ?', [$code])->exists()) {
                        $validator->errors()->add("{$index}.code", 'A track with this code already exists.');
                    }
                }
            }
        });
    }
}
