<?php

namespace Tests\Feature;

use App\Imports\TracksImport;
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

    private function importCsv(array $rows, string $header = "name,code"): TracksImport
    {
        $csv = $header . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', $row) . "\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'tracks_import_') . '.csv';
        file_put_contents($path, $csv);

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

    public function test_valid_track_is_imported(): void
    {
        $import = $this->importCsv([
            ['Academic Track', 'acad'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertSame(1, $import->importedCount);
        // Code is auto-uppercased, matching TrackController::store().
        $this->assertDatabaseHas('tracks', ['name' => 'Academic Track', 'code' => 'ACAD']);
    }

    public function test_duplicate_name_against_existing_database_track_is_rejected(): void
    {
        Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        $import = $this->importCsv([
            ['Academic Track', 'ACAD2'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(1, Track::where('name', 'Academic Track')->count());
    }

    public function test_duplicate_code_against_existing_database_track_is_rejected_case_insensitively(): void
    {
        Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        $import = $this->importCsv([
            ['Some Other Track', 'acad'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('tracks', ['name' => 'Some Other Track']);
    }

    public function test_duplicate_within_the_same_file_is_rejected_without_crashing(): void
    {
        $import = $this->importCsv([
            ['Technical-Professional Track', 'TVL'],
            ['Technical-Professional Track', 'TVL2'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(1, Track::where('name', 'Technical-Professional Track')->count());
    }

    public function test_missing_required_field_is_rejected(): void
    {
        $import = $this->importCsv([
            ['', 'ACAD'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(0, Track::count());
    }

    public function test_valid_and_invalid_rows_in_the_same_file_are_handled_independently(): void
    {
        $import = $this->importCsv([
            ['Valid Track', 'VAL'],
            ['', 'BAD'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseHas('tracks', ['name' => 'Valid Track']);
    }

    public function test_admin_can_import_tracks_via_http_and_gets_a_summary(): void
    {
        $admin = User::factory()->admin()->create();

        $csv  = "name,code\n";
        $csv .= "Academic Track,ACAD\n";
        $path = tempnam(sys_get_temp_dir(), 'tracks_http_import_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'tracks.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/tracks/import', ['file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.tracks'));
        $response->assertSessionHas('success', '1 track(s) imported successfully!');
        $this->assertDatabaseHas('tracks', ['name' => 'Academic Track', 'code' => 'ACAD']);
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
