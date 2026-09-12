<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Section;
use App\Http\Controllers\Concerns\SummarizesImportFailures;
use App\Http\Controllers\Concerns\ValidatesSpreadsheetUpload;
use App\Imports\StudentsImport;
use App\Services\EcrProfileDetector;
use App\Services\EcrReaderService;
use App\Services\Grade12EcrProfileDetector;
use App\Services\Grade12EcrReaderService;
use Maatwebsite\Excel\Facades\Excel;
use App\Helpers\LogActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * StudentController (Admin)
 *
 * Students are master data — Admin is the only role that can add, import,
 * edit, or remove student records. Advisers only view/edit students already
 * placed in their own section (see Adviser\StudentController).
 */
class StudentController extends Controller
{
    use SummarizesImportFailures;
    use ValidatesSpreadsheetUpload;

    /**
     * Show all students with optional section filter.
     * Paginated at 10 per page for performance.
     */
    public function index()
    {
        $query = Student::with(['section'])->orderBy('last_name');

        // "Decision flow, report scoping, and dashboard pass" TASK 4b —
        // the Admin dashboard's "learners not assigned to any section"
        // data-health figure links here with this sentinel value.
        if (request('section_id') === 'none') {
            $query->whereNull('section_id');
        } elseif (request('section_id')) {
            // Filter by section if selected in dropdown
            $query->where('section_id', request('section_id'));
        }

        $students = $query->paginate(10);
        $sections = Section::with(['track', 'specialization'])
            ->orderBy('grade_level')
            ->get();

        return view('admin.students', compact('students', 'sections'));
    }

    /**
     * Add a single new student. Unlike the (removed) adviser version, the
     * section is not implicit — Admin manages all sections, so it must be
     * chosen explicitly and is validated against the sections table.
     */
    public function store(Request $request)
    {
        $request->validate([
            'lrn'         => 'required|digits:12|unique:students,lrn',
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'gender'      => 'required|in:male,female',
            'birthdate'   => 'nullable|date',
            'section_id'  => 'required|exists:sections,id',
        ]);

        $student = Student::create($request->only([
            'lrn', 'last_name', 'first_name',
            'middle_name', 'gender', 'birthdate', 'section_id',
        ]));

        LogActivity::log(
            action:      'add_student',
            description: 'Added student: ' . $student->last_name . ', ' . $student->first_name,
            tableName:   'students',
            recordId:    $student->id
        );

        return redirect()->route('admin.students')
            ->with('success', 'Student added successfully!');
    }

    /**
     * Bulk-import students from an Excel/CSV file into a single, explicitly
     * chosen section (mirrors the removed adviser upload's design: the
     * target section is forced server-side from the form field, never read
     * from the uploaded file, so a crafted file can't redirect students
     * into a different section).
     */
    public function import(Request $request)
    {
        $request->validateWithBag('import', [
            'section_id' => 'required|exists:sections,id',
            'file'       => $this->spreadsheetFileRule(),
        ]);

        $import = new StudentsImport((int) $request->section_id);
        Excel::import($import, $request->file('file'));

        $failures = $import->failures();
        $createdCount = $import->createdCount();

        if ($failures->count() > 0) {
            // Named by last_name/first_name, not just a row number -- see
            // rowLabel() in the trait. Matches this screen's own promise
            // ("Import Students will reject a blank LRN by name") for real,
            // rather than only by row position.
            $result = $this->summarizeImportFailures($failures, $import, ['last_name', 'first_name']);

            // Session only, same shape as roster_extraction below -- lets
            // downloadRejectedStudents() stream just these rows back out as
            // a re-uploadable CSV, so fixing 2 of 8 rejected rows means
            // uploading 2 rows next time, not the whole original file
            // (which would bounce the other 6, already-imported rows off
            // the LRN unique constraint for no reason).
            session(['rejected_students' => [
                'rows'            => $result['rejectedRows'],
                'source_filename' => $request->file('file')->getClientOriginalName(),
            ]]);

            LogActivity::log(
                action:      'import_students',
                description: "Imported students ({$createdCount} created, {$result['skippedCount']} rejected)",
                tableName:   'students',
                recordId:    null
            );

            return redirect()->route('admin.students')
                ->with('warning', "{$createdCount} student(s) created. {$result['skippedCount']} row(s) were rejected:")
                ->with('import_errors', $result['rowMessages'])
                ->with('import_header_hint', $result['headerHint']);
        }

        // A prior import's rejected-rows CSV is now stale -- either this
        // upload IS the corrected re-upload (nothing left to download), or
        // it's an unrelated later import.
        session()->forget('rejected_students');

        LogActivity::log(
            action:      'import_students',
            description: "Bulk imported students via file upload ({$createdCount} created)",
            tableName:   'students',
            recordId:    null
        );

        return redirect()->route('admin.students')
            ->with('success', "{$createdCount} student(s) imported successfully!");
    }

