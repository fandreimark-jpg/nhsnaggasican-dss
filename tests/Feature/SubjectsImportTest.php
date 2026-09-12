<?php

namespace Tests\Feature;

use App\Imports\SubjectsImport;
use App\Models\Specialization;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * SubjectsImport is driven through Excel::import() against a real generated
 * CSV (same reasoning as StudentsImportTest — WithValidation's row-skipping
 * only kicks in through the full Maatwebsite pipeline).
 */
class SubjectsImportTest extends TestCase
{
    use RefreshDatabase;

    private function importCsv(array $rows, string $header = "name,type,grade_level,track,specialization"): SubjectsImport
    {
        $csv = $header . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', $row) . "\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'subjects_import_') . '.csv';
        file_put_contents($path, $csv);

        $import = new SubjectsImport();
        Excel::import($import, $path);

        @unlink($path);

        return $import;
    }

    public function test_admin_subjects_page_renders_with_new_import_modal(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/subjects')->assertOk();
    }

    public function test_valid_core_subject_is_imported(): void
    {
        $import = $this->importCsv([
            ['General Mathematics', 'core', '11', 'core_academic', '', ''],
        ], 'name,type,grade_level,subject_group,track,specialization');

        $this->assertCount(0, $import->failures());
        $this->assertSame(1, $import->importedCount);
        $this->assertDatabaseHas('subjects', [
            'name'        => 'General Mathematics',
            'type'        => 'core',
            'grade_level' => 11,
            'track_id'    => null,
        ]);
    }

    public function test_elective_subject_resolves_track_and_specialization_by_name(): void
    {
        $track = Track::factory()->create(['name' => 'Technical-Professional Track', 'code' => 'TVL']);
        $spec  = Specialization::factory()->create(['track_id' => $track->id, 'name' => 'Information and Communications Technology', 'code' => 'ICT']);

        // "Subject classification and grading weights cleanup" pass —
        // subject_group must be BLANK for a Grade 12 row (DO 8, s. 2015
        // weighs by section track, not subject_group) -- track/spec
        // resolution is what's under test here, not weighting.
        $import = $this->importCsv([
            ['Programming', 'elective', '12', '', 'Technical-Professional Track', 'ICT'],
        ], 'name,type,grade_level,subject_group,track,specialization');

        $this->assertCount(0, $import->failures());
        $this->assertDatabaseHas('subjects', [
            'name'              => 'Programming',
            'track_id'          => $track->id,
            'specialization_id' => $spec->id,
        ]);
    }

    public function test_elective_subject_resolves_track_by_code(): void
    {
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        // Grade 12 -- subject_group must be blank (see note above).
        $import = $this->importCsv([
            ['Research', 'elective', '12', '', 'ACAD', ''],
        ], 'name,type,grade_level,subject_group,track,specialization');

        $this->assertCount(0, $import->failures());
        $this->assertDatabaseHas('subjects', ['name' => 'Research', 'track_id' => $track->id]);
    }

