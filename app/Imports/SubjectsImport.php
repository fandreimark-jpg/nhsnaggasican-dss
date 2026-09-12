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
 * subject_group is optional for CORE rows only — defaults to
 * 'core_academic' (same default the subjects table column itself has,
 * and the weight a genuinely core subject actually carries under DO 015 —
 * see CLAUDE.md's subject catalog table), so an older core-only import
 * file with no such column keeps working exactly as before this column
 * existed. It is REQUIRED for ELECTIVE rows — there is no safe default
 * for an elective (Arts, Research, TechPro, and Field Experience
 * electives all carry different weights), so a blank subject_group on an
 * elective row is a validation error, not a silent core_academic guess
 * (see withValidator() below). When given, it must be one of the groups
 * seeded in subject_group_weights (see SubjectGroupWeight) — a typo here
 * would silently mis-weight every grade computed for that subject, so it
 * is validated, not guessed. track/specialization are optional and
 * matched by name OR code (case-insensitive) — required only for
 * elective subjects that need one.
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
     * "ECR alignment" work order, PART 4a — names of every CORE row whose
     * subject_group cell was blank or absent and fell back to
     * core_academic, so Admin\SubjectController::import() can report this
     * by name instead of the fallback happening invisibly. Populated in
     * model() below. Never populated for an ELECTIVE row — that case is a
     * validation error now (see withValidator()), not a default.
     */
    public array $defaultedSubjectGroupNames = [];

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

    /**
     * Every do015_2026 subject_group actually seeded (excluding its 'all'
     * fallback bucket, never a real per-subject value). Scoped to
     * do015_2026 the same way Admin\SubjectController::
     * availableSubjectGroups() is: the five do8_* rows are computed by
     * GradingEngine::resolveDo8GroupKey(), never typed by a human, so a
     * file column carrying one must be rejected here exactly as it is on
     * the manual form -- before this fix this query was unscoped and
     * would have silently accepted a do8_* value from a file.
     */
    private function validSubjectGroups(): array
    {
        return SubjectGroupWeight::where('scheme', 'do015_2026')->where('subject_group', '!=', 'all')->distinct()->pluck('subject_group')->all();
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
                $type       = strtolower(trim($row['type'] ?? ''));

                // SYSTEM_FIXES_AND_ML_AUDIT.md, "Core and electives must
                // not be swapped" — core_academic (20/50/30) is only a
                // safe default for CORE subjects, which genuinely ARE
                // core_academic-weighted under DO 015 (see CLAUDE.md's
                // subject catalog table). An elective with no subject_group
                // has no safe default at all — Arts, Research, TechPro,
                // and Field Experience electives carry different weights,
                // and silently applying core_academic would misweight
                // whichever one this row actually is. Blocked here rather
                // than left to default, so the row never reaches model().
                if ($type === 'elective' && trim((string) ($row['subject_group'] ?? '')) === '') {
                    $validator->errors()->add(
                        "{$index}.subject_group",
                        'Electives must specify an explicit subject_group — there is no safe default for an elective (unlike Core subjects, which may default to Core Academic). Valid values: ' . implode(', ', $this->validSubjectGroups()) . '.'
                    );
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
