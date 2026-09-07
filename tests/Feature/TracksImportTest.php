<?php

namespace Tests\Feature;

use App\Imports\TracksImport;
use App\Models\Specialization;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * TracksImport is driven through Excel::import() against a real generated
 * CSV (same reasoning as SubjectsImportTest — WithValidation's row-skipping
 * only kicks in through the full Maatwebsite pipeline, not by calling
 * model()/rules() directly).
 */
class TracksImportTest extends TestCase
{
    use RefreshDatabase;

    private function importCsv(array $rows, string $header = "track_name,track_code,specialization_name,specialization_code"): TracksImport
    {
        $path = tempnam(sys_get_temp_dir(), 'tracks_import_') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, explode(',', $header));
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        $import = new TracksImport();
        Excel::import($import, $path);

        @unlink($path);

        return $import;
    }

    public function test_admin_tracks_page_renders_with_new_import_modal(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/tracks')->assertOk();
    }

    public function test_empty_tracks_table_shows_an_empty_state_pointing_at_the_persistent_add_button(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/tracks');

        $response->assertOk();
        $response->assertSee('No tracks yet.');
        // The "Add Track" control must exist exactly once — in the header —
        // not duplicated inside the empty state (see AssessmentsEmptyStateTest
        // and the "no screen renders two controls for the same action" rule).
        $this->assertSame(1, substr_count($response->getContent(), 'openAddTrackModal()'));
    }

    public function test_valid_row_creates_track_and_specialization(): void
    {
        $import = $this->importCsv([
            ['Academic Track', 'acad', 'Science, Technology, Engineering and Mathematics', 'stem'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertSame(1, $import->trackCount);
        $this->assertSame(1, $import->specializationCount);
        $this->assertDatabaseHas('tracks', ['name' => 'Academic Track', 'code' => 'ACAD']);
        $this->assertDatabaseHas('specializations', ['name' => 'Science, Technology, Engineering and Mathematics', 'code' => 'STEM']);
    }

    public function test_track_repeats_across_rows_but_is_created_only_once(): void
    {
        $import = $this->importCsv([
            ['Academic Track', 'ACAD', 'STEM', 'STEM'],
            ['Academic Track', 'ACAD', 'HUMSS', 'HUMSS'],
            ['Academic Track', 'ACAD', 'ABM', 'ABM'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertSame(1, $import->trackCount);
        $this->assertSame(3, $import->specializationCount);
        $this->assertSame(1, Track::where('code', 'ACAD')->count());
        $this->assertSame(3, Specialization::count());
    }

    public function test_blank_specialization_columns_create_track_only(): void
    {
        $import = $this->importCsv([
            ['Sports Track', 'SPORTS', '', ''],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertSame(1, $import->trackCount);
        $this->assertSame(0, $import->specializationCount);
        $this->assertDatabaseHas('tracks', ['code' => 'SPORTS']);
        $this->assertSame(0, Specialization::count());
    }

    public function test_reimporting_the_exact_same_file_is_a_no_op(): void
    {
        $rows = [
            ['Academic Track', 'ACAD', 'STEM', 'STEM'],
            ['Academic Track', 'ACAD', 'HUMSS', 'HUMSS'],
            ['Technical-Professional Track', 'TVL', 'ICT', 'ICT'],
        ];

        $this->importCsv($rows);
        $this->assertSame(2, Track::count());
        $this->assertSame(3, Specialization::count());

        // Re-run the identical file — must not create duplicates.
        $secondImport = $this->importCsv($rows);

        $this->assertCount(0, $secondImport->failures());
        $this->assertSame(2, Track::count());
        $this->assertSame(3, Specialization::count());
    }

    public function test_same_track_code_with_a_different_track_name_is_rejected(): void
    {
        $import = $this->importCsv([
            ['Academic Track', 'ACAD', '', ''],
            ['Academic Programs', 'ACAD', '', ''],
        ]);

        $this->assertCount(1, $import->failures());
    }

    public function test_same_track_code_and_specialization_code_pair_twice_is_rejected(): void
    {
        $import = $this->importCsv([
            ['Academic Track', 'ACAD', 'STEM', 'STEM'],
            ['Academic Track', 'ACAD', 'Science Tech Eng Math', 'STEM'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(1, Specialization::count());
    }

    public function test_same_specialization_code_under_a_different_track_is_not_a_conflict(): void
    {
        $import = $this->importCsv([
            ['Academic Track', 'ACAD', 'General', 'GEN'],
            ['Technical-Professional Track', 'TVL', 'General', 'GEN'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertSame(2, Specialization::where('code', 'GEN')->count());
    }

    public function test_specialization_name_without_code_is_rejected(): void
    {
        $import = $this->importCsv([
            ['Academic Track', 'ACAD', 'STEM', ''],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(0, Specialization::count());
    }

    public function test_specialization_code_without_name_is_rejected(): void
    {
        $import = $this->importCsv([
            ['Academic Track', 'ACAD', '', 'STEM'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(0, Specialization::count());
    }

    public function test_missing_required_track_field_is_rejected(): void
    {
        $import = $this->importCsv([
            ['', 'ACAD', '', ''],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(0, Track::count());
    }

    public function test_valid_and_invalid_rows_in_the_same_file_are_handled_independently(): void
    {
        $import = $this->importCsv([
            ['Valid Track', 'VAL', '', ''],
            ['', 'BAD', '', ''],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseHas('tracks', ['code' => 'VAL']);
    }

    public function test_admin_can_import_tracks_via_http_and_gets_a_summary(): void
    {
        $admin = User::factory()->admin()->create();

        $csv  = "track_name,track_code,specialization_name,specialization_code\n";
        $csv .= "Academic Track,ACAD,STEM,STEM\n";
        $path = tempnam(sys_get_temp_dir(), 'tracks_http_import_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'tracks.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/tracks/import', ['file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.tracks'));
        $response->assertSessionHas('success', '1 track(s) and 1 specialization(s) imported successfully!');
        $this->assertDatabaseHas('tracks', ['name' => 'Academic Track', 'code' => 'ACAD']);
        $this->assertDatabaseHas('specializations', ['name' => 'STEM', 'code' => 'STEM']);
    }

    public function test_adviser_cannot_import_tracks(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->post('/admin/tracks/import', [])
            ->assertForbidden();
    }

    public function test_principal_cannot_import_tracks(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->post('/admin/tracks/import', [])
            ->assertForbidden();
    }
}
