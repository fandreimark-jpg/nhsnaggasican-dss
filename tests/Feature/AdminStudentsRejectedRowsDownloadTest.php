<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * "Fix and re-upload just the rejected rows" feature. StudentsImport
 * already carries each rejected row's full original data via Maatwebsite's
 * Failure::values() -- this stores that in session after a partial-failure
 * import and streams it back out as a re-uploadable CSV, so correcting 2 of
 * 8 rejected rows means uploading 2 rows next time, not re-submitting the
 * whole original 8 (which would bounce the other 6, already-imported rows
 * off the LRN unique constraint again).
 */
class AdminStudentsRejectedRowsDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function importFile(User $admin, Section $section, string $csv)
    {
        $path = tempnam(sys_get_temp_dir(), 'rejected_rows_') . '.csv';
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'students.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/students/import', [
            'section_id' => $section->id,
            'file'       => $file,
        ]);

        @unlink($path);

        return $response;
    }

    public function test_import_result_states_created_and_rejected_counts_with_rejected_rows_named(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $csv  = "lrn,last_name,first_name,middle_name,gender\n";
        $csv .= "100000000301,Valid,One,,male\n";
        $csv .= ",Delos Santos,Maria,,female\n"; // blank LRN
        $csv .= "100000000302,Valid,Two,,female\n";

        $response = $this->importFile($admin, $section, $csv);

        $response->assertRedirect(route('admin.students'));
        $response->assertSessionHas('warning', '2 student(s) created. 1 row(s) were rejected:');

        $errors = session('import_errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Delos Santos, Maria', $errors[0]);

        $this->assertSame(2, Student::where('section_id', $section->id)->count());
        $this->assertDatabaseMissing('students', ['last_name' => 'Delos Santos']);
    }

    public function test_fully_successful_import_states_the_created_count_and_clears_any_stale_rejected_rows(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        // Leave a stale rejected_students entry from an earlier request,
        // as if a prior partial-failure import had happened in this session.
        session(['rejected_students' => ['rows' => [['lrn' => '1']], 'source_filename' => 'old.csv']]);

        $csv  = "lrn,last_name,first_name,middle_name,gender\n";
        $csv .= "100000000303,Valid,Three,,male\n";

        $response = $this->importFile($admin, $section, $csv);

        $response->assertSessionHas('success', '1 student(s) imported successfully!');
        $this->assertNull(session('rejected_students'));
    }

    public function test_rejected_rows_can_be_downloaded_as_a_reuploadable_csv(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $csv  = "lrn,last_name,first_name,middle_name,gender\n";
        $csv .= "100000000304,Valid,Four,,male\n";
        $csv .= ",Delos Santos,Maria,Reyes,female\n"; // blank LRN
        $csv .= ",Bautista,Jose,,male\n";             // blank LRN

        $this->importFile($admin, $section, $csv);

        $download = $this->actingAs($admin)->get('/admin/students/rejected/download');

        $download->assertOk();
        $download->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $download->getContent();
        $this->assertStringContainsString("lrn,last_name,first_name,middle_name,gender\n", $content);
        $this->assertStringContainsString(',Delos Santos,Maria,Reyes,female', $content);
        $this->assertStringContainsString(',Bautista,Jose,,male', $content);
        // The one row that imported successfully must NOT be in the
        // download -- only the two rejected rows belong in it.
        $this->assertStringNotContainsString('100000000304', $content);
        $this->assertStringNotContainsString('Valid,Four', $content);

        // Exactly the rejected rows, nothing more -- header line + 2 rows.
        $this->assertSame(3, substr_count($content, "\n"));
    }

    public function test_downloading_rejected_rows_with_nothing_pending_is_a_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/students/rejected/download')->assertNotFound();
    }

    public function test_the_download_link_only_appears_on_the_students_page_after_a_rejection(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $this->actingAs($admin)->get('/admin/students')->assertDontSee('Download rejected rows');

        $csv  = "lrn,last_name,first_name,middle_name,gender\n";
        $csv .= ",Delos Santos,Maria,,female\n"; // blank LRN

        $this->importFile($admin, $section, $csv);

        $this->actingAs($admin)->get('/admin/students')->assertSee('Download rejected rows');
    }
}
