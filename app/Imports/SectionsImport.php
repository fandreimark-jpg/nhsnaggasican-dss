<?php

namespace App\Imports;

use App\Models\Section;
use App\Models\Specialization;
use App\Models\Track;
use App\Models\User;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;

/**
 * Bulk sections import.
 *
 * Expected heading row: name | grade_level | track | specialization | adviser_email | school_year
 *
 * track/specialization are matched by name OR code (case-insensitive) —
 * the same lookup SubjectsImport already uses, except specialization is
 * scoped to the resolved track (an unscoped code search would collide:
 * e.g. ICT exists under TVL and ICTPROG under TechPro).
 *
 * UNLIKE SubjectsImport (which leaves track_id/specialization_id null
 * when a subject's track/specialization doesn't resolve), an unresolved
 * track or specialization HERE is a row failure, not a silent null —
 * Subject::forSection() depends on section.track_id being set, so a
 * section with a null track_id would silently receive no subjects at
 * all for every student in it, with nothing on screen to explain why.
 *
 * adviser_email is optional (blank = no adviser, which is legitimate —
 * sections.adviser_id is nullable) but when present must resolve to an
 * existing user with role='adviser' — the same restriction
 * Admin\SectionController::store() already applies via
 * Rule::exists('users','id')->where('role','adviser') and for the same
 * reason: exists:users,id alone would let an admin/principal account be
 * assigned as a section's adviser. An unknown email is also a row
 * failure (a blank cell means "no adviser"; a typo must not look like
 * one).
 *
 * A duplicate name+school_year (within the file, or already in the
 * database) is a row failure — two sections both called "Narra" in the
 * same year can't be told apart on any screen in the app.
 *
 * The SAME adviser_email on more than one row is NOT a failure — a
 * school may genuinely want that — but IS reported as a warning, since
 * Adviser\DashboardController's
 * `Section::where('adviser_id', auth()->id())->first()` only ever shows
 * the adviser their FIRST section, with no error anywhere telling them
 * (or the admin) that a second one is invisible.
 *
 * IMPORTANT: this driver validates and models ONE ROW AT A TIME — each
 * call to withValidator()'s closure only ever sees the rows in the
 * CURRENT CHUNK via $validator->getData(), never the whole file, once
 * WithChunkReading is active. Cross-row state (duplicate names, repeated
 * adviser emails) therefore accumulates on $this rather than in
 * closure-local variables — same pattern as TracksImport/StudentsImport/
 * SubjectsImport.
 */
class SectionsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, WithChunkReading, WithBatchInserts
{
    use SkipsFailures;

    private const CHUNK_SIZE = 200;

    public int $importedCount = 0;

    /**
     * adviser_email => [section names] for every ROW in the file whose
     * adviser_email resolved to a valid adviser account — populated in
     * withValidator() (see its docblock for why a row with any OTHER
     * validation error still contributes here). Consumed by the
     * controller to build the "assigned to more than one section"
     * warning.
     *
     * @var array<string, array<string>>
     */
    public array $duplicateAdviserWarnings = [];

    /** adviser_email => [section names], accumulated across every chunk. */
    private array $sectionNamesByAdviserEmail = [];

    /** "lowercased name|lowercased school_year" pairs already seen during this import run, across every chunk. */
    private array $seenNameYear = [];

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
        $track = $this->findTrack($row['track']);
        $specialization = $track ? $this->findSpecialization($track, $row['specialization']) : null;

        $adviserEmail = trim((string) ($row['adviser_email'] ?? ''));
        $adviser = $adviserEmail !== '' ? User::where('email', $adviserEmail)->first() : null;

        $this->importedCount++;

        // SYSTEM_FIXES_AND_ML_AUDIT.md, "Sections must remain separate
        // master-data records" / "sections/curriculum reconciliation" --
        // optional column, kept nullable rather than made required: every
        // pre-existing import file (and this importer's own established
        // 6-column header) has no curriculum column at all, and forcing
        // one now would break every one of them for no real gain until
        // there is an actual school file that supplies it. When given,
        // it is used directly instead of falling back to
        // TransmutationService::schemeFor()'s grade-level inference --
        // ECR_ALIGNMENT_WORK_ORDER.md Part 7 explicitly requires curriculum
        // to be set explicitly, not inferred a second time, for every
        // section it creates from the real roster once that arrives.
        $curriculum = trim((string) ($row['curriculum'] ?? ''));

