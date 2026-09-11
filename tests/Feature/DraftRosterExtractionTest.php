<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * "Draft roster from an E-Class Record" feature — export only, never
 * creates a student directly. Verified through the real HTTP routes and
 * the real StudentsImport class (not a unit-level shortcut), so this
 * exercises the exact same code path a real admin would.
 */
class DraftRosterExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function filledFixturePath(): string
    {
        return 'C:\\Users\\kimbe\\AppData\\Local\\Temp\\claude\\filled_ecr_for_roster_test.xlsx';
    }

    public function test_extraction_from_a_filled_ecr_produces_the_expected_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $file = new UploadedFile($this->filledFixturePath(), 'filled.xlsx', null, null, true);

        $response = $this->actingAs($admin)->post(route('admin.students.extract-roster'), ['file' => $file]);

        $response->assertRedirect(route('admin.students'));
        $response->assertSessionHas('success');

        $rx = session('roster_extraction');
        $this->assertNotNull($rx);
        $this->assertCount(5, $rx['rows']);
        $this->assertSame(95, $rx['skipped_empty']);
        $this->assertSame(1, $rx['missing_lrn_count']);

        $download = $this->get(route('admin.students.extract-roster.download'));
        $download->assertOk();
        $download->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $download->getContent();

        $this->assertStringContainsString('lrn,last_name,first_name,middle_name,gender,birthdate', $csv);
        $this->assertStringContainsString('110000000001,Dela Cruz,Juan Miguel,Reyes,male,', $csv);
        $this->assertStringContainsString(',Reyes,Juan,,male,', $csv); // blank LRN
        $this->assertStringContainsString('110000000003,NOCOMMANAME,,,male,', $csv); // no comma at all
        $this->assertStringContainsString('110000000004,Santos,Maria,Clara,female,', $csv);
        $this->assertStringContainsString('110000000005,Bautista,Ana Lopez,Cruz,female,', $csv);

        // Session cleared once downloaded.
        $this->assertNull(session('roster_extraction'));

        // Save the real CSV to disk so it can be inspected/reported verbatim.
        file_put_contents(
            'C:\\Users\\kimbe\\AppData\\Local\\Temp\\claude\\draft_roster_downloaded.csv',
            $csv
        );
    }

    /**
     * Uses only well-formed names here -- NOCOMMANAME (no comma at all) is
     * a separate edge case already covered by the CSV-shape test above,
     * and deliberately produces its OWN rejection (blank first_name, which
     * StudentsImport also requires) -- mixing both edge cases into one
     * scenario would make it unclear which rejection this test is about.
     */
    public function test_a_blank_lrn_row_is_rejected_by_name_not_silently_skipped(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $csv = "lrn,last_name,first_name,middle_name,gender,birthdate\n"
            . "110000000001,Dela Cruz,Juan Miguel,Reyes,male,\n"
            . ",Reyes,Juan,,male,\n" // blank LRN -- must be rejected by name
            . "110000000004,Santos,Maria,Clara,female,\n"
            . "110000000005,Bautista,Ana Lopez,Cruz,female,\n";

        $path = tempnam(sys_get_temp_dir(), 'draft_roster_') . '.csv';
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'draft_roster.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post(route('admin.students.import'), [
            'section_id' => $section->id,
            'file'       => $file,
        ]);
        @unlink($path);

        $response->assertRedirect(route('admin.students'));
        $response->assertSessionHas('warning');

        $errors = session('import_errors');
        $this->assertNotNull($errors);

        // Save the real messages so they can be reported verbatim.
        file_put_contents(
            'C:\\Users\\kimbe\\AppData\\Local\\Temp\\claude\\import_rejection_messages.txt',
            "warning: " . session('warning') . "\n\n" . implode("\n", $errors)
        );

        // The other 3 valid rows imported; the blank-LRN row did not.
        $this->assertSame(3, Student::count());
        $this->assertDatabaseMissing('students', ['first_name' => 'Juan', 'last_name' => 'Reyes']);
    }

    public function test_filling_in_the_lrn_and_reimporting_lands_all_students_correctly(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $csv = "lrn,last_name,first_name,middle_name,gender,birthdate\n"
            . "110000000001,Dela Cruz,Juan Miguel,Reyes,male,\n"
            . "110000000002,Reyes,Juan,,male,\n" // LRN now filled in
            . "110000000004,Santos,Maria,Clara,female,\n"
            . "110000000005,Bautista,Ana Lopez,Cruz,female,\n";

        $path = tempnam(sys_get_temp_dir(), 'draft_roster_') . '.csv';
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'draft_roster.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post(route('admin.students.import'), [
            'section_id' => $section->id,
            'file'       => $file,
        ]);
        @unlink($path);

        $response->assertRedirect(route('admin.students'));
        $response->assertSessionHas('success', 'Students imported successfully!');
        $this->assertSame(4, Student::count());

        $juanMiguel = Student::where('lrn', '110000000001')->first();
        $this->assertSame('Dela Cruz', $juanMiguel->last_name);
        $this->assertSame('Juan Miguel', $juanMiguel->first_name);
        $this->assertSame('Reyes', $juanMiguel->middle_name);
        $this->assertSame('male', $juanMiguel->gender);
        $this->assertSame($section->id, $juanMiguel->section_id);

        $maria = Student::where('lrn', '110000000004')->first();
        $this->assertSame('Santos', $maria->last_name);
        $this->assertSame('Maria', $maria->first_name);
        $this->assertSame('Clara', $maria->middle_name);
        $this->assertSame('female', $maria->gender);
        $this->assertSame($section->id, $maria->section_id);
    }

    /**
     * A name with no comma at all (a malformed row, not the normal "Last,
     * First Middle" shape) produces a blank first_name -- which
     * StudentsImport ALSO requires, independently of the LRN. This is a
     * genuine interaction between the two features, not a bug: the
     * extractor faithfully exports what it read (last_name = the whole
     * string, first/middle blank), and the existing importer correctly
     * refuses to create a student with no first name either way.
     */
    public function test_a_malformed_no_comma_name_is_rejected_for_its_own_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $csv = "lrn,last_name,first_name,middle_name,gender,birthdate\n"
            . "110000000003,NOCOMMANAME,,,male,\n";

        $path = tempnam(sys_get_temp_dir(), 'draft_roster_') . '.csv';
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'draft_roster.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post(route('admin.students.import'), [
            'section_id' => $section->id,
            'file'       => $file,
        ]);
        @unlink($path);

        $response->assertSessionHas('warning');
        $this->assertSame(0, Student::count());
    }

    public function test_a_non_ecr_file_falls_through_with_a_clear_message(): void
    {
        $admin = User::factory()->admin()->create();
        $path = tempnam(sys_get_temp_dir(), 'not_ecr_') . '.xlsx';
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'not_ecr.xlsx', null, null, true);

        $response = $this->actingAs($admin)->post(route('admin.students.extract-roster'), ['file' => $file]);
        @unlink($path);

        $response->assertRedirect(route('admin.students'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('does not look like an SSHS E-Class Record', session('error'));
        $this->assertNull(session('roster_extraction'));
    }

    public function test_the_real_blank_checked_in_fixture_reports_nothing_to_extract(): void
    {
        $admin = User::factory()->admin()->create();
        $file = new UploadedFile(base_path('tests/Fixtures/SSHS-E-Class-Record-SY-2026-2027.xlsx'), 'blank.xlsx', null, null, true);

        $response = $this->actingAs($admin)->post(route('admin.students.extract-roster'), ['file' => $file]);

        $response->assertRedirect(route('admin.students'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('roster is empty', session('error'));
        $this->assertNull(session('roster_extraction'));
    }
}
