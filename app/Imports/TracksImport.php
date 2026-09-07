<?php

namespace App\Imports;

use App\Models\Specialization;
use App\Models\Track;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;

/**
 * Bulk tracks + specializations import.
 *
 * Expected heading row: track_name | track_code | specialization_name | specialization_code
 *
 * One row per specialization; the track repeats across its rows. A row may
 * leave both specialization columns blank to create the track with no
 * specializations yet — that's legitimate, not an error.
 *
 * Both the track and the specialization are resolved with firstOrCreate(),
 * keyed on their natural code, so re-importing the exact same file is a
 * no-op rather than producing duplicates. Because model() persists directly
 * (via firstOrCreate) instead of returning an unsaved model for the
 * framework to insert, it returns null — nothing left for
 * Maatwebsite\Excel\Imports\ModelManager to save.
 *
 * IMPORTANT: this driver validates and models ONE ROW AT A TIME — each call
 * to withValidator()'s closure only ever sees the single current row via
 * $validator->getData(), never the whole file. Cross-row duplicate
 * detection therefore can't use closure-local variables (a fresh, empty
 * array every call); it has to accumulate on $this, which is the same
 * import instance for the whole file — including once WithChunkReading
 * splits a large file into multiple chunks below, since $this persists
 * across chunk boundaries the same way it already persists across rows.
 */
class TracksImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, WithChunkReading, WithBatchInserts
{
    use SkipsFailures;

    private const CHUNK_SIZE = 200;

    public int $trackCount = 0;
    public int $specializationCount = 0;

    public function chunkSize(): int
    {
        return self::CHUNK_SIZE;
    }

    public function batchSize(): int
    {
        return self::CHUNK_SIZE;
    }

    /** Track codes already counted toward trackCount during this import run. */
    private array $seenTrackCodes = [];

    /** track_code (lowercased) => track_name (lowercased) seen so far, for the name-mismatch check. */
    private array $seenTrackNameByCode = [];

    /** "track_code|specialization_code" pairs already seen, for the duplicate-pair check. */
    private array $seenSpecPairs = [];

    public function model(array $row)
    {
        $trackName = trim($row['track_name']);
        $trackCode = strtoupper(trim($row['track_code']));

        $track = Track::firstOrCreate(
            ['code' => $trackCode],
            ['name' => $trackName]
        );

        if (!isset($this->seenTrackCodes[$trackCode])) {
            $this->seenTrackCodes[$trackCode] = true;
            $this->trackCount++;
        }

        $specName = trim($row['specialization_name'] ?? '');
        $specCode = strtoupper(trim($row['specialization_code'] ?? ''));

        if ($specName !== '' && $specCode !== '') {
            Specialization::firstOrCreate(
                ['track_id' => $track->id, 'code' => $specCode],
                ['name' => $specName]
            );
            $this->specializationCount++;
        }

        return null;
    }

    public function rules(): array
    {
        return [
            '*.track_name'          => ['required', 'string', 'max:255'],
            '*.track_code'          => ['required', 'string', 'max:20'],
            '*.specialization_name' => ['nullable', 'string', 'max:255'],
            '*.specialization_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Cross-field and cross-row checks that Laravel's array-validation
     * wildcard rules can't express cleanly on their own:
     *
     *   - specialization_name/specialization_code must be present together
     *     (required_with, applied by hand instead of the rule string —
     *     Maatwebsite's RowValidator only rewrites required_without and
     *     comma-bearing required_* rules to the '*.' wildcard form, so a
     *     bare 'required_with:specialization_code' would silently look for
     *     a top-level field that never exists).
     *   - the same track_code must not appear with two different
     *     track_name values in one file.
     *   - the same track_code + specialization_code pair must not appear
     *     twice in one file.
     *
     * All three checks accumulate state on $this (see the class docblock)
     * rather than in closure-local variables, since this closure only ever
     * receives one row at a time.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            foreach ($validator->getData() as $index => $row) {
                $trackName = trim($row['track_name'] ?? '');
                $trackCode = strtolower(trim($row['track_code'] ?? ''));
                $specName  = trim($row['specialization_name'] ?? '');
                $specCode  = strtolower(trim($row['specialization_code'] ?? ''));

                if ($specName !== '' && $specCode === '') {
                    $validator->errors()->add("{$index}.specialization_code", 'The specialization code field is required when specialization name is present.');
                }
                if ($specCode !== '' && $specName === '') {
                    $validator->errors()->add("{$index}.specialization_name", 'The specialization name field is required when specialization code is present.');
                }

                if ($trackCode === '') {
                    continue; // already flagged by the required rule above
                }

                if (isset($this->seenTrackNameByCode[$trackCode])) {
                    if ($this->seenTrackNameByCode[$trackCode] !== strtolower($trackName)) {
                        $validator->errors()->add("{$index}.track_name", 'This track code appears more than once in the uploaded file with a different track name.');
                    }
                } else {
                    $this->seenTrackNameByCode[$trackCode] = strtolower($trackName);
                }

                if ($specCode === '') {
                    continue;
                }

                $specKey = $trackCode . '|' . $specCode;

                if (isset($this->seenSpecPairs[$specKey])) {
                    $validator->errors()->add("{$index}.specialization_code", 'This track code + specialization code pair appears more than once in the uploaded file.');
                } else {
                    $this->seenSpecPairs[$specKey] = true;
                }
            }
        });
    }
}
