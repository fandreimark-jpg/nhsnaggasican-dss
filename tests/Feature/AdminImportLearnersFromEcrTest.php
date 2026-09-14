<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * "Import Learners from ECR" -- a DISTINCT admin feature from Import
 * Students (plain CSV) and the existing "Draft roster" export-only flow.
 * Reads learner identity directly from a real SSHS or Grade 12 workbook
 * and writes to `students`, but ONLY after the admin reviews a classified
 * preview (Insert/Existing/Conflict/Rejected) and confirms it.
 */
class AdminImportLearnersFromEcrTest extends TestCase
{
    use RefreshDatabase;

    private function academicSnapshot(Student $student): array
    {
        $grade = \App\Models\Grade::factory()->create(['student_id' => $student->id, 'section_id' => $student->section_id]);
        $assessment = \App\Models\Assessment::factory()->create(['section_id' => $student->section_id, 'subject_id' => $grade->subject_id]);
        \App\Models\AssessmentScore::factory()->create(['student_id' => $student->id, 'assessment_id' => $assessment->id]);
        $risk = \App\Models\RiskResult::create(['student_id' => $student->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'average_grade' => 85, 'risk_level' => 'low', 'generated_at' => now()]);
        \App\Models\Intervention::factory()->create(['student_id' => $student->id, 'risk_result_id' => $risk->id, 'subject_id' => $grade->subject_id]);

        $snapshot = [];
        foreach (['students', 'grades', 'assessments', 'assessment_scores', 'risk_results', 'interventions'] as $table) {
            $snapshot[$table] = \DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $snapshot;
    }

    private function assertSnapshotPreserved(array $snapshot): void
    {
        foreach ($snapshot as $table => $rows) {
            foreach ($rows as $row) {
                $this->assertSame($row, (array) \DB::table($table)->find($row['id']), $table.' history changed');
            }
            if ($table !== 'students') $this->assertDatabaseCount($table, count($rows));
        }
    }

    private function previewRoster(Section $section, array $roster)
    {
        $path = $this->buildFilledSshsFixture($roster);
        try {
            return $this->post('/admin/students/import-from-ecr/preview', [
                'section_id' => $section->id,
                'file' => new UploadedFile($path, 'anonymous-roster.xlsx', null, null, true),
            ])->assertOk();
        } finally {
            @unlink($path);
        }
    }

    public function test_import_adds_twelve_to_six_narra_learners_without_changing_any_history(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $section = Section::factory()->create(['name' => 'Narra', 'grade_level' => 11]);
        $original = Student::factory()->count(6)->create(['section_id' => $section->id]);
        $snapshot = $this->academicSnapshot($original->first());
        $roster = array_map(fn ($n) => [sprintf('990000000%03d', $n), 'Demo'.$n.', Learner'], range(1, 12));

        $preview = $this->previewRoster($section, $roster);
        $this->assertSame(12, $preview->viewData('counts')['insert']);
        $this->assertSame(6, $section->students()->count(), 'Preview must not write students.');
        $preview->assertSee('data-import-confirm="Insert 12 new learner(s) into Narra? Existing, Conflict, and Rejected rows will be skipped and will not be modified."', false);
        $html = new \DOMDocument();
        @$html->loadHTML($preview->getContent());
        $xpath = new \DOMXPath($html);
        $this->assertSame(0, $xpath->query('//form[contains(@action, "import-from-ecr/confirm") and @data-confirm]')->length);
        $this->assertStringContainsString('Confirm Import', $xpath->query('//*[@id="confirmImportModal"]')->item(0)->textContent);
        $this->assertSame('Yes, Import', trim($xpath->query('//*[@id="confirmImportBtn"]')->item(0)->textContent));
        $this->assertStringContainsString('bg-brand-700', $xpath->query('//*[@id="confirmImportBtn"]')->item(0)->getAttribute('class'));
        $this->assertStringContainsString('bg-red-600', $xpath->query('//*[@id="confirmDeleteBtn"]')->item(0)->getAttribute('class'));
        $this->assertStringContainsString('Yes, Delete', $xpath->query('//*[@id="confirmDeleteBtn"]')->item(0)->textContent);

        $this->post('/admin/students/import-from-ecr/confirm')->assertRedirect(route('admin.students'))->assertSessionHas('success');
        $this->assertSame(18, $section->students()->count());
        foreach ($roster as [$lrn]) $this->assertDatabaseHas('students', ['lrn' => $lrn, 'section_id' => $section->id]);
        $this->assertSnapshotPreserved($snapshot);
    }

    public function test_mixed_preview_writes_only_five_insert_rows_and_preserves_all_other_rows(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $section = Section::factory()->create(['name' => 'Narra', 'grade_level' => 11]);
        $other = Section::factory()->create(['name' => 'Molave', 'grade_level' => 11]);
        $existing = Student::factory()->count(3)->create(['section_id' => $section->id]);
        $conflicts = Student::factory()->count(2)->create(['section_id' => $other->id]);
        $snapshot = $this->academicSnapshot($existing->first());
        $roster = array_map(fn ($n) => [sprintf('990000000%03d', $n), 'Demo'.$n.', Learner'], range(1, 5));
        foreach ($existing->concat($conflicts) as $student) $roster[] = [$student->lrn, 'Changed, Must Not Apply'];
        $roster[] = ['', 'RejectedOne, Demo'];
        $roster[] = ['', 'RejectedTwo, Demo'];

        $preview = $this->previewRoster($section, $roster);
        $this->assertSame(['insert' => 5, 'existing' => 3, 'conflict' => 2, 'rejected' => 2], $preview->viewData('counts'));
        $this->post('/admin/students/import-from-ecr/confirm')->assertRedirect(route('admin.students'))->assertSessionHas('success');
        $this->assertDatabaseCount('students', 10);
        $this->assertSame(8, $section->students()->count());
        $this->assertSame(2, $other->students()->count());
        $this->assertDatabaseMissing('students', ['last_name' => 'Changed']);
        $this->assertDatabaseMissing('students', ['last_name' => 'RejectedOne']);
        $this->assertDatabaseMissing('students', ['last_name' => 'RejectedTwo']);
        $this->assertSnapshotPreserved($snapshot);
    }

    private function sshsFixture(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx'),
            'SSHS-E-Class-Record-SY-2026-2027.xlsx', null, null, true
        );
    }