    public function test_unknown_track_name_leaves_track_null_instead_of_failing(): void
    {
        // Track/specialization are optional lookups, not required fields —
        // matching the existing manual Add Subject form's own leniency.
        // Grade 12 -- subject_group must be blank (see note above).
        $import = $this->importCsv([
            ['Mystery Elective', 'elective', '12', '', 'Nonexistent Track', ''],
        ], 'name,type,grade_level,subject_group,track,specialization');

        $this->assertCount(0, $import->failures());
        $this->assertDatabaseHas('subjects', ['name' => 'Mystery Elective', 'track_id' => null]);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $import = $this->importCsv([
            ['Weird Subject', 'bogus', '11', '', ''],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('subjects', ['name' => 'Weird Subject']);
    }

    public function test_invalid_grade_level_is_rejected(): void
    {
        $import = $this->importCsv([
            ['Grade 10 Subject', 'core', '10', '', ''],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('subjects', ['name' => 'Grade 10 Subject']);
    }

    public function test_duplicate_against_existing_database_subject_is_rejected(): void
    {
        Subject::factory()->create(['name' => 'Filipino', 'grade_level' => 11, 'type' => 'core']);

        $import = $this->importCsv([
            ['Filipino', 'core', '11', 'core_academic', '', ''],
        ], 'name,type,grade_level,subject_group,track,specialization');

        $this->assertCount(1, $import->failures());
        $this->assertSame(1, Subject::where('name', 'Filipino')->count());
    }

    public function test_duplicate_within_the_same_file_is_rejected_without_crashing(): void
    {
        $import = $this->importCsv([
            ['Physical Education', 'core', '11', 'core_academic', '', ''],
            ['Physical Education', 'core', '11', 'core_academic', '', ''],
        ], 'name,type,grade_level,subject_group,track,specialization');

        $this->assertCount(1, $import->failures());
        $this->assertSame(1, Subject::where('name', 'Physical Education')->count());
    }

    public function test_same_name_different_grade_level_is_not_a_duplicate(): void
    {
        // Grade 11 requires subject_group; Grade 12 requires it blank.
        $import = $this->importCsv([
            ['Statistics', 'core', '11', 'core_academic', '', ''],
            ['Statistics', 'core', '12', '', '', ''],
        ], 'name,type,grade_level,subject_group,track,specialization');

        $this->assertCount(0, $import->failures());
        $this->assertSame(2, Subject::where('name', 'Statistics')->count());
    }

    public function test_valid_and_invalid_rows_in_the_same_file_are_handled_independently(): void
    {
        $import = $this->importCsv([
            ['Valid Subject', 'core', '11', 'core_academic', '', ''],
            ['Invalid Subject', 'not-a-type', '11', '', '', ''],
        ], 'name,type,grade_level,subject_group,track,specialization');

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseHas('subjects', ['name' => 'Valid Subject']);
        $this->assertDatabaseMissing('subjects', ['name' => 'Invalid Subject']);
    }

    public function test_admin_can_import_subjects_via_http_and_gets_a_summary(): void
    {
        $admin = User::factory()->admin()->create();

        $csv  = "name,type,grade_level,subject_group,track,specialization\n";
        $csv .= "Empowerment Technologies,core,11,core_academic,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_http_import_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'subjects.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/subjects/import', ['grade_level' => '11', 'file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.subjects'));
        $response->assertSessionHas('success', '1 subject(s) imported successfully!');
        $this->assertDatabaseHas('subjects', ['name' => 'Empowerment Technologies']);
    }

    /**
     * "ECR alignment" work order, bug found in testing — validSubjectGroups()
     * was unscoped and would have accepted a do8_* slug from a file column,
     * the same way the Admin form's dropdown accepted it before that fix.
     * The five do8_* subject_group_weights rows are computed by
     * GradingEngine::resolveDo8GroupKey(), never typed by a human or a
     * file, in either place.
     */
    public function test_a_do8_subject_group_in_the_file_is_rejected(): void
    {
        // importCsv()'s default header has no subject_group column, so
        // build the CSV directly here instead.
        $csv = "name,type,grade_level,subject_group,track,specialization\n";
        $csv .= "Sneaky Do8 Subject,core,12,do8_core,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_import_') . '.csv';
        file_put_contents($path, $csv);
        $import = new SubjectsImport();
        Excel::import($import, $path);
        @unlink($path);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('subjects', ['name' => 'Sneaky Do8 Subject']);
    }

    /**
     * "Subject classification and grading weights cleanup" pass -- a
     * Grade 11 subject has no safe default for a blank subject_group, core
     * or elective (the prior "blank CORE row defaults to core_academic"
     * leniency was removed on purpose -- an unclassified subject must stay
     * unclassified, never quietly become Core). This exercises the
     * elective case specifically -- Arts/Research/TechPro/Field Experience
     * electives all carry different weights, so there is no single safe
     * guess.
     */
    public function test_an_elective_with_no_subject_group_is_rejected_not_defaulted_to_core(): void
    {
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        $csv = "name,type,grade_level,subject_group,track,specialization\n";
        $csv .= "Creative Writing,elective,11,,{$track->name},\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_import_') . '.csv';
        file_put_contents($path, $csv);
        $import = new SubjectsImport();
        Excel::import($import, $path);
        @unlink($path);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('subjects', ['name' => 'Creative Writing']);
    }

    public function test_an_elective_with_an_explicit_subject_group_still_imports(): void
    {
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        // 'field_exposure' -- a real do015_2026 group seeded by
        // SubjectGroupWeightsSeeder, not core_academic -- proves the row
        // imports with the group it actually declared, not a default.
        $csv = "name,type,grade_level,subject_group,track,specialization\n";
        $csv .= "Creative Writing,elective,11,field_exposure,{$track->name},\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_import_') . '.csv';
        file_put_contents($path, $csv);
        $import = new SubjectsImport();
        Excel::import($import, $path);
        @unlink($path);

        $this->assertCount(0, $import->failures());
        $this->assertDatabaseHas('subjects', [
            'name' => 'Creative Writing', 'type' => 'elective', 'subject_group' => 'field_exposure',
        ]);
    }

    /**
     * SYSTEM_FIXES_AND_ML_AUDIT.md, "Subject upload by year level" -- a
     * Grade 12 row in a file uploaded against a Grade 11 selection must be
     * rejected, not silently imported under the wrong grade level.
     */
    public function test_a_row_whose_grade_level_does_not_match_the_selected_upload_grade_is_rejected(): void
    {
        $csv  = "name,type,grade_level,track,specialization\n";
        $csv .= "Statistics and Probability,core,12,,\n"; // Grade 12 row
        $path = tempnam(sys_get_temp_dir(), 'subjects_import_') . '.csv';
        file_put_contents($path, $csv);

        $import = new SubjectsImport(11); // Admin selected Grade 11
        Excel::import($import, $path);
        @unlink($path);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('subjects', ['name' => 'Statistics and Probability']);
    }

    public function test_a_row_matching_the_selected_upload_grade_still_imports(): void
    {
        $csv  = "name,type,grade_level,subject_group,track,specialization\n";
        $csv .= "Statistics and Probability,core,11,core_academic,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_import_') . '.csv';
        file_put_contents($path, $csv);

        $import = new SubjectsImport(11);
        Excel::import($import, $path);
        @unlink($path);

        $this->assertCount(0, $import->failures());
        $this->assertDatabaseHas('subjects', ['name' => 'Statistics and Probability', 'grade_level' => 11]);
    }

    public function test_admin_must_select_a_grade_level_before_uploading_subjects(): void
    {
        $admin = User::factory()->admin()->create();

        $csv  = "name,type,grade_level,track,specialization\n";
        $csv .= "Statistics and Probability,core,11,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_import_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'subjects.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/subjects/import', ['file' => $file]);
        @unlink($path);

        $response->assertSessionHasErrors(['grade_level'], null, 'import');
        $this->assertDatabaseMissing('subjects', ['name' => 'Statistics and Probability']);
    }

    public function test_a_grade_12_row_uploaded_against_a_grade_11_selection_is_rejected_via_http(): void
    {
        $admin = User::factory()->admin()->create();

        $csv  = "name,type,grade_level,track,specialization\n";
        $csv .= "Statistics and Probability,core,12,,\n";
        $path = tempnam(sys_get_temp_dir(), 'subjects_import_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'subjects.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/subjects/import', ['grade_level' => '11', 'file' => $file]);
        @unlink($path);

        $response->assertRedirect(route('admin.subjects'));
        $response->assertSessionHas('warning');
        $this->assertStringContainsString('Grade 11 was selected', implode("\n", session('import_errors')));
        $this->assertDatabaseMissing('subjects', ['name' => 'Statistics and Probability']);
    }

    public function test_adviser_cannot_import_subjects(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->post('/admin/subjects/import', [])
            ->assertForbidden();
    }

    public function test_principal_cannot_import_subjects(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->post('/admin/subjects/import', [])
            ->assertForbidden();
    }
}
