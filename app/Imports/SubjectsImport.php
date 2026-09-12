<?php

namespace App\Imports;

use App\Models\DepedSubjectCatalog;
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

/**
 * Bulk subject import.
 *
 * Expected heading row: name | type | grade_level | subject_group | track | specialization
 *
 * "Subject classification and grading weights cleanup" pass — a file
 * NEVER carries WW/PT/Exam percentages; the grading profile is always
 * resolved automatically from (catalog match, then subject_group) at
 * grading time, never typed by an Admin. `subject_group` is REQUIRED for
 * every Grade 11 row, core or elective — there is no safe default for
 * ANY row any more (a prior pass let a blank cell on a CORE row silently
 * become `core_academic`; that leniency is removed here on purpose: an
 * unclassified subject must stay unclassified, never quietly become
 * Core — see SubjectGroupWeight::classificationError(), the single
 * authoritative check both this importer and the manual Admin form call,
 * so they can never disagree about what's a valid combination). It must
 * be BLANK for a Grade 12 row — DO 8, s. 2015 weighs by section track,
 * not subject_group, so a value there would imply a rule that is never
 * actually applied. When given for Grade 11, it must be one of the groups
 * seeded in subject_group_weights AND consistent with the row's type
 * (`core_academic` for type=core only, never for an elective, and vice
 * versa) — classificationError() rejects any other combination outright
 * rather than leaving it unresolved in the imported row. track/specialization
 * are optional and matched by name OR code (case-insensitive) — required
 * only for elective subjects that need one.
 *
 * Every imported subject is also checked against `deped_subject_catalog`
 * by exact, case-insensitive name match — a hit auto-links `catalog_id`,
 * which GradingEngine then prefers over subject_group entirely (see that
 * class), the same automatic resolution a manually-created subject gets
 * nowhere yet (manual creation has no equivalent lookup; import gets one
 * for free since the whole file is already being read row by row).
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
     * Names of every subject auto-linked to a deped_subject_catalog row
     * by exact name match during this import — so Admin\SubjectController
     * ::import() can report it, the same way it used to report the
     * (now-removed) silent subject_group default.
     */
    public array $catalogLinkedNames = [];

    /** "name|grade_level" pairs already seen during this import run, across every chunk. */
    private array $seen = [];

    /**
     * SYSTEM_FIXES_AND_ML_AUDIT.md, "Subject upload by year level" — Admin
     * picks Grade 11 or 12 BEFORE uploading; every row in the file must
     * match that choice, or the row is rejected rather than silently
     * imported under the wrong grade level. Nullable — direct
     * instantiation with no argument (existing tests, and any future
     * caller with no upfront selection) keeps every row's own grade_level
     * exactly as before this check existed.
     */
    private ?int $expectedGradeLevel;

    public function __construct(?int $expectedGradeLevel = null)
    {
        $this->expectedGradeLevel = $expectedGradeLevel;
    }

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
        $gradeLevel = (int) $row['grade_level'];
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

        $name = trim($row['name']);
        $subjectGroup = trim((string) ($row['subject_group'] ?? ''));
        $subjectGroup = $gradeLevel === 12 ? null : ($subjectGroup !== '' ? $subjectGroup : null);

        $catalogId = DepedSubjectCatalog::whereRaw('LOWER(course_title) = ?', [strtolower($name)])->value('id');
        if ($catalogId) {
            $this->catalogLinkedNames[] = $name;
        }

        return new Subject([
            'name'              => $name,
            'type'              => $type,
            'grade_level'       => $gradeLevel,
            'subject_group'     => $subjectGroup,
            'catalog_id'        => $catalogId,
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
            '*.subject_group'  => ['nullable', 'string'],
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
     * Cross-row and DB duplicate checks, plus the authoritative
     * type/grade_level/subject_group consistency check — the same
     * SubjectGroupWeight::classificationError() the manual Admin form
     * calls, so a file can never get away with a combination the form
     * would reject. There is no dedicated "subject code" column in this
     * schema, so name + grade_level is the natural composite key (matches
     * how Admin\SubjectController::store() already treats subject
     * identity).
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            foreach ($validator->getData() as $index => $row) {
                $name       = strtolower(trim($row['name'] ?? ''));
                $gradeLevel = $row['grade_level'] ?? null;
                $type       = strtolower(trim($row['type'] ?? ''));
                $subjectGroup = trim((string) ($row['subject_group'] ?? ''));
                $subjectGroup = $subjectGroup !== '' ? $subjectGroup : null;

                if (in_array((int) $gradeLevel, [11, 12], true) && in_array($type, ['core', 'elective'], true)) {
                    $error = SubjectGroupWeight::classificationError($type, (int) $gradeLevel, $subjectGroup);
                    if ($error) {
                        $validator->errors()->add("{$index}.subject_group", $error);
                    }
                }

                if ($this->expectedGradeLevel !== null && $gradeLevel && (int) $gradeLevel !== $this->expectedGradeLevel) {
                    $validator->errors()->add(
                        "{$index}.grade_level",
                        "This row is Grade {$gradeLevel}, but Grade {$this->expectedGradeLevel} was selected for this upload — file and selection must match, not be silently imported under the wrong grade level."
                    );
                }

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
