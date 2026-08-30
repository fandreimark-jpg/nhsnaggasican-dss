<?php

namespace Tests\Feature;

use App\Imports\StudentsImport;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * StudentsImport is driven through Excel::import() against a real
 * generated CSV, rather than calling model()/rules() directly, because
 * WithValidation's row-skipping (SkipsOnFailure/SkipsFailures) only
 * kicks in as part of the full Maatwebsite import pipeline.
 */
class StudentsImportTest extends TestCase
{
    use RefreshDatabase;

    private function importCsv(Section $section, array $rows): StudentsImport
    {
        $csv = "lrn,last_name,first_name,middle_name,gender,birthdate\n";
        foreach ($rows as $row) {
            $csv .= implode(',', $row) . "\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'students_import_') . '.csv';
        file_put_contents($path, $csv);

        $import = new StudentsImport($section->id);
        Excel::import($import, $path);

        @unlink($path);

        return $import;
    }

    public function test_valid_row_is_imported_and_forced_into_the_advisers_section(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $import = $this->importCsv($section, [
            ['100000000001', 'Dela Cruz', 'Juan', 'Santos', 'male', '2008-05-01'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertDatabaseHas('students', [
            'lrn'        => '100000000001',
            'section_id' => $section->id,
        ]);
    }

    public function test_lrn_must_be_exactly_12_digits(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $import = $this->importCsv($section, [
            ['12345', 'Dela Cruz', 'Juan', '', 'male', '2008-05-01'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('students', ['last_name' => 'Dela Cruz']);
    }

    public function test_duplicate_lrn_is_rejected(): void
    {
        $adviser  = User::factory()->create();
        $section  = Section::factory()->create(['adviser_id' => $adviser->id]);
        Student::factory()->create(['section_id' => $section->id, 'lrn' => '100000000002']);

        $import = $this->importCsv($section, [
            ['100000000002', 'Reyes', 'Maria', '', 'female', '2008-05-01'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(1, Student::where('lrn', '100000000002')->count());
    }

    public function test_invalid_gender_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $import = $this->importCsv($section, [
            ['100000000003', 'Santos', 'Pedro', '', 'other', '2008-05-01'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('students', ['lrn' => '100000000003']);
    }

    public function test_future_birthdate_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $import = $this->importCsv($section, [
            ['100000000004', 'Gomez', 'Ana', '', 'female', '2099-01-01'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('students', ['lrn' => '100000000004']);
    }

    public function test_missing_required_field_is_rejected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $import = $this->importCsv($section, [
            ['100000000005', '', 'Ana', '', 'female', '2008-05-01'],
        ]);

        $this->assertCount(1, $import->failures());
    }

    public function test_valid_and_invalid_rows_in_the_same_file_are_handled_independently(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $import = $this->importCsv($section, [
            ['100000000006', 'Valid', 'Row', '', 'male', '2008-05-01'],
            ['bad-lrn', 'Invalid', 'Row', '', 'male', '2008-05-01'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseHas('students', ['lrn' => '100000000006']);
        $this->assertDatabaseMissing('students', ['last_name' => 'Invalid']);
    }

    public function test_students_are_always_assigned_to_the_importing_advisers_section(): void
    {
        // Security measure documented on StudentsImport::model() — the
        // section_id is forced from the constructor, never read from
        // the uploaded file, so an adviser can't inject students into
        // someone else's section via a crafted upload.
        $adviser      = User::factory()->create();
        $mySection    = Section::factory()->create(['adviser_id' => $adviser->id]);

        $this->importCsv($mySection, [
            ['100000000007', 'Cruz', 'Jose', '', 'male', '2008-05-01'],
        ]);

        $student = Student::where('lrn', '100000000007')->firstOrFail();
        $this->assertSame($mySection->id, $student->section_id);
    }
}
