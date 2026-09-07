<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * maatwebsite/excel's SkipsFailures reports one Failure per failed
 * ATTRIBUTE, not per row — an 8-row file where every row is missing all
 * 5 required columns would otherwise be misreported as "40 row(s) were
 * skipped" instead of 8. This proves the fix (Admin\SectionController's
 * use of SummarizesImportFailures) end to end through the real HTTP
 * upload, not just at the importer level.
 */
class ImportFailureCountTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'name,grade_level,track,specialization,adviser_email,school_year';

    public function test_eight_completely_blank_rows_are_reported_as_eight_skipped_rows_not_forty(): void
    {
        $admin = User::factory()->admin()->create();

        // Every required column left blank (name, grade_level, track,
        // specialization, school_year) but a junk adviser_email value so
        // the row isn't ENTIRELY empty — a fully empty row is silently
        // skipped by the reader itself before validation ever runs,
        // which would produce zero failures and defeat this test.
        $csv = self::HEADER . "\n";
        for ($i = 0; $i < 8; $i++) {
            $csv .= ",,,,not-an-email,\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'blank_sections_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'sections.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/sections/import', ['file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.sections'));
        $response->assertSessionHas('warning', function ($warning) {
            return str_contains($warning, '8 row(s) were skipped');
        });

        $errors = session('import_errors');
        $this->assertCount(8, $errors, 'Eight bad rows with five missing fields each must be reported as 8 skipped rows, not 40.');

        foreach ($errors as $index => $message) {
            $this->assertStringStartsWith('Row ' . ($index + 2) . ':', $message, 'Each message must combine one row\'s errors, not repeat one field at a time.');
        }

        $this->assertNotNull(session('import_header_hint'), 'A file where every row is missing every required column should surface the header-mismatch hint.');
        $this->assertStringContainsString('name, grade_level, track, specialization, school_year', session('import_header_hint'));

        // The flashed session data survives into this next request —
        // prove the shared partial actually renders it, not just that
        // the session carries it.
        $page = $this->get(route('admin.sections'));
        $page->assertOk();
        $page->assertSee('8 row(s) were skipped', false);
        $page->assertSee('Show all 8 skipped rows', false);
        $page->assertSee('header row matches the template exactly', false);
    }

    public function test_a_mixed_valid_and_invalid_file_still_imports_the_valid_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $track = \App\Models\Track::factory()->create(['name' => 'Academic Track', 'code' => 'ACAD']);
        \App\Models\Specialization::factory()->create([
            'track_id' => $track->id, 'name' => 'STEM', 'code' => 'STEM',
        ]);

        $csv = self::HEADER . "\n";
        $csv .= "Narra,11,ACAD,STEM,,2026-2027\n";
        $csv .= ",,,,not-an-email,\n";

        $path = tempnam(sys_get_temp_dir(), 'mixed_sections_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'sections.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/sections/import', ['file' => $file]);

        @unlink($path);

        $response->assertRedirect(route('admin.sections'));
        $this->assertDatabaseHas('sections', ['name' => 'Narra']);
        $this->assertCount(1, session('import_errors'));
    }
}