    /**
     * Update student information.
     * LRN uniqueness check excludes the current student.
     */
    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);

        $request->validate([
            'lrn'         => 'required|digits:12|unique:students,lrn,' . $student->id,
            'last_name'   => 'required|string|max:255',
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'gender'      => 'required|in:male,female',
            'birthdate'   => 'nullable|date',
            'section_id'  => 'required|exists:sections,id',
        ]);

        $student->update($request->only([
            'lrn', 'last_name', 'first_name',
            'middle_name', 'gender', 'birthdate', 'section_id'
        ]));

        return redirect()->route('admin.students')
            ->with('success', 'Student updated successfully!');
    }

    /**
     * Delete a student record.
     * Related grades and risk results are deleted via cascade in the database.
     */
    public function destroy($id)
    {
        $student = Student::findOrFail($id);
        $student->delete();

        LogActivity::log(
            'delete_student',
            'Removed student: ' . $student->last_name . ', ' . $student->first_name,
            'students',
            $id
        );

        return redirect()->route('admin.students')
            ->with('success', 'Student removed successfully!');
    }

    /**
     * "Draft roster from an E-Class Record" feature — reads the uploaded
     * workbook's roster and stores a DRAFT csv (session only, never the
     * database) for the admin to download, correct, and import through
     * students.import above, unchanged. No student is created here.
     *
     * Reuses the existing ECR profile detector before reading anything —
     * a file that isn't a real SSHS E-Class Record falls through with a
     * clear message instead of EcrReaderService guessing at a shape that
     * isn't there.
     *
     * Validated by EXTENSION, not `mimes:` (MIME-sniffing) — see
     * ValidatesSpreadsheetUpload and CLAUDE.md, "mimes: MIME-sniffing
     * rejects the official DepEd ECR." Content is verified for real
     * immediately after by EcrProfileDetector, which checks actual
     * workbook structure (sheet names, a marker cell, a version tag), not
     * a guess from bytes.
     */
    public function extractRosterPreview(Request $request)
    {
        $request->validateWithBag('extractRoster', [
            'file' => $this->spreadsheetFileRule(['xlsx', 'xls'], 10240),
        ]);

        $path = $request->file('file')->getRealPath();
        $originalName = $request->file('file')->getClientOriginalName();

        $version = (new EcrProfileDetector())->detect($path);
        if ($version === null) {
            return redirect()->route('admin.students')
                ->with('error', "\"{$originalName}\" does not look like an SSHS E-Class Record — profile not detected. Nothing was extracted.");
        }

        $extraction = (new EcrReaderService())->extractDraftRoster($path);

        if (empty($extraction['rows'])) {
            return redirect()->route('admin.students')
                ->with('error', "\"{$originalName}\" was recognised as an E-Class Record ({$version}), but INPUT DATA's roster is empty — nothing to extract.");
        }

        // Session only — this is a draft export, never written to the
        // database. Cleared once downloaded (see downloadRosterExtraction()).
        session([
            'roster_extraction' => [
                'rows'              => $extraction['rows'],
                'source_filename'   => $originalName,
                'skipped_empty'     => $extraction['skipped_empty'],
                'missing_lrn_count' => $extraction['missing_lrn_count'],
            ],
        ]);

        LogActivity::log(
            action:      'extract_roster',
            description: 'Extracted a draft roster (' . count($extraction['rows']) . ' row(s)) from E-Class Record: ' . $originalName,
            tableName:   'students',
            recordId:    null
        );

        return redirect()->route('admin.students')
            ->with('success', 'Draft roster extracted from "' . $originalName . '" — review the summary below and download the CSV.');
    }

    /** Streams the CSV built from the last extractRosterPreview() result, then clears it. */
    public function downloadRosterExtraction(): Response
    {
        $extraction = session('roster_extraction');
        abort_if(!$extraction, 404, 'No draft roster extraction is pending — upload an E-Class Record first.');

        session()->forget('roster_extraction');

        return $this->studentRowsCsvResponse(
            $extraction['rows'],
            'draft_roster_' . pathinfo($extraction['source_filename'], PATHINFO_FILENAME) . '.csv'
        );
    }

    /**
     * Streams a CSV of just the rows rejected by the last import(), in the
     * same lrn/last_name/first_name/middle_name/gender/birthdate shape the
     * upload expects -- so correcting what was wrong means re-uploading
     * those rows only, not the whole original file (which would bounce
     * every already-imported row off the LRN unique constraint again).
     * Left in session across repeat downloads; a later import() call
     * (success or failure) replaces or clears it, whichever fits.
     */
    public function downloadRejectedStudents(): Response
    {
        $rejected = session('rejected_students');
        abort_if(!$rejected, 404, 'No rejected rows are pending — import a file first.');

        return $this->studentRowsCsvResponse(
            $rejected['rows'],
            'rejected_students_' . pathinfo($rejected['source_filename'], PATHINFO_FILENAME) . '.csv'
        );
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function studentRowsCsvResponse(array $rows, string $downloadName): Response
    {
        $csv = "lrn,last_name,first_name,middle_name,gender,birthdate\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(function ($value) {
                $value = (string) $value;
                return str_contains($value, ',') || str_contains($value, '"')
                    ? '"' . str_replace('"', '""', $value) . '"'
                    : $value;
            }, [
                $row['lrn'] ?? '', $row['last_name'] ?? '', $row['first_name'] ?? '',
                $row['middle_name'] ?? '', $row['gender'] ?? '', $row['birthdate'] ?? '',
            ])) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
        ]);
    }

    private const ECR_LEARNER_IMPORT_TEMP_DIR = 'temp_ecr_learner_imports';

    /**
     * "Import Learners from ECR" — a DISTINCT feature from Import
     * Students above (plain CSV/XLSX) and from "Draft roster" above
     * (exports a CSV, never touches the database): this one reads a real
     * SSHS or Grade 12 class-record workbook and writes to `students`
     * directly, but ONLY learner identity — never an assessment score,
     * and never anything until the Admin has reviewed a classified
     * preview and explicitly confirmed it.
     *
     * Every row is classified before anything is saved:
     *   INSERT   — a new student (SSHS only; Grade 12 never invents an
     *              LRN, so it can only ever confirm an EXISTING learner).
     *   EXISTING — LRN (or, for Grade 12, a confidently name-matched
     *              learner) already belongs to THIS section — nothing to
     *              do, never touched.
     *   CONFLICT — the LRN already exists but belongs to a DIFFERENT
     *              section — never silently reassigned.
     *   REJECTED — blank/invalid LRN, or (Grade 12) a roster name with
     *              zero or more than one matching candidate in this
     *              section — never guessed.
     *
     * Section is chosen by the Admin up front (same "pick section first"
     * convention the Adviser ECR import already uses) rather than
     * auto-resolved purely from the file's own embedded grade/section
     * text — the file's values are compared against the chosen Section
     * and surfaced as a match/mismatch signal, not used to silently
     * redirect the import to a different section.
     */
    public function importFromEcrPreview(Request $request)
    {
        $request->validateWithBag('ecrLearnerImport', [
            'section_id' => 'required|exists:sections,id',
            'file'       => $this->spreadsheetFileRule(['xlsx', 'xls'], 10240),
        ]);

        $section = Section::findOrFail($request->section_id);
        $originalName = $request->file('file')->getClientOriginalName();
        $storedFilename = Str::uuid() . '.' . $request->file('file')->getClientOriginalExtension();
        $request->file('file')->storeAs(self::ECR_LEARNER_IMPORT_TEMP_DIR, $storedFilename, 'local');
        $path = Storage::disk('local')->path(self::ECR_LEARNER_IMPORT_TEMP_DIR . '/' . $storedFilename);

        $sshsVersion = (new EcrProfileDetector())->detect($path);
        $isGrade12 = $sshsVersion === null && (new Grade12EcrProfileDetector())->detect($path);

        if ($sshsVersion === null && !$isGrade12) {
            Storage::disk('local')->delete(self::ECR_LEARNER_IMPORT_TEMP_DIR . '/' . $storedFilename);
            return redirect()->route('admin.students')
                ->with('error', "\"{$originalName}\" is not a recognized E-Class Record format (Strengthened SHS or Grade 12) — nothing was extracted.");
        }

        if ($sshsVersion !== null) {
            $format = 'sshs';
            $meta = (new EcrReaderService())->describe($path);
            $fileGradeLevel = $meta['grade_level'];
            $fileSectionName = $meta['section_name'];
            $fileSchoolYear = null; // not carried by describe(); section-year match is skipped for SSHS, named explicitly below
            $rows = $this->classifySshsRows((new EcrReaderService())->extractDraftRoster($path)['rows'], $section);
        } else {
            $format = 'grade12';
            $reader = new Grade12EcrReaderService();
            $meta = $reader->extractMetadata($path);
            $fileGradeLevel = $meta['grade_level'];
            $fileSectionName = $meta['section_name'];
            $fileSchoolYear = $meta['school_year'];
            $rows = $this->classifyGrade12Rows($reader->matchLearners($path, $section->id));
        }

        $sectionMatch = $this->describeSectionMatch($section, $fileGradeLevel, $fileSectionName);
        $yearMatch = $fileSchoolYear !== null ? ($fileSchoolYear === $section->school_year) : null;

        session(['ecr_learner_import' => [
            'format'          => $format,
            'section_id'      => $section->id,
            'source_filename' => $originalName,
            'stored_filename' => $storedFilename,
            'rows'            => $rows,
        ]]);

        return view('admin.students-ecr-import-preview', [
            'section'         => $section,
            'format'          => $format,
            'originalName'    => $originalName,
            'fileGradeLevel'  => $fileGradeLevel,
            'fileSectionName' => $fileSectionName,
            'fileSchoolYear'  => $fileSchoolYear,
            'sectionMatch'    => $sectionMatch,
            'yearMatch'       => $yearMatch,
            'rows'            => $rows,
            'counts'          => [
                'insert'   => count(array_filter($rows, fn($r) => $r['status'] === 'insert')),
                'existing' => count(array_filter($rows, fn($r) => $r['status'] === 'existing')),
                'conflict' => count(array_filter($rows, fn($r) => $r['status'] === 'conflict')),
                'rejected' => count(array_filter($rows, fn($r) => $r['status'] === 'rejected')),
            ],
        ]);
    }

    /** "12-AGILA" vs a chosen Section — shown before import, never used to silently redirect to a different section. */
    private function describeSectionMatch(Section $section, ?int $fileGradeLevel, string $fileSectionName): array
    {
        $gradeMatches = $fileGradeLevel === null || $fileGradeLevel === $section->grade_level;
        $nameMatches = $fileSectionName === '' || strtolower(trim($fileSectionName)) === strtolower(trim($section->name));

        return [
            'grade_matches' => $gradeMatches,
            'name_matches'  => $nameMatches,
            'matched'       => $gradeMatches && $nameMatches,
        ];
    }

    /** @return array<int, array{status: string, lrn: string, last_name: string, first_name: string, middle_name: string, gender: string, birthdate: string, reason: ?string}> */
    private function classifySshsRows(array $draftRows, Section $section): array
    {
        $classified = [];
        foreach ($draftRows as $row) {
            $lrn = trim((string) ($row['lrn'] ?? ''));

            if ($lrn === '') {
                $classified[] = $row + ['status' => 'rejected', 'reason' => 'No LRN in the file — never invented.'];
                continue;
            }

            $existing = Student::where('lrn', $lrn)->first();
            if (!$existing) {
                $classified[] = $row + ['status' => 'insert', 'reason' => null];
            } elseif ((int) $existing->section_id === (int) $section->id) {
                $classified[] = $row + ['status' => 'existing', 'reason' => 'Already enrolled in this section.'];
            } else {
                $classified[] = $row + ['status' => 'conflict', 'reason' => 'This LRN already belongs to a different section — not reassigned.'];
            }
        }

        return $classified;
    }

    /** @param array<int, array{slot_row: int, name: string, student: ?Student}> $matches */
    private function classifyGrade12Rows(array $matches): array
    {
        return array_map(function (array $entry) {
            [$last, $first] = str_contains($entry['name'], ',')
                ? array_map('trim', explode(',', $entry['name'], 2))
                : [$entry['name'], ''];

            if ($entry['student']) {
                return [
                    'status' => 'existing', 'lrn' => $entry['student']->lrn,
                    'last_name' => $entry['student']->last_name, 'first_name' => $entry['student']->first_name,
                    'middle_name' => '', 'gender' => '', 'birthdate' => '',
                    'reason' => 'Matched to an existing student in this section.',
                ];
            }

            return [
                'status' => 'rejected', 'lrn' => '', 'last_name' => $last, 'first_name' => $first,
                'middle_name' => '', 'gender' => '', 'birthdate' => '',
                'reason' => 'No confident match to an existing student in this section — never invented.',
            ];
        }, $matches);
    }

    /**
     * Saves only the rows the preview classified as INSERT — EXISTING,
     * CONFLICT, and REJECTED are never written, by construction (they
     * simply aren't in the insert loop below). One transaction: a
     * mid-batch failure leaves no partially-applied import behind.
     */
    public function importFromEcrConfirm(Request $request)
    {
        $pending = session('ecr_learner_import');
        abort_if(!$pending, 404, 'No ECR learner import is pending — upload a file first.');

        $inserted = 0;

        \DB::transaction(function () use ($pending, &$inserted) {
            foreach ($pending['rows'] as $row) {
                if ($row['status'] !== 'insert') {
                    continue;
                }

                Student::create([
                    'lrn'         => $row['lrn'],
                    'last_name'   => $row['last_name'],
                    'first_name'  => $row['first_name'],
                    'middle_name' => $row['middle_name'] ?: null,
                    'gender'      => $row['gender'],
                    'birthdate'   => $row['birthdate'] ?: null,
                    'section_id'  => $pending['section_id'],
                ]);
                $inserted++;
            }
        });

        Storage::disk('local')->delete(self::ECR_LEARNER_IMPORT_TEMP_DIR . '/' . $pending['stored_filename']);
        session()->forget('ecr_learner_import');

        $existingCount = count(array_filter($pending['rows'], fn($r) => $r['status'] === 'existing'));
        $conflictCount = count(array_filter($pending['rows'], fn($r) => $r['status'] === 'conflict'));
        $rejectedCount = count(array_filter($pending['rows'], fn($r) => $r['status'] === 'rejected'));

        LogActivity::log(
            action:      'import_learners_from_ecr',
            description: "Imported learners from ECR \"{$pending['source_filename']}\" ({$pending['format']}): {$inserted} inserted, {$existingCount} already existing, {$conflictCount} conflicts skipped, {$rejectedCount} rejected",
            tableName:   'students',
            recordId:    null
        );

        return redirect()->route('admin.students')
            ->with('success', "Imported {$inserted} new learner(s) from ECR. {$existingCount} already existed, {$conflictCount} conflict(s) and {$rejectedCount} rejected row(s) were skipped — nothing was overwritten.");
    }
}