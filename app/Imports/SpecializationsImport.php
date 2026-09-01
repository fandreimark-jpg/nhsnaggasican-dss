<?php

namespace App\Imports;

use App\Models\Specialization;
use App\Models\Track;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Bulk specialization import.
 *
 * Expected heading row: name | code | track
 * Unlike SubjectsImport's optional track, 'track' is REQUIRED here —
 * specializations.track_id is NOT NULL in the schema (every specialization
 * belongs to exactly one track), so a track that doesn't resolve is a
 * validation failure, not silently left null.
 */
class SpecializationsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure
{
    use SkipsFailures;

    public int $importedCount = 0;

    public function model(array $row)
    {
        $track = $this->findTrack($row['track']);

        $this->importedCount++;

        return new Specialization([
            'track_id' => $track->id,
            'name'     => trim($row['name']),
            'code'     => strtoupper(trim($row['code'])), // matches SpecializationController::store()'s auto-uppercase
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
            '*.name'  => ['required', 'string', 'max:255'],
            '*.code'  => ['required', 'string', 'max:20'],
            '*.track' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Cross-row and DB duplicate checks, plus resolving 'track' up front so
     * an unresolvable track name/code is reported as a validation error
     * (rather than surfacing later as a null-track_id database failure).
     * Code is checked for uniqueness globally (SHS specialization codes
     * like STEM/HUMSS/ICT are meant to be unique identifiers); name is
     * checked for uniqueness WITHIN the same track, since the same name
     * could plausibly appear once per track in a badly-formed file but
     * shouldn't appear twice under the same one.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $seenNameByTrack = [];
            $seenCodes = [];

            foreach ($validator->getData() as $index => $row) {
                $name = strtolower(trim($row['name'] ?? ''));
                $code = strtolower(trim($row['code'] ?? ''));
                $trackNeedle = trim($row['track'] ?? '');

                if ($name === '' || $code === '' || $trackNeedle === '') {
                    continue; // already flagged by the required rules above
                }

                $track = $this->findTrack($trackNeedle);
                if (!$track) {
                    $validator->errors()->add("{$index}.track", "Track \"{$trackNeedle}\" was not found. Add it first or check the spelling/code.");
                    continue;
                }

                $nameKey = $track->id . '|' . $name;

                if (isset($seenNameByTrack[$nameKey])) {
                    $validator->errors()->add("{$index}.name", 'This specialization name appears more than once for this track in the uploaded file.');
                } else {
                    $seenNameByTrack[$nameKey] = true;
                    if (Specialization::where('track_id', $track->id)->whereRaw('LOWER(name) = ?', [$name])->exists()) {
                        $validator->errors()->add("{$index}.name", 'A specialization with this name already exists under this track.');
                    }
                }

                if (isset($seenCodes[$code])) {
                    $validator->errors()->add("{$index}.code", 'This specialization code appears more than once in the uploaded file.');
                } else {
                    $seenCodes[$code] = true;
                    if (Specialization::whereRaw('LOWER(code) = ?', [$code])->exists()) {
                        $validator->errors()->add("{$index}.code", 'A specialization with this code already exists.');
                    }
                }
            }
        });
    }
}
