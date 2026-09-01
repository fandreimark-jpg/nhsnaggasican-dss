<?php

namespace Tests\Feature;

use App\Imports\SpecializationsImport;
use App\Models\Specialization;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * SpecializationsImport is driven through Excel::import() against a real
 * generated CSV — same reasoning as TracksImportTest/SubjectsImportTest.
 */
class SpecializationsImportTest extends TestCase
{
    use RefreshDatabase;

    private function importCsv(array $rows, string $header = "name,code,track"): SpecializationsImport
    {
        $csv = $header . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', $row) . "\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'specializations_import_') . '.csv';
        file_put_contents($path, $csv);

        $import = new SpecializationsImport();
        Excel::import($import, $path);

        @unlink($path);

        return $import;
    }

    public function test_admin_specializations_page_renders_with_new_import_modal(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/specializations')->assertOk();
    }

    public function test_valid_specialization_resolves_track_by_name(): void
    {
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        $import = $this->importCsv([
            ['Humanities and Social Sciences', 'humss', 'Academic Track'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertSame(1, $import->importedCount);
        $this->assertDatabaseHas('specializations', [
            'name'     => 'Humanities and Social Sciences',
            'code'     => 'HUMSS', // auto-uppercased
            'track_id' => $track->id,
        ]);
    }

    public function test_valid_specialization_resolves_track_by_code(): void
    {
        $track = Track::factory()->create(['name' => 'Technical-Professional Track', 'code' => 'TVL']);

        $import = $this->importCsv([
            ['Information and Communications Technology', 'ICT', 'TVL'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertDatabaseHas('specializations', ['name' => 'Information and Communications Technology', 'track_id' => $track->id]);
    }

    public function test_unresolvable_track_is_rejected_not_left_null(): void
    {
        // Unlike Subject's optional track, Specialization.track_id is
        // NOT NULL in the schema — an unresolvable track must fail
        // validation, never silently import with a null track.
        $import = $this->importCsv([
            ['Mystery Specialization', 'MYST', 'Nonexistent Track'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('specializations', ['name' => 'Mystery Specialization']);
    }

    public function test_missing_track_column_is_rejected(): void
    {
        $import = $this->importCsv([
            ['No Track Specialization', 'NTS', ''],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('specializations', ['name' => 'No Track Specialization']);
    }

    public function test_duplicate_name_within_same_track_against_existing_database_row_is_rejected(): void
    {
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        Specialization::factory()->create(['track_id' => $track->id, 'name' => 'STEM', 'code' => 'STEM']);

        $import = $this->importCsv([
            ['STEM', 'STEM2', 'Academic Track'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(1, Specialization::where('name', 'STEM')->count());
    }

    public function test_same_name_under_a_different_track_is_not_a_duplicate(): void
    {
        $trackA = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        $trackB = Track::factory()->create(['name' => 'Technical-Professional Track', 'code' => 'TVL']);
        Specialization::factory()->create(['track_id' => $trackA->id, 'name' => 'General', 'code' => 'GEN1']);

        $import = $this->importCsv([
            ['General', 'GEN2', 'Technical-Professional Track'],
        ]);

        $this->assertCount(0, $import->failures());
        $this->assertSame(2, Specialization::where('name', 'General')->count());
    }

    public function test_duplicate_code_against_existing_database_row_is_rejected_globally(): void
    {
        $track = Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        Specialization::factory()->create(['track_id' => $track->id, 'name' => 'STEM', 'code' => 'STEM']);
        $trackB = Track::factory()->create(['name' => 'Technical-Professional Track', 'code' => 'TVL']);

        $import = $this->importCsv([
            ['Science Tech Eng Math', 'stem', 'Technical-Professional Track'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseMissing('specializations', ['name' => 'Science Tech Eng Math']);
    }

    public function test_duplicate_within_the_same_file_is_rejected_without_crashing(): void
    {
        Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        $import = $this->importCsv([
            ['STEM', 'STEM', 'Academic Track'],
            ['STEM', 'STEM2', 'Academic Track'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertSame(1, Specialization::where('name', 'STEM')->count());
    }

    public function test_valid_and_invalid_rows_in_the_same_file_are_handled_independently(): void
    {
        Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        $import = $this->importCsv([
            ['Valid Spec', 'VAL', 'Academic Track'],
            ['Invalid Spec', 'INV', 'Nonexistent Track'],
        ]);

        $this->assertCount(1, $import->failures());
        $this->assertDatabaseHas('specializations', ['name' => 'Valid Spec']);
        $this->assertDatabaseMissing('specializations', ['name' => 'Invalid Spec']);
    }

    public function test_admin_can_import_specializations_via_http_and_gets_a_summary(): void
    {
        $admin = User::factory()->admin()->create();
        Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);

        $csv  = "name,code,track\n";
        $csv .= "Accountancy Business and Management,ABM,Academic Track\n";
        $path = tempnam(sys_get_temp_dir(), 'specializations_http_import_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'specializations.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/specializations/import', ['file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.specializations'));
        $response->assertSessionHas('success', '1 specialization(s) imported successfully!');
        $this->assertDatabaseHas('specializations', ['name' => 'Accountancy Business and Management']);
    }

    public function test_adviser_cannot_import_specializations(): void
    {
        $adviser = User::factory()->create();

        $this->actingAs($adviser)->post('/admin/specializations/import', [])
            ->assertForbidden();
    }

    public function test_principal_cannot_import_specializations(): void
    {
        $principal = User::factory()->principal()->create();

        $this->actingAs($principal)->post('/admin/specializations/import', [])
            ->assertForbidden();
    }
}