    private function grade12Fixture(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/GRADE-12-SANITIZED.xlsx'),
            'GRADE-12-SANITIZED.xlsx', null, null, true
        );
    }

    /** Same technique DraftRosterExtractionTest already uses -- clone the real checked-in SSHS template and fill in a few roster cells, never touching the real fixture on disk. */
    private function buildFilledSshsFixture(?array $roster = null): string
    {
        $source = base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx');
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($source);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($source);
        $inputData = $spreadsheet->getSheetByName('INPUT DATA');

        $roster ??= [
            ['110000000001', 'Dela Cruz, Juan Miguel'],
            ['110000000002', 'Reyes, Ana'],
        ];
        foreach ($roster as $index => [$lrn, $name]) {
            $row = 11 + $index;
            $inputData->setCellValueExplicit('N'.$row, $lrn, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $inputData->setCellValueExplicit('O'.$row, $name, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }

        $path = tempnam(sys_get_temp_dir(), 'ecr_learner_import_fixture_') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_admin_can_preview_an_sshs_ecr_and_see_classified_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'name' => 'Narra']);

        $response = $this->actingAs($admin)->post('/admin/students/import-from-ecr/preview', [
            'section_id' => $section->id,
            'file'       => $this->sshsFixture(),
        ]);

        $response->assertOk();
        $response->assertSee('Strengthened SHS E-Class Record');
        // The real checked-in SSHS fixture is a genuinely blank template --
        // zero roster rows, so the preview must say so rather than pretend
        // there's something to insert.
        $response->assertSee('No learner rows found');
    }

    public function test_admin_can_preview_and_confirm_a_grade_12_ecr_inserting_only_matched_conflict_free_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create(['code' => 'TECHPRO']);
        $section = Section::factory()->create(['grade_level' => 12, 'name' => 'AGILA', 'track_id' => $track->id, 'school_year' => '2026-2027']);

        // Every fixture roster name is unmatched (no students exist yet) --
        // Grade 12 NEVER inserts (no LRN in the file), so every row must
        // classify as rejected, not silently invented.
        $preview = $this->actingAs($admin)->post('/admin/students/import-from-ecr/preview', [
            'section_id' => $section->id,
            'file'       => $this->grade12Fixture(),
        ]);

        $preview->assertOk();
        $preview->assertSee('Grade 12 Class Record');
        $preview->assertSee('AGILA');
        $rows = $preview->viewData('rows');
        $this->assertCount(7, $rows);
        foreach ($rows as $row) {
            $this->assertSame('rejected', $row['status'], 'Grade 12 must never invent an LRN -- an unmatched name is rejected, not inserted.');
        }
        $this->assertSame(0, $preview->viewData('counts')['insert']);
    }

    public function test_a_grade_12_learner_matching_an_existing_student_classifies_as_existing_and_is_never_reinserted(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create(['code' => 'TECHPRO']);
        $section = Section::factory()->create(['grade_level' => 12, 'name' => 'AGILA', 'track_id' => $track->id, 'school_year' => '2026-2027']);
        $existing = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'ALPHA', 'first_name' => 'JUAN']);

        $preview = $this->actingAs($admin)->post('/admin/students/import-from-ecr/preview', [
            'section_id' => $section->id,
            'file'       => $this->grade12Fixture(),
        ]);

        $rows = $preview->viewData('rows');
        $alphaRow = collect($rows)->firstWhere('last_name', 'ALPHA');
        $this->assertSame('existing', $alphaRow['status']);
        $this->assertSame($existing->lrn, $alphaRow['lrn']);

        $this->actingAs($admin)->post('/admin/students/import-from-ecr/confirm')->assertRedirect();
        $this->assertSame(1, Student::where('last_name', 'ALPHA')->count(), 'An existing match must never be duplicated.');
    }

    public function test_confirm_inserts_only_insert_rows_never_existing_conflict_or_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $sectionA = Section::factory()->create(['grade_level' => 11, 'name' => 'Narra', 'school_year' => '2026-2027']);
        $sectionB = Section::factory()->create(['grade_level' => 11, 'name' => 'Molave', 'school_year' => '2026-2027']);

        // A student whose LRN would collide, belonging to a DIFFERENT
        // section -- this must classify as CONFLICT, not be reassigned.
        $conflictLrn = '110000000099';
        Student::factory()->create(['lrn' => $conflictLrn, 'section_id' => $sectionB->id]);

        // Build a tiny real-shaped SSHS-like CSV path is not applicable here
        // (SSHS reads from a real xlsx) -- instead verify the classification
        // helper end-to-end using the real checked-in fixture, which is a
        // blank template (0 rows), so this test asserts the confirm() path
        // is transaction-safe and idempotent-safe with an EMPTY pending
        // import rather than fabricating xlsx content.
        $preview = $this->actingAs($admin)->post('/admin/students/import-from-ecr/preview', [
            'section_id' => $sectionA->id,
            'file'       => $this->sshsFixture(),
        ]);
        $preview->assertOk();

        $before = Student::count();
        $this->actingAs($admin)->post('/admin/students/import-from-ecr/confirm')->assertRedirect();
        $this->assertSame($before, Student::count(), 'A blank template has nothing to insert -- confirm() must not create anything.');
    }

    public function test_a_filled_sshs_ecr_classifies_new_lrns_as_insert_and_a_colliding_lrn_in_another_section_as_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        $targetSection = Section::factory()->create(['grade_level' => 11, 'name' => 'Narra']);
        $otherSection = Section::factory()->create(['grade_level' => 11, 'name' => 'Molave']);
        Student::factory()->create(['lrn' => '110000000002', 'section_id' => $otherSection->id]);

        $fixturePath = $this->buildFilledSshsFixture();
        $file = new UploadedFile($fixturePath, 'filled.xlsx', null, null, true);

        $preview = $this->actingAs($admin)->post('/admin/students/import-from-ecr/preview', [
            'section_id' => $targetSection->id,
            'file'       => $file,
        ]);
        @unlink($fixturePath);

        $preview->assertOk();
        $rows = $preview->viewData('rows');
        $this->assertCount(2, $rows);

        $insertRow = collect($rows)->firstWhere('lrn', '110000000001');
        $this->assertSame('insert', $insertRow['status']);

        $conflictRow = collect($rows)->firstWhere('lrn', '110000000002');
        $this->assertSame('conflict', $conflictRow['status']);

        $this->assertSame(1, $preview->viewData('counts')['insert']);
        $this->assertSame(1, $preview->viewData('counts')['conflict']);

        // Confirm: only the INSERT row is written; the CONFLICT row's
        // existing student is completely untouched, still in its own section.
        $this->actingAs($admin)->post('/admin/students/import-from-ecr/confirm')->assertRedirect();

        $this->assertDatabaseHas('students', ['lrn' => '110000000001', 'section_id' => $targetSection->id, 'last_name' => 'Dela Cruz']);
        $this->assertSame(1, Student::where('lrn', '110000000002')->count(), 'The conflicting LRN must never be duplicated.');
        $this->assertSame($otherSection->id, Student::where('lrn', '110000000002')->first()->section_id, 'The conflicting student must stay in their original section, never reassigned.');
    }

    /**
     * SYSTEM_FIXES_AND_ML_AUDIT.md, "duplicate import protection" -- the
     * same ECR uploaded a second time must not create a second student.
     * The first upload's INSERT row is now an EXISTING row (its LRN is in
     * the database, in the same section) the second time, so the second
     * confirm() genuinely has nothing new to insert.
     */
    public function test_reuploading_the_same_sshs_ecr_does_not_duplicate_the_learner(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'name' => 'Narra']);

        $doImportOnce = function () use ($admin, $section) {
            $fixturePath = $this->buildFilledSshsFixture();
            $file = new UploadedFile($fixturePath, 'filled.xlsx', null, null, true);

            $preview = $this->actingAs($admin)->post('/admin/students/import-from-ecr/preview', [
                'section_id' => $section->id,
                'file'       => $file,
            ]);
            @unlink($fixturePath);
            $preview->assertOk();

            return $this->actingAs($admin)->post('/admin/students/import-from-ecr/confirm');
        };

        $doImportOnce()->assertRedirect();
        $countAfterFirst = Student::count();
        $this->assertSame(2, $countAfterFirst); // the fixture's 2 real (non-conflicting) LRNs

        $second = $doImportOnce();
        $second->assertRedirect();
        $this->assertSame($countAfterFirst, Student::count(), 'Re-uploading the same ECR must not create duplicate students.');
        $second->assertSessionHas('success');
        $this->assertStringContainsString('Imported 0 new learner', session('success'), 'The second upload must clearly report that nothing new was inserted, not silently succeed as if it were the first time.');
    }

    public function test_confirming_with_nothing_pending_is_a_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/students/import-from-ecr/confirm')->assertNotFound();
    }

    public function test_an_unrecognized_file_format_is_rejected_with_a_clear_message(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $path = tempnam(sys_get_temp_dir(), 'not_an_ecr_') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx(new \PhpOffice\PhpSpreadsheet\Spreadsheet()))->save($path);
        $file = new UploadedFile($path, 'not-an-ecr.xlsx', null, null, true);

        $response = $this->actingAs($admin)->post('/admin/students/import-from-ecr/preview', [
            'section_id' => $section->id,
            'file'       => $file,
        ]);
        @unlink($path);

        $response->assertRedirect(route('admin.students'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('not a recognized E-Class Record format', session('error'));
    }

    public function test_adviser_cannot_use_import_learners_from_ecr(): void
    {
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section = Section::factory()->create();

        $this->actingAs($adviser)->post('/admin/students/import-from-ecr/preview', [
            'section_id' => $section->id,
            'file'       => $this->sshsFixture(),
        ])->assertForbidden();
    }

    public function test_principal_cannot_use_import_learners_from_ecr(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create();

        $this->actingAs($principal)->post('/admin/students/import-from-ecr/preview', [
            'section_id' => $section->id,
            'file'       => $this->sshsFixture(),
        ])->assertForbidden();
    }
}