        return new Section([
            'name'              => trim($row['name']),
            'grade_level'       => (int) $row['grade_level'],
            'curriculum'        => $curriculum !== '' ? strtolower($curriculum) : null,
            'track_id'          => $track?->id,
            'specialization_id' => $specialization?->id,
            'adviser_id'        => $adviser?->id,
            'school_year'       => trim($row['school_year']),
        ]);
    }

    private function findTrack(string $needle): ?Track
    {
        $needle = trim($needle);

        return Track::whereRaw('LOWER(name) = ?', [strtolower($needle)])
            ->orWhereRaw('LOWER(code) = ?', [strtolower($needle)])
            ->first();
    }

    /** Scoped to $track — see the class docblock on why an unscoped search is a latent bug. */
    private function findSpecialization(Track $track, string $needle): ?Specialization
    {
        $needle = trim($needle);

        return Specialization::where('track_id', $track->id)
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(name) = ?', [strtolower($needle)])
                  ->orWhereRaw('LOWER(code) = ?', [strtolower($needle)]);
            })
            ->first();
    }

    public function rules(): array
    {
        return [
            '*.name'           => ['required', 'string', 'max:255'],
            '*.grade_level'    => ['required', 'in:11,12'],
            '*.track'          => ['required', 'string'],
            '*.specialization' => ['required', 'string'],
            '*.adviser_email'  => ['nullable', 'email'],
            '*.school_year'    => ['required', 'string', 'max:20'],
            // Optional (see model()'s docblock) but strictly validated when
            // given, case-insensitively — an unrecognized value is a row
            // failure, never silently dropped to null the way an absent
            // column already, correctly, is.
            '*.curriculum'     => ['nullable', 'in:sshs,k12_2013,SSHS,K12_2013'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            '*.grade_level.in' => 'Grade level must be 11 or 12.',
            '*.curriculum.in'  => 'Curriculum must be "sshs" or "k12_2013" when given.',
        ];
    }

    /**
     * Everything Laravel's array-validation wildcard rules can't express
     * on their own: track/specialization resolution, adviser role/
     * existence, cross-row + database duplicate name+school_year, and
     * accumulating the repeated-adviser-email warning data. See the
     * class docblock for why all of this state lives on $this.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            foreach ($validator->getData() as $index => $row) {
                $name          = trim($row['name'] ?? '');
                $schoolYear    = trim($row['school_year'] ?? '');
                $trackNeedle   = trim($row['track'] ?? '');
                $specNeedle    = trim($row['specialization'] ?? '');
                $adviserEmail  = trim((string) ($row['adviser_email'] ?? ''));

                $track = $trackNeedle !== '' ? $this->findTrack($trackNeedle) : null;
                if ($trackNeedle !== '' && !$track) {
                    $validator->errors()->add("{$index}.track", "Track \"{$trackNeedle}\" was not found (matched by name or code).");
                }

                if ($specNeedle !== '') {
                    if ($track) {
                        if (!$this->findSpecialization($track, $specNeedle)) {
                            $validator->errors()->add("{$index}.specialization", "Specialization \"{$specNeedle}\" was not found under track \"{$track->name}\".");
                        }
                    } elseif ($trackNeedle !== '') {
                        // Track itself didn't resolve — can't scope the
                        // specialization lookup to it, so don't pile a
                        // second, potentially misleading error onto this row.
                    }
                }

                // Adviser email: unknown email or non-adviser role is a
                // row failure, not a silent null — see class docblock.
                if ($adviserEmail !== '') {
                    $adviser = User::where('email', $adviserEmail)->first();

                    if (!$adviser) {
                        $validator->errors()->add("{$index}.adviser_email", "No user was found with email \"{$adviserEmail}\".");
                    } elseif ($adviser->role !== 'adviser') {
                        $validator->errors()->add("{$index}.adviser_email", "\"{$adviserEmail}\" belongs to a {$adviser->role} account, not an adviser.");
                    } else {
                        $this->sectionNamesByAdviserEmail[$adviserEmail][] = $name;
                    }
                }

                if ($name === '' || $schoolYear === '') {
                    continue; // already flagged by the required rules above
                }

                $key = strtolower($name) . '|' . strtolower($schoolYear);

                if (isset($this->seenNameYear[$key])) {
                    $validator->errors()->add("{$index}.name", 'This section name and school year appears more than once in the uploaded file.');
                    continue;
                }
                $this->seenNameYear[$key] = true;

                if (Section::where('school_year', $schoolYear)->whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
                    $validator->errors()->add("{$index}.name", 'A section with this name already exists for this school year.');
                }
            }

            // Rebuilt every time this closure runs (once per chunk) from
            // $this->sectionNamesByAdviserEmail, which itself accumulates
            // across every chunk — so this stays correct regardless of
            // how many chunks the file was split into.
            $this->duplicateAdviserWarnings = array_filter(
                $this->sectionNamesByAdviserEmail,
                fn(array $names) => count($names) > 1
            );
        });
    }
}
